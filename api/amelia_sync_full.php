<?php
/**
 * GET  /api/amelia-sync-full          → muestra qué bookings de Amelia faltan en Supabase
 * POST /api/amelia-sync-full          → importa los faltantes (body: {"importar":true})
 * GET  /api/amelia-sync-full?desde=YYYY-MM-DD → filtra desde una fecha específica
 */

// Mapa serviceId → tipo_cabana
$SERVICE_NAMES = [1 => 'Mixta', 3 => 'Privada', 4 => 'Familiar'];

// ── 1. Obtener todos los bookings de Amelia vía bridge ────────────────────────
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-365 days'));
$bridge = bridge_call('export_all', ['desde' => $desde]);

if (!empty($bridge['error']) || empty($bridge['success'])) {
    json_error('No se pudo contactar el bridge de Amelia: ' . json_encode($bridge), 502);
}

$amelia_bookings = $bridge['data'] ?? [];
if (empty($amelia_bookings)) {
    json_response(['faltantes' => [], 'total_amelia' => 0, 'total_supabase' => 0, 'mensaje' => 'No hay bookings en Amelia para el período.']);
}

// ── 2. Obtener todos los amelia_booking_* en Supabase ────────────────────────
$sb = sb_get("reservaciones?link_pago=like.amelia_booking_*&select=id,link_pago,nombre,fecha_ascenso,tipo_cabana&limit=2000");
$en_supabase = [];
foreach ($sb['body'] ?? [] as $r) {
    // link_pago = "amelia_booking_1234" → extraer 1234
    if (preg_match('/amelia_booking_(\d+)/', $r['link_pago'], $m)) {
        $en_supabase[(int)$m[1]] = $r;
    }
}

// ── 3. Comparar ───────────────────────────────────────────────────────────────
$faltantes = [];
$ya_existen = 0;

foreach ($amelia_bookings as $b) {
    $booking_id = (int)($b['booking_id'] ?? 0);
    if (!$booking_id) continue;

    // Ignorar cancelados de Amelia
    if (in_array($b['booking_status'] ?? '', ['canceled', 'rejected', 'no-show'])) continue;

    $tipo = $SERVICE_NAMES[(int)($b['serviceId'] ?? 0)] ?? 'Desconocida';
    $fecha = substr($b['bookingStart'] ?? '', 0, 10);
    $nombre = trim(($b['firstName'] ?? '') . ' ' . ($b['lastName'] ?? ''));

    if (isset($en_supabase[$booking_id])) {
        $ya_existen++;
        continue;
    }

    $faltantes[] = [
        'booking_id'    => $booking_id,
        'appointment_id'=> (int)($b['appointment_id'] ?? 0),
        'fecha_ascenso' => $fecha,
        'tipo_cabana'   => $tipo,
        'nombre'        => $nombre,
        'correo'        => $b['email'] ?? '',
        'telefono'      => $b['phone'] ?? null,
        'personas'      => (int)($b['persons'] ?? 1),
        'precio'        => (float)($b['price'] ?? 0),
        'estado_pago'   => $b['booking_status'] === 'approved' ? 'Completado' : 'Pendiente',
    ];
}

// ── 4. Solo reporte (GET) ─────────────────────────────────────────────────────
if ($method !== 'POST') {
    json_response([
        'total_amelia'    => count($amelia_bookings),
        'ya_en_supabase'  => $ya_existen,
        'faltantes'       => count($faltantes),
        'desde'           => $desde,
        'detalle'         => $faltantes,
    ]);
}

// ── 5. Importar (POST con {"importar":true}) ──────────────────────────────────
$body = get_body();
if (empty($body['importar'])) {
    json_error('Enviá {"importar":true} para confirmar la importación.', 400);
}

$importados = [];
$errores    = [];
$hoy        = gt_date();

foreach ($faltantes as $f) {
    $payload = array_filter([
        'nombre'         => $f['nombre'],
        'correo'         => $f['correo'] ?: null,
        'telefono'       => $f['telefono'] ?: null,
        'fecha_ascenso'  => $f['fecha_ascenso'],
        'fecha_pago'     => $f['estado_pago'] === 'Completado' ? $hoy : null,
        'tipo_cabana'    => $f['tipo_cabana'],
        'no_personas'    => $f['personas'],
        'precio'         => $f['precio'],
        'estado_pago'    => $f['estado_pago'],
        'metodo_pago'    => 'Transferencia',
        'tipo_pago'      => 'Recurrente',
        'agencia'        => 'Wolfs Acatenango',
        'registrado_por' => 'Sistema (Sync Amelia)',
        'link_pago'      => 'amelia_booking_' . $f['booking_id'],
        'notas'          => 'Importado desde Amelia. appointment_id=' . $f['appointment_id'],
    ], fn($v) => $v !== null && $v !== '');

    $res = sb_post('reservaciones', $payload);
    if ($res['status'] >= 300) {
        $errores[] = ['booking_id' => $f['booking_id'], 'nombre' => $f['nombre'], 'error' => json_encode($res['body'])];
    } else {
        $importados[] = ['booking_id' => $f['booking_id'], 'nombre' => $f['nombre'], 'fecha' => $f['fecha_ascenso'], 'tipo' => $f['tipo_cabana']];
    }
}

json_response([
    'importados' => count($importados),
    'errores'    => count($errores),
    'detalle_importados' => $importados,
    'detalle_errores'    => $errores,
]);
