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

$monto_centavos = (int) round($precio * 100);

// ── 1. Crear producto en Recurrente ──────────────────────────────────────────
$prod_res = recurrente_post('/products', ['product' => [
    'name'        => $nombre,
    'description' => $descripcion,
    'prices_attributes' => [[
        'amount_in_cents' => $monto_centavos,
        'currency'        => 'GTQ',
        'charge_type'     => 'one_time',
    ]],
    'adjustable_quantity'      => false,
    'billing_info_requirement' => 'required',
    'phone_requirement'        => 'required',
    'success_url' => 'https://wolfsacatenango.com',
    'cancel_url'  => 'https://wolfsacatenango.com',
]]);
if ($prod_res['status'] >= 300) json_error('Error creando producto en Recurrente: ' . json_encode($prod_res['body']), 502);
$product_id = $prod_res['body']['id'] ?? '';

// ── 2. Crear checkout ─────────────────────────────────────────────────────────
$expires_at = gmdate('Y-m-d\TH:i:s\Z', time() + 6 * 3600);
$co_res = recurrente_post('/checkouts', [
    'items'       => [['product_id' => $product_id, 'quantity' => 1]],
    'expires_at'  => $expires_at,
    'success_url' => 'https://wolfsacatenango.com',
    'cancel_url'  => 'https://wolfsacatenango.com',
]);
if ($co_res['status'] >= 300) json_error('Error de Recurrente: ' . json_encode($co_res['body']), 502);
$checkout_url = $co_res['body']['checkout_url'] ?? $co_res['body']['url'] ?? '';
$checkout_id  = $co_res['body']['id'] ?? '';
if (!$checkout_url) json_error('Recurrente no devolvió URL', 500);

// ── 3. Guardar en links_pendientes ────────────────────────────────────────────
sb_post('links_pendientes', [
    'checkout_id'          => $checkout_id,
    'checkout_url'         => $checkout_url,
    'product_id'           => $product_id,
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
], false);

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
    'tipo_pago'              => 'Recurrente',
    'metodo_pago'            => 'Tarjeta',
    'estado_pago'            => 'Pendiente',
    'registrado_por'         => $generado_por,
    'link_pago'              => $checkout_url,
];
$rv_res = sb_post('reservaciones', $rv_data, false);
if ($rv_res['status'] >= 300 && (
    str_contains(json_encode($rv_res['body']), 'cantidad_veganos') ||
    str_contains(json_encode($rv_res['body']), 'cantidad_vegetarianos')
)) {
    unset($rv_data['cantidad_veganos'], $rv_data['cantidad_vegetarianos']);
    sb_post('reservaciones', $rv_data, false);
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
        'link_id'       => $checkout_id,
        'extra_info'    => implode(' | ', $notas_extra),
    ]);
}

json_response(['checkout_url' => $checkout_url, 'checkout_id' => $checkout_id]);
