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
    $curl_err = curl_error($ch);
    curl_close($ch);
    if ($curl_err) {
        error_log("[SUPABASE GET] curl_error=$curl_err path=$path");
    }
    if ($status >= 400) {
        error_log("[SUPABASE GET] HTTP $status path=$path body=$body");
    }
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

// ── QPayPro ───────────────────────────────────────────────────────────────────

/**
 * Registra una transacción en QPayPro (Hosted Page) y devuelve el token.
 * Respuesta exitosa: ['estado' => 'success', 'data' => ['token' => 'XXXX']]
 */
function qpaypro_register_token(array $data): array {
    $ch = curl_init(QPAYPRO_BASE . '/register_transaction_store');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode($body, true) ?? []];
}

/**
 * Verifica el x_MD5_Hash que QPayPro envía en el relay URL.
 * Fórmula estándar compatible con Authorize.Net:
 *   MD5(api_secret + x_login + x_trans_id + x_amount)
 */
function qpaypro_verificar_hash(string $trans_id, string $amount, string $hash_recibido): bool {
    if (!$hash_recibido) return false;
    // Fórmula confirmada: MD5(QPAYPRO_KEY + QPAYPRO_LOGIN + trans_id + amount)
    $esperado = md5(QPAYPRO_KEY . QPAYPRO_LOGIN . $trans_id . $amount);
    return hash_equals($esperado, strtolower($hash_recibido));
}

/**
 * Construye la URL de checkout QPayPro a partir del token.
 */
function qpaypro_checkout_url(string $token): string {
    return QPAYPRO_BASE . '/store?token=' . $token;
}

// ── Telegram ─────────────────────────────────────────────────────────────────

/**
 * Envía un mensaje a un chat de Telegram.
 * $chat_id opcional: si se omite usa TELEGRAM_CHAT_ID (grupo de cuadros).
 */
function telegram_notify(string $msg, string $chat_id = ''): void {
    if (!defined('TELEGRAM_TOKEN') || !TELEGRAM_TOKEN) return;
    $cid = $chat_id ?: (defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : '');
    if (!$cid) return;
    $url = 'https://api.telegram.org/bot' . TELEGRAM_TOKEN . '/sendMessage';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'chat_id'    => $cid,
            'text'       => $msg,
            'parse_mode' => 'HTML',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT    => 5,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) error_log("[TELEGRAM] curl_error=$err");
    else {
        $body = json_decode($res, true);
        if (!($body['ok'] ?? false)) error_log('[TELEGRAM] Error: ' . $res);
    }
}

/** Envía al grupo de reservaciones (TELEGRAM_NOTIF_ID). */
function telegram_notif_res(string $msg): void {
    if (!defined('TELEGRAM_NOTIF_ID') || !TELEGRAM_NOTIF_ID) return;
    telegram_notify($msg, TELEGRAM_NOTIF_ID);
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

    // IMPORTANTE: usar or=(estado_pago.neq.Cancelado,estado_pago.is.null)
    // porque en PostgreSQL "!= 'Cancelado'" NO incluye filas con NULL — se perderían.
    $res = sb_get("reservaciones?fecha_ascenso=eq.$fecha&or=(estado_pago.neq.Cancelado,estado_pago.is.null)&select=tipo_cabana,no_personas");
    $lp  = sb_get("links_pendientes?fecha_ascenso=eq.$fecha&estado=eq.Esperando%20pago&select=tipo_cabana,no_personas");
    $todas = array_merge($res['body'] ?? [], $lp['body'] ?? []);

    $mixta_usado = $privada_usada = $familiar_usada = 0;
    foreach ($todas as $r) {
        // ucfirst+strtolower para tolerar variaciones de mayúsculas en la BD
        $tipo = ucfirst(strtolower(trim($r['tipo_cabana'] ?? '')));
        if ($tipo === 'Mixta')    $mixta_usado    += max(1, (int)($r['no_personas'] ?? 1));
        if ($tipo === 'Privada')  $privada_usada  += 1;
        if ($tipo === 'Familiar') $familiar_usada += 1;
    }
    return [
        'Mixta'    => ['capacidad' => $map['Mixta']['capacidad'],    'libre' => max(0, $map['Mixta']['capacidad']    - $mixta_usado)],
        'Privada'  => ['capacidad' => $map['Privada']['capacidad'],  'libre' => max(0, $map['Privada']['capacidad']  - $privada_usada)],
        'Familiar' => ['capacidad' => $map['Familiar']['capacidad'], 'libre' => max(0, $map['Familiar']['capacidad'] - $familiar_usada)],
    ];
}

// ── Clientes ─────────────────────────────────────────────────────────────────

/**
 * Busca un cliente existente por teléfono o correo.
 * Si lo encuentra lo actualiza, si no lo crea.
 * Devuelve el cliente_id (int) o null si falla.
 */
function upsert_cliente(string $nombre, ?string $telefono, ?string $correo, array $extra = []): ?int {
    $cliente = null;

    // Buscar por teléfono primero
    if ($telefono) {
        $res = sb_get("clientes?telefono=eq." . urlencode($telefono) . "&limit=1&select=*");
        $cliente = $res['body'][0] ?? null;
    }
    // Buscar por correo si no encontró por teléfono
    if (!$cliente && $correo) {
        $res = sb_get("clientes?correo=eq." . urlencode($correo) . "&limit=1&select=*");
        $cliente = $res['body'][0] ?? null;
    }

    $data = [
        'nombre'              => $nombre,
        'actualizado_at'      => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    if ($telefono)                          $data['telefono']            = $telefono;
    if ($correo)                            $data['correo']              = $correo;
    if ($extra['identificacion'] ?? null)   $data['identificacion']      = $extra['identificacion'];
    if ($extra['nit'] ?? null)              $data['nit']                 = $extra['nit'];
    if ($extra['tipo_identificacion'] ?? null) $data['tipo_identificacion'] = $extra['tipo_identificacion'];
    if ($extra['nombre_fiscal'] ?? null)    $data['nombre_fiscal']       = $extra['nombre_fiscal'];

    if ($cliente) {
        sb_patch("clientes?id=eq.{$cliente['id']}", $data);
        return (int) $cliente['id'];
    } else {
        $insert = sb_post('clientes', $data, true);
        $id = $insert['body'][0]['id'] ?? null;
        return $id ? (int) $id : null;
    }
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
    ?string $tipo_identificacion = null, ?string $nombre_fiscal = null, ?string $paquete = null): ?array
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
    } elseif ($tipo_id === 'PASAPORTE') {
        $to_cf    = 0;
        $receptor = ['tax_code_type' => 'PASAPORTE', 'tax_code' => $nit, 'tax_name' => $nombre_fiscal ?: $nombre,
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
    if (strtolower($paquete ?? '') === '4x4') {
        $desc .= ' Incluye servicio de transporte 4x4.';
    }

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

function html_confirmacion_reserva(string $nombre, string $tipo_cabana, ?string $factura_url = null, bool $omitir_nota = false): string {
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
        : ($omitir_nota ? '' : "<table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\" style=\"margin-bottom:28px;\">
              <tr>
                <td bgcolor=\"#f5f5f5\" style=\"background-color:#f5f5f5;padding:14px 18px;border-left:3px solid #cccccc;\">
                  <div style=\"font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#777777;line-height:1.6;\">
                    <strong style=\"color:#333333;\">Nota sobre tu factura:</strong> La factura correspondiente a tu compra será enviada en un correo separado.
                  </div>
                </td>
              </tr>
            </table>");

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

// ── Telegram: enviar foto ─────────────────────────────────────────────────────

/**
 * Envía un archivo de imagen PNG al grupo de Telegram y lo fija (notifica a todos).
 * Devuelve ['ok'=>true,'message_id'=>N] o ['error'=>'...'].
 */
function telegram_send_photo(string $img_path, string $caption): array {
    if (!defined('TELEGRAM_TOKEN') || !TELEGRAM_TOKEN) return ['error' => 'no configurado'];
    if (!defined('TELEGRAM_CHAT_ID') || !TELEGRAM_CHAT_ID) return ['error' => 'no configurado'];

    $url   = 'https://api.telegram.org/bot' . TELEGRAM_TOKEN . '/sendPhoto';
    $cfile = new CURLFile($img_path, 'image/png', 'cuadro.png');
    $ch    = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'chat_id'    => TELEGRAM_CHAT_ID,
            'photo'      => $cfile,
            'caption'    => $caption,
            'parse_mode' => 'HTML',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) { error_log("[TELEGRAM FOTO] curl: $err"); return ['error' => $err]; }
    $body = json_decode($res, true);
    if (!($body['ok'] ?? false)) {
        error_log('[TELEGRAM FOTO] ' . $res);
        return ['error' => $body['description'] ?? 'unknown'];
    }
    $msg_id = $body['result']['message_id'] ?? null;

    return ['ok' => true, 'message_id' => $msg_id];
}

/**
 * Genera una imagen PNG del cuadro de ascenso usando PHP GD.
 * Devuelve el path del archivo temporal generado.
 */
function generar_cuadro_png(string $fecha_str, string $fecha_leg, array $rows, array $totales): string {
    $W     = 960;
    $row_h = 32;
    $n     = count($rows);
    $H     = 72 + 62 + 34 + ($n * $row_h) + 36;

    $img = imagecreatetruecolor($W, $H);

    $c = [
        'bg'      => imagecolorallocate($img, 247, 247, 247),
        'navy'    => imagecolorallocate($img,  26,  26,  46),
        'white'   => imagecolorallocate($img, 255, 255, 255),
        'lgray'   => imagecolorallocate($img, 250, 250, 250),
        'border'  => imagecolorallocate($img, 200, 200, 200),
        'black'   => imagecolorallocate($img,   0,   0,   0),
        'text'    => imagecolorallocate($img,  17,  17,  17),
        'text2'   => imagecolorallocate($img,  85,  85,  85),
        'text3'   => imagecolorallocate($img, 136, 136, 136),
        'mix_bg'  => imagecolorallocate($img, 219, 234, 254),
        'mix_t'   => imagecolorallocate($img,  30,  64, 175),
        'pri_bg'  => imagecolorallocate($img, 252, 231, 243),
        'pri_t'   => imagecolorallocate($img, 157,  23,  77),
        'fam_bg'  => imagecolorallocate($img, 209, 250, 229),
        'fam_t'   => imagecolorallocate($img,   6,  95,  70),
        'warn_bg' => imagecolorallocate($img, 254, 243, 199),
        'warn_t'  => imagecolorallocate($img, 146,  64,  14),
        'ok_t'    => imagecolorallocate($img,   6,  95,  70),
        'menu_t'  => imagecolorallocate($img,  22, 101,  52),
        'nav_sub' => imagecolorallocate($img, 160, 160, 200),
    ];

    imagefilledrectangle($img, 0, 0, $W-1, $H-1, $c['bg']);

    // Buscar fuente TTF para mejor calidad visual
    $ttf = null;
    foreach ([
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/TTF/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    ] as $p) { if (file_exists($p)) { $ttf = $p; break; } }

    $txt = function(int $x, int $y, string $s, $col, int $sz = 11) use ($img, $ttf) {
        if ($ttf && function_exists('imagettftext')) {
            imagettftext($img, $sz, 0, $x, $y + $sz, $col, $ttf, $s);
        } else {
            $f = $sz >= 14 ? 5 : ($sz >= 11 ? 4 : ($sz >= 9 ? 3 : 2));
            imagestring($img, $f, $x, $y, $s, $col);
        }
    };

    // Encabezado
    imagefilledrectangle($img, 0, 0, $W-1, 71, $c['navy']);
    $txt(20, 12, "Wolfs Acatenango — Ascenso del {$fecha_leg}", $c['white'], 15);
    $txt(20, 40, count($rows) . " reservaciones  |  generado " . gmdate('d/m/Y H:i') . " GT", $c['nav_sub'], 10);

    // Estadísticas
    $total_g = array_sum($totales);
    $sw = 210; $sh = 48; $sg = 10;
    $stx = (int)(($W - (4*$sw + 3*$sg)) / 2);
    $sy  = 82;
    foreach ([
        ['Mixta',    $totales['Mixta'],    'mix_bg', 'mix_t'],
        ['Privada',  $totales['Privada'],  'pri_bg', 'pri_t'],
        ['Familiar', $totales['Familiar'], 'fam_bg', 'fam_t'],
        ['TOTAL',    $total_g,             'navy',   'white'],
    ] as $i => [$lbl, $val, $bk, $tk]) {
        $sx = $stx + $i*($sw+$sg);
        imagefilledrectangle($img, $sx, $sy, $sx+$sw-1, $sy+$sh-1, $c[$bk]);
        imagerectangle($img, $sx, $sy, $sx+$sw-1, $sy+$sh-1, $c['border']);
        $txt($sx+10, $sy+4,  (string)$val, $c[$tk], 16);
        $txt($sx+10, $sy+28, $lbl,         $c[$tk], 10);
    }

    // Cabecera tabla
    $thy = 144;
    imagefilledrectangle($img, 0, $thy, $W-1, $thy+33, $c['navy']);
    // anchos: 32+185+78+48+72+112+92+136+205 = 960
    $col_defs = [
        ['#',32],['NOMBRE',185],['CABANA',78],['PERS',48],
        ['PAQUETE',72],['AGENCIA',112],['ESTADO',92],['NOTAS',136],['ALERGIA/MENU',205],
    ];
    $cx = 0;
    foreach ($col_defs as [$lbl, $cw]) {
        imageline($img, $cx, $thy, $cx, $thy+33, $c['black']);
        $txt($cx+4, $thy+9, $lbl, $c['white'], 9);
        $cx += $cw;
    }
    imageline($img, $W-1, $thy, $W-1, $thy+33, $c['black']);

    // Filas
    $ry = $thy + 34;
    foreach ($rows as $idx => $r) {
        $rbg = ($idx % 2 === 0) ? $c['white'] : $c['lgray'];
        $cab = $r['tipo_cabana'] ?? '';
        $np  = (int)($r['no_personas'] ?? 1);
        if ($cab === 'Privada'  && $np <= 1) $np = 2;
        if ($cab === 'Familiar' && $np <= 1) $np = 4;

        $nVgn  = (int)($r['cantidad_veganos'] ?? 0) + ($r['es_vegano'] ? 1 : 0);
        $nVeg  = (int)($r['cantidad_vegetarianos'] ?? 0) + ($r['es_vegetariano'] ? 1 : 0);
        $cumple = $r['es_cumpleanos'] ?? false;
        $mp    = array_filter([
            $nVgn   ? "Vegano x{$nVgn}"  : '',
            $nVeg   ? "Veg x{$nVeg}"     : '',
            $cumple ? 'Cumple!'           : '',
        ]);
        $aler  = $r['alergias'] ?? '';
        $am_bg = $aler ? $c['warn_bg'] : $rbg;
        $am_t  = $aler ? $c['warn_t']  : $c['menu_t'];
        $am    = $aler ? mb_substr("ALR: {$aler}", 0, 26) : mb_substr(implode(' ', $mp), 0, 26);
        $est   = $r['estado_pago'] ?? '';

        if ($cab === 'Mixta')      { $cbg = $c['mix_bg']; $ct = $c['mix_t']; }
        elseif ($cab === 'Privada') { $cbg = $c['pri_bg']; $ct = $c['pri_t']; }
        else                       { $cbg = $c['fam_bg']; $ct = $c['fam_t']; }

        $cells = [
            [($idx+1),                              32,  $rbg,   $c['text3']],
            [mb_substr($r['nombre']??'—',0,23),    185, $rbg,   $c['text']],
            [mb_substr($cab,0,9),                   78,  $cbg,   $ct],
            [(string)$np,                           48,  $rbg,   $c['text']],
            [mb_substr($r['paquete']??'—',0,8),    72,  $rbg,   $c['text2']],
            [mb_substr($r['agencia']??'—',0,14),   112, $rbg,   $c['text2']],
            [mb_substr($est,0,12),                  92,  $rbg,   ($est==='Completado'?$c['ok_t']:$c['warn_t'])],
            [mb_substr($r['notas']??'—',0,17),     136, $rbg,   $c['text2']],
            [$am ?: '—',                            205, $am_bg, $am_t],
        ];

        $cx = 0;
        foreach ($cells as [$val, $cw, $cbg2, $ct2]) {
            imagefilledrectangle($img, $cx, $ry, $cx+$cw-1, $ry+$row_h-1, $cbg2);
            imageline($img, $cx,    $ry,          $cx,       $ry+$row_h-1, $c['border']);
            imageline($img, $cx,    $ry+$row_h-1, $cx+$cw-1, $ry+$row_h-1, $c['border']);
            $txt($cx+4, $ry+8, (string)$val, $ct2, 10);
            $cx += $cw;
        }
        imageline($img, $W-1, $ry, $W-1, $ry+$row_h-1, $c['border']);
        $ry += $row_h;
    }
    imageline($img, 0, $ry, $W-1, $ry, $c['border']);

    // Pie
    $txt(20, $ry+10, "Wolfs Acatenango · Sistema de Reservaciones · " . gmdate('d/m/Y H:i') . " GT", $c['text3'], 9);

    $path = sys_get_temp_dir() . '/cuadro_' . $fecha_str . '_' . time() . '.png';
    imagepng($img, $path, 6);
    imagedestroy($img);
    return $path;
}
