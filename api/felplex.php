<?php
// GET /api/felplex/validar/{tipo}/{codigo}
$tipo   = strtoupper($_route_fel_tipo   ?? '');
$codigo = $_route_fel_codigo ?? '';

if (!in_array($tipo, ['NIT', 'CUI', 'EXT'])) json_error('Tipo debe ser NIT, CUI o EXT', 400);
if (!$codigo) json_error('codigo requerido', 400);

$resultado = felplex_validar($tipo, $codigo);
json_response($resultado);
