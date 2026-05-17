<?php
// POST /api/reservaciones/{id}/establecer-recordatorio
$res_id = $_route_res_id ?? 0;
if (!$res_id) json_error('ID inválido', 400);

$data       = get_body();
$recordar_el= $data['recordar_el'] ?? '';
$creado_por = $data['creado_por']  ?? 'Sistema';

if (!fecha_valida($recordar_el)) json_error('recordar_el es requerida (YYYY-MM-DD)', 400);

$rv_res = sb_get("reservaciones?id=eq.$res_id&select=nombre,fecha_ascenso,tipo_cabana,paquete,agencia,no_personas,link_pago");
$rows = $rv_res['body'] ?? [];
if (empty($rows)) json_error('Reservación no encontrada', 404);

$rv           = $rows[0];
$nombre       = $rv['nombre']       ?? '';
$agencia      = $rv['agencia']      ?? '';
$fecha_original= $rv['fecha_ascenso'] ?? '';
$tipo_cabana  = $rv['tipo_cabana']  ?? '';
$paquete      = $rv['paquete']      ?? '';
$link_pago    = $rv['link_pago']    ?? '';

// Cancelar cita en Amelia para liberar el espacio
if (es_wolfs($agencia) && $link_pago) {
    $cancel_payload = ['estado' => 'canceled'];
    $sentinel_lp    = null;

    if (str_starts_with($link_pago, 'amelia_app_')) {
        $app_id = str_replace('amelia_app_', '', $link_pago);
        $cancel_payload['appointment_id'] = $app_id;
        $sentinel_lp = "amelia_cancelado_$app_id";
    } elseif (str_starts_with($link_pago, 'amelia_booking_')) {
        $booking_id = str_replace('amelia_booking_', '', $link_pago);
        $cancel_payload['booking_id'] = $booking_id;
        $sentinel_lp = "amelia_cancelado_booking_$booking_id";
        // use cancel_booking instead
        enqueue_sync('cancel_booking', ['booking_id' => $booking_id]);
        sb_patch("reservaciones?id=eq.$res_id", ['link_pago' => $sentinel_lp]);
        // skip second enqueue_sync below
        $link_pago = '';
    } else {
        $lp_res = sb_get("links_pendientes?checkout_url=eq." . urlencode($link_pago) . "&select=checkout_id");
        $l_rows = $lp_res['body'] ?? [];
        $cancel_payload['link_id'] = $l_rows[0]['checkout_id'] ?? $link_pago;
    }

    if ($link_pago) {
        enqueue_sync('update_status', $cancel_payload);
        sb_patch("reservaciones?id=eq.$res_id", ['link_pago' => $sentinel_lp]);
    }
}

// Crear recordatorio
sb_post('reschedule_recordatorios', [
    'reservacion_id' => $res_id,
    'nombre'         => $nombre,
    'fecha_original' => $fecha_original,
    'agencia'        => $agencia ?: null,
    'paquete'        => $paquete ?: null,
    'tipo_cabana'    => $tipo_cabana ?: null,
    'recordar_el'    => $recordar_el,
    'estado'         => 'Activo',
    'creado_por'     => $creado_por,
], false);

json_response(['success' => true]);
