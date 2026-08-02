<?php

namespace App;

class CadastroPessoa
{
    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = new SupabaseClient();
    }

    /**
     * $dados esperado:
     *  nome, telefone, email, cpf_cnpj (opcional), papel
     *    (cliente | entregador | fornecedor | operador_pdv),
     *  senha (obrigatória se o papel precisa de login),
     *  veiculo => ['placa','tipo','modelo']  (só se papel = entregador)
     */
    public function cadastrar(array $dados): array
    {
        $papel = $dados['papel'] ?? 'cliente';
        $papeisComLogin = ['cliente', 'entregador', 'operador_pdv', 'admin'];

        // 1) Registro central da pessoa -- serve pra qualquer papel,
        // evita duplicar nome/telefone se essa pessoa já tinha outro
        // papel cadastrado antes.
        $pessoa = $this->db->inserir('pessoas', [
            'nome' => $dados['nome'],
            'telefone' => $dados['telefone'] ?? null,
            'email' => $dados['email'] ?? null,
            'cpf_cnpj' => $dados['cpf_cnpj'] ?? null,
        ])[0];

        $resultado = ['pessoa_id' => $pessoa['id'], 'papel' => $papel];

        if (in_array($papel, $papeisComLogin, true)) {
            if (empty($dados['email']) || empty($dados['senha'])) {
                throw new \InvalidArgumentException('E-mail e senha são obrigatórios pra papéis com login.');
            }

            // 2) Cria o login de verdade -- isso já dispara o trigger
            // que cria a linha em "perfis" automaticamente.
            $usuarioAuth = $this->db->criarUsuarioAuth($dados['email'], $dados['senha'], $dados['nome']);

            if (isset($usuarioAuth['msg']) || isset($usuarioAuth['error_code'])) {
                throw new \RuntimeException('Falha ao criar login: ' . ($usuarioAuth['msg'] ?? $usuarioAuth['error_code']));
            }

            $usuarioId = $usuarioAuth['id'];

            // 3) Atualiza o perfil com o papel certo, telefone, e o
            // vínculo com o registro central de pessoa.
            $this->db->atualizar('perfis', ['id' => $usuarioId], [
                'papel' => $papel,
                'telefone' => $dados['telefone'] ?? null,
                'pessoa_id' => $pessoa['id'],
            ]);

            $resultado['usuario_id'] = $usuarioId;

            // 4) Se for entregador, já cadastra o veículo e o
            // registro de entregador na mesma tacada.
            if ($papel === 'entregador' && !empty($dados['veiculo'])) {
                $veiculo = $this->db->inserir('veiculos', [
                    'placa' => strtoupper($dados['veiculo']['placa']),
                    'tipo' => $dados['veiculo']['tipo'] ?? 'moto',
                    'modelo' => $dados['veiculo']['modelo'] ?? null,
                ])[0];

                $this->db->inserir('entregadores', [
                    'usuario_id' => $usuarioId,
                    'veiculo_id' => $veiculo['id'],
                    'status' => 'disponivel',
                ]);

                $resultado['veiculo_id'] = $veiculo['id'];
            }
        }

        if ($papel === 'fornecedor') {
            // Fornecedor não loga no app -- não precisa de conta,
            // só do registro na tabela de fornecedores já usada
            // pela importação de XML de compra.
            $fornecedor = $this->db->inserir('fornecedores', [
                'cnpj' => $dados['cpf_cnpj'] ?? '',
                'razao_social' => $dados['nome'],
                'telefone' => $dados['telefone'] ?? null,
                'email' => $dados['email'] ?? null,
                'pessoa_id' => $pessoa['id'],
            ])[0];

            $resultado['fornecedor_id'] = $fornecedor['id'];
        }

        return $resultado;
    }
}
