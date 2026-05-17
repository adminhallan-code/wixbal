<?php
// GET /api/links-pendientes
$estado = $_GET['estado'] ?? '';
$qs = $estado ? "estado=eq." . urlencode($estado) . "&" : "";
$res = sb_get($qs . "links_pendientes?order=id.desc&limit=100&select=id,nombre,fecha_ascenso,tipo_cabana,precio,estado,agencia,checkout_url,creado_at,generado_por");
json_response($res['body'] ?? []);
