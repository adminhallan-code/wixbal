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

// ── 2. Obtener TODAS las reservaciones de Supabase del período ───────────────
$sb_booking = sb_get("reservaciones?link_pago=like.amelia_booking_*&select=id,link_pago,correo,nombre,fecha_ascenso,tipo_cabana&limit=2000");
$en_supabase = [];
foreach ($sb_booking['body'] ?? [] as $r) {
    if (preg_match('/amelia_booking_(\d+)/', $r['link_pago'], $m)) {
        $en_supabase[(int)$m[1]] = $r;
    }
}

// Índice por correo+fecha+cabaña para detectar duplicados por otra vía
$sb_todas = sb_get("reservaciones?fecha_ascenso=gte.$desde&select=id,correo,nombre,fecha_ascenso,tipo_cabana,link_pago&limit=5000");
$por_correo_fecha = [];   // clave: "correo|fecha|tipo"
$por_nombre_fecha = [];   // clave: "nombre_normalizado|fecha|tipo"
foreach ($sb_todas['body'] ?? [] as $r) {
    $correo_norm = strtolower(trim($r['correo'] ?? ''));
    $nombre_norm = strtolower(trim($r['nombre'] ?? ''));
    $fecha  = $r['fecha_ascenso'] ?? '';
    $tipo   = $r['tipo_cabana']   ?? '';
    if ($correo_norm && !str_contains($correo_norm, 'wolfsacatenango.com')) {
        $por_correo_fecha["$correo_norm|$fecha|$tipo"] = $r;
    }
    if ($nombre_norm) {
        $por_nombre_fecha["$nombre_norm|$fecha|$tipo"] = $r;
    }
}

// ── 3. Comparar ───────────────────────────────────────────────────────────────
$faltantes        = [];
$ya_existen       = 0;
$posibles_duplic  = [];

foreach ($amelia_bookings as $b) {
    $booking_id = (int)($b['booking_id'] ?? 0);
    if (!$booking_id) continue;

    if (in_array($b['booking_status'] ?? '', ['canceled', 'rejected', 'no-show'])) continue;

    $tipo   = $SERVICE_NAMES[(int)($b['serviceId'] ?? 0)] ?? 'Desconocida';
    $fecha  = substr($b['bookingStart'] ?? '', 0, 10);
    $nombre = trim(($b['firstName'] ?? '') . ' ' . ($b['lastName'] ?? ''));
    $correo = strtolower(trim($b['email'] ?? ''));
    $correo_falso = str_contains($correo, 'wolfsacatenango.com');

    // 1) Coincidencia exacta por booking_id
    if (isset($en_supabase[$booking_id])) {
        $ya_existen++;
        continue;
    }

    // 2) Coincidencia por correo real + fecha + cabaña
    $match_correo = (!$correo_falso && isset($por_correo_fecha["$correo|$fecha|$tipo"]))
        ? $por_correo_fecha["$correo|$fecha|$tipo"] : null;

    // 3) Coincidencia por nombre normalizado + fecha + cabaña
    $nombre_norm = strtolower($nombre);
    $match_nombre = isset($por_nombre_fecha["$nombre_norm|$fecha|$tipo"])
        ? $por_nombre_fecha["$nombre_norm|$fecha|$tipo"] : null;

    if ($match_correo || $match_nombre) {
        $match = $match_correo ?? $match_nombre;
        $via   = $match_correo ? 'correo' : 'nombre';
        $posibles_duplic[] = [
            'booking_id'      => $booking_id,
            'fecha_ascenso'   => $fecha,
            'tipo_cabana'     => $tipo,
            'nombre_amelia'   => $nombre,
            'correo_amelia'   => $b['email'] ?? '',
            'coincide_via'    => $via,
            'supabase_id'     => $match['id'],
            'supabase_nombre' => $match['nombre'],
            'supabase_link'   => $match['link_pago'],
        ];
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
        'total_amelia'       => count($amelia_bookings),
        'ya_en_supabase'     => $ya_existen,
        'posibles_duplicados'=> count($posibles_duplic),
        'faltantes'          => count($faltantes),
        'desde'              => $desde,
        'detalle_duplicados' => $posibles_duplic,
        'detalle'            => $faltantes,
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
