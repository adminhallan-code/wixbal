<?php
// POST /crear-link-pago
$data = get_body();

$nombre       = $data['nombre']        ?? '';
$fecha        = $data['fecha_ascenso'] ?? '';
$tipo_cabana  = $data['tipo_cabana']   ?? '';
$no_personas  = isset($data['no_personas']) ? (int)$data['no_personas'] : 1;
$precio       = isset($data['precio'])      ? (float)$data['precio']    : 0;
$agencia      = $data['agencia']       ?? '';
$paquete      = $data['paquete']       ?? '';
$notas        = $data['notas']         ?? null;
$alergias     = $data['alergias']      ?? null;
$es_vegano    = !empty($data['es_vegano']);
$es_vegetariano  = !empty($data['es_vegetariano']);
$cantidad_veganos      = (int)($data['cantidad_veganos']      ?? 0);
$cantidad_vegetarianos = (int)($data['cantidad_vegetarianos'] ?? 0);
$es_cumpleanos = !empty($data['es_cumpleanos']);
$telefono     = $data['telefono']       ?? null;
$identificacion = $data['identificacion'] ?? null;
$correo       = $data['correo']         ?? null;
$generado_por = $data['generado_por']   ?? '';
$nit          = $data['nit']            ?? null;
$tipo_identificacion = $data['tipo_identificacion'] ?? null;
$nombre_fiscal = $data['nombre_fiscal'] ?? null;

if (!$nombre || !$fecha || !$tipo_cabana || !$paquete) {
    json_error('nombre, fecha_ascenso, tipo_cabana y paquete son requeridos', 400);
}
if (!fecha_valida($fecha)) json_error('Fecha inválida', 400);
if (!in_array($tipo_cabana, ['Mixta', 'Privada', 'Familiar'])) json_error('tipo_cabana inválido', 400);

// ── Validaciones de tiempo (zona horaria Guatemala UTC-6) ──────────────────
$now_gt       = time() + GT_OFFSET * 3600;
$hoy_gt       = gmdate('Y-m-d', $now_gt);
$manana_gt    = gmdate('Y-m-d', $now_gt + 86400);
$hora_gt      = (int) gmdate('G', $now_gt);
$ascenso_ts   = strtotime($fecha . 'T07:00:00-06:00');

if ($fecha === $hoy_gt) {
    json_error('No se pueden generar links para el mismo día del ascenso. El ascenso debe ser a partir de mañana.', 400);
}
if ($paquete === '4x4') {
    $horas_diff = ($ascenso_ts - (time())) / 3600;
    if ($horas_diff < 48) {
        $horas_r = max(0, (int)$horas_diff);
        json_error("El paquete 4x4 requiere reservar con mínimo 48 horas de anticipación. Faltan {$horas_r}h para el ascenso del $fecha.", 400);
    }
} else {
    if ($fecha === $manana_gt && $hora_gt >= 12) {
        json_error("Ya no se pueden crear links para mañana ($fecha). El límite es las 12:00 del mediodía del día anterior.", 400);
    }
}

// ── Disponibilidad ────────────────────────────────────────────────────────────
$disp = get_disponibilidad($fecha, $agencia);
if ($tipo_cabana === 'Mixta'    && $no_personas > $disp['Mixta']['libre'])     json_error("Sin cupo en Mixta. Solo quedan {$disp['Mixta']['libre']} personas.", 400);
if ($tipo_cabana === 'Privada'  && $disp['Privada']['libre']  === 0)           json_error('Las 2 cabañas privadas ya están ocupadas para ese día.', 400);
if ($tipo_cabana === 'Familiar' && $disp['Familiar']['libre'] === 0)           json_error('La cabaña familiar ya está ocupada para ese día.', 400);

// ── Descripción del link ──────────────────────────────────────────────────────
$desc_partes = ["Fecha de ascenso: $fecha"];
if ($tipo_cabana === 'Mixta' && $no_personas) $desc_partes[] = "Personas: $no_personas";
$svc_label = "Cabaña $tipo_cabana" . ($paquete === '4x4' ? ' + Servicio 4x4' : '');
$desc_partes[] = "Servicio: $svc_label";
if ($notas)    $desc_partes[] = "Notas: $notas";
if ($alergias) $desc_partes[] = "Alergias: $alergias";
if ($cantidad_veganos || $es_vegano)        $desc_partes[] = "Menú vegano: " . ($cantidad_veganos ?: 1) . " persona(s)";
if ($cantidad_vegetarianos || $es_vegetariano) $desc_partes[] = "Menú vegetariano: " . ($cantidad_vegetarianos ?: 1) . " persona(s)";
if ($es_cumpleanos) $desc_partes[] = '¡Celebración de cumpleaños!';
$descripcion = implode(' | ', $desc_partes);

// ── 1. Pre-insertar en links_pendientes para obtener el ID (= x_invoice_num) ──
$lp_data = [
    'checkout_id'          => 'pending',
    'checkout_url'         => 'pending',
    'product_id'           => null,
    'nombre'               => $nombre,
    'fecha_ascenso'        => $fecha,
    'tipo_cabana'          => $tipo_cabana,
    'no_personas'          => $no_personas,
    'precio'               => $precio,
    'agencia'              => $agencia,
    'paquete'              => $paquete,
    'notas'                => $notas,
    'alergias'             => $alergias,
    'es_vegano'            => $es_vegano,
    'es_vegetariano'       => $es_vegetariano,
    'es_cumpleanos'        => $es_cumpleanos,
    'telefono'             => $telefono,
    'identificacion'       => $identificacion,
    'correo'               => $correo,
    'generado_por'         => $generado_por,
    'nit'                  => $nit,
    'tipo_identificacion'  => $tipo_identificacion,
    'nombre_fiscal'        => $nombre_fiscal,
    'estado'               => 'Esperando pago',
];
$lp_insert = sb_post('links_pendientes', $lp_data, true);
$lp_id = $lp_insert['body'][0]['id'] ?? null;
error_log('[CREAR LINK] SB insert links_pendientes: http=' . $lp_insert['status'] . ' body=' . json_encode($lp_insert['body']) . ' lp_id=' . $lp_id);
if (!$lp_id) json_error('Error al registrar link en base de datos', 500);

// ── 2. Registrar transacción en QPayPro (Hosted Page) ────────────────────────
$nombre_parts = explode(' ', trim($nombre), 2);
$x_first_name = $nombre_parts[0];
$x_last_name  = $nombre_parts[1] ?? 'N/A';

// Nombre del producto — incluye todos los detalles visibles en el checkout de QPayPro
$prod_nombre_partes = ["$svc_label - Wolfs Acatenango"];
$prod_nombre_partes[] = "Fecha ascenso: $fecha";
if ($tipo_cabana === 'Mixta') $prod_nombre_partes[] = "Personas: $no_personas";
$prod_nombre_partes[] = "Paquete: $paquete";
if ($telefono)    $prod_nombre_partes[] = "Tel: $telefono";
if ($notas)       $prod_nombre_partes[] = "Notas: $notas";
if ($alergias)    $prod_nombre_partes[] = "Alergias: $alergias";
if ($cantidad_veganos || $es_vegano)           $prod_nombre_partes[] = "Vegano: " . ($cantidad_veganos ?: 1) . " persona(s)";
if ($cantidad_vegetarianos || $es_vegetariano) $prod_nombre_partes[] = "Vegetariano: " . ($cantidad_vegetarianos ?: 1) . " persona(s)";
if ($es_cumpleanos) $prod_nombre_partes[] = "¡Cumpleaños!";
$prod_nombre = implode(' | ', $prod_nombre_partes);

$products_arr = json_encode([[
    $prod_nombre,
    'WOLF-' . strtoupper(substr($tipo_cabana, 0, 3)),
    '',
    1,
    number_format($precio, 2, '.', ''),
    number_format($precio, 2, '.', ''),
]]);

$relay_url = 'https://wixbal.com/webhook/qpaypro';

error_log('[CREAR LINK] Enviando a QPayPro: lp_id=' . $lp_id . ' precio=' . $precio . ' nombre=' . $nombre);
$qpp_res = qpaypro_register_token([
    'x_login'         => QPAYPRO_LOGIN,
    'x_api_key'       => QPAYPRO_KEY,
    'x_amount'        => number_format($precio, 2, '.', ''),
    'x_currency_code' => 'GTQ',
    'x_first_name'    => $x_first_name,
    'x_last_name'     => $x_last_name,
    'x_phone'         => $telefono ?: '00000000',
    'x_email'         => $correo   ?: 'noreply@wolfsacatenango.com',
    'x_description'   => $descripcion,
    'x_invoice_num'   => $lp_id,
    'x_company'       => $nit ?: 'C/F',
    'x_address'       => 'Guatemala',
    'x_city'          => 'Guatemala',
    'x_state'         => '0',
    'x_country'       => 'Guatemala',
    'x_zip'           => '01001',
    'x_freight'       => '0.00',
    'taxes'           => '0.00',
    'x_type'          => 'AUTH_CAPTURE',
    'x_method'        => 'CC',
    'x_visacuotas'    => 'no',
    'x_relay_url'     => $relay_url,              // server-to-server POST de QPayPro
    'x_url_success'   => 'https://wolfsacatenango.com',  // redirect browser al pagar
    'x_url_cancel'    => 'https://wolfsacatenango.com',  // redirect browser al cancelar
    'x_url_error'     => 'https://wolfsacatenango.com',  // redirect browser en error
    'http_origin'     => 'wolfsacatenango.com',
    'origen'          => 'PLUGIN',
    'store_type'      => 'hostedpage',
    'x_discount'      => '0',
    'products'        => $products_arr,
    'custom_fields'   => json_encode(['link_id' => $lp_id, 'agencia' => $agencia]),
]);

error_log('[QPAYPRO TOKEN] status=' . $qpp_res['status'] . ' body=' . json_encode($qpp_res['body']));

if ($qpp_res['status'] >= 300 || ($qpp_res['body']['estado'] ?? '') !== 'success') {
    // Limpiar el registro pendiente
    sb_patch("links_pendientes?id=eq.$lp_id", ['estado' => 'Cancelado']);
    json_error('Error creando checkout en QPayPro: ' . json_encode($qpp_res['body']), 502);
}

$token        = $qpp_res['body']['data']['token'] ?? '';
$checkout_url = qpaypro_checkout_url($token);

// ── 3. Actualizar links_pendientes con token y URL definitivos ────────────────
sb_patch("links_pendientes?id=eq.$lp_id", [
    'checkout_id'  => $token,
    'checkout_url' => $checkout_url,
]);

// ── 4. Guardar reservación pendiente ──────────────────────────────────────────
$rv_data = [
    'nombre'                 => $nombre,
    'fecha_pago'             => null,
    'fecha_ascenso'          => $fecha,
    'tipo_cabana'            => $tipo_cabana,
    'no_personas'            => $no_personas,
    'precio'                 => $precio,
    'agencia'                => $agencia,
    'paquete'                => $paquete,
    'notas'                  => $notas,
    'alergias'               => $alergias,
    'es_vegano'              => $es_vegano,
    'es_vegetariano'         => $es_vegetariano,
    'cantidad_veganos'       => $cantidad_veganos,
    'cantidad_vegetarianos'  => $cantidad_vegetarianos,
    'es_cumpleanos'          => $es_cumpleanos,
    'telefono'               => $telefono,
    'identificacion'         => $identificacion,
    'correo'                 => $correo,
    'tipo_pago'              => 'QPayPro',
    'metodo_pago'            => 'Tarjeta',
    'estado_pago'            => 'Pendiente',
    'registrado_por'         => $generado_por,
    'link_pago'              => $checkout_url,
];
$rv_res = sb_post('reservaciones', $rv_data, false);
error_log('[CREAR LINK] SB insert reservaciones: http=' . $rv_res['status'] . ' body=' . json_encode($rv_res['body']));
if ($rv_res['status'] >= 300) {
    if (str_contains(json_encode($rv_res['body']), 'cantidad_veganos') ||
        str_contains(json_encode($rv_res['body']), 'cantidad_vegetarianos')) {
        unset($rv_data['cantidad_veganos'], $rv_data['cantidad_vegetarianos']);
        $rv_res2 = sb_post('reservaciones', $rv_data, false);
        error_log('[CREAR LINK] SB insert reservaciones (retry): http=' . $rv_res2['status'] . ' body=' . json_encode($rv_res2['body']));
    }
}

// ── 5. Sync Amelia (solo Wolfs) ───────────────────────────────────────────────
if (es_wolfs($agencia)) {
    $notas_extra = ["Creado por Link: " . ($generado_por ?: 'Admin')];
    if ($telefono)    $notas_extra[] = "Tel: $telefono";
    if ($identificacion) $notas_extra[] = "ID: $identificacion";
    if ($correo)      $notas_extra[] = "Email: $correo";
    if ($alergias)    $notas_extra[] = "Alergias: $alergias";
    if ($es_vegano)   $notas_extra[] = "Menu: Vegano";
    if ($es_vegetariano) $notas_extra[] = "Menu: Vegetariano";
    if ($es_cumpleanos)  $notas_extra[] = "Es Cumpleanos!";
    if ($notas)       $notas_extra[] = "Notas: $notas";
    enqueue_sync('create_manual', [
        'nombre'        => $nombre,
        'correo'        => $correo ?: 'manual@wolfsacatenango.com',
        'fecha_ascenso' => $fecha,
        'tipo_cabana'   => $tipo_cabana,
        'no_personas'   => $no_personas ?: 1,
        'precio'        => $precio,
        'estado'        => 'pending',
        'link_id'       => $token,
        'extra_info'    => implode(' | ', $notas_extra),
    ]);
}

json_response(['checkout_url' => $checkout_url, 'checkout_id' => $token]);
