<?php

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\EmissorNFCe;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Microservico-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');
Config::carregarEnv();

// Protege o endpoint com um token simples combinado com o backend
$tokenRecebido = $_SERVER['HTTP_X_MICROSERVICO_TOKEN'] ?? '';
if (!hash_equals($_ENV['MICROSERVICO_TOKEN'], $tokenRecebido)) {
    http_response_code(401);
    echo json_encode(['erro' => 'Token inválido']);
    exit;
}

$corpo = json_decode(file_get_contents('php://input'), true);
$pedidoId = $corpo['pedido_id'] ?? null;

if (!$pedidoId) {
    http_response_code(400);
    echo json_encode(['erro' => 'pedido_id é obrigatório']);
    exit;
}

try {
    $emissor = new EmissorNFCe();
    $resultado = $emissor->emitirPorPedido($pedidoId);

    http_response_code(200);
    echo json_encode($resultado);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()]);
}
