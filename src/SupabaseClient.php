<?php

namespace App;

use GuzzleHttp\Client;

class SupabaseClient
{
    private Client $http;

    public function __construct()
    {
        Config::carregarEnv();

        $this->http = new Client([
            'base_uri' => rtrim($_ENV['SUPABASE_URL'], '/') . '/rest/v1/',
            'headers' => [
                'apikey' => $_ENV['SUPABASE_SERVICE_ROLE_KEY'],
                'Authorization' => 'Bearer ' . $_ENV['SUPABASE_SERVICE_ROLE_KEY'],
                'Content-Type' => 'application/json',
                'Prefer' => 'return=representation',
            ],
        ]);
    }

    public function selecionar(string $tabela, array $filtros = []): array
    {
        $query = [];
        foreach ($filtros as $coluna => $valor) {
            $query[$coluna] = "eq.$valor";
        }

        $resposta = $this->http->get($tabela, ['query' => $query]);
        return json_decode((string) $resposta->getBody(), true);
    }

    public function inserir(string $tabela, array $dados): array
    {
        $resposta = $this->http->post($tabela, ['json' => $dados]);
        return json_decode((string) $resposta->getBody(), true);
    }

    public function atualizar(string $tabela, array $filtros, array $dados): array
    {
        $query = [];
        foreach ($filtros as $coluna => $valor) {
            $query[$coluna] = "eq.$valor";
        }

        $resposta = $this->http->patch($tabela, ['query' => $query, 'json' => $dados]);
        return json_decode((string) $resposta->getBody(), true);
    }

    /**
     * Chama uma função do banco (RPC) -- usada pras funções de
     * baixa/incremento de estoque, que precisam ser atômicas
     * (evitar condição de corrida entre vendas simultâneas).
     */
    public function rpc(string $funcao, array $parametros = []): mixed
    {
        $resposta = $this->http->post("rpc/$funcao", ['json' => $parametros]);
        return json_decode((string) $resposta->getBody(), true);
    }
}
