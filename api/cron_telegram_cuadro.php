<?php
// GET /api/cron/telegram-cuadro[?fecha=YYYY-MM-DD]
// Genera imagen PNG del cuadro y la envía al grupo de Telegram.
// Sin parámetro: envía el cuadro de mañana (usado por cron).
// Con ?fecha=: envía el cuadro de la fecha indicada (usado por el botón manual).

$manana   = gmdate('Y-m-d', time() + (-6 * 3600) + 86400);
$fecha    = $_GET['fecha'] ?? $manana;

if (!fecha_valida($fecha)) json_error('Fecha inválida', 400);

$res  = sb_get(
    "reservaciones?fecha_ascenso=eq.{$fecha}&estado_pago=neq.Cancelado"
    . "&select=nombre,tipo_cabana,no_personas,paquete,agencia,alergias,notas,"
    . "es_vegano,es_vegetariano,cantidad_veganos,cantidad_vegetarianos,es_cumpleanos,estado_pago"
    . "&order=tipo_cabana.asc"
);
$rows = $res['body'] ?? [];

// Totales con defaults Privada=2, Familiar=4
$totales = ['Mixta' => 0, 'Privada' => 0, 'Familiar' => 0];
foreach ($rows as $r) {
    $cab = $r['tipo_cabana'] ?? '';
    $n   = (int)($r['no_personas'] ?? 1);
    if ($cab === 'Privada'  && $n <= 1) $n = 2;
    if ($cab === 'Familiar' && $n <= 1) $n = 4;
    $totales[$cab] = ($totales[$cab] ?? 0) + $n;
}
$total_g = array_sum($totales);

// Fecha legible
$meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
[$y,$m,$d] = explode('-', $fecha);
$fecha_leg = (int)$d . ' de ' . $meses[(int)$m - 1] . ' de ' . $y;

if (empty($rows)) {
    // Sin reservaciones: aviso corto de texto
    telegram_notify("📋 <b>Cuadro de ascenso — {$fecha_leg}</b>\n\nSin reservaciones para este día.");
    json_response(['ok' => true, 'fecha' => $fecha, 'reservaciones' => 0, 'aviso' => 'sin reservaciones, mensaje de texto enviado']);
}

// Generar imagen PNG
$img_path = generar_cuadro_png($fecha, $fecha_leg, $rows, $totales);

// Caption del mensaje
$caption =
    "📋 <b>Cuadro de ascenso — {$fecha_leg}</b>\n" .
    "👥 <b>{$total_g} personas</b>  ·  " .
    "Mixta: {$totales['Mixta']}  ·  Privada: {$totales['Privada']}  ·  Familiar: {$totales['Familiar']}\n" .
    "📌 " . count($rows) . " reservacion(es)";

$result = telegram_send_photo($img_path, $caption);

@unlink($img_path); // limpiar temp

if (isset($result['error'])) {
    json_error('Error enviando foto: ' . $result['error'], 502);
}

json_response([
    'ok'            => true,
    'fecha'         => $fecha,
    'reservaciones' => count($rows),
    'total_personas'=> $total_g,
    'message_id'    => $result['message_id'] ?? null,
]);
