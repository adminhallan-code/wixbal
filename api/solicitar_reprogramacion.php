<?php
// POST /api/reservaciones/{id}/solicitar-reprogramacion
$res_id = $_route_res_id ?? 0;
if (!$res_id) json_error('ID inválido', 400);

$data         = get_body();
$nueva_fecha  = $data['nueva_fecha']  ?? '';
$solicitado_por = $data['solicitado_por'] ?? '';

if (!fecha_valida($nueva_fecha)) json_error('nueva_fecha es requerida (YYYY-MM-DD)', 400);

$rv_res = sb_get("reservaciones?id=eq.$res_id&select=nombre,fecha_ascenso,tipo_cabana,paquete,agencia,no_personas,estado_pago,link_pago,correo,precio");
$rows = $rv_res['body'] ?? [];
if (empty($rows)) json_error('Reservación no encontrada', 404);

$rv = $rows[0];
if ($rv['estado_pago'] === 'Cancelado') json_error('No se puede reprogramar una reservación cancelada.', 400);

$nombre         = $rv['nombre']       ?? 'Cliente';
$agencia        = $rv['agencia']      ?? '';
$fecha_original = $rv['fecha_ascenso'] ?? '';
$tipo_cabana    = $rv['tipo_cabana']  ?? 'Mixta';
$no_personas    = (int)($rv['no_personas'] ?? 1);
$paquete        = $rv['paquete']      ?? 'Trekking';
$link_pago      = $rv['link_pago']    ?? '';
$correo         = $rv['correo']       ?? 'manual@wolfsacatenango.com';
$precio_orig    = (float)($rv['precio'] ?? 0);

// Verificar disponibilidad
$disp = get_disponibilidad($nueva_fecha, $agencia);
if ($tipo_cabana === 'Mixta'    && $no_personas > $disp['Mixta']['libre'])    json_error("Sin cupo Mixta para $nueva_fecha. Quedan {$disp['Mixta']['libre']} personas.", 400);
if ($tipo_cabana === 'Privada'  && $disp['Privada']['libre']  === 0)          json_error("Sin cabañas privadas disponibles para $nueva_fecha.", 400);
if ($tipo_cabana === 'Familiar' && $disp['Familiar']['libre'] === 0)          json_error("Cabaña familiar no disponible para $nueva_fecha.", 400);

// Actualizar fecha_ascenso
sb_patch("reservaciones?id=eq.$res_id", ['fecha_ascenso' => $nueva_fecha]);

// Crear reschedule_pendiente
sb_post('reschedule_pendientes', [
    'reservacion_id' => $res_id,
    'nombre'         => $nombre,
    'fecha_original' => $fecha_original,
    'fecha_nueva'    => $nueva_fecha,
    'paquete'        => $paquete,
    'agencia'        => $agencia,
    'estado'         => 'Pendiente pago',
    'solicitado_por' => $solicitado_por,
], false);

// Recordatorio automático en 3 días
$recordar_el = gmdate('Y-m-d', time() + GT_OFFSET * 3600 + 3 * 86400);
sb_post('reschedule_recordatorios', [
    'reservacion_id' => $res_id,
    'nombre'         => $nombre,
    'fecha_original' => $fecha_original,
    'agencia'        => $agencia ?: null,
    'paquete'        => $paquete ?: null,
    'tipo_cabana'    => $tipo_cabana ?: null,
    'recordar_el'    => $recordar_el,
    'estado'         => 'Activo',
    'creado_por'     => $solicitado_por ?: 'Sistema',
], false);

// Sync Amelia
if (es_wolfs($agencia)) {
    if (str_starts_with($link_pago, 'amelia_booking_')) {
        enqueue_sync('move_booking', [
            'booking_id'  => str_replace('amelia_booking_', '', $link_pago),
            'nueva_fecha' => $nueva_fecha,
        ]);
    } elseif (str_starts_with($link_pago, 'amelia_app_')) {
        enqueue_sync('reschedule', [
            'nueva_fecha'    => $nueva_fecha,
            'appointment_id' => str_replace('amelia_app_', '', $link_pago),
        ]);
    } elseif (str_starts_with($link_pago, 'amelia_cancelado_')) {
        enqueue_sync('create_manual', [
            'nombre'        => $nombre,
            'correo'        => $correo,
            'fecha_ascenso' => $nueva_fecha,
            'tipo_cabana'   => $tipo_cabana,
            'no_personas'   => $no_personas,
            'precio'        => $precio_orig,
            'estado'        => 'approved',
            'extra_info'    => "Reprogramación en efectivo | " . ($solicitado_por ?: 'Admin'),
        ]);
    } elseif ($link_pago) {
        $lp_res = sb_get("links_pendientes?checkout_url=eq." . urlencode($link_pago) . "&select=checkout_id");
        $l_rows = $lp_res['body'] ?? [];
        enqueue_sync('reschedule', [
            'nueva_fecha' => $nueva_fecha,
            'link_id'     => $l_rows[0]['checkout_id'] ?? $link_pago,
        ]);
    }
}

json_response(['success' => true, 'fecha_nueva' => $nueva_fecha, 'fecha_original' => $fecha_original, 'nombre' => $nombre]);
