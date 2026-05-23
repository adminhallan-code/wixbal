<?php
// POST /webhook/amelia
$body = get_body();
error_log('[WEBHOOK AMELIA] Payload: ' . json_encode($body));

$tipo       = strtolower($body['type'] ?? '');
$app_data   = $body['appointment'] ?? [];
$app_tipo   = strtolower($app_data['type'] ?? '');

$is_appointment = str_contains($tipo, 'appointment') || str_contains($tipo, 'booking')
               || str_contains($app_tipo, 'appointment') || isset($body['appointment']);

if (!$is_appointment) {
    json_response(['received' => true]);
}

$amelia_status  = strtolower($app_data['status'] ?? '');
$appointment_id = (int) ($app_data['id'] ?? 0);
$fecha_ascenso  = substr($app_data['bookingStart'] ?? '', 0, 10);

// Determinar tipo de cabaña desde serviceId
$service_id     = (int) ($app_data['serviceId'] ?? 0);
$tipo_cabana    = 'Desconocida';
foreach (SERVICE_MAP as $cab => $info) {
    if ($info['serviceId'] === $service_id) { $tipo_cabana = $cab; break; }
}

// ── Cancelación ───────────────────────────────────────────────────────────────
$is_cancelacion = in_array($amelia_status, ['canceled', 'rejected', 'cancelled']);

if ($is_cancelacion && $appointment_id) {
    error_log("[WEBHOOK AMELIA] Cancelación detectada appointment $appointment_id");

    $res = sb_get("reservaciones?link_pago=like.*amelia_app_{$appointment_id}*&select=id,estado_pago,link_pago");
    $rows = $res['body'] ?? [];

    if (empty($rows) && $fecha_ascenso && $tipo_cabana !== 'Desconocida') {
        $res2 = sb_get(
            "reservaciones?fecha_ascenso=eq.$fecha_ascenso&tipo_cabana=eq." . urlencode($tipo_cabana)
            . "&estado_pago=neq.Cancelado&select=id,estado_pago"
        );
        $rows = $res2['body'] ?? [];
    }
    foreach ($rows as $row) {
        sb_patch("reservaciones?id=eq." . $row['id'], ['estado_pago' => 'Cancelado']);
        error_log("[WEBHOOK AMELIA] Reservacion " . $row['id'] . " cancelada.");
    }
    json_response(['received' => true, 'accion' => 'cancelado_desde_amelia']);
}

// ── Reprogramación ────────────────────────────────────────────────────────────
$is_reprogramada = str_contains($tipo, 'reschedul');
$nueva_fecha     = substr($app_data['bookingStart'] ?? '', 0, 10);

if ($is_reprogramada && $appointment_id && $nueva_fecha) {
    error_log("[WEBHOOK AMELIA] Reprogramación appointment $appointment_id -> $nueva_fecha");

    $res = sb_get("reservaciones?link_pago=like.*amelia_app_{$appointment_id}*&select=id,link_pago");
    $rows = $res['body'] ?? [];

    if (empty($rows) && $fecha_ascenso && $tipo_cabana !== 'Desconocida') {
        $res2 = sb_get(
            "reservaciones?fecha_ascenso=eq.$fecha_ascenso&tipo_cabana=eq." . urlencode($tipo_cabana)
            . "&estado_pago=neq.Cancelado&select=id,link_pago"
        );
        $rows = $res2['body'] ?? [];
    }
    foreach ($rows as $row) {
        sb_patch("reservaciones?id=eq." . $row['id'], ['fecha_ascenso' => $nueva_fecha]);
        error_log("[WEBHOOK AMELIA] Reservacion " . $row['id'] . " reprogramada a $nueva_fecha.");
    }
    json_response(['received' => true, 'accion' => 'reprogramado_desde_amelia']);
}

// ── Cambio de estado ──────────────────────────────────────────────────────────
$tipo_norm = str_replace(['_', ' '], '', $tipo);
$is_cambio = str_contains($tipo_norm, 'status')
          || ($amelia_status && !$is_cancelacion && !str_contains($tipo, 'reschedul'));

$mapa_estados = [
    'approved' => 'Completado',
    'pending'  => 'Pendiente',
    'canceled' => 'Cancelado',
    'rejected' => 'Cancelado',
    'no-show'  => 'Cancelado',
];

if ($is_cambio && $appointment_id && isset($mapa_estados[$amelia_status])) {
    $nuevo_estado = $mapa_estados[$amelia_status];
    error_log("[WEBHOOK AMELIA] Cambio estado appointment $appointment_id -> $nuevo_estado");

    // Extraer el booking específico que cambió (viene en body['bookings'])
    $bookings_root = $body['bookings'] ?? [];
    $bookings_app  = $app_data['bookings'] ?? [];
    $bookings_dict = $bookings_root ?: $bookings_app;
    $primer_b = is_array($bookings_dict)
        ? (isset($bookings_dict[0]) ? $bookings_dict[0] : ($bookings_dict['0'] ?? []))
        : [];
    $booking_id_cs = $primer_b['id'] ?? null;
    $rows = [];

    // 1) Si tenemos booking_id específico, buscar SOLO por ese booking
    if ($booking_id_cs) {
        $res_bk = sb_get("reservaciones?link_pago=eq.amelia_booking_{$booking_id_cs}&select=id,estado_pago,link_pago");
        $rows = $res_bk['body'] ?? [];
        if ($rows) error_log("[WEBHOOK AMELIA] Encontrado por amelia_booking_$booking_id_cs");
    }

    // 2) Sin booking específico: buscar por appointment o manual_*
    if (empty($rows) && !$booking_id_cs) {
        $res = sb_get("reservaciones?link_pago=like.*amelia_app_{$appointment_id}*&select=id,estado_pago,link_pago");
        $rows = $res['body'] ?? [];

        if (empty($rows) && $fecha_ascenso && $tipo_cabana !== 'Desconocida') {
            $res2 = sb_get(
                "reservaciones?fecha_ascenso=eq.$fecha_ascenso&tipo_cabana=eq." . urlencode($tipo_cabana)
                . "&estado_pago=neq.Cancelado&link_pago=like.manual_*&select=id,estado_pago,link_pago"
            );
            $rows = $res2['body'] ?? [];
            error_log("[WEBHOOK AMELIA] Fallback manual_*: " . count($rows) . " encontradas");
        }
    }

    foreach ($rows as $row) {
        $patch = ['estado_pago' => $nuevo_estado];
        if ($nuevo_estado === 'Completado' && !str_starts_with($row['link_pago'], 'amelia_')) {
            $patch['link_pago'] = "amelia_booking_$booking_id_cs";
        }
        sb_patch("reservaciones?id=eq." . $row['id'], $patch);
        error_log("[WEBHOOK AMELIA] Reservacion " . $row['id'] . " -> $nuevo_estado");
    }

    // Si no existe, crear nueva para este booking específico
    if (empty($rows) && $fecha_ascenso && $tipo_cabana !== 'Desconocida') {
        $booking_id_new = $booking_id_cs ?? null;
        $cliente  = $primer_b['customer'] ?? [];
        $nombre   = trim(($cliente['firstName'] ?? '') . ' ' . ($cliente['lastName'] ?? '')) ?: 'Sin nombre';
        $correo   = $cliente['email'] ?? "amelia_{$appointment_id}@wolfsacatenango.com";
        if (str_contains($correo, 'fallback_')) $correo = "amelia_{$appointment_id}@wolfsacatenango.com";
        $precio   = (float) ($primer_b['price'] ?? 0);
        $personas = (int) ($primer_b['persons'] ?? 1);
        $link_new = $booking_id_new ? "amelia_booking_$booking_id_new" : "amelia_app_$appointment_id";

        // Extraer campos personalizados de Amelia (alergias, notas, etc.)
        $custom_fields = $primer_b['customFields'] ?? [];
        $alergias_val  = null;
        if (!empty($custom_fields)) {
            $partes = [];
            foreach ($custom_fields as $cf) {
                $label = strtolower($cf['label'] ?? '');
                $val   = trim($cf['value'] ?? '');
                if ($val === '' || $val === '-' || $val === 'ninguna' || $val === 'no') continue;
                // Priorizar campos de alergias
                if (str_contains($label, 'alergi') || str_contains($label, 'restrict') || str_contains($label, 'intoler')) {
                    array_unshift($partes, $val);
                } else {
                    $partes[] = $val;
                }
            }
            if ($partes) $alergias_val = implode(' | ', $partes);
        }
        error_log("[WEBHOOK AMELIA] customFields booking $booking_id_new: " . json_encode($custom_fields));

        $nueva = array_filter([
            'nombre'         => $nombre,
            'correo'         => $correo,
            'fecha_ascenso'  => $fecha_ascenso,
            'tipo_cabana'    => $tipo_cabana,
            'no_personas'    => $personas,
            'precio'         => $precio,
            'estado_pago'    => $nuevo_estado,
            'metodo_pago'    => 'Amelia / WooCommerce',
            'tipo_pago'      => 'Recurrente',
            'fecha_pago'     => $nuevo_estado === 'Completado' ? gt_date() : null,
            'link_pago'      => $link_new,
            'agencia'        => 'Wolfs Acatenango',
            'registrado_por' => 'Sistema (Amelia)',
            'alergias'       => $alergias_val,
            'notas'          => "Creado automaticamente desde Amelia (appointment $appointment_id, booking $booking_id_new)",
        ], fn($v) => $v !== null);

        $r = sb_post('reservaciones', $nueva);
        error_log("[WEBHOOK AMELIA] Nueva reservacion creada: status={$r['status']}");
    }

    json_response(['received' => true, 'accion' => "estado_actualizado_$nuevo_estado"]);
}

json_response(['received' => true]);
