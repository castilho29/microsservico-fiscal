<?php

namespace App;

use Dotenv\Dotenv;
use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;

require_once __DIR__ . '/../vendor/autoload.php';

class Config
{
    public static function carregarEnv(): void
    {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
        $dotenv->safeLoad();
    }

    /**
     * Monta o objeto Tools da NFePHP já configurado pro autorizador
     * do Pará. A biblioteca resolve sozinha se o PA usa SVAN ou outro
     * autorizador — não precisamos apontar URL de webservice na mão.
     */
    public static function tools(): Tools
    {
        self::carregarEnv();

        $configJson = json_encode([
            'atualizacao' => date('Y-m-d H:i:s'),
            'tpAmb' => (int) $_ENV['NFE_AMBIENTE'],       // 1=producao 2=homologacao
            'razaosocial' => $_ENV['NFE_RAZAO_SOCIAL'],
            'cnpj' => $_ENV['NFE_CNPJ'],
            'siglaUF' => $_ENV['NFE_UF'],                 // PA
            'schemes' => 'PL_009_V4',
            'versao' => '4.00',
            'tokenIBPT' => '',
            'CSC' => $_ENV['NFE_CSC'],
            'CSCid' => $_ENV['NFE_CSC_ID'],
            'aProxyConf' => [
                'proxyIp' => '',
                'proxyPort' => '',
                'proxyUser' => '',
                'proxyPass' => '',
            ],
        ]);

        $certificadoConteudo = file_get_contents($_ENV['NFE_CERTIFICADO_PATH']);
        $certificate = Certificate::readPfx($certificadoConteudo, $_ENV['NFE_CERTIFICADO_SENHA']);

        return new Tools($configJson, $certificate);
    }
}
