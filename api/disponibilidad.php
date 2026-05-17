<?php
// GET /disponibilidad/{fecha}
$fecha = $_route_fecha ?? '';
if (!fecha_valida($fecha)) json_error('Fecha inválida. Formato: YYYY-MM-DD');

$agencia = $_GET['agencia'] ?? '';

// Reservaciones del dia (no canceladas)
$res = sb_get("reservaciones?fecha_ascenso=eq.$fecha&estado_pago=neq.Cancelado&select=tipo_cabana,no_personas,agencia");
$reservas = $res['body'] ?? [];

// Links pendientes de pago del mismo dia
$lp = sb_get("links_pendientes?fecha_ascenso=eq.$fecha&estado=eq.Esperando%20pago&select=tipo_cabana,no_personas");
$pendientes = $lp['body'] ?? [];

$todas = array_merge($reservas, $pendientes);

$map = SERVICE_MAP;

$mixta_max    = $map['Mixta']['capacidad'];    // 22 personas
$privada_max  = $map['Privada']['capacidad'];  // 1 cabaña
$familiar_max = $map['Familiar']['capacidad']; // 1 cabaña

$mixta_usado    = 0;
$privada_usada  = 0;
$familiar_usada = 0;

foreach ($todas as $r) {
    $tipo = $r['tipo_cabana'] ?? '';
    $pers = (int) ($r['no_personas'] ?? 1);
    if ($tipo === 'Mixta')    $mixta_usado    += $pers;
    if ($tipo === 'Privada')  $privada_usada  += 1;
    if ($tipo === 'Familiar') $familiar_usada += 1;
}

json_response([
    'fecha'  => $fecha,
    'Mixta'  => [
        'capacidad' => $mixta_max,
        'ocupado'   => $mixta_usado,
        'libre'     => max(0, $mixta_max - $mixta_usado),
    ],
    'Privada' => [
        'capacidad' => $privada_max,
        'ocupado'   => $privada_usada,
        'libre'     => max(0, $privada_max - $privada_usada),
    ],
    'Familiar' => [
        'capacidad' => $familiar_max,
        'ocupado'   => $familiar_usada,
        'libre'     => max(0, $familiar_max - $familiar_usada),
    ],
]);
