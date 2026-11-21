<?php
declare(strict_types=1);

/**
 * Configuración de conexión MySQL para andcontrolelectoral.
 * Puede sobrescribirse mediante variables de entorno:
 * DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 */
function getDbConfig(): array
{
    return [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => getenv('DB_PORT') ?: '3306',
        'dbname'  => getenv('DB_NAME') ?: 'andcontrolelectoral',
        'user'    => getenv('DB_USER') ?: 'adn_user',
        'pass'    => getenv('DB_PASS') ?: 'AdnElectoral2026!',
        'charset' => 'utf8mb4',
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
