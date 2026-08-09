<?php

namespace App;

class ImportadorNFeCompra
{
    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = new SupabaseClient();
    }

    /**
     * Recebe o conteudo bruto do XML de uma NF-e de compra (a que o
     * FORNECEDOR emitiu pra voce) e:
     *  1. cadastra o fornecedor se ainda nao existir
     *  2. cria a nota_entrada e os itens_entrada
     *  3. se for venda a prazo, gera uma linha em contas_pagar por
     *     duplicata (parcela) informada no XML
     */
    public function importar(string $xmlConteudo): array
    {
        $xml = new \SimpleXMLElement($xmlConteudo);
        $xml->registerXPathNamespace('n', 'http://www.portalfiscal.inf.br/nfe');

        $infNFe = $xml->xpath('//n:infNFe')[0];
        $emit = $xml->xpath('//n:emit')[0];
        $ide = $xml->xpath('//n:ide')[0];
        $total = $xml->xpath('//n:ICMSTot')[0];

        $chaveAcesso = (string) $infNFe->attributes()->Id;
        $chaveAcesso = str_replace('NFe', '', $chaveAcesso);

        // Evita importar a mesma nota duas vezes
        $existente = $this->db->selecionar('notas_entrada', ['chave_acesso' => $chaveAcesso]);
        if (!empty($existente)) {
            throw new \RuntimeException("Nota $chaveAcesso já foi importada anteriormente");
        }

        $fornecedor = $this->obterOuCriarFornecedor(
            cnpj: (string) $emit->CNPJ,
            razaoSocial: (string) $emit->xNome,
            nomeFantasia: (string) ($emit->xFant ?? '')
        );

        $duplicatas = $xml->xpath('//n:cobr/n:dup');
        $formaPagamento = count($duplicatas) > 0 ? 'a_prazo' : 'a_vista';

        $notaEntrada = $this->db->inserir('notas_entrada', [
            'fornecedor_id' => $fornecedor['id'],
            'numero' => (int) $ide->nNF,
            'serie' => (int) $ide->serie,
            'chave_acesso' => $chaveAcesso,
            'valor_total' => (float) $total->vNF,
            'forma_pagamento' => $formaPagamento,
            'data_emissao' => substr((string) $ide->dhEmi, 0, 10),
        ])[0];

        $this->importarItens($xml, $notaEntrada['id']);

        if ($formaPagamento === 'a_prazo') {
            $this->gerarContasPagar($duplicatas, $fornecedor['id'], $notaEntrada['id']);
        } else {
            // A vista: gera uma unica conta ja com vencimento hoje,
            // pra manter o fluxo de caixa consistente mesmo pra compras
            // pagas na hora.
            $this->db->inserir('contas_pagar', [
                'fornecedor_id' => $fornecedor['id'],
                'nota_entrada_id' => $notaEntrada['id'],
                'numero_parcela' => 1,
                'valor' => (float) $total->vNF,
                'vencimento' => date('Y-m-d'),
                'status' => 'pago',
                'pago_em' => date('c'),
                'pago_valor' => (float) $total->vNF,
            ]);
        }

        return [
            'nota_entrada_id' => $notaEntrada['id'],
            'fornecedor' => $fornecedor['razao_social'],
            'quantidade_itens' => count($xml->xpath('//n:det')),
            'forma_pagamento' => $formaPagamento,
            'parcelas_geradas' => $formaPagamento === 'a_prazo' ? count($duplicatas) : 1,
        ];
    }

    private function obterOuCriarFornecedor(string $cnpj, string $razaoSocial, string $nomeFantasia): array
    {
        $existente = $this->db->selecionar('fornecedores', ['cnpj' => $cnpj]);
        if (!empty($existente)) {
            return $existente[0];
        }

        return $this->db->inserir('fornecedores', [
            'cnpj' => $cnpj,
            'razao_social' => $razaoSocial,
            'nome_fantasia' => $nomeFantasia,
        ])[0];
    }

    private function importarItens(\SimpleXMLElement $xml, string $notaEntradaId): void
    {
        foreach ($xml->xpath('//n:det') as $det) {
            $prod = $det->prod;

            $codigoBarras = (string) $prod->cEAN;
            if ($codigoBarras === '' || strtoupper($codigoBarras) === 'SEM GTIN') {
                $codigoBarras = null;
            }

            $nome = (string) $prod->xProd;
            $ncm = (string) $prod->NCM;
            $unidade = (string) $prod->uCom;
            $quantidade = (float) $prod->qCom;
            $valorUnitario = (float) $prod->vUnCom;

            $produto = $this->obterOuCriarProduto($codigoBarras, $nome, $ncm, $unidade, $valorUnitario);

            $this->db->inserir('itens_entrada', [
                'nota_entrada_id' => $notaEntradaId,
                'produto_id' => $produto['id'],
                'descricao_xml' => $nome,
                'codigo_fornecedor' => (string) $prod->cProd,
                'quantidade' => $quantidade,
                'valor_unitario' => $valorUnitario,
                'vinculado' => true,
            ]);

            // Toda entrada vinda de XML de fornecedor é, por definição,
            // estoque documentado -- soma em estoque_com_nota, nunca em
            // estoque_sem_nota.
            $this->db->rpc('incrementar_estoque_com_nota', [
                'p_produto_id' => $produto['id'],
                'p_quantidade' => $quantidade,
            ]);
        }
    }

    /**
     * Procura o produto pelo código de barras do XML. Se achar,
     * reaproveita (já é um produto seu, só chegou mais estoque).
     * Se não achar, cria um produto novo -- fica INATIVO por
     * padrão, porque o preço de venda sugerido (custo + margem
     * padrão) precisa de revisão humana antes de ir pro catálogo.
     */
    private function obterOuCriarProduto(?string $codigoBarras, string $nome, string $ncm, string $unidade, float $custoUnitario): array
    {
        if ($codigoBarras !== null) {
            $existente = $this->db->selecionar('produtos', ['codigo_barras' => $codigoBarras]);
            if (!empty($existente)) {
                return $existente[0];
            }
        }

        $margemPadrao = 1.3; // 30% -- só um ponto de partida, o operador ajusta na tela de produtos
        $precoSugerido = round($custoUnitario * $margemPadrao, 2);

        return $this->db->inserir('produtos', [
            'nome' => $nome,
            'preco' => $precoSugerido,
            'codigo_barras' => $codigoBarras,
            'ncm' => $ncm,
            'unidade' => $unidade ?: 'UN',
            'ativo' => false, // precisa de revisão antes de aparecer na loja
        ])[0];
    }

    private function gerarContasPagar(array $duplicatas, string $fornecedorId, string $notaEntradaId): void
    {
        $numero = 1;
        foreach ($duplicatas as $dup) {
            $this->db->inserir('contas_pagar', [
                'fornecedor_id' => $fornecedorId,
                'nota_entrada_id' => $notaEntradaId,
                'numero_parcela' => $numero,
                'valor' => (float) $dup->vDup,
                'vencimento' => (string) $dup->dVenc,
                'status' => 'pendente',
            ]);
            $numero++;
        }
    }
}
