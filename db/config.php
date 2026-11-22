<?php
declare(strict_types=1);

/**
 * Configuración de conexión MySQL para adncontrolelectoral.
 * Puede sobrescribirse mediante db/config.local.php o variables de entorno:
 * DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 */
function getDbConfig(): array
{
    $localFile = __DIR__ . '/config.local.php';
    $local = is_file($localFile) ? (require $localFile) : [];
    if (!is_array($local)) {
        $local = [];
    }

    return [
        'host'    => $local['host']    ?? (getenv('DB_HOST') ?: '127.0.0.1'),
        'port'    => $local['port']    ?? (getenv('DB_PORT') ?: '3306'),
        'dbname'  => $local['dbname']  ?? (getenv('DB_NAME') ?: 'adncontrolelectoral'),
        'user'    => $local['user']    ?? (getenv('DB_USER') ?: 'adn_user'),
        'pass'    => $local['pass']    ?? (getenv('DB_PASS') ?: 'AdnElectoral2026!'),
        'charset' => $local['charset'] ?? 'utf8mb4',
    ];
}

function getPdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = getDbConfig();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $cfg['host'],
        $cfg['port'],
        $cfg['dbname'],
        $cfg['charset']
    );

    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

function getGeminiApiKey(): string
{
    $localFile = __DIR__ . '/config.local.php';
    $local = is_file($localFile) ? (require $localFile) : [];
    if (is_array($local) && !empty($local['gemini_api_key'])) {
        return trim((string)$local['gemini_api_key']);
    }
    return trim((string)(getenv('GEMINI_API_KEY') ?: ''));
}
