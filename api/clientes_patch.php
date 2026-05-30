<?php
// PATCH /api/clientes/{id}  — actualizar notas_internas u otros campos editables

$cliente_id = $_route_cliente_id ?? 0;
if (!$cliente_id) json_error('ID de cliente requerido', 400);

$data = get_body();

// Solo campos editables manualmente
$allowed = ['notas_internas', 'nombre', 'telefono', 'correo', 'identificacion',
            'nit', 'tipo_identificacion', 'nombre_fiscal'];
$update  = [];
foreach ($allowed as $field) {
    if (array_key_exists($field, $data)) {
        $update[$field] = $data[$field];
    }
}
if (empty($update)) json_error('Sin campos válidos para actualizar', 400);

$update['actualizado_at'] = gmdate('Y-m-d\TH:i:s\Z');

$res = sb_patch("clientes?id=eq.$cliente_id", $update);
json_response(['ok' => true]);
