    <?php
    // POST /api/reservaciones/crear_manual
    $data = get_body();

    $fake_id = 'manual_' . time();
    if (!isset($data['link_pago'])) $data['link_pago'] = $fake_id;

    // ── 1. Guardar en Supabase ────────────────────────────────────────────────────
    $res = sb_post('reservaciones', $data);
    if ($res['status'] >= 300) {
        $body_str = json_encode($res['body']);
        if (str_contains($body_str, 'cantidad_veganos') || str_contains($body_str, 'cantidad_vegetarianos')) {
            unset($data['cantidad_veganos'], $data['cantidad_vegetarianos']);
            $res = sb_post('reservaciones', $data);
        }
        if ($res['status'] >= 300) {
            json_error('Error al guardar en Supabase: ' . json_encode($res['body']), 500);
        }
    }
    $rows = $res['body'];
    if (empty($rows)) json_error('Supabase no devolvió la reservación', 500);
    $nueva = $rows[0];
    $res_id = $nueva['id'];

    $nombre      = $data['nombre']        ?? '';
    $tipo_cabana = $data['tipo_cabana']   ?? '';
    $fecha       = $data['fecha_ascenso'] ?? '';
    $correo      = $data['correo']        ?? null;
    $precio      = (float)($data['precio']      ?? 0);
    $agencia     = $data['agencia']       ?? '';
    $estado_pago = $data['estado_pago']   ?? 'Pendiente';

    // ── 2. Sync Amelia (solo Wolfs, síncrono) ────────────────────────────────────
    $amelia_ok  = null;
    $amelia_msg = null;
    if (es_wolfs($agencia)) {
        $notas_extra = ["Creado manual por: " . ($data['registrado_por'] ?? 'Admin')];
        if (!empty($data['telefono']))       $notas_extra[] = "Tel: {$data['telefono']}";
        if (!empty($data['identificacion'])) $notas_extra[] = "ID: {$data['identificacion']}";
        if (!empty($correo))                 $notas_extra[] = "Email: $correo";
        if (!empty($data['alergias']))       $notas_extra[] = "Alergias: {$data['alergias']}";
        if (!empty($data['es_vegano']))      $notas_extra[] = "Menu: Vegano";
        if (!empty($data['es_vegetariano'])) $notas_extra[] = "Menu: Vegetariano";
        if (!empty($data['es_cumpleanos']))  $notas_extra[] = "Es Cumpleanos!";
        if (!empty($data['notas']))          $notas_extra[] = "Notas: {$data['notas']}";

        $sync_data = [
            'nombre'        => $nombre,
            'correo'        => $correo ?: 'manual@wolfsacatenango.com',
            'fecha_ascenso' => $fecha,
            'tipo_cabana'   => $tipo_cabana,
            'no_personas'   => (int)($data['no_personas'] ?? 1),
            'precio'        => $precio,
            'estado'        => $estado_pago === 'Completado' ? 'approved' : 'pending',
            'link_id'       => $fake_id,
            'extra_info'    => implode(' | ', $notas_extra),
        ];

        $bridge_result = bridge_call('create_manual', $sync_data);
        if (!isset($bridge_result['error'])) {
            $amelia_ok  = true;
            $amelia_msg = json_encode($bridge_result);
            $booking_id = $bridge_result['bookingId'] ?? null;
            if ($booking_id) {
                $new_link = "amelia_booking_$booking_id";
                sb_patch("reservaciones?id=eq.$res_id", ['link_pago' => $new_link]);
                $nueva['link_pago'] = $new_link;
            }
        } else {
            $amelia_ok  = false;
            $amelia_msg = json_encode($bridge_result);
            enqueue_sync('create_manual', $sync_data);
        }
    }

    // Reserva presencial nunca genera factura (solo links de pago la generan)

    // ── 4. Notificar al equipo ────────────────────────────────────────────────────
    $no_pers = (int)($data['no_personas'] ?? 1);
    enviar_email(
        "🧾 Nueva reservación presencial: $nombre — $fecha",
        html_notif_pago_equipo($nombre, $tipo_cabana, $fecha, $precio, $no_pers, $data['registrado_por'] ?? null)
    );

    $result = $nueva;
    $result['amelia_sync_ok']  = $amelia_ok;
    $result['amelia_sync_msg'] = $amelia_msg;
    json_response($result);
