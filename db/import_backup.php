<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/sync_lib.php';

$backupFile = $argv[1] ?? dirname(__DIR__) . '/Respaldo_Conteo_Electoral_2026-11-21.json';
if (!is_file($backupFile)) {
    fwrite(STDERR, "No se encontró el archivo de respaldo: $backupFile\n");
    exit(1);
}

$raw = file_get_contents($backupFile);
$json = json_decode($raw ?: '', true);
if (!is_array($json)) {
    fwrite(STDERR, "El archivo JSON no es válido.\n");
    exit(1);
}

$payload = [
    'dist'  => $json['distributivo'] ?? [],
    'cand'  => $json['candidatos'] ?? [],
    'deleg' => $json['delegados'] ?? [],
    'actas' => $json['actas'] ?? [],
];

$pdo = getPdo();

// Asegurar usuario administrador por defecto si la tabla usuarios está vacía
$uCount = (int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
if ($uCount === 0) {
    $payload['users'] = [[
        'id'        => 'u0',
        'nom'       => 'Administrador',
        'ape'       => 'General',
        'ced'       => '0000000000',
        'tel'       => '',
        'mail'      => '',
        'user'      => 'admin',
        'salt'      => '7NrRUjA52hDYlryl1RgUuw==',
        'hash'      => 'hj4y/Nb/F8AQfbgVur3fQiQ52ZNhuBLUxIHWzIDnVr4=',
        'rol'       => 'superadmin',
        'activo'    => true,
        'bloq'      => false,
        'cambio'    => false,
        'ultimo'    => null,
        'canton'    => '',
        'parroquia' => '',
        'recinto'   => '',
        'mesa'      => '',
    ]];
}

$counts = syncToDb($pdo, $payload, false);
echo "Importación completada en 'andcontrolelectoral':\n";
foreach ($counts as $k => $v) {
    echo "  - $k: $v registros\n";
}
