<?php
// GET  /api/reschedule-recordatorios        → listar activos
// POST /api/reschedule-recordatorios        → crear
// POST /api/reschedule-recordatorios/{id}/extender
// POST /api/reschedule-recordatorios/{id}/resolver

$rec_id = isset($_route_rec_id) ? (int)$_route_rec_id : 0;
$action = $_route_rec_action ?? '';  // 'extender' | 'resolver' | ''

// POST .../extender
if ($method === 'POST' && $rec_id && $action === 'extender') {
    $data       = get_body();
    $nueva_fecha = $data['nueva_fecha'] ?? '';
    if (!fecha_valida($nueva_fecha)) json_error('nueva_fecha requerida (YYYY-MM-DD)', 400);
    sb_patch("reschedule_recordatorios?id=eq.$rec_id", ['recordar_el' => $nueva_fecha, 'ultimo_recordatorio' => null]);
    json_response(['extendido' => true]);
}

// POST .../resolver
if ($method === 'POST' && $rec_id && $action === 'resolver') {
    sb_patch("reschedule_recordatorios?id=eq.$rec_id", ['estado' => 'Resuelto']);
    json_response(['resuelto' => true]);
}

// POST /api/reschedule-recordatorios (crear)
if ($method === 'POST' && !$rec_id) {
    $data = get_body();
    $req_fields = ['reservacion_id', 'nombre', 'recordar_el'];
    foreach ($req_fields as $f) {
        if (empty($data[$f])) json_error("$f es requerido", 400);
    }
    if (!fecha_valida($data['recordar_el'])) json_error('recordar_el inválido (YYYY-MM-DD)', 400);

    $res = sb_post('reschedule_recordatorios', [
        'reservacion_id' => (int)$data['reservacion_id'],
        'nombre'         => $data['nombre'],
        'fecha_original' => $data['fecha_original'] ?? null,
        'agencia'        => $data['agencia']         ?? null,
        'paquete'        => $data['paquete']         ?? null,
        'tipo_cabana'    => $data['tipo_cabana']     ?? null,
        'notas'          => $data['notas']           ?? null,
        'recordar_el'    => $data['recordar_el'],
        'estado'         => 'Activo',
        'creado_por'     => $data['creado_por']      ?? null,
    ]);
    if ($res['status'] >= 300) json_error('Error guardando recordatorio: ' . json_encode($res['body']), 500);
    $row = $res['body'][0] ?? [];
    json_response(['id' => $row['id'] ?? null, 'estado' => 'Activo']);
}

// GET /api/reschedule-recordatorios
$res = sb_get("reschedule_recordatorios?estado=eq.Activo&select=*&order=recordar_el.asc");
json_response($res['body'] ?? []);
