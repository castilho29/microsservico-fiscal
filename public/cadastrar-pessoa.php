<?php

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\CadastroPessoa;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Microservico-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');
Config::carregarEnv();

$tokenRecebido = $_SERVER['HTTP_X_MICROSERVICO_TOKEN'] ?? '';
if (!hash_equals($_ENV['MICROSERVICO_TOKEN'], $tokenRecebido)) {
    http_response_code(401);
    echo json_encode(['erro' => 'Token inválido']);
    exit;
}

$dados = json_decode(file_get_contents('php://input'), true);

if (empty($dados['nome']) || empty($dados['papel'])) {
    http_response_code(400);
    echo json_encode(['erro' => 'nome e papel são obrigatórios']);
    exit;
}

try {
    $cadastro = new CadastroPessoa();
    $resultado = $cadastro->cadastrar($dados);

    http_response_code(200);
    echo json_encode($resultado);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['erro' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
}
