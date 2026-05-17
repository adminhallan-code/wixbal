<?php
// GET /api/cron/amelia-sync
// Reintenta sincronizaciones fallidas con Amelia.
// Configurar en cPanel → Cron Jobs (cada 15 min):
//   curl -s "https://wixbal.com/api/cron/amelia-sync?secret=WOLFS_CRON_2026" > /dev/null

define('CRON_SECRET', 'WOLFS_CRON_2026');

$secret = $_GET['secret'] ?? '';
if ($secret !== CRON_SECRET) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$MAX_INTENTOS = 5;

$res  = sb_get("sync_queue?estado=eq.pendiente&intentos=lt.$MAX_INTENTOS&order=id.asc&limit=20");
$rows = $res['body'] ?? [];

$resultados = [];

foreach ($rows as $row) {
    $id      = $row['id'];
    $action  = $row['action'];
    $payload = is_array($row['payload']) ? $row['payload'] : json_decode($row['payload'], true);
    $intentos = (int)($row['intentos'] ?? 0) + 1;

    $result = bridge_call($action, $payload);

    if (!isset($result['error'])) {
        sb_patch("sync_queue?id=eq.$id", ['estado' => 'completado', 'intentos' => $intentos]);
        error_log("[CRON AMELIA SYNC] OK — id=$id action=$action intento=$intentos");
        $resultados[] = ['id' => $id, 'action' => $action, 'resultado' => 'ok', 'intentos' => $intentos];
    } else {
        $nuevo_estado = $intentos >= $MAX_INTENTOS ? 'fallido' : 'pendiente';
        sb_patch("sync_queue?id=eq.$id", ['estado' => $nuevo_estado, 'intentos' => $intentos]);
        error_log("[CRON AMELIA SYNC] FAIL — id=$id action=$action intento=$intentos estado=$nuevo_estado error=" . json_encode($result));
        $resultados[] = ['id' => $id, 'action' => $action, 'resultado' => $nuevo_estado, 'intentos' => $intentos];
    }
}

json_response([
    'procesados' => count($rows),
    'resultados' => $resultados,
]);
