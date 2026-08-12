<?php

namespace App;

use NFePHP\NFe\Make;
use NFePHP\NFe\Complements;

class EmissorNFCe
{
    private SupabaseClient $db;

    public function __construct()
    {
        $this->db = new SupabaseClient();
    }

    /**
     * Recebe o id do pedido, monta a NFC-e, assina e envia pra SEFAZ-PA.
     * Grava o resultado (autorizada, rejeitada ou em contingência) na
     * tabela notas_fiscais.
     */
    public function emitirPorPedido(string $pedidoId): array
    {
        $pedido = $this->buscarPedidoCompleto($pedidoId);

        $notaFiscal = $this->db->inserir('notas_fiscais', [
            'pedido_id' => $pedidoId,
            'tipo' => 'nfce',
            'status' => 'processando',
        ])[0];

        try {
            $xml = $this->montarXml($pedido);

            $tools = Config::tools();
            $tools->model(65); // 65 = NFC-e

            $idLote = (string) time();
            $resposta = $tools->sefazEnviaLote([$xml], $idLote, 1);
            $resultado = $this->interpretarResposta($resposta, $xml, $tools);

            $this->db->atualizar('notas_fiscais', ['id' => $notaFiscal['id']], $resultado);

            return $resultado;
        } catch (\Throwable $e) {
            // SEFAZ fora do ar ou erro de comunicação -> contingência.
            // A lei permite vender mesmo assim; a nota é transmitida
            // depois quando o serviço voltar (ver metodo reenviarContingencia).
            $this->db->atualizar('notas_fiscais', ['id' => $notaFiscal['id']], [
                'status' => 'contingencia',
                'motivo_rejeicao' => $e->getMessage(),
            ]);

            return ['status' => 'contingencia', 'motivo' => $e->getMessage()];
        }
    }

    private function buscarPedidoCompleto(string $pedidoId): array
    {
        $pedido = $this->db->selecionar('pedidos', ['id' => $pedidoId])[0] ?? null;
        if (!$pedido) {
            throw new \RuntimeException("Pedido $pedidoId não encontrado");
        }

        $itens = $this->db->selecionar('itens_pedido', ['pedido_id' => $pedidoId]);

        $pedido['itens'] = $itens;
        return $pedido;
    }

    /**
     * Monta o XML da NFC-e usando a classe Make da NFePHP.
     * Este é o esqueleto com os grupos obrigatórios principais —
     * ainda falta completar dados fiscais por produto (CFOP, NCM,
     * CST/CSOSN conforme o regime tributário do mercado) antes de
     * ir pra produção.
     */
    private function montarXml(array $pedido): string
    {
        $make = new Make();

        $make->taginfNFe((object) [
            'versao' => '4.00',
        ]);

        $numeroNota = $this->db->rpc('proximo_numero_nfce', ['p_serie' => 1]);

        $make->tagide((object) [
            'cUF' => 15, // codigo IBGE do Para
            'natOp' => 'Venda de mercadoria',
            'mod' => 65,
            'serie' => 1,
            'nNF' => $numeroNota,
            'dhEmi' => date('Y-m-d\TH:i:sP'),
            'tpNF' => 1,
            'idDest' => 1,
            'cMunFG' => (int) $_ENV['NFE_MUNICIPIO_CODIGO_IBGE'],
            'tpImp' => 4,
            'tpEmis' => 1,
            'tpAmb' => (int) $_ENV['NFE_AMBIENTE'],
            'finNFe' => 1,
            'indFinal' => 1,
            'indPres' => 1,
            'procEmi' => 0,
            'verProc' => '1.0.0',
        ]);

        $make->tagemit((object) [
            'CNPJ' => $_ENV['NFE_CNPJ'],
            'xNome' => $_ENV['NFE_RAZAO_SOCIAL'],
            'IE' => $_ENV['NFE_INSCRICAO_ESTADUAL'],
            'CRT' => 1, // TODO: ajustar regime tributario real (1=Simples Nacional)
        ]);

        $make->tagenderEmit((object) [
            'xLgr' => $_ENV['NFE_ENDERECO_LOGRADOURO'],
            'nro' => $_ENV['NFE_ENDERECO_NUMERO'],
            'xBairro' => $_ENV['NFE_ENDERECO_BAIRRO'],
            'cMun' => (int) $_ENV['NFE_MUNICIPIO_CODIGO_IBGE'],
            'xMun' => $_ENV['NFE_MUNICIPIO_NOME'],
            'UF' => $_ENV['NFE_UF'],
            'CEP' => preg_replace('/\D/', '', $_ENV['NFE_ENDERECO_CEP']),
            'cPais' => 1058,
            'xPais' => 'Brasil',
        ]);

        $itemNumero = 1;
        $valorTotal = 0;

        $itens = $pedido['itens'];
        // Busca os dados fiscais completos de cada produto (o pedido só
        // traz produto_id e quantidade, os campos fiscais vivem na
        // tabela produtos).
        foreach ($itens as &$item) {
            $produtoFiscal = $this->db->selecionar('produtos', ['id' => $item['produto_id']])[0] ?? [];
            $item['produto_dados'] = $produtoFiscal;
        }
        unset($item);

        foreach ($itens as $item) {
            $dadosProduto = $item['produto_dados'] ?? [];
            $valorItem = $item['quantidade'] * $item['preco_unitario'];
            $valorTotal += $valorItem;

            $make->tagprod((object) [
                'item' => $itemNumero,
                'cProd' => $item['produto_id'],
                'cEAN' => $dadosProduto['codigo_barras'] ?: 'SEM GTIN',
                'xProd' => $dadosProduto['nome'] ?? 'Produto',
                'NCM' => $dadosProduto['ncm'] ?: '00000000',
                'CFOP' => $dadosProduto['cfop'] ?: '5102',
                'uCom' => $dadosProduto['unidade'] ?: 'UN',
                'qCom' => $item['quantidade'],
                'vUnCom' => $item['preco_unitario'],
                'vProd' => $valorItem,
                'cEANTrib' => $dadosProduto['codigo_barras'] ?: 'SEM GTIN',
                'uTrib' => $dadosProduto['unidade'] ?: 'UN',
                'qTrib' => $item['quantidade'],
                'vUnTrib' => $item['preco_unitario'],
                'indTot' => 1,
            ]);

            // Grupo de ICMS -- assume Simples Nacional (CSOSN). Se o
            // mercado não for do Simples, isso precisa virar tagICMS
            // com CST em vez de CSOSN -- ajustar junto com o contador.
            $make->tagICMSSN((object) [
                'item' => $itemNumero,
                'orig' => $dadosProduto['origem_mercadoria'] ?? 0,
                'CSOSN' => $dadosProduto['csosn'] ?: '102',
            ]);

            $itemNumero++;
        }

        $make->tagICMSTot((object) [
            'vProd' => $valorTotal,
            'vNF' => $valorTotal,
        ]);

        $make->tagpag((object) [
            'vTroco' => 0,
        ]);

        $codigoPagamento = match ($pedido['forma_pagamento'] ?? 'dinheiro') {
            'dinheiro' => '01',
            'cartao' => '03', // cartão de crédito -- o pedido não distingue crédito/débito ainda
            'pix' => '17',
            default => '99', // outros
        };

        $make->tagdetPag((object) [
            'tPag' => $codigoPagamento,
            'vPag' => $valorTotal,
        ]);

        if (!$make->montaNFe()) {
            throw new \RuntimeException('Falha ao montar XML: ' . implode(' | ', $make->getErrors()));
        }

        return $make->getXML();
    }

    private function interpretarResposta(string $respostaSefaz, string $xml, $tools): array
    {
        $std = \NFePHP\NFe\Common\Standardize::toStd($respostaSefaz);

        // Simplificado — na pratica a resposta do lote traz um
        // protocolo que precisa ser consultado (sefazConsultaRecibo)
        // se o processamento for sincrono/assincrono. Ajustar conforme
        // retorno real observado em homologacao.
        if (isset($std->protNFe->infProt->cStat) && $std->protNFe->infProt->cStat == 100) {
            $xmlAssinadoComProtocolo = Complements::toAuthorize($xml, $respostaSefaz);

            return [
                'status' => 'autorizada',
                'chave_acesso' => $std->protNFe->infProt->chNFe ?? null,
                'protocolo_autorizacao' => $std->protNFe->infProt->nProt ?? null,
            ];
        }

        return [
            'status' => 'rejeitada',
            'motivo_rejeicao' => $std->protNFe->infProt->xMotivo ?? 'Motivo não identificado',
        ];
    }
}
