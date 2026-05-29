<?php
// /api/solicitudes-link  (GET list, POST create, GET /{id}, POST /{id}/autorizar, POST /{id}/denegar)

$sol_id = isset($_route_sol_id) ? (int)$_route_sol_id : 0;
$action = $_route_sol_action ?? '';  // 'autorizar' | 'denegar' | ''

// ── GET /api/solicitudes-link/{id} ───────────────────────────────────────────
if ($method === 'GET' && $sol_id) {
    $res  = sb_get("solicitudes_link?id=eq.$sol_id&select=*");
    $rows = $res['body'] ?? [];
    if (empty($rows)) json_error('Solicitud no encontrada', 404);
    json_response($rows[0]);
}

// ── POST /api/solicitudes-link/{id}/autorizar ────────────────────────────────
if ($method === 'POST' && $sol_id && $action === 'autorizar') {
    $body          = get_body();
    $autorizado_por= $body['autorizado_por'] ?? 'Admin';

    $res  = sb_get("solicitudes_link?id=eq.$sol_id&select=*");
    $rows = $res['body'] ?? [];
    if (empty($rows)) json_error('Solicitud no encontrada', 404);
    $sol = $rows[0];
    if ($sol['estado'] !== 'Pendiente') json_error("Solicitud ya procesada ({$sol['estado']})", 400);

    $fecha      = $sol['fecha_ascenso'] ?? '';
    $cab        = $sol['tipo_cabana']   ?? 'Mixta';
    $personas   = (int)($sol['no_personas'] ?? 1);
    $nombre     = $sol['nombre']        ?? '';
    $precio     = (float)($sol['precio'] ?? 0);
    $agencia    = $sol['agencia']       ?? '';
    $paquete    = $sol['paquete']       ?? 'Trekking';

    // Disponibilidad
    $disp = get_disponibilidad($fecha, $agencia);
    if ($cab === 'Mixta'    && $personas > $disp['Mixta']['libre'])   json_error("Sin cupo Mixta: solo {$disp['Mixta']['libre']} disponibles.", 400);
    if ($cab === 'Privada'  && $disp['Privada']['libre']  === 0)      json_error('Cabañas privadas ya ocupadas para ese día.', 400);
    if ($cab === 'Familiar' && $disp['Familiar']['libre'] === 0)      json_error('Cabaña familiar ya ocupada para ese día.', 400);

    // ── Pre-insertar en links_pendientes para obtener el ID (= x_invoice_num) ──
    $lp_sol_ins = sb_post('links_pendientes', [
        'checkout_id'         => 'pending',
        'checkout_url'        => 'pending',
        'product_id'          => null,
        'nombre'              => $nombre, 'fecha_ascenso' => $fecha, 'tipo_cabana' => $cab,
        'no_personas'         => $sol['no_personas'] ?? null, 'precio' => $precio,
        'agencia'             => $agencia, 'paquete' => $paquete,
        'notas'               => $sol['notas'] ?? null, 'alergias' => $sol['alergias'] ?? null,
        'es_vegano'           => false, 'es_vegetariano' => false, 'es_cumpleanos' => $sol['es_cumpleanos'] ?? false,
        'telefono'            => $sol['telefono']            ?? null,
        'identificacion'      => $sol['identificacion']      ?? null,
        'nit'                 => $sol['nit'] ?? $sol['identificacion'] ?? null,
        'tipo_identificacion' => $sol['tipo_identificacion'] ?? null,
        'nombre_fiscal'       => $sol['nombre_fiscal']       ?? null,
        'correo'              => $sol['correo']              ?? null,
        'generado_por'        => $autorizado_por,
        'estado'              => 'Esperando pago',
    ], true);
    $lp_sol_id = $lp_sol_ins['body'][0]['id'] ?? null;
    if (!$lp_sol_id) json_error('Error al registrar link en base de datos', 500);

    // ── Crear checkout en QPayPro ──────────────────────────────────────────────
    $desc = "Fecha de ascenso: $fecha | Servicio: Cabaña $cab";
    if (!empty($sol['notas']))    $desc .= " | Notas: {$sol['notas']}";
    if (!empty($sol['alergias'])) $desc .= " | Alergias: {$sol['alergias']}";

    $nom_parts = explode(' ', trim($nombre), 2);
    $svc_lbl   = "Cabaña $cab Wolfs Acatenango";
    $prods_arr = json_encode([[$svc_lbl, 'WOLF-' . strtoupper(substr($cab, 0, 3)), '', 1,
        number_format($precio, 2, '.', ''), number_format($precio, 2, '.', '')]]);

    $qpp_sol = qpaypro_register_token([
        'x_login'         => QPAYPRO_LOGIN,
        'x_api_key'       => QPAYPRO_KEY,
        'x_amount'        => number_format($precio, 2, '.', ''),
        'x_currency_code' => 'GTQ',
        'x_first_name'    => $nom_parts[0],
        'x_last_name'     => $nom_parts[1] ?? 'N/A',
        'x_phone'         => $sol['telefono'] ?? '00000000',
        'x_email'         => $sol['correo']   ?? 'noreply@wolfsacatenango.com',
        'x_description'   => $desc,
        'x_invoice_num'   => $lp_sol_id,
        'x_company'       => $sol['nit'] ?? 'C/F',
        'x_address'       => 'Guatemala', 'x_city' => 'Guatemala',
        'x_state'         => 'Guatemala', 'x_country' => 'Guatemala', 'x_zip' => '01001',
        'x_freight'       => '0.00', 'taxes' => '0.00',
        'x_type'          => 'AUTH_ONLY', 'x_method' => 'CC',
        'x_visacuotas'    => 'no',
        'x_relay_url'     => 'https://wixbal.com/webhook/qpaypro',
        'x_url_success'   => 'https://wolfsacatenango.com',
        'x_url_cancel'    => 'https://wolfsacatenango.com',
        'x_url_error'     => 'https://wolfsacatenango.com',
        'http_origin'     => 'wolfsacatenango.com',
        'origen'          => 'PLUGIN', 'store_type' => 'hostedpage',
        'x_discount'      => '0', 'products' => $prods_arr,
        'custom_fields'   => json_encode(['link_id' => $lp_sol_id, 'agencia' => $agencia]),
    ]);
    if ($qpp_sol['status'] >= 300 || ($qpp_sol['body']['estado'] ?? '') !== 'success') {
        sb_patch("links_pendientes?id=eq.$lp_sol_id", ['estado' => 'Cancelado']);
        json_error('Error QPayPro: ' . json_encode($qpp_sol['body']), 502);
    }
    $token        = $qpp_sol['body']['data']['token'] ?? '';
    $checkout_url = qpaypro_checkout_url($token);
    $checkout_id  = $token;
    $product_id   = null;

    // Actualizar links_pendientes con token/URL
    sb_patch("links_pendientes?id=eq.$lp_sol_id", [
        'checkout_id'  => $token,
        'checkout_url' => $checkout_url,
    ]);

    // Crear reservación pendiente
    sb_post('reservaciones', [
        'nombre'       => $nombre, 'fecha_pago' => null, 'fecha_ascenso' => $fecha,
        'tipo_cabana'  => $cab, 'no_personas' => $sol['no_personas'] ?? null, 'precio' => $precio,
        'agencia'      => $agencia, 'paquete' => $paquete, 'notas' => $sol['notas'] ?? null,
        'alergias'     => $sol['alergias'] ?? null, 'es_cumpleanos' => $sol['es_cumpleanos'] ?? false,
        'cantidad_veganos' => $sol['cantidad_veganos'] ?? 0, 'cantidad_vegetarianos' => $sol['cantidad_vegetarianos'] ?? 0,
        'telefono'            => $sol['telefono']            ?? null,
        'identificacion'      => $sol['identificacion']      ?? null,
        'nit'                 => $sol['nit']                 ?? $sol['identificacion'] ?? null,
        'tipo_identificacion' => $sol['tipo_identificacion'] ?? null,
        'nombre_fiscal'       => $sol['nombre_fiscal']       ?? null,
        'correo'              => $sol['correo']              ?? null,
        'tipo_pago'           => 'QPayPro',
        'metodo_pago'         => 'Tarjeta',
        'estado_pago'         => 'Pendiente',
        'registrado_por'      => "Link autorizado por $autorizado_por",
        'link_pago'           => $checkout_url,
    ], false);

    // Actualizar solicitud
    $now_gt = gmdate('Y-m-d\TH:i:sP', time() + GT_OFFSET * 3600);
    sb_patch("solicitudes_link?id=eq.$sol_id", [
        'estado' => 'Autorizado', 'autorizado_por' => $autorizado_por,
        'checkout_url' => $checkout_url, 'checkout_id' => $checkout_id,
        'product_id' => $product_id, 'actualizado_en' => $now_gt,
    ]);

    // Sync Amelia
    if (es_wolfs($agencia)) {
        $extras = ["Link autorizado por: $autorizado_por"];
        if (!empty($sol['telefono'])) $extras[] = "Tel: {$sol['telefono']}";
        if (!empty($sol['correo']))   $extras[] = "Email: {$sol['correo']}";
        if (!empty($sol['alergias'])) $extras[] = "Alergias: {$sol['alergias']}";
        enqueue_sync('create_manual', [
            'nombre'        => $nombre, 'correo' => $sol['correo'] ?? 'manual@wolfsacatenango.com',
            'fecha_ascenso' => $fecha,  'tipo_cabana' => $cab,
            'no_personas'   => $sol['no_personas'] ?? 1, 'precio' => $precio,
            'estado'        => 'pending', 'link_id' => $checkout_id, 'extra_info' => implode(' | ', $extras),
        ]);
    }

    json_response(['checkout_url' => $checkout_url, 'checkout_id' => $checkout_id]);
}

// ── POST /api/solicitudes-link/{id}/denegar ───────────────────────────────────
if ($method === 'POST' && $sol_id && $action === 'denegar') {
    $body        = get_body();
    $motivo      = trim($body['motivo'] ?? '');
    $denegado_por= $body['denegado_por'] ?? 'Admin';
    if (!$motivo) json_error('El motivo de denegación es requerido.', 400);

    $res  = sb_get("solicitudes_link?id=eq.$sol_id&select=*");
    $rows = $res['body'] ?? [];
    if (empty($rows)) json_error('Solicitud no encontrada', 404);
    $sol = $rows[0];
    if ($sol['estado'] !== 'Pendiente') json_error("Solicitud ya procesada ({$sol['estado']})", 400);

    $now_gt = gmdate('Y-m-d\TH:i:sP', time() + GT_OFFSET * 3600);
    sb_patch("solicitudes_link?id=eq.$sol_id", [
        'estado' => 'Denegado', 'autorizado_por' => $denegado_por,
        'motivo_denegacion' => $motivo, 'actualizado_en' => $now_gt,
    ]);

    $nombre_sol = $sol['nombre'] ?? '';
    $sol_por    = $sol['solicitado_por'] ?? '';
    enviar_email(
        "❌ Link denegado — $nombre_sol",
        "<div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;'>
          <div style='background:#991b1b;padding:20px 24px;'><h2 style='color:#fff;margin:0;'>❌ Solicitud de Link Denegada</h2></div>
          <div style='padding:24px;'>
            <table style='width:100%;border-collapse:collapse;font-size:15px;'>
              <tr><td style='padding:8px 0;color:#555;width:160px;'><b>Cliente</b></td><td>$nombre_sol</td></tr>
              <tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'><b>Solicitado por</b></td><td style='padding:8px;'>$sol_por</td></tr>
              <tr><td style='padding:8px 0;color:#555;'><b>Denegado por</b></td><td>$denegado_por</td></tr>
              <tr style='background:#fef2f2;'><td style='padding:8px;color:#555;'><b>Motivo</b></td><td style='padding:8px;color:#991b1b;font-weight:600;'>$motivo</td></tr>
            </table>
          </div>
          <div style='background:#f5f5f5;padding:12px 24px;font-size:12px;color:#999;text-align:center;'>Wolfs Acatenango · Sistema de Reservaciones</div>
        </div>"
    );
    json_response(['denegado' => true]);
}

// ── GET /api/solicitudes-link ────────────────────────────────────────────────
if ($method === 'GET') {
    $filters = '';
    if (!empty($_GET['estado']))        $filters .= '&estado=eq.'        . urlencode($_GET['estado']);
    if (!empty($_GET['solicitado_por'])) $filters .= '&solicitado_por=eq.' . urlencode($_GET['solicitado_por']);
    $res = sb_get("solicitudes_link?select=*&order=creado_en.desc&limit=30$filters");
    json_response($res['body'] ?? []);
}

// ── POST /api/solicitudes-link (crear) ────────────────────────────────────────
$data = get_body();
foreach (['nombre', 'fecha_ascenso', 'tipo_cabana', 'precio'] as $f) {
    if (empty($data[$f])) json_error("$f es requerido", 400);
}

$sol_data = [
    'estado'         => 'Pendiente',
    'nombre'         => $data['nombre'],
    'fecha_ascenso'  => $data['fecha_ascenso'],
    'tipo_cabana'    => $data['tipo_cabana'],
    'no_personas'    => $data['no_personas'] ?? null,
    'precio'         => (float)$data['precio'],
    'agencia'        => $data['agencia']        ?? null,
    'paquete'        => $data['paquete']        ?? null,
    'notas'          => $data['notas']          ?? null,
    'alergias'       => $data['alergias']       ?? null,
    'cantidad_veganos'      => (int)($data['cantidad_veganos']      ?? 0),
    'cantidad_vegetarianos' => (int)($data['cantidad_vegetarianos'] ?? 0),
    'es_cumpleanos'  => !empty($data['es_cumpleanos']),
    'telefono'       => $data['telefono']       ?? null,
    'correo'         => $data['correo']         ?? null,
    'identificacion'      => $data['identificacion']      ?? null,
    'nit'                 => $data['nit']                 ?? $data['identificacion'] ?? null,
    'tipo_identificacion' => $data['tipo_identificacion'] ?? null,
    'nombre_fiscal'       => $data['nombre_fiscal']       ?? null,
    'solicitado_por'      => $data['solicitado_por']      ?? null,
];
$res = sb_post('solicitudes_link', $sol_data);
// Si falla por columnas que no existen (cantidad_veganos / cantidad_vegetarianos), reintentar sin ellas
if ($res['status'] >= 300) {
    $err_body = json_encode($res['body']);
    if (str_contains($err_body, 'cantidad_veganos') || str_contains($err_body, 'cantidad_vegetarianos')) {
        unset($sol_data['cantidad_veganos'], $sol_data['cantidad_vegetarianos']);
        $res = sb_post('solicitudes_link', $sol_data);
    }
}
if ($res['status'] >= 300) json_error('Error guardando solicitud: ' . json_encode($res['body']), 500);
$sol    = $res['body'][0] ?? [];
$sol_id = $sol['id'] ?? 0;

// Notificación en sistema
$precio_f = number_format((float)$data['precio'], 0);
sb_post('notificaciones', [
    'tipo'       => 'solicitud_link',
    'titulo'     => "Solicitud de link: {$data['nombre']}",
    'mensaje'    => "{$data['solicitado_por']} solicita link → {$data['nombre']} | {$data['fecha_ascenso']} | {$data['tipo_cabana']} | Q$precio_f",
    'datos'      => $sol_data,
    'creado_por' => $data['solicitado_por'] ?? 'Ventas',
], false);

// Email
$ag   = $data['agencia']  ?? '—';
$paq  = $data['paquete']  ?? '—';
$pers = isset($data['no_personas']) ? "{$data['no_personas']} pers." : '—';
$precio_fmt = number_format((float)$data['precio'], 2);
$alergias_row = !empty($data['alergias']) ? "<tr><td style='padding:8px 0;color:#555;'><b>Alergias</b></td><td style='padding:8px 0;'>{$data['alergias']}</td></tr>" : '';
$notas_row    = !empty($data['notas'])    ? "<tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'><b>Notas</b></td><td style='padding:8px;'>{$data['notas']}</td></tr>" : '';
$tel_row      = !empty($data['telefono']) ? "<tr><td style='padding:8px 0;color:#555;'><b>Tel.</b></td><td style='padding:8px 0;'>{$data['telefono']}</td></tr>" : '';
enviar_email(
    "🔔 Solicitud de link #$sol_id — {$data['nombre']} ({$data['fecha_ascenso']})",
    "<div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;'>
      <div style='background:#1e3a5f;padding:20px 24px;'>
        <h2 style='color:#fff;margin:0;'>🔔 Nueva Solicitud de Link</h2>
        <p style='color:rgba(255,255,255,0.8);margin:6px 0 0;font-size:14px;'>Solicitud #$sol_id — requiere autorización</p>
      </div>
      <div style='padding:24px;'>
        <table style='width:100%;border-collapse:collapse;font-size:15px;'>
          <tr><td style='padding:8px 0;color:#555;width:160px;'><b>Cliente</b></td><td>{$data['nombre']}</td></tr>
          <tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'><b>Fecha ascenso</b></td><td style='padding:8px;'>{$data['fecha_ascenso']}</td></tr>
          <tr><td style='padding:8px 0;color:#555;'><b>Cabaña</b></td><td>{$data['tipo_cabana']} ($pers)</td></tr>
          <tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'><b>Paquete</b></td><td style='padding:8px;'>$paq</td></tr>
          <tr><td style='padding:8px 0;color:#555;'><b>Agencia</b></td><td>$ag</td></tr>
          <tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'><b>Precio</b></td><td style='padding:8px;font-weight:bold;'>Q$precio_fmt</td></tr>
          $alergias_row$notas_row$tel_row
        </table>
      </div>
      <div style='background:#f5f5f5;padding:12px 24px;font-size:12px;color:#999;text-align:center;'>Wolfs Acatenango · Sistema de Reservaciones</div>
    </div>"
);
json_response(['id' => $sol_id, 'estado' => 'Pendiente']);
