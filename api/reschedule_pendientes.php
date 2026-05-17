<?php
// GET /api/reschedule-pendientes
$res = sb_get("reschedule_pendientes?order=id.desc&limit=100&select=*");
json_response($res['body'] ?? []);
