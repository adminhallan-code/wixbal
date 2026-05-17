<?php
// GET /api/notificaciones
$res = sb_get("notificaciones?select=*&order=creado_en.desc&limit=60");
json_response($res['body'] ?? []);
