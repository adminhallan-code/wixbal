<?php
// POST /api/reservaciones/{id}/cancelar
$res_id = $_route_res_id ?? 0;
if (!$res_id) json_error('ID inválido', 400);

$body         = get_body();
$motivo       = $body['motivo']       ?? '';
$cancelado_por= $body['cancelado_por'] ?? 'Sistema';

$res = sb_get("reservaciones?id=eq.$res_id&select=link_pago,agencia,nombre,fecha_ascenso,tipo_cabana,no_personas,paquete");
$rows = $res['body'] ?? [];
if (empty($rows)) json_error('Reservación no encontrada', 404);

$rv           = $rows[0];
$link_pago    = $rv['link_pago']    ?? '';
$agencia      = $rv['agencia']      ?? '';
$nombre       = $rv['nombre']       ?? '';
$fecha_ascenso= $rv['fecha_ascenso'] ?? '';
$tipo_cabana  = $rv['tipo_cabana']  ?? '';
$no_personas  = $rv['no_personas']  ?? null;
$paquete      = $rv['paquete']      ?? '';

// Actualizar Supabase
sb_patch("reservaciones?id=eq.$res_id", ['estado_pago' => 'Cancelado']);

// Crear notificación
$detalle = "$nombre — $fecha_ascenso — $tipo_cabana";
if ($no_personas) $detalle .= " ($no_personas pers.)";
if ($agencia)     $detalle .= " — $agencia";
if ($motivo)      $detalle .= " | Motivo: $motivo";

sb_post('notificaciones', [
    'tipo'      => 'cancelacion',
    'titulo'    => "Cancelación: $nombre",
    'mensaje'   => $detalle,
    'datos'     => ['reservacion_id' => $res_id, 'nombre' => $nombre,
                    'fecha_ascenso' => $fecha_ascenso, 'tipo_cabana' => $tipo_cabana,
                    'agencia' => $agencia, 'paquete' => $paquete, 'motivo' => $motivo],
    'creado_por'=> $cancelado_por,
], false);

// Email al equipo
$no_pers_txt = $no_personas ? " ($no_personas pers.)" : '';
$motivo_row  = $motivo ? "<tr><td style='padding:8px 0;color:#555;'><b>Motivo</b></td><td style='padding:8px 0;'>$motivo</td></tr>" : '';
enviar_email(
    "❌ Cancelación: $nombre — $fecha_ascenso",
    "<div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;'>
      <div style='background:#c0392b;padding:20px 24px;'><h2 style='color:#fff;margin:0;'>❌ Cancelación de Reserva</h2></div>
      <div style='padding:24px;'>
        <table style='width:100%;border-collapse:collapse;font-size:15px;'>
          <tr><td style='padding:8px 0;color:#555;width:140px;'><b>Cliente</b></td><td>$nombre</td></tr>
          <tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'><b>Fecha ascenso</b></td><td style='padding:8px;'>$fecha_ascenso</td></tr>
          <tr><td style='padding:8px 0;color:#555;'><b>Tipo cabaña</b></td><td>$tipo_cabana$no_pers_txt</td></tr>
          <tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'><b>Paquete</b></td><td style='padding:8px;'>" . ($paquete ?: '—') . "</td></tr>
          <tr><td style='padding:8px 0;color:#555;'><b>Agencia</b></td><td>" . ($agencia ?: '—') . "</td></tr>
          <tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'><b>Cancelado por</b></td><td style='padding:8px;'>$cancelado_por</td></tr>
          $motivo_row
        </table>
      </div>
      <div style='background:#f5f5f5;padding:12px 24px;font-size:12px;color:#999;text-align:center;'>Wolfs Acatenango · Sistema de Reservaciones</div>
    </div>"
);

// Sync Amelia
if ($link_pago && es_wolfs($agencia)) {
    if (str_starts_with($link_pago, 'amelia_booking_')) {
        $booking_id = str_replace('amelia_booking_', '', $link_pago);
        enqueue_sync('cancel_booking', ['booking_id' => $booking_id]);
    } elseif (str_starts_with($link_pago, 'amelia_app_')) {
        $app_id = str_replace('amelia_app_', '', $link_pago);
        enqueue_sync('update_status', ['estado' => 'canceled', 'appointment_id' => $app_id]);
    } else {
        $lp_res  = sb_get("links_pendientes?checkout_url=eq." . urlencode($link_pago) . "&select=checkout_id");
        $l_rows  = $lp_res['body'] ?? [];
        $link_id = $l_rows[0]['checkout_id'] ?? $link_pago;
        enqueue_sync('update_status', ['estado' => 'canceled', 'link_id' => $link_id]);
    }
}

json_response(['success' => true]);
