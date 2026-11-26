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

if ($action === 'ocr_borrador' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw ?: '', true);
        if (!is_array($payload) || empty($payload['imageDataUrl'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Falta la imagen del borrador.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $apiKey = getGeminiApiKey();
        if ($apiKey === '' && !empty($payload['apiKey'])) {
            $apiKey = trim((string)$payload['apiKey']);
        }
        if ($apiKey === '') {
            http_response_code(400);
            echo json_encode([
                'ok'      => false,
                'needKey' => true,
                'error'   => 'No hay API Key de Gemini configurada en el servidor ni en el navegador.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $dataUrl = (string)$payload['imageDataUrl'];
        if (!preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/s', $dataUrl, $m)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Formato de imagen inválido (se esperaba DataURL base64).'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $mimeType = $m[1];
        $base64Data = preg_replace('/\s+/', '', $m[2]);

        $dignidad = (string)($payload['dignidad'] ?? '');
        $candidatos = is_array($payload['candidatos'] ?? null) ? $payload['candidatos'] : [];
        $candListText = [];
        foreach ($candidatos as $c) {
            $candListText[] = sprintf(
                '- id: "%s" | lista: "%s" | partido: "%s" | nombre: "%s"',
                $c['id'] ?? '',
                $c['lista'] ?? '',
                $c['partido'] ?? '',
                $c['nombre'] ?? ''
            );
        }

        $prompt = "Eres un asistente experto en lectura (OCR) de actas y borradores de escrutinio electoral de Ecuador.\n"
            . "Analiza cuidadosamente la imagen adjunta (borrador de escrutinio / hoja auxiliar de conteo).\n"
            . ($dignidad !== '' ? "Dignidad seleccionada en el formulario: {$dignidad}.\n" : '')
            . "Lista de organizaciones políticas / candidatos esperados:\n"
            . implode("\n", $candListText) . "\n\n"
            . "Extrae los valores numéricos enteros de votación que aparecen en la imagen.\n"
            . "Devuelve EXCLUSIVAMENTE un objeto JSON válido con esta estructura exacta:\n"
            . "{\n"
            . "  \"total_papeletas\": numero_entero_o_null,\n"
            . "  \"blancos\": numero_entero_o_null,\n"
            . "  \"nulos\": numero_entero_o_null,\n"
            . "  \"candidatos\": {\n"
            . "    \"<id_del_candidato>\": numero_entero_o_null\n"
            . "  },\n"
            . "  \"observacion\": \"breve nota si algún número es poco legible o si la imagen corresponde a otra dignidad\"\n"
            . "}\n"
            . "Reglas: usa exactamente los mismos 'id' de la lista proporcionada. Si un valor no es visible o no está en la imagen, usa null (no inventes números).";

        $reqBody = json_encode([
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64Data]],
                ],
            ]],
            'generationConfig' => [
                'temperature'      => 0.1,
                'responseMimeType' => 'application/json',
            ],
        ], JSON_UNESCAPED_UNICODE);

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent?key=' . urlencode($apiKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $reqBody,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $respBody = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($respBody === false) {
            http_response_code(502);
            echo json_encode(['ok' => false, 'error' => 'Error de red hacia Gemini: ' . $curlErr], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $gemJson = json_decode($respBody, true);
        if ($httpCode >= 400 || !is_array($gemJson)) {
            $errMsg = $gemJson['error']['message'] ?? ('HTTP ' . $httpCode . ' de Gemini');
            http_response_code(502);
            echo json_encode(['ok' => false, 'error' => $errMsg], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $text = $gemJson['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text));
        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            http_response_code(502);
            echo json_encode(['ok' => false, 'error' => 'Gemini no devolvió un JSON válido.', 'raw' => $text], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['ok' => true, 'result' => $parsed], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

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
