<?php

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\ImportadorNFeCompra;

// CORS -- necessário porque o pdv-web (localhost:3000) e este
// microsserviço (localhost:8080) rodam em portas diferentes, o que
// o navegador trata como "origens diferentes" e bloqueia por padrão.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Microservico-Token');

// O navegador manda um "OPTIONS" de checagem antes do POST de verdade
// (preflight) -- só precisa responder OK, sem processar nada.
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

// Aceita tanto upload de arquivo (multipart/form-data, campo "xml")
// quanto o XML cru no corpo da requisição.
if (isset($_FILES['xml'])) {
    $xmlConteudo = file_get_contents($_FILES['xml']['tmp_name']);
} else {
    $xmlConteudo = file_get_contents('php://input');
}

if (empty($xmlConteudo)) {
    http_response_code(400);
    echo json_encode(['erro' => 'Nenhum XML recebido']);
    exit;
}

try {
    $importador = new ImportadorNFeCompra();
    $resultado = $importador->importar($xmlConteudo);

    http_response_code(200);
    echo json_encode($resultado);
} catch (\Throwable $e) {
    http_response_code(422);
    echo json_encode(['erro' => $e->getMessage()]);
}
