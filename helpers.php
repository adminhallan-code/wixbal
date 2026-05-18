<?php
// helpers.php — Funciones compartidas: Supabase, Recurrente, Amelia, email

require_once __DIR__ . '/config.php';

// ── Supabase ──────────────────────────────────────────────────────────────────

function sb_headers(): array {
    return [
        'apikey: '        . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
    ];
}

function sb_get(string $path): array {
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => sb_headers(),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode($body, true) ?? []];
}

function sb_post(string $path, array $data, bool $return_rep = true): array {
    $headers = sb_headers();
    if ($return_rep) $headers[] = 'Prefer: return=representation';
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode($body, true) ?? []];
}

function sb_patch(string $path, array $data): array {
    $headers   = sb_headers();
    $headers[] = 'Prefer: return=minimal';
    $ch = curl_init(SUPABASE_URL . '/rest/v1/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PATCH',
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode($body, true) ?? []];
}

// ── Amelia bridge ────────────────────────────────────────────────────────────

function bridge_call(string $action, array $data, int $max_intentos = 3): array {
    $payload = array_merge(['secret' => AMELIA_SECRET, 'action' => $action], $data);
    foreach (WP_DOMAINS as $domain) {
        for ($intento = 1; $intento <= $max_intentos; $intento++) {
            $ch = curl_init($domain . '/amelia_bridge.php');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($payload),
                CURLOPT_TIMEOUT        => 20,
            ]);
            $body   = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status === 200) {
                $parsed = json_decode($body, true) ?? [];
                if (!isset($parsed['error'])) {
                    error_log("[AMELIA BRIDGE] OK $domain $action intento=$intento: $body");
                    return $parsed;
                }
                error_log("[AMELIA BRIDGE] Error $domain $action intento=$intento: $body");
                break; // respuesta válida con error lógico — no reintentar este dominio
            }
            error_log("[AMELIA BRIDGE] HTTP $status $domain $action intento=$intento");
            if ($intento < $max_intentos) sleep(1);
        }
    }
    return ['error' => 'Todos los dominios fallaron'];
}

// Encola en sync_queue e intenta ejecutar inmediatamente
function enqueue_sync(string $action, array $data): void {
    sb_post('sync_queue', [
        'action'   => $action,
        'payload'  => $data,
        'estado'   => 'pendiente',
        'intentos' => 0,
    ]);
    $result = bridge_call($action, $data);
    if (!isset($result['error'])) {
        // Marcar como completado en sync_queue (buscar el ultimo pendiente de esta accion)
        $rows = sb_get("sync_queue?action=eq.$action&estado=eq.pendiente&order=id.desc&limit=1");
        if (!empty($rows['body'])) {
            sb_patch('sync_queue?id=eq.' . $rows['body'][0]['id'], ['estado' => 'completado']);
        }
    }
}

// ── Recurrente ───────────────────────────────────────────────────────────────

function recurrente_post(string $path, array $data): array {
    $ch = curl_init(RECURRENTE_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'X-PUBLIC-KEY: '  . RECURRENTE_API_KEY,
            'X-SECRET-KEY: '  . RECURRENTE_SECRET,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode($body, true) ?? []];
}

function recurrente_delete(string $path): int {
    $ch = curl_init(RECURRENTE_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_HTTPHEADER     => [
            'X-PUBLIC-KEY: '  . RECURRENTE_API_KEY,
            'X-SECRET-KEY: '  . RECURRENTE_SECRET,
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $status;
}

function expirar_checkout(string $checkout_id): bool {
    if (!$checkout_id) return false;
    $r = recurrente_post("/checkouts/$checkout_id/expire", []);
    return $r['status'] < 300;
}

function borrar_producto(string $product_id): bool {
    if (!$product_id) return false;
    $status = recurrente_delete("/products/$product_id");
    return $status < 300;
}

// ── Email (Resend) ────────────────────────────────────────────────────────────

function enviar_email(string $asunto, string $html): void {
    if (!RESEND_API_KEY) return;
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'from'    => FROM_EMAIL,
            'to'      => [NOTIFY_EMAIL],
            'subject' => $asunto,
            'html'    => $html,
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── Utilidades ────────────────────────────────────────────────────────────────

function es_wolfs(string $agencia): bool {
    $a = strtolower(trim($agencia));
    foreach (AGENCIAS_WOLFS as $w) {
        if (str_contains($a, $w)) return true;
    }
    return false;
}

function fecha_valida(string $f): bool {
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && checkdate(
        (int) substr($f, 5, 2),
        (int) substr($f, 8, 2),
        (int) substr($f, 0, 4)
    );
}

function gt_date(): string {
    return gmdate('Y-m-d', time() + GT_OFFSET * 3600);
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $msg, int $status = 400): void {
    json_response(['error' => $msg], $status);
}

function get_body(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

// ── Disponibilidad ────────────────────────────────────────────────────────────

function get_disponibilidad(string $fecha, string $agencia = ''): array {
    $map = SERVICE_MAP;
    $res = sb_get("reservaciones?fecha_ascenso=eq.$fecha&estado_pago=neq.Cancelado&select=tipo_cabana,no_personas");
    $lp  = sb_get("links_pendientes?fecha_ascenso=eq.$fecha&estado=eq.Esperando%20pago&select=tipo_cabana,no_personas");
    $todas = array_merge($res['body'] ?? [], $lp['body'] ?? []);

    $mixta_usado = $privada_usada = $familiar_usada = 0;
    foreach ($todas as $r) {
        $tipo = $r['tipo_cabana'] ?? '';
        if ($tipo === 'Mixta')    $mixta_usado    += (int)($r['no_personas'] ?? 1);
        if ($tipo === 'Privada')  $privada_usada  += 1;
        if ($tipo === 'Familiar') $familiar_usada += 1;
    }
    return [
        'Mixta'    => ['capacidad' => $map['Mixta']['capacidad'],    'libre' => max(0, $map['Mixta']['capacidad']    - $mixta_usado)],
        'Privada'  => ['capacidad' => $map['Privada']['capacidad'],  'libre' => max(0, $map['Privada']['capacidad']  - $privada_usada)],
        'Familiar' => ['capacidad' => $map['Familiar']['capacidad'], 'libre' => max(0, $map['Familiar']['capacidad'] - $familiar_usada)],
    ];
}

// ── FELplex ───────────────────────────────────────────────────────────────────

define('FELPLEX_BASE', 'https://app.felplex.com');
define('FELPLEX_EMPRESA', '7107');

function felplex_validar(string $tipo, string $codigo): array {
    $key = FELPLEX_API_KEY ?: '';
    if (!$key) return ['valido' => false, 'nombre' => ''];
    $ch = curl_init(FELPLEX_BASE . "/api/entity/" . FELPLEX_EMPRESA . "/find/$tipo/$codigo");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["X-Authorization: $key", "Accept: application/json"],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) return ['valido' => false, 'nombre' => ''];
    $data = json_decode($body, true) ?? [];
    if (is_array($data) && isset($data[0])) {
        $item   = $data[0];
        $nombre = $item['name'] ?? $item['tax_name'] ?? $item['nombre'] ?? '';
        return ['valido' => true, 'nombre' => $nombre];
    }
    if (is_array($data) && !isset($data[0])) {
        $nombre = $data['name'] ?? $data['tax_name'] ?? $data['nombre'] ?? '';
        return ['valido' => (bool)$nombre, 'nombre' => $nombre];
    }
    return ['valido' => false, 'nombre' => ''];
}

function felplex_emitir_factura(int $reservacion_id, string $nombre, ?string $correo, float $precio,
    string $tipo_cabana, string $fecha_ascenso, ?string $nit = null,
    ?string $tipo_identificacion = null, ?string $nombre_fiscal = null): ?array
{
    $key = FELPLEX_API_KEY ?: '';
    if (!$key || $precio <= 0) return null;

    $total = round($precio, 2);
    $iva   = round($precio * 12 / 112, 2);

    $tipo_id = strtoupper($tipo_identificacion ?? 'CF');
    if ($tipo_id === 'CF' || !$nit) {
        $to_cf    = 1;
        $receptor = null;
    } elseif ($tipo_id === 'NIT') {
        $to_cf    = 0;
        $tax_type = 'NIT';
        $receptor = ['tax_code_type' => $tax_type, 'tax_code' => $nit, 'tax_name' => $nombre_fiscal ?: $nombre,
                     'address' => ['street' => 'Ciudad', 'city' => 'Guatemala', 'state' => 'GU', 'zip' => '01001', 'country' => 'GT']];
    } elseif ($tipo_id === 'CUI') {
        $to_cf    = 0;
        $receptor = ['tax_code_type' => 'DPI', 'tax_code' => $nit, 'tax_name' => $nombre_fiscal ?: $nombre,
                     'address' => ['street' => 'Ciudad', 'city' => 'Guatemala', 'state' => 'GU', 'zip' => '01001', 'country' => 'GT']];
    } else {
        $to_cf    = 0;
        $receptor = ['tax_code_type' => 'EXT', 'tax_code' => $nit, 'tax_name' => $nombre_fiscal ?: $nombre,
                     'address' => ['street' => 'Ciudad', 'city' => 'Guatemala', 'state' => 'GU', 'zip' => '01001', 'country' => 'GT']];
    }

    $descs = [
        'Privada'  => 'Cabaña Privada Exclusiva: Ascenso al Volcán Acatenango con servicio todo incluido: traslado de ida y vuelta, alimentación durante el recorrido, alojamiento en cabaña privada, acompañamiento de guías certificados e ingreso a los parques nacionales correspondientes.',
        'Mixta'    => 'Cabaña Mixta Compartida: Ascenso al Volcán Acatenango con servicio todo incluido: traslado de ida y vuelta, alimentación durante el recorrido, alojamiento en cabaña compartida, acompañamiento de guías certificados e ingreso a los parques nacionales correspondientes.',
        'Familiar' => 'Cabaña Familiar VIP+: Ascenso al Volcán Acatenango con servicio todo incluido: traslado de ida y vuelta, alimentación durante el recorrido, alojamiento en cabaña familiar, acompañamiento de guías certificados e ingreso a los parques nacionales correspondientes.',
    ];
    $desc = $descs[$tipo_cabana] ?? "Ascenso Volcán Acatenango — Cabaña $tipo_cabana";

    $gt_now = gmdate('Y-m-d\TH:i:s', time() + GT_OFFSET * 3600);
    $payload = [
        'type' => 'FACT', 'currency' => 'GTQ', 'datetime_issue' => $gt_now,
        'items' => [['qty' => 1, 'type' => 'S', 'price' => $total, 'description' => $desc,
                     'without_iva' => 0, 'discount' => 0, 'is_discount_percentage' => 0]],
        'total' => $total, 'total_tax' => $iva, 'to_cf' => $to_cf,
        'emails' => $correo ? [['email' => $correo]] : [],
    ];
    if ($receptor) $payload['to'] = $receptor;

    $ch = curl_init(FELPLEX_BASE . "/api/entity/" . FELPLEX_EMPRESA . "/invoices/await");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ["X-Authorization: $key", "Accept: application/json", "Content-Type: application/json"],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status >= 200 && $status < 300) {
        $data = json_decode($body, true) ?? [];
        if (!empty($data['valid'])) {
            $uuid = $data['uuid'] ?? '';
            $url  = $data['invoice_url'] ?? '';
            $sat  = $data['sat']['authorization'] ?? '';
            sb_patch("reservaciones?id=eq.$reservacion_id",
                ['factura_uuid' => $uuid, 'factura_url' => $url, 'factura_sat_autorizacion' => $sat]);
            return ['uuid' => $uuid, 'url' => $url];
        }
    }
    return null;
}

// ── Email de confirmación al cliente ─────────────────────────────────────────

function html_confirmacion_reserva(string $nombre, string $tipo_cabana, ?string $factura_url = null): string {
    $servicio = "Cabaña $tipo_cabana";
    $factura_block = $factura_url
        ? "<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\" style=\"margin-bottom:28px;\">
              <tr>
                <td align=\"center\" bgcolor=\"#ffffff\" style=\"background-color:#ffffff;padding:6px 0;\">
                  <table cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\" align=\"center\">
                    <tr>
                      <td bgcolor=\"#0a0a0a\" style=\"background-color:#0a0a0a;padding:12px 28px;\">
                        <a href=\"$factura_url\" style=\"font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:900;letter-spacing:2px;text-transform:uppercase;color:#ffffff;text-decoration:none;\">&#8659; &nbsp;Descargar Factura PDF</a>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>"
        : "<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\" style=\"margin-bottom:28px;\">
              <tr>
                <td bgcolor=\"#f5f5f5\" style=\"background-color:#f5f5f5;padding:14px 18px;border-left:3px solid #cccccc;\">
                  <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#777777;line-height:1.6;\">
                    <strong style=\"color:#333333;\">Nota sobre tu factura:</strong> La factura correspondiente a tu compra será enviada en un correo separado.
                  </div>
                </td>
              </tr>
            </table>";

    return "<!DOCTYPE html>
<html lang=\"es\"><head><meta charset=\"UTF-8\"></head>
<body style=\"margin:0;padding:0;\">
<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\" bgcolor=\"#f0f0f0\" style=\"background-color:#f0f0f0;margin:0;padding:0;\">
  <tr>
    <td align=\"center\" bgcolor=\"#f0f0f0\" style=\"background-color:#f0f0f0;padding:40px 20px;\">
      <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\" align=\"center\" style=\"max-width:600px;width:100%;\">
        <tr>
          <td align=\"center\" bgcolor=\"#0a0a0a\" style=\"background-color:#0a0a0a;padding:36px 40px 30px;text-align:center;\">
            <img src=\"https://wolfsacatenango.com/wp-content/uploads/2026/03/logo1-1-e1774859975322.png\" alt=\"Wolfs Acatenango\" width=\"auto\" height=\"70\" style=\"height:70px;width:auto;display:block;margin:0 auto 16px;border:0;\">
            <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:900;letter-spacing:5px;text-transform:uppercase;color:#ffffff;margin-bottom:8px;\">Wolfs Acatenango</div>
            <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:9px;font-weight:700;letter-spacing:3px;text-transform:uppercase;color:#666666;\">Átate las botas y síguenos</div>
          </td>
        </tr>
        <tr>
          <td align=\"center\" bgcolor=\"#ffffff\" style=\"background-color:#ffffff;padding:32px 40px 0;text-align:center;\">
            <table cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\" align=\"center\">
              <tr>
                <td bgcolor=\"#0a0a0a\" style=\"background-color:#0a0a0a;padding:8px 22px;\">
                  <span style=\"font-family:Arial,Helvetica,sans-serif;font-size:10px;font-weight:900;letter-spacing:3px;text-transform:uppercase;color:#ffffff;\">&#10003; &nbsp;Reserva Confirmada</span>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td bgcolor=\"#ffffff\" style=\"background-color:#ffffff;padding:28px 40px 36px;\">
            <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:26px;font-weight:900;color:#0a0a0a;margin-bottom:6px;line-height:1.3;\">Hola, $nombre</div>
            <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#888888;margin-bottom:28px;line-height:1.6;\">Tu expedición al volcán está agendada. Te esperamos.</div>
            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\" style=\"margin-bottom:24px;\">
              <tr>
                <td bgcolor=\"#0a0a0a\" style=\"background-color:#0a0a0a;padding:20px 28px 14px;border-bottom:1px solid #1e1e1e;\">
                  <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:9px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#666666;margin-bottom:6px;\">Servicio reservado</div>
                  <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#ffffff;\">$servicio</div>
                </td>
              </tr>
              <tr>
                <td bgcolor=\"#0a0a0a\" style=\"background-color:#0a0a0a;padding:14px 28px;border-bottom:1px solid #1e1e1e;\">
                  <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:9px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#666666;margin-bottom:6px;\">Punto de encuentro</div>
                  <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:#ffffff;\">Antigua Guatemala, 9 calle Oriente 12</div>
                </td>
              </tr>
              <tr>
                <td bgcolor=\"#0a0a0a\" style=\"background-color:#0a0a0a;padding:14px 28px 22px;\">
                  <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:9px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#666666;margin-bottom:8px;\">Hora de salida</div>
                  <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:30px;font-weight:900;color:#ffffff;letter-spacing:2px;\">07:15 <span style=\"font-size:15px;font-weight:700;letter-spacing:1px;color:#ffffff;\">A.M.</span></div>
                </td>
              </tr>
            </table>
            <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#555555;line-height:1.8;margin-bottom:20px;\">
              Hemos agendado tu cita exitosamente. Te estaremos esperando en el punto indicado a las <strong style=\"color:#0a0a0a;\">07:15 A.M.</strong> con todo listo para comenzar la expedición. Si tienes alguna duda antes de la fecha, no dudes en contactarnos.
            </div>
            $factura_block
            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\">
              <tr>
                <td bgcolor=\"#ffffff\" style=\"background-color:#ffffff;padding-top:20px;border-top:1px solid #eeeeee;\">
                  <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#888888;line-height:1.6;margin-bottom:4px;\">Gracias por escogernos,</div>
                  <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:13px;font-weight:900;letter-spacing:2px;text-transform:uppercase;color:#0a0a0a;\">Wolfs Acatenango</div>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td align=\"center\" bgcolor=\"#0a0a0a\" style=\"background-color:#0a0a0a;padding:24px 40px;text-align:center;\">
            <table cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\" align=\"center\" style=\"margin:0 auto 18px;\">
              <tr>
                <td bgcolor=\"#0a0a0a\" style=\"background-color:#0a0a0a;padding:0 4px;\">
                  <table cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\">
                    <tr><td bgcolor=\"#222222\" style=\"background-color:#222222;padding:7px 14px;\">
                      <a href=\"https://www.instagram.com/wolfs_acatenango/\" style=\"font-family:Arial,Helvetica,sans-serif;font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#aaaaaa;text-decoration:none;\">Instagram</a>
                    </td></tr>
                  </table>
                </td>
                <td bgcolor=\"#0a0a0a\" style=\"background-color:#0a0a0a;padding:0 4px;\">
                  <table cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\">
                    <tr><td bgcolor=\"#222222\" style=\"background-color:#222222;padding:7px 14px;\">
                      <a href=\"https://www.facebook.com/profile.php?id=61573388570575\" style=\"font-family:Arial,Helvetica,sans-serif;font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#aaaaaa;text-decoration:none;\">Facebook</a>
                    </td></tr>
                  </table>
                </td>
                <td bgcolor=\"#0a0a0a\" style=\"background-color:#0a0a0a;padding:0 4px;\">
                  <table cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\">
                    <tr><td bgcolor=\"#222222\" style=\"background-color:#222222;padding:7px 14px;\">
                      <a href=\"https://wa.me/50254960705\" style=\"font-family:Arial,Helvetica,sans-serif;font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#aaaaaa;text-decoration:none;\">WhatsApp</a>
                    </td></tr>
                  </table>
                </td>
              </tr>
            </table>
            <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:11px;font-weight:700;color:#555555;letter-spacing:1px;margin-bottom:6px;\">+502 5496-0705</div>
            <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#444444;letter-spacing:0.5px;\">&#169; 2026 Wolfs Acatenango &#8212; Todos los derechos reservados.</div>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body></html>";
}

function html_notif_pago_equipo(string $nombre, string $tipo_cabana, string $fecha_ascenso,
    float $precio, ?int $no_personas = null, ?string $registrado_por = null): string
{
    $personas_str = $no_personas ? "$no_personas persona(s)" : '—';
    $por_str      = $registrado_por ?: 'Sistema';
    $precio_fmt   = number_format($precio, 2);
    return "<div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;'>
      <div style='background:#2c7a4b;padding:20px 24px;'>
        <h2 style='color:#fff;margin:0;'>✅ Pago Completado</h2>
        <p style='color:#d4f0e0;margin:6px 0 0;font-size:14px;'>Nueva reservación confirmada</p>
      </div>
      <div style='padding:24px;'>
        <table style='width:100%;border-collapse:collapse;font-size:15px;'>
          <tr><td style='padding:8px 0;color:#555;width:160px;'><b>Cliente</b></td><td style='padding:8px 0;'>$nombre</td></tr>
          <tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'><b>Fecha de ascenso</b></td><td style='padding:8px;font-weight:bold;color:#2c7a4b;'>$fecha_ascenso</td></tr>
          <tr><td style='padding:8px 0;color:#555;'><b>Cabaña</b></td><td style='padding:8px 0;'>$tipo_cabana</td></tr>
          <tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'><b>Personas</b></td><td style='padding:8px;'>$personas_str</td></tr>
          <tr><td style='padding:8px 0;color:#555;'><b>Monto</b></td><td style='padding:8px 0;font-weight:bold;'>Q$precio_fmt</td></tr>
          <tr style='background:#f9f9f9;'><td style='padding:8px;color:#555;'><b>Registrado por</b></td><td style='padding:8px;'>$por_str</td></tr>
        </table>
      </div>
      <div style='background:#f5f5f5;padding:12px 24px;font-size:12px;color:#999;text-align:center;'>
        Wolfs Acatenango · Sistema de Reservaciones
      </div>
    </div>";
}

function enviar_confirmacion_cliente(string $correo, string $nombre, string $tipo_cabana, ?string $factura_url = null): void {
    if (!$correo || !RESEND_API_KEY) return;
    $html = html_confirmacion_reserva($nombre, $tipo_cabana, $factura_url);
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'from'    => FROM_EMAIL,
            'to'      => [$correo],
            'subject' => '✅ Reserva Confirmada — Wolfs Acatenango',
            'html'    => $html,
        ]),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT    => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
