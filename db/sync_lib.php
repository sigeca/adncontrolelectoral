<?php
declare(strict_types=1);

function normGeo(?string $v): string
{
    return mb_strtoupper(trim((string)($v ?? '')), 'UTF-8');
}

function distHash(array $d): string
{
    return md5(implode('|', [
        normGeo($d['canton'] ?? ''),
        normGeo($d['parroquia'] ?? ''),
        normGeo($d['zona'] ?? ''),
        normGeo($d['recinto'] ?? ''),
    ]));
}

function distCprKey(array $d): string
{
    return implode('|', [
        normGeo($d['canton'] ?? ''),
        normGeo($d['parroquia'] ?? ''),
        normGeo($d['recinto'] ?? ''),
    ]);
}

function buildDistLookup(PDO $pdo): array
{
    $byHash = [];
    $byCpr = [];
    $stmt = $pdo->query('SELECT id, ubicacion_hash, canton, parroquia, recinto FROM distributivo');
    foreach ($stmt as $row) {
        $byHash[$row['ubicacion_hash']] = (int)$row['id'];
        $byCpr[distCprKey($row)] = (int)$row['id'];
    }
    return [$byHash, $byCpr];
}

function resolveDistId(array $item, array $byHash, array $byCpr): ?int
{
    $h = distHash($item);
    if (isset($byHash[$h])) {
        return $byHash[$h];
    }
    $cpr = distCprKey($item);
    return $byCpr[$cpr] ?? null;
}

function loadAllFromDb(PDO $pdo): array
{
    // 1. Distributivo
    $dist = [];
    $stmt = $pdo->query('SELECT canton, parroquia, zona, recinto, mesas_fem_inicio, mesas_fem_fin, mesas_masc_inicio, mesas_masc_fin, padron_mesa, electores_recinto FROM distributivo ORDER BY id ASC');
    foreach ($stmt as $r) {
        $dist[] = [
            'canton'            => $r['canton'],
            'parroquia'         => $r['parroquia'],
            'zona'              => $r['zona'],
            'recinto'           => $r['recinto'],
            'mesas_fem_inicio'  => (int)$r['mesas_fem_inicio'],
            'mesas_fem_fin'     => (int)$r['mesas_fem_fin'],
            'mesas_masc_inicio' => (int)$r['mesas_masc_inicio'],
            'mesas_masc_fin'    => (int)$r['mesas_masc_fin'],
            'padron_mesa'       => (int)$r['padron_mesa'],
            'electores_recinto' => (int)$r['electores_recinto'],
        ];
    }

    // 2. Candidatos + candidatos_orden
    $ordenMap = [];
    $stmtOrd = $pdo->query('SELECT candidato_id, nombre FROM candidatos_orden ORDER BY candidato_id ASC, orden ASC');
    foreach ($stmtOrd as $r) {
        $ordenMap[$r['candidato_id']][] = $r['nombre'];
    }

    $cand = [];
    $stmtCand = $pdo->query('SELECT id, dignidad, lista, partido, color, nombre, canton, circunscripcion FROM candidatos ORDER BY orden_visual ASC, id ASC');
    foreach ($stmtCand as $r) {
        $cand[] = [
            'id'               => $r['id'],
            'dignidad'         => $r['dignidad'],
            'lista'            => $r['lista'],
            'partido'          => $r['partido'],
            'color'            => $r['color'],
            'nombre'           => $r['nombre'],
            'canton'           => $r['canton'],
            'circunscripcion'  => $r['circunscripcion'],
            'candidatos_orden' => $ordenMap[$r['id']] ?? null,
        ];
    }

    // 3. Delegados
    $deleg = [];
    $stmtDel = $pdo->query('SELECT id, apellidos, nombres, nombre, cedula, telefono, rol, acreditado, canton, parroquia, zona, recinto, mesa, fecha FROM delegados ORDER BY fecha ASC, id ASC');
    foreach ($stmtDel as $r) {
        $deleg[] = [
            'id'         => $r['id'],
            'apellidos'  => $r['apellidos'],
            'nombres'    => $r['nombres'],
            'nombre'     => $r['nombre'],
            'cedula'     => $r['cedula'],
            'telefono'   => $r['telefono'],
            'rol'        => $r['rol'],
            'acreditado' => $r['acreditado'],
            'canton'     => $r['canton'],
            'parroquia'  => $r['parroquia'],
            'zona'       => $r['zona'],
            'recinto'    => $r['recinto'],
            'mesa'       => $r['mesa'],
            'fecha'      => $r['fecha'],
        ];
    }

    // 4. Actas + acta_votos
    $votosMap = [];
    $stmtVot = $pdo->query('SELECT acta_key, candidato_id, votos FROM acta_votos');
    foreach ($stmtVot as $r) {
        $votosMap[$r['acta_key']][$r['candidato_id']] = (int)$r['votos'];
    }

    $actas = [];
    $stmtAct = $pdo->query('SELECT acta_key, dignidad, canton, parroquia, zona, recinto, mesa, total_papeletas, validos, blancos, nulos, total_votos, diferencia, cuadrada, observaciones, foto_acta, foto_borrador, timestamp, extra_json FROM actas');
    foreach ($stmtAct as $r) {
        $extra = $r['extra_json'] ? json_decode($r['extra_json'], true) : [];
        if (!is_array($extra)) {
            $extra = [];
        }
        $acta = array_merge($extra, [
            'key'             => $r['acta_key'],
            'dignidad'        => $r['dignidad'],
            'canton'          => $r['canton'],
            'parroquia'       => $r['parroquia'],
            'zona'            => $r['zona'],
            'recinto'         => $r['recinto'],
            'mesa'            => $r['mesa'],
            'total_papeletas' => (int)$r['total_papeletas'],
            'validos'         => (int)$r['validos'],
            'blancos'         => (int)$r['blancos'],
            'nulos'           => (int)$r['nulos'],
            'total_votos'     => (int)$r['total_votos'],
            'diferencia'      => (int)$r['diferencia'],
            'cuadrada'        => (bool)$r['cuadrada'],
            'candidatos'      => $votosMap[$r['acta_key']] ?? new stdClass(),
            'observaciones'   => $r['observaciones'] ?? '',
            'fotoActa'        => $r['foto_acta'],
            'timestamp'       => $r['timestamp'],
        ]);
        if ($r['foto_borrador'] !== null) {
            $acta['fotoBorrador'] = $r['foto_borrador'];
        }
        $actas[$r['acta_key']] = $acta;
    }

    // 5. Respaldos
    $resp = [];
    $stmtResp = $pdo->query('SELECT resp_key, dignidad, canton, parroquia, zona, recinto, mesa, foto_acta, foto_borrador, timestamp, payload_json FROM respaldos');
    foreach ($stmtResp as $r) {
        $obj = $r['payload_json'] ? json_decode($r['payload_json'], true) : [];
        if (!is_array($obj)) {
            $obj = [];
        }
        $obj['key'] = $r['resp_key'];
        $obj['dignidad'] = $r['dignidad'];
        $obj['canton'] = $r['canton'];
        $obj['parroquia'] = $r['parroquia'];
        $obj['zona'] = $r['zona'];
        $obj['recinto'] = $r['recinto'];
        $obj['mesa'] = $r['mesa'];
        $obj['fotoActa'] = $r['foto_acta'];
        if ($r['foto_borrador'] !== null) {
            $obj['fotoBorrador'] = $r['foto_borrador'];
        }
        $obj['timestamp'] = $r['timestamp'];
        $resp[$r['resp_key']] = $obj;
    }

    // 6. Tarifas
    $tarifas = [];
    $stmtTar = $pdo->query('SELECT rol_key, monto FROM tarifas');
    foreach ($stmtTar as $r) {
        $tarifas[$r['rol_key']] = (float)$r['monto'];
    }

    // 7. Pagos
    $pagos = [];
    $stmtPag = $pdo->query('SELECT delegado_id, monto, estado, metodo, ref, fecha, observaciones FROM pagos');
    foreach ($stmtPag as $r) {
        $p = [
            'estado' => $r['estado'],
            'metodo' => $r['metodo'],
            'ref'    => $r['ref'],
            'fecha'  => $r['fecha'],
        ];
        if ($r['monto'] !== null) {
            $p['monto'] = (float)$r['monto'];
        }
        if ($r['observaciones'] !== null) {
            $p['observaciones'] = $r['observaciones'];
        }
        $pagos[$r['delegado_id']] = $p;
    }

    // 8. Usuarios
    $users = [];
    $stmtUsr = $pdo->query('SELECT id, nom, ape, ced, tel, mail, user, salt, hash, rol, activo, bloq, cambio, ultimo, canton, parroquia, recinto, mesa FROM usuarios ORDER BY id ASC');
    foreach ($stmtUsr as $r) {
        $users[] = [
            'id'        => $r['id'],
            'nom'       => $r['nom'],
            'ape'       => $r['ape'],
            'ced'       => $r['ced'],
            'tel'       => $r['tel'],
            'mail'      => $r['mail'],
            'user'      => $r['user'],
            'salt'      => $r['salt'],
            'hash'      => $r['hash'],
            'rol'       => $r['rol'],
            'activo'    => (bool)$r['activo'],
            'bloq'      => (bool)$r['bloq'],
            'cambio'    => (bool)$r['cambio'],
            'ultimo'    => $r['ultimo'],
            'canton'    => $r['canton'],
            'parroquia' => $r['parroquia'],
            'recinto'   => $r['recinto'],
            'mesa'      => $r['mesa'],
        ];
    }

    // 9. Auditoría
    $audit = [];
    $stmtAud = $pdo->query('SELECT t, usr, op, det FROM auditoria ORDER BY id ASC LIMIT 300');
    foreach ($stmtAud as $r) {
        $audit[] = [
            't'   => $r['t'],
            'usr' => $r['usr'],
            'op'  => $r['op'],
            'det' => $r['det'],
        ];
    }

    return [
        'dist'    => $dist,
        'cand'    => $cand,
        'deleg'   => $deleg,
        'actas'   => empty($actas) ? new stdClass() : $actas,
        'resp'    => empty($resp) ? new stdClass() : $resp,
        'tarifas' => empty($tarifas) ? new stdClass() : $tarifas,
        'pagos'   => empty($pagos) ? new stdClass() : $pagos,
        'users'   => $users,
        'audit'   => $audit,
    ];
}

function syncToDb(PDO $pdo, array $data, bool $allowDeletes = true): array
{
    $counts = [];
    $pdo->beginTransaction();

    try {
        // 1. Distributivo
        if (isset($data['dist']) && is_array($data['dist'])) {
            $sql = 'INSERT INTO distributivo
                (ubicacion_hash, canton, parroquia, zona, recinto, mesas_fem_inicio, mesas_fem_fin, mesas_masc_inicio, mesas_masc_fin, padron_mesa, electores_recinto)
                VALUES (:h, :c, :p, :z, :r, :fi, :ff, :mi, :mf, :pm, :er)
                ON DUPLICATE KEY UPDATE
                  canton = VALUES(canton),
                  parroquia = VALUES(parroquia),
                  zona = VALUES(zona),
                  recinto = VALUES(recinto),
                  mesas_fem_inicio = VALUES(mesas_fem_inicio),
                  mesas_fem_fin = VALUES(mesas_fem_fin),
                  mesas_masc_inicio = VALUES(mesas_masc_inicio),
                  mesas_masc_fin = VALUES(mesas_masc_fin),
                  padron_mesa = VALUES(padron_mesa),
                  electores_recinto = VALUES(electores_recinto)';
            $stmt = $pdo->prepare($sql);
            $hashes = [];
            foreach ($data['dist'] as $d) {
                if (!is_array($d)) continue;
                $h = distHash($d);
                $hashes[$h] = true;
                $stmt->execute([
                    ':h'  => $h,
                    ':c'  => (string)($d['canton'] ?? ''),
                    ':p'  => (string)($d['parroquia'] ?? ''),
                    ':z'  => (string)($d['zona'] ?? ''),
                    ':r'  => (string)($d['recinto'] ?? ''),
                    ':fi' => (int)($d['mesas_fem_inicio'] ?? 0),
                    ':ff' => (int)($d['mesas_fem_fin'] ?? 0),
                    ':mi' => (int)($d['mesas_masc_inicio'] ?? 0),
                    ':mf' => (int)($d['mesas_masc_fin'] ?? 0),
                    ':pm' => (int)($d['padron_mesa'] ?? 350),
                    ':er' => (int)($d['electores_recinto'] ?? 0),
                ]);
            }
            if ($allowDeletes) {
                $existing = $pdo->query('SELECT ubicacion_hash FROM distributivo')->fetchAll(PDO::FETCH_COLUMN);
                $delStmt = $pdo->prepare('DELETE FROM distributivo WHERE ubicacion_hash = ?');
                foreach ($existing as $eh) {
                    if (!isset($hashes[$eh])) {
                        $delStmt->execute([$eh]);
                    }
                }
            }
            $counts['dist'] = count($hashes);
        }

        [$distByHash, $distByCpr] = buildDistLookup($pdo);

        // 2. Candidatos + candidatos_orden
        if (isset($data['cand']) && is_array($data['cand'])) {
            $sqlCand = 'INSERT INTO candidatos
                (id, dignidad, lista, partido, color, nombre, canton, circunscripcion, orden_visual)
                VALUES (:id, :dig, :lis, :par, :col, :nom, :can, :cir, :ord)
                ON DUPLICATE KEY UPDATE
                  dignidad = VALUES(dignidad),
                  lista = VALUES(lista),
                  partido = VALUES(partido),
                  color = VALUES(color),
                  nombre = VALUES(nombre),
                  canton = VALUES(canton),
                  circunscripcion = VALUES(circunscripcion),
                  orden_visual = VALUES(orden_visual)';
            $stmtCand = $pdo->prepare($sqlCand);
            $delOrd = $pdo->prepare('DELETE FROM candidatos_orden WHERE candidato_id = ?');
            $insOrd = $pdo->prepare('INSERT INTO candidatos_orden (candidato_id, orden, nombre) VALUES (?, ?, ?)');

            $candIds = [];
            foreach ($data['cand'] as $idx => $c) {
                if (!is_array($c) || empty($c['id'])) continue;
                $cid = (string)$c['id'];
                $candIds[$cid] = true;
                $stmtCand->execute([
                    ':id'  => $cid,
                    ':dig' => (string)($c['dignidad'] ?? ''),
                    ':lis' => (string)($c['lista'] ?? ''),
                    ':par' => (string)($c['partido'] ?? ''),
                    ':col' => (string)($c['color'] ?? '#2563eb'),
                    ':nom' => (string)($c['nombre'] ?? ''),
                    ':can' => (string)($c['canton'] ?? ''),
                    ':cir' => (string)($c['circunscripcion'] ?? ''),
                    ':ord' => (int)$idx,
                ]);
                $delOrd->execute([$cid]);
                if (!empty($c['candidatos_orden']) && is_array($c['candidatos_orden'])) {
                    foreach ($c['candidatos_orden'] as $oIdx => $oNom) {
                        $insOrd->execute([$cid, $oIdx + 1, (string)$oNom]);
                    }
                }
            }
            if ($allowDeletes) {
                $existing = $pdo->query('SELECT id FROM candidatos')->fetchAll(PDO::FETCH_COLUMN);
                $delCand = $pdo->prepare('DELETE FROM candidatos WHERE id = ?');
                foreach ($existing as $eid) {
                    if (!isset($candIds[$eid])) {
                        $delCand->execute([$eid]);
                    }
                }
            }
            $counts['cand'] = count($candIds);
        }

        // 3. Delegados
        if (isset($data['deleg']) && is_array($data['deleg'])) {
            $sqlDel = 'INSERT INTO delegados
                (id, distributivo_id, apellidos, nombres, nombre, cedula, telefono, rol, acreditado, canton, parroquia, zona, recinto, mesa, fecha)
                VALUES (:id, :did, :ape, :nom, :full, :ced, :tel, :rol, :acr, :can, :par, :zon, :rec, :mes, :fec)
                ON DUPLICATE KEY UPDATE
                  distributivo_id = VALUES(distributivo_id),
                  apellidos = VALUES(apellidos),
                  nombres = VALUES(nombres),
                  nombre = VALUES(nombre),
                  cedula = VALUES(cedula),
                  telefono = VALUES(telefono),
                  rol = VALUES(rol),
                  acreditado = VALUES(acreditado),
                  canton = VALUES(canton),
                  parroquia = VALUES(parroquia),
                  zona = VALUES(zona),
                  recinto = VALUES(recinto),
                  mesa = VALUES(mesa),
                  fecha = VALUES(fecha)';
            $stmtDel = $pdo->prepare($sqlDel);
            $delIds = [];
            foreach ($data['deleg'] as $d) {
                if (!is_array($d) || empty($d['id'])) continue;
                $did = (string)$d['id'];
                $delIds[$did] = true;
                $distId = resolveDistId($d, $distByHash, $distByCpr);
                $stmtDel->execute([
                    ':id'   => $did,
                    ':did'  => $distId,
                    ':ape'  => (string)($d['apellidos'] ?? ''),
                    ':nom'  => (string)($d['nombres'] ?? ''),
                    ':full' => (string)($d['nombre'] ?? ''),
                    ':ced'  => (string)($d['cedula'] ?? ''),
                    ':tel'  => (string)($d['telefono'] ?? ''),
                    ':rol'  => (string)($d['rol'] ?? 'DELEGADO_TITULAR'),
                    ':acr'  => (string)($d['acreditado'] ?? 'PENDIENTE'),
                    ':can'  => (string)($d['canton'] ?? ''),
                    ':par'  => (string)($d['parroquia'] ?? ''),
                    ':zon'  => (string)($d['zona'] ?? ''),
                    ':rec'  => (string)($d['recinto'] ?? ''),
                    ':mes'  => (string)($d['mesa'] ?? ''),
                    ':fec'  => $d['fecha'] ?? null,
                ]);
            }
            if ($allowDeletes) {
                $existing = $pdo->query('SELECT id FROM delegados')->fetchAll(PDO::FETCH_COLUMN);
                $delStmt = $pdo->prepare('DELETE FROM delegados WHERE id = ?');
                foreach ($existing as $eid) {
                    if (!isset($delIds[$eid])) {
                        $delStmt->execute([$eid]);
                    }
                }
            }
            $counts['deleg'] = count($delIds);
        }

        // Conjunto de candidatos válidos para FK acta_votos
        $validCands = array_flip($pdo->query('SELECT id FROM candidatos')->fetchAll(PDO::FETCH_COLUMN));

        // 4. Actas + acta_votos
        if (isset($data['actas']) && is_array($data['actas'])) {
            $existingPhotos = [];
            $stmtPh = $pdo->query('SELECT acta_key, foto_acta, foto_borrador FROM actas');
            foreach ($stmtPh as $r) {
                $existingPhotos[$r['acta_key']] = $r;
            }

            $sqlAct = 'INSERT INTO actas
                (acta_key, distributivo_id, dignidad, canton, parroquia, zona, recinto, mesa, total_papeletas, validos, blancos, nulos, total_votos, diferencia, cuadrada, observaciones, foto_acta, foto_borrador, timestamp, extra_json)
                VALUES (:key, :did, :dig, :can, :par, :zon, :rec, :mes, :tp, :val, :bla, :nul, :tv, :dif, :cua, :obs, :fa, :fb, :ts, :ext)
                ON DUPLICATE KEY UPDATE
                  distributivo_id = VALUES(distributivo_id),
                  dignidad = VALUES(dignidad),
                  canton = VALUES(canton),
                  parroquia = VALUES(parroquia),
                  zona = VALUES(zona),
                  recinto = VALUES(recinto),
                  mesa = VALUES(mesa),
                  total_papeletas = VALUES(total_papeletas),
                  validos = VALUES(validos),
                  blancos = VALUES(blancos),
                  nulos = VALUES(nulos),
                  total_votos = VALUES(total_votos),
                  diferencia = VALUES(diferencia),
                  cuadrada = VALUES(cuadrada),
                  observaciones = VALUES(observaciones),
                  foto_acta = VALUES(foto_acta),
                  foto_borrador = VALUES(foto_borrador),
                  timestamp = VALUES(timestamp),
                  extra_json = VALUES(extra_json)';
            $stmtAct = $pdo->prepare($sqlAct);
            $delVot = $pdo->prepare('DELETE FROM acta_votos WHERE acta_key = ?');
            $insVot = $pdo->prepare('INSERT INTO acta_votos (acta_key, candidato_id, votos) VALUES (?, ?, ?)');

            $knownKeys = ['key','dignidad','canton','parroquia','zona','recinto','mesa','total_papeletas','validos','blancos','nulos','total_votos','diferencia','cuadrada','candidatos','observaciones','fotoActa','fotoBorrador','timestamp'];
            $actKeys = [];

            foreach ($data['actas'] as $k => $a) {
                if (!is_array($a)) continue;
                $akey = (string)($a['key'] ?? $k);
                $actKeys[$akey] = true;
                $distId = resolveDistId($a, $distByHash, $distByCpr);

                $fa = $a['fotoActa'] ?? null;
                if ($fa === '__IDB_PERSISTED__') {
                    $fa = $existingPhotos[$akey]['foto_acta'] ?? null;
                }
                $fb = $a['fotoBorrador'] ?? null;
                if ($fb === '__IDB_PERSISTED__') {
                    $fb = $existingPhotos[$akey]['foto_borrador'] ?? null;
                }

                $extra = array_diff_key($a, array_flip($knownKeys));
                $stmtAct->execute([
                    ':key' => $akey,
                    ':did' => $distId,
                    ':dig' => (string)($a['dignidad'] ?? ''),
                    ':can' => (string)($a['canton'] ?? ''),
                    ':par' => (string)($a['parroquia'] ?? ''),
                    ':zon' => (string)($a['zona'] ?? ''),
                    ':rec' => (string)($a['recinto'] ?? ''),
                    ':mes' => (string)($a['mesa'] ?? ''),
                    ':tp'  => (int)($a['total_papeletas'] ?? 0),
                    ':val' => (int)($a['validos'] ?? 0),
                    ':bla' => (int)($a['blancos'] ?? 0),
                    ':nul' => (int)($a['nulos'] ?? 0),
                    ':tv'  => (int)($a['total_votos'] ?? 0),
                    ':dif' => (int)($a['diferencia'] ?? 0),
                    ':cua' => !empty($a['cuadrada']) ? 1 : 0,
                    ':obs' => (string)($a['observaciones'] ?? ''),
                    ':fa'  => $fa,
                    ':fb'  => $fb,
                    ':ts'  => $a['timestamp'] ?? null,
                    ':ext' => empty($extra) ? null : json_encode($extra, JSON_UNESCAPED_UNICODE),
                ]);

                $delVot->execute([$akey]);
                if (!empty($a['candidatos']) && is_array($a['candidatos'])) {
                    foreach ($a['candidatos'] as $cid => $votos) {
                        $cid = (string)$cid;
                        if (isset($validCands[$cid])) {
                            $insVot->execute([$akey, $cid, (int)$votos]);
                        }
                    }
                }
            }
            if ($allowDeletes) {
                $existing = $pdo->query('SELECT acta_key FROM actas')->fetchAll(PDO::FETCH_COLUMN);
                $delAct = $pdo->prepare('DELETE FROM actas WHERE acta_key = ?');
                foreach ($existing as $ek) {
                    if (!isset($actKeys[$ek])) {
                        $delAct->execute([$ek]);
                    }
                }
            }
            $counts['actas'] = count($actKeys);
        }

        // 5. Respaldos
        if (isset($data['resp']) && is_array($data['resp'])) {
            $existingRespPhotos = [];
            $stmtRPh = $pdo->query('SELECT resp_key, foto_acta, foto_borrador FROM respaldos');
            foreach ($stmtRPh as $r) {
                $existingRespPhotos[$r['resp_key']] = $r;
            }

            $sqlResp = 'INSERT INTO respaldos
                (resp_key, distributivo_id, dignidad, canton, parroquia, zona, recinto, mesa, foto_acta, foto_borrador, timestamp, payload_json)
                VALUES (:key, :did, :dig, :can, :par, :zon, :rec, :mes, :fa, :fb, :ts, :pay)
                ON DUPLICATE KEY UPDATE
                  distributivo_id = VALUES(distributivo_id),
                  dignidad = VALUES(dignidad),
                  canton = VALUES(canton),
                  parroquia = VALUES(parroquia),
                  zona = VALUES(zona),
                  recinto = VALUES(recinto),
                  mesa = VALUES(mesa),
                  foto_acta = VALUES(foto_acta),
                  foto_borrador = VALUES(foto_borrador),
                  timestamp = VALUES(timestamp),
                  payload_json = VALUES(payload_json)';
            $stmtResp = $pdo->prepare($sqlResp);
            $respKeys = [];
            foreach ($data['resp'] as $k => $r) {
                if (!is_array($r)) continue;
                $rkey = (string)($r['key'] ?? $k);
                $respKeys[$rkey] = true;
                $fa = $r['fotoActa'] ?? null;
                if ($fa === '__IDB_PERSISTED__') {
                    $fa = $existingRespPhotos[$rkey]['foto_acta'] ?? null;
                }
                $fb = $r['fotoBorrador'] ?? null;
                if ($fb === '__IDB_PERSISTED__') {
                    $fb = $existingRespPhotos[$rkey]['foto_borrador'] ?? null;
                }
                $rCopy = $r;
                unset($rCopy['fotoActa'], $rCopy['fotoBorrador']);
                $stmtResp->execute([
                    ':key' => $rkey,
                    ':did' => resolveDistId($r, $distByHash, $distByCpr),
                    ':dig' => (string)($r['dignidad'] ?? ''),
                    ':can' => (string)($r['canton'] ?? ''),
                    ':par' => (string)($r['parroquia'] ?? ''),
                    ':zon' => (string)($r['zona'] ?? ''),
                    ':rec' => (string)($r['recinto'] ?? ''),
                    ':mes' => (string)($r['mesa'] ?? ''),
                    ':fa'  => $fa,
                    ':fb'  => $fb,
                    ':ts'  => $r['timestamp'] ?? null,
                    ':pay' => json_encode($rCopy, JSON_UNESCAPED_UNICODE),
                ]);
            }
            if ($allowDeletes) {
                $existing = $pdo->query('SELECT resp_key FROM respaldos')->fetchAll(PDO::FETCH_COLUMN);
                $delResp = $pdo->prepare('DELETE FROM respaldos WHERE resp_key = ?');
                foreach ($existing as $ek) {
                    if (!isset($respKeys[$ek])) {
                        $delResp->execute([$ek]);
                    }
                }
            }
            $counts['resp'] = count($respKeys);
        }

        // 6. Tarifas
        if (isset($data['tarifas']) && is_array($data['tarifas'])) {
            $stmtTar = $pdo->prepare('INSERT INTO tarifas (rol_key, monto) VALUES (?, ?) ON DUPLICATE KEY UPDATE monto = VALUES(monto)');
            foreach ($data['tarifas'] as $rk => $m) {
                $stmtTar->execute([(string)$rk, (float)$m]);
            }
            $counts['tarifas'] = count($data['tarifas']);
        }

        // 7. Pagos
        if (isset($data['pagos']) && is_array($data['pagos'])) {
            $validDels = array_flip($pdo->query('SELECT id FROM delegados')->fetchAll(PDO::FETCH_COLUMN));
            $sqlPag = 'INSERT INTO pagos (delegado_id, monto, estado, metodo, ref, fecha, observaciones)
                VALUES (:did, :mon, :est, :met, :ref, :fec, :obs)
                ON DUPLICATE KEY UPDATE
                  monto = VALUES(monto),
                  estado = VALUES(estado),
                  metodo = VALUES(metodo),
                  ref = VALUES(ref),
                  fecha = VALUES(fecha),
                  observaciones = VALUES(observaciones)';
            $stmtPag = $pdo->prepare($sqlPag);
            $pagIds = [];
            foreach ($data['pagos'] as $did => $p) {
                $did = (string)$did;
                if (!is_array($p) || !isset($validDels[$did])) continue;
                $pagIds[$did] = true;
                $stmtPag->execute([
                    ':did' => $did,
                    ':mon' => isset($p['monto']) ? (float)$p['monto'] : null,
                    ':est' => (string)($p['estado'] ?? 'PENDIENTE'),
                    ':met' => $p['metodo'] ?? null,
                    ':ref' => $p['ref'] ?? null,
                    ':fec' => $p['fecha'] ?? null,
                    ':obs' => $p['observaciones'] ?? null,
                ]);
            }
            if ($allowDeletes) {
                $existing = $pdo->query('SELECT delegado_id FROM pagos')->fetchAll(PDO::FETCH_COLUMN);
                $delPag = $pdo->prepare('DELETE FROM pagos WHERE delegado_id = ?');
                foreach ($existing as $eid) {
                    if (!isset($pagIds[$eid])) {
                        $delPag->execute([$eid]);
                    }
                }
            }
            $counts['pagos'] = count($pagIds);
        }

        // 8. Usuarios
        if (isset($data['users']) && is_array($data['users'])) {
            $sqlUsr = 'INSERT INTO usuarios
                (id, nom, ape, ced, tel, mail, user, salt, hash, rol, activo, bloq, cambio, ultimo, canton, parroquia, recinto, mesa)
                VALUES (:id, :nom, :ape, :ced, :tel, :mail, :usr, :salt, :hash, :rol, :act, :blq, :cmb, :ult, :can, :par, :rec, :mes)
                ON DUPLICATE KEY UPDATE
                  nom = VALUES(nom),
                  ape = VALUES(ape),
                  ced = VALUES(ced),
                  tel = VALUES(tel),
                  mail = VALUES(mail),
                  user = VALUES(user),
                  salt = VALUES(salt),
                  hash = VALUES(hash),
                  rol = VALUES(rol),
                  activo = VALUES(activo),
                  bloq = VALUES(bloq),
                  cambio = VALUES(cambio),
                  ultimo = VALUES(ultimo),
                  canton = VALUES(canton),
                  parroquia = VALUES(parroquia),
                  recinto = VALUES(recinto),
                  mesa = VALUES(mesa)';
            $stmtUsr = $pdo->prepare($sqlUsr);
            $uIds = [];
            foreach ($data['users'] as $u) {
                if (!is_array($u) || empty($u['id']) || empty($u['user'])) continue;
                $uid = (string)$u['id'];
                $uIds[$uid] = true;
                $stmtUsr->execute([
                    ':id'   => $uid,
                    ':nom'  => (string)($u['nom'] ?? ''),
                    ':ape'  => (string)($u['ape'] ?? ''),
                    ':ced'  => (string)($u['ced'] ?? ''),
                    ':tel'  => (string)($u['tel'] ?? ''),
                    ':mail' => (string)($u['mail'] ?? ''),
                    ':usr'  => (string)$u['user'],
                    ':salt' => (string)($u['salt'] ?? ''),
                    ':hash' => (string)($u['hash'] ?? ''),
                    ':rol'  => (string)($u['rol'] ?? 'digitador'),
                    ':act'  => isset($u['activo']) ? ($u['activo'] ? 1 : 0) : 1,
                    ':blq'  => !empty($u['bloq']) ? 1 : 0,
                    ':cmb'  => !empty($u['cambio']) ? 1 : 0,
                    ':ult'  => $u['ultimo'] ?? null,
                    ':can'  => (string)($u['canton'] ?? ''),
                    ':par'  => (string)($u['parroquia'] ?? ''),
                    ':rec'  => (string)($u['recinto'] ?? ''),
                    ':mes'  => (string)($u['mesa'] ?? ''),
                ]);
            }
            if ($allowDeletes && count($uIds) > 0) {
                $existing = $pdo->query('SELECT id FROM usuarios')->fetchAll(PDO::FETCH_COLUMN);
                $delUsr = $pdo->prepare('DELETE FROM usuarios WHERE id = ?');
                foreach ($existing as $eid) {
                    if (!isset($uIds[$eid])) {
                        $delUsr->execute([$eid]);
                    }
                }
            }
            $counts['users'] = count($uIds);
        }

        // 9. Auditoría
        if (isset($data['audit']) && is_array($data['audit'])) {
            $pdo->exec('TRUNCATE TABLE auditoria');
            $stmtAud = $pdo->prepare('INSERT INTO auditoria (t, usr, op, det) VALUES (?, ?, ?, ?)');
            foreach (array_slice($data['audit'], 0, 300) as $a) {
                if (!is_array($a)) continue;
                $stmtAud->execute([
                    (string)($a['t'] ?? ''),
                    (string)($a['usr'] ?? ''),
                    (string)($a['op'] ?? ''),
                    (string)($a['det'] ?? ''),
                ]);
            }
            $counts['audit'] = min(300, count($data['audit']));
        }

        $pdo->commit();
        return $counts;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
