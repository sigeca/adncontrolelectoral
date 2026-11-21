<?php
declare(strict_types=1);

require_once __DIR__ . '/db/config.php';
require_once __DIR__ . '/db/sync_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    $pdo = getPdo();

    if ($action === 'health') {
        $pdo->query('SELECT 1');
        echo json_encode(['ok' => true, 'db' => getDbConfig()['dbname']]);
        exit;
    }

    if ($action === 'load') {
        $state = loadAllFromDb($pdo);
        echo json_encode([
            'ok'   => true,
            'data' => $state,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw ?: '', true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
            exit;
        }
        $counts = syncToDb($pdo, $payload, true);
        echo json_encode(['ok' => true, 'counts' => $counts]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Acción no soportada']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
