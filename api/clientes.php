<?php
// GET /api/clientes              — lista con búsqueda
// GET /api/clientes/{id}         — detalle + historial de reservaciones

$cliente_id = $_route_cliente_id ?? 0;

// ── Detalle de un cliente ─────────────────────────────────────────────────────
if ($cliente_id) {
    $res = sb_get("clientes?id=eq.$cliente_id&select=*");
    $cliente = $res['body'][0] ?? null;
    if (!$cliente) json_error('Cliente no encontrado', 404);

    // Reservaciones del cliente
    $rv = sb_get(
        "reservaciones?cliente_id=eq.$cliente_id"
        . "&select=id,nombre,fecha_ascenso,tipo_cabana,precio,estado_pago,tipo_pago,paquete,no_personas,creado_at,link_pago,agencia"
        . "&order=fecha_ascenso.desc"
    );

    json_response([
        'cliente'       => $cliente,
        'reservaciones' => $rv['body'] ?? [],
    ]);
}

// ── Lista / búsqueda ──────────────────────────────────────────────────────────
$q      = trim($_GET['q']      ?? '');
$limit  = min((int)($_GET['limit']  ?? 50), 200);
$offset = (int)($_GET['offset'] ?? 0);

if ($q) {
    // Búsqueda por nombre, teléfono o correo
    $q_enc = urlencode("%$q%");
    $path  = "clientes?or=(nombre.ilike.$q_enc,telefono.ilike.$q_enc,correo.ilike.$q_enc)"
           . "&order=actualizado_at.desc&limit=$limit&offset=$offset&select=*";
} else {
    $path = "clientes?order=actualizado_at.desc&limit=$limit&offset=$offset&select=*";
}

$res      = sb_get($path);
$clientes = $res['body'] ?? [];

// Agregar conteo de reservaciones a cada cliente
if (!empty($clientes)) {
    $ids = implode(',', array_column($clientes, 'id'));
    $rv_count_res = sb_get(
        "reservaciones?cliente_id=in.($ids)&select=cliente_id"
    );
    $conteos = [];
    foreach ($rv_count_res['body'] ?? [] as $row) {
        $cid = $row['cliente_id'];
        $conteos[$cid] = ($conteos[$cid] ?? 0) + 1;
    }
    foreach ($clientes as &$c) {
        $c['total_reservaciones'] = $conteos[$c['id']] ?? 0;
    }
    unset($c);
}

json_response(['clientes' => $clientes, 'total' => count($clientes)]);
