<?php
// POST /api/telegram-foto-cuadro
// Recibe la captura de pantalla del cuadro (desde html2canvas) y la envía al grupo de Telegram.

if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    json_error('Sin imagen válida', 400);
}
if (!defined('TELEGRAM_TOKEN') || !TELEGRAM_TOKEN) {
    json_error('Telegram no configurado en el servidor', 503);
}

$tmp   = $_FILES['foto']['tmp_name'];
$fecha = $_POST['fecha']         ?? gmdate('Y-m-d');
$nres  = (int)($_POST['reservaciones']   ?? 0);
$npers = (int)($_POST['total_personas']  ?? 0);

// Fecha legible
$meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
[$y,$m,$d] = explode('-', $fecha);
$fecha_leg = (int)$d . ' de ' . $meses[(int)$m - 1] . ' de ' . $y;

$caption =
    "📋 <b>Cuadro de ascenso — {$fecha_leg}</b>\n" .
    "👥 <b>{$npers} personas</b>  ·  {$nres} reservación(es)";

$result = telegram_send_photo($tmp, $caption);

if (isset($result['error'])) {
    json_error('Error enviando al grupo: ' . $result['error'], 502);
}

json_response(['ok' => true, 'message_id' => $result['message_id'] ?? null]);
