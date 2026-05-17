<?php
// PATCH /api/reservaciones/{id}
$res_id = $_route_res_id ?? 0;
if (!$res_id) json_error('ID inválido', 400);

$data = get_body();
if (empty($data)) json_error('Sin datos para actualizar', 400);

$res = sb_patch("reservaciones?id=eq.$res_id", $data);
json_response(['success' => true]);
