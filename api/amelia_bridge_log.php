<?php
// GET /api/amelia-bridge-log
$res = bridge_call('get_log', []);
json_response($res);
