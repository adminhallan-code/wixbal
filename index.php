<?php
// index.php — Router principal
// Mapea URL + método HTTP al archivo de endpoint correspondiente.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

// Parsear la ruta limpia
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = rtrim($uri, '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

// Extraer segmentos
$segs = explode('/', trim($uri, '/'));
$s0   = $segs[0] ?? '';   // primer segmento
$s1   = $segs[1] ?? '';   // segundo
$s2   = $segs[2] ?? '';   // tercero
$s3   = $segs[3] ?? '';   // cuarto

// ── Rutas ────────────────────────────────────────────────────────────────────

// GET /health
if ($method === 'GET' && $uri === '/health') {
    json_response(['status' => 'ok', 'servicio' => 'Wolfs Reservaciones API (PHP)']);
}

// GET /disponibilidad/{fecha}
if ($method === 'GET' && $s0 === 'disponibilidad' && $s1) {
    $_route_fecha = $s1;
    require __DIR__ . '/api/disponibilidad.php';
}

// POST /crear-link-pago
if ($method === 'POST' && $uri === '/crear-link-pago') {
    require __DIR__ . '/api/crear_link_pago.php';
}

// POST /cancelar-link/{id}
if ($method === 'POST' && $s0 === 'cancelar-link' && $s1) {
    $_route_link_id = (int) $s1;
    require __DIR__ . '/api/cancelar_link.php';
}

// POST /webhook/recurrente
if ($method === 'POST' && $s0 === 'webhook' && $s1 === 'recurrente') {
    require __DIR__ . '/api/webhook_recurrente.php';
}

// POST /webhook/amelia
if ($method === 'POST' && $s0 === 'webhook' && $s1 === 'amelia') {
    require __DIR__ . '/api/webhook_amelia.php';
}

// GET /api/reservaciones
if ($method === 'GET' && $s0 === 'api' && $s1 === 'reservaciones' && !$s2) {
    require __DIR__ . '/api/reservaciones_list.php';
}

// PATCH /api/reservaciones/{id}
if ($method === 'PATCH' && $s0 === 'api' && $s1 === 'reservaciones' && $s2 && !$s3) {
    $_route_res_id = (int) $s2;
    require __DIR__ . '/api/reservaciones_patch.php';
}

// POST /api/reservaciones/{id}/estado
if ($method === 'POST' && $s0 === 'api' && $s1 === 'reservaciones' && $s2 && $s3 === 'estado') {
    $_route_res_id = (int) $s2;
    require __DIR__ . '/api/reservaciones_estado.php';
}

// POST /api/reservaciones/{id}/reprogramar
if ($method === 'POST' && $s0 === 'api' && $s1 === 'reservaciones' && $s2 && $s3 === 'reprogramar') {
    $_route_res_id = (int) $s2;
    require __DIR__ . '/api/reservaciones_reprogramar.php';
}

// POST /api/reservaciones/{id}/solicitar-reprogramacion
if ($method === 'POST' && $s0 === 'api' && $s1 === 'reservaciones' && $s2 && $s3 === 'solicitar-reprogramacion') {
    $_route_res_id = (int) $s2;
    require __DIR__ . '/api/solicitar_reprogramacion.php';
}

// GET /api/links-pendientes
if ($method === 'GET' && $s0 === 'api' && $s1 === 'links-pendientes') {
    require __DIR__ . '/api/links_pendientes.php';
}

// GET /api/reschedule-pendientes
if ($method === 'GET' && $s0 === 'api' && $s1 === 'reschedule-pendientes') {
    require __DIR__ . '/api/reschedule_pendientes.php';
}

// POST /api/reschedule-pendientes/{id}/marcar-pagado
if ($method === 'POST' && $s0 === 'api' && $s1 === 'reschedule-pendientes' && $s2 && $s3 === 'marcar-pagado') {
    $_route_resc_id = (int) $s2;
    require __DIR__ . '/api/reschedule_marcar_pagado.php';
}

// POST /api/reschedule-pendientes/{id}/cancelar
if ($method === 'POST' && $s0 === 'api' && $s1 === 'reschedule-pendientes' && $s2 && $s3 === 'cancelar') {
    $_route_resc_id = (int) $s2;
    require __DIR__ . '/api/reschedule_cancelar.php';
}

// GET /api/amelia-bridge-log
if ($method === 'GET' && $s0 === 'api' && $s1 === 'amelia-bridge-log') {
    require __DIR__ . '/api/amelia_bridge_log.php';
}

// POST /api/reservaciones/crear_manual
if ($method === 'POST' && $s0 === 'api' && $s1 === 'reservaciones' && $s2 === 'crear_manual') {
    require __DIR__ . '/api/crear_manual.php';
}

// POST /api/reservaciones/{id}/cancelar
if ($method === 'POST' && $s0 === 'api' && $s1 === 'reservaciones' && $s2 && $s3 === 'cancelar') {
    $_route_res_id = (int) $s2;
    require __DIR__ . '/api/cancelar_reservacion.php';
}

// POST /api/reservaciones/{id}/establecer-recordatorio
if ($method === 'POST' && $s0 === 'api' && $s1 === 'reservaciones' && $s2 && $s3 === 'establecer-recordatorio') {
    $_route_res_id = (int) $s2;
    require __DIR__ . '/api/establecer_recordatorio.php';
}

// POST /api/links/{id}/cancelar
if ($method === 'POST' && $s0 === 'api' && $s1 === 'links' && $s2 && $s3 === 'cancelar') {
    $_route_link_id = (int) $s2;
    require __DIR__ . '/api/cancelar_link_v2.php';
}

// GET  /api/reschedule-recordatorios
// POST /api/reschedule-recordatorios
// POST /api/reschedule-recordatorios/{id}/extender
// POST /api/reschedule-recordatorios/{id}/resolver
if ($s0 === 'api' && $s1 === 'reschedule-recordatorios') {
    $_route_rec_id     = $s2 ? (int)$s2 : 0;
    $_route_rec_action = $s3 ?: '';
    require __DIR__ . '/api/reschedule_recordatorios.php';
}

// GET  /api/solicitudes-link
// POST /api/solicitudes-link
// GET  /api/solicitudes-link/{id}
// POST /api/solicitudes-link/{id}/autorizar
// POST /api/solicitudes-link/{id}/denegar
if ($s0 === 'api' && $s1 === 'solicitudes-link') {
    $_route_sol_id     = $s2 ? (int)$s2 : 0;
    $_route_sol_action = $s3 ?: '';
    require __DIR__ . '/api/solicitudes_link.php';
}

// GET /api/notificaciones
if ($method === 'GET' && $s0 === 'api' && $s1 === 'notificaciones') {
    require __DIR__ . '/api/notificaciones.php';
}

// GET/POST/PATCH/DELETE /api/sb/{path}
if ($s0 === 'api' && $s1 === 'sb' && $s2) {
    // Rebuild path from s2 onward
    $_route_sb_path = implode('/', array_slice($segs, 2));
    require __DIR__ . '/api/supabase_proxy.php';
}

// GET /api/felplex/validar/{tipo}/{codigo}
if ($method === 'GET' && $s0 === 'api' && $s1 === 'felplex' && $s2 === 'validar' && $s3) {
    $_route_fel_tipo   = $s3;
    $_route_fel_codigo = $segs[4] ?? '';
    require __DIR__ . '/api/felplex.php';
}

// GET /api/version
if ($method === 'GET' && $s0 === 'api' && $s1 === 'version') {
    require __DIR__ . '/api/version.php';
}

// GET /api/cron/recordatorios  (llamado por cron job de cPanel)
if ($method === 'GET' && $s0 === 'api' && $s1 === 'cron' && $s2 === 'recordatorios') {
    require __DIR__ . '/api/cron_recordatorios.php';
}

// GET /api/cron/amelia-sync  (reintenta sincronizaciones fallidas con Amelia)
if ($method === 'GET' && $s0 === 'api' && $s1 === 'cron' && $s2 === 'amelia-sync') {
    require __DIR__ . '/api/cron_amelia_sync.php';
}

// GET /api/tmp_anular  (temporal)
if ($method === 'GET' && $s0 === 'api' && $s1 === 'tmp_anular') {
    require __DIR__ . '/api/tmp_anular.php';
}

// GET /api/tmp_facturas2  (temporal)
if ($method === 'GET' && $s0 === 'api' && $s1 === 'tmp_facturas2') {
    require __DIR__ . '/api/tmp_facturas2.php';
}

// GET /api/tmp_facturas3  (temporal)
if ($method === 'GET' && $s0 === 'api' && $s1 === 'tmp_facturas3') {
    require __DIR__ . '/api/tmp_facturas3.php';
}


// GET /api/amelia-sync-test
if ($method === 'GET' && $s0 === 'api' && $s1 === 'amelia-sync-test') {
    $ts = time();
    $test_data = [
        'nombre'        => 'Test Diagnostico',
        'correo'        => "test_{$ts}@wolfsacatenango.com",
        'fecha_ascenso' => $_GET['fecha'] ?? '2026-12-01',
        'tipo_cabana'   => $_GET['tipo']  ?? 'Mixta',
        'no_personas'   => 1,
        'precio'        => 100,
        'estado'        => 'pending',
        'link_id'       => "test_$ts",
        'extra_info'    => 'Diagnóstico PHP',
    ];
    $result = bridge_call('create_manual', $test_data);
    json_response(['test_data' => $test_data, 'bridge_result' => $result]);
}

// Frontend pages (serve from /frontend/ or return 404)
$frontend_pages = ['', 'index', 'jefes', 'it', 'links', 'facturacion', 'cocina', 'gestion'];
if ($method === 'GET' && in_array($s0, $frontend_pages) && !$s1) {
    $page = $s0 ?: 'index';
    $file = __DIR__ . "/frontend/{$page}.html";
    if (file_exists($file)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($file);
        exit;
    }
}

// Static JS/JSON assets
$static_map = [
    'notif.js'     => ['application/javascript', 'notif.js'],
    'auth.js'      => ['application/javascript', 'auth.js'],
    'sw.js'        => ['application/javascript', 'sw.js'],
    'update.js'    => ['application/javascript', 'update.js'],
    'nav.js'       => ['application/javascript', 'nav.js'],
    'manifest.json'=> ['application/json',        'manifest.json'],
    'icon-192.png' => ['image/png',               'icon-192.png'],
    'icon-512.png' => ['image/png',               'icon-512.png'],
];
if ($method === 'GET' && isset($static_map[$s0]) && !$s1) {
    [$mime, $fname] = $static_map[$s0];
    $file = __DIR__ . "/frontend/$fname";
    if (file_exists($file)) {
        header("Content-Type: $mime");
        if ($fname === 'sw.js') {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Service-Worker-Allowed: /');
            $build_id = gmdate('YmdH'); // cambia cada hora, fuerza refresh del SW
            echo str_replace('__BUILD_ID__', $build_id, file_get_contents($file));
        } else {
            readfile($file);
        }
        exit;
    }
}

// Ruta no encontrada
json_error('Ruta no encontrada', 404);
