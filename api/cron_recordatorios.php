<?php
// GET /api/cron/recordatorios
// Llamar cada hora desde cPanel → Cron Jobs:
//   curl -s https://tudominio.com/api/cron/recordatorios?secret=CRON_SECRET > /dev/null

// Clave secreta para que solo el cron lo pueda llamar
define('CRON_SECRET', 'WOLFS_CRON_2026');

$secret = $_GET['secret'] ?? '';
if ($secret !== CRON_SECRET) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$hoy = gmdate('Y-m-d', time() + GT_OFFSET * 3600);

// Obtener recordatorios activos cuya fecha ya venció
$res  = sb_get("reschedule_recordatorios?estado=eq.Activo&recordar_el=lte.$hoy&select=*");
$rows = $res['body'] ?? [];

$enviados = 0;
$omitidos = 0;

foreach ($rows as $rec) {
    // Solo notificar si ultimo_recordatorio es nulo o han pasado más de 3 días
    $ul = $rec['ultimo_recordatorio'] ?? null;
    if ($ul) {
        $ul_ts = strtotime($ul);
        $dias  = (time() - $ul_ts) / 86400;
        if ($dias < 3) {
            $omitidos++;
            continue;
        }
    }

    $rec_id     = $rec['id'];
    $nombre     = $rec['nombre']       ?? 'Cliente';
    $fecha_orig = $rec['fecha_original'] ?? '—';
    $paquete    = $rec['paquete']      ?? '—';
    $agencia    = $rec['agencia']      ?? '—';
    $tipo_cab   = $rec['tipo_cabana']  ?? '—';
    $notas      = $rec['notas']        ?? '';

    $notas_row = $notas
        ? "<tr style='background:#fef3c7;'><td style='padding:8px;color:#555;'><b>Notas</b></td><td style='padding:8px;'>$notas</td></tr>"
        : '';

    $html = "<div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;'>
      <div style='background:#92400e;padding:20px 24px;'>
        <h2 style='color:#fff;margin:0;'>Recordatorio: Reprogramación Pendiente</h2>
        <p style='color:rgba(255,255,255,0.8);margin:6px 0 0;font-size:14px;'>Hay una reprogramación sin confirmar que requiere seguimiento.</p>
      </div>
      <div style='padding:24px;'>
        <table style='width:100%;border-collapse:collapse;font-size:15px;'>
          <tr><td style='padding:8px 0;color:#555;width:140px;'><b>Cliente</b></td><td style='padding:8px 0;'>$nombre</td></tr>
          <tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'><b>Fecha original</b></td><td style='padding:8px;'>$fecha_orig</td></tr>
          <tr><td style='padding:8px 0;color:#555;'><b>Cabaña</b></td><td style='padding:8px 0;'>$tipo_cab</td></tr>
          <tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'><b>Paquete</b></td><td style='padding:8px;'>$paquete</td></tr>
          <tr><td style='padding:8px 0;color:#555;'><b>Agencia</b></td><td style='padding:8px 0;'>$agencia</td></tr>
          $notas_row
        </table>
        <div style='margin-top:20px;padding:14px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;'>
          <p style='margin:0;font-size:14px;color:#92400e;'>
            Contactá al cliente y gestioná la reprogramación en el sistema.
          </p>
        </div>
      </div>
      <div style='background:#f5f5f5;padding:12px 24px;font-size:12px;color:#999;text-align:center;'>
        Wolfs Acatenango · Sistema de Reservaciones · Recordatorio #$rec_id
      </div>
    </div>";

    enviar_email("Recordatorio reprogramación pendiente: $nombre (original: $fecha_orig)", $html);

    // Actualizar ultimo_recordatorio
    $ahora_utc = gmdate('Y-m-d\TH:i:s\Z');
    sb_patch("reschedule_recordatorios?id=eq.$rec_id", ['ultimo_recordatorio' => $ahora_utc]);

    $enviados++;
}

json_response([
    'ok'       => true,
    'fecha'    => $hoy,
    'enviados' => $enviados,
    'omitidos' => $omitidos,
]);
