<?php
// GET /api/reservaciones
// Soporta filtros por query string: fecha_ascenso, estado_pago, agencia, tipo_cabana
$filters = [];

$fecha  = $_GET['fecha_ascenso'] ?? '';
$estado = $_GET['estado_pago']   ?? '';
$ag     = $_GET['agencia']       ?? '';
$tipo   = $_GET['tipo_cabana']   ?? '';
$orden  = $_GET['order']         ?? 'fecha_ascenso.asc';
$limite = min((int)($_GET['limit'] ?? 200), 500);

if ($fecha)  $filters[] = "fecha_ascenso=eq.$fecha";
if ($estado) $filters[] = "estado_pago=eq." . urlencode($estado);
if ($ag)     $filters[] = "agencia=eq."     . urlencode($ag);
if ($tipo)   $filters[] = "tipo_cabana=eq." . urlencode($tipo);

$qs = implode('&', array_merge(
    $filters,
    ["order=$orden", "limit=$limite",
     "select=id,nombre,correo,fecha_ascenso,tipo_cabana,no_personas,precio,estado_pago,metodo_pago,agencia,link_pago,notas,registrado_por,creado_at"]
));

$res = sb_get("reservaciones?$qs");
json_response($res['body'] ?? []);
