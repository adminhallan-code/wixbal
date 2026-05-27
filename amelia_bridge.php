<?php
// amelia_bridge.php
// Subir a la carpeta raíz de WordPress (public_html) donde está wp-config.php

require_once('wp-load.php');
global $wpdb;

header('Content-Type: application/json');

function log_msg($msg) {
    $log_file = __DIR__ . '/amelia_bridge.log';
    $time = current_time('mysql');
    file_put_contents($log_file, "[$time] $msg\n", FILE_APPEND);
}

// Clave secreta para que nadie más pueda usar este archivo
$secret = 'WOLFS_RESERVACIONES_SECRET_2026';
if (!isset($_POST['secret']) || $_POST['secret'] !== $secret) {
    log_msg("ERROR: Intento de acceso no autorizado.");
    http_response_code(403);
    die(json_encode(['error' => 'No autorizado']));
}

$action = $_POST['action'] ?? '';
log_msg("INFO: Petición recibida. Acción: $action");

// Mapeo de cabañas según la BD de Hostinger
$service_map = [
    'Mixta'    => ['serviceId' => 1, 'providerId' => 6],
    'Privada'  => ['serviceId' => 3, 'providerId' => 7],
    'Familiar' => ['serviceId' => 4, 'providerId' => 3],
];

// Helper: genera token aleatorio de 10 chars como Amelia
function amelia_token() {
    return bin2hex(random_bytes(5));
}

/**
 * Inserta en amelia_customer_bookings con fallback por columnas opcionales.
 * Intenta primero con 'created'; si falla por esa columna, reintenta sin ella.
 * Devuelve el insert_id resultante (0 si ambos fallaron).
 */
function amelia_insert_booking($wpdb, $data) {
    // Intento 1: con 'created'
    $wpdb->insert("{$wpdb->prefix}amelia_customer_bookings", $data);
    if (!$wpdb->last_error) {
        return $wpdb->insert_id;
    }
    $err1 = $wpdb->last_error;
    // Si el error menciona 'created', reintentar sin esa columna
    if (stripos($err1, 'created') !== false || stripos($err1, 'Unknown column') !== false) {
        log_msg("WARN: amelia_customer_bookings con 'created' falló ($err1) — reintentando sin 'created'.");
        $data_min = $data;
        unset($data_min['created']);
        $wpdb->insert("{$wpdb->prefix}amelia_customer_bookings", $data_min);
        if ($wpdb->last_error) {
            log_msg("ERROR: amelia_customer_bookings fallback también falló: " . $wpdb->last_error);
            return 0;
        }
        return $wpdb->insert_id;
    }
    log_msg("ERROR: amelia_customer_bookings falló: $err1");
    return 0;
}

/**
 * Inserta pago dummy en amelia_payments con fallback por columna 'entity'.
 * No es crítico — si ambos intentos fallan, solo se loguea y se sigue.
 */
function amelia_insert_payment($wpdb, $data) {
    $wpdb->insert("{$wpdb->prefix}amelia_payments", $data);
    if (!$wpdb->last_error) {
        return;
    }
    $err1 = $wpdb->last_error;
    if (stripos($err1, 'entity') !== false || stripos($err1, 'Unknown column') !== false) {
        log_msg("WARN: amelia_payments con 'entity' falló ($err1) — reintentando sin 'entity'.");
        $data_min = $data;
        unset($data_min['entity']);
        $wpdb->insert("{$wpdb->prefix}amelia_payments", $data_min);
        if ($wpdb->last_error) {
            log_msg("WARN: amelia_payments fallback también falló: " . $wpdb->last_error . " (no crítico).");
        }
    } else {
        log_msg("WARN: amelia_payments falló: $err1 (no crítico).");
    }
}

// ── Helpers de validación ──────────────────────────────────────────────────

/** Valida y normaliza un email. Si es inválido devuelve un email generado automáticamente. */
function amelia_email($raw) {
    $email = trim((string)$raw);
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fallback = 'manual_' . time() . '@wolfsacatenango.com';
        log_msg("WARN: Email inválido '$email' — usando fallback $fallback");
        return $fallback;
    }
    return substr($email, 0, 191); // max length seguro para BD
}

/** Valida formato de fecha YYYY-MM-DD. Devuelve false si es inválida. */
function amelia_fecha($raw) {
    $f = trim((string)$raw);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) return false;
    [$y, $m, $d] = explode('-', $f);
    return checkdate((int)$m, (int)$d, (int)$y) ? $f : false;
}

/** Valida que el estado sea uno de los valores aceptados por Amelia. */
function amelia_estado($raw) {
    $validos = ['pending', 'approved', 'canceled', 'rejected', 'no-show'];
    $estado = strtolower(trim((string)$raw));
    return in_array($estado, $validos) ? $estado : 'pending';
}

/** Sanitiza un entero positivo con límite máximo. */
function amelia_int($raw, $min = 1, $max = 50) {
    $v = intval($raw);
    return max($min, min($max, $v));
}

/** Sanitiza un precio (float no negativo). */
function amelia_precio($raw) {
    $v = floatval($raw);
    return max(0.0, $v);
}

/** Sanitiza texto libre: strip tags, trim, truncar. */
function amelia_texto($raw, $maxlen = 200) {
    return substr(trim(strip_tags((string)$raw)), 0, $maxlen);
}

if ($action === 'create_booking') {
    $nombre  = amelia_texto($_POST['nombre'] ?? 'Cliente Wolfs');
    $fecha   = amelia_fecha($_POST['fecha'] ?? '');
    $tipo    = sanitize_text_field($_POST['tipo'] ?? '');
    $personas= amelia_int($_POST['personas'] ?? 1);
    $estado  = amelia_estado($_POST['estado'] ?? 'pending');
    $precio  = amelia_precio($_POST['precio'] ?? 0);
    $link_id = sanitize_text_field($_POST['link_id'] ?? '');

    log_msg("INFO: Intentando crear cita para '$nombre' en '$tipo'.");

    if (!$fecha) {
        log_msg("ERROR: Fecha inválida: '{$_POST['fecha']}'.");
        die(json_encode(['error' => 'Fecha inválida']));
    }
    if (!isset($service_map[$tipo])) {
        log_msg("ERROR: Tipo de cabaña '$tipo' inválido.");
        die(json_encode(['error' => 'Tipo de cabaña inválido']));
    }

    $serviceId = $service_map[$tipo]['serviceId'];
    $providerId = $service_map[$tipo]['providerId'];

    // Horarios: 07:00 a 08:00 (duración 3600)
    $bookingStart = $fecha . ' 07:00:00';
    $bookingEnd = $fecha . ' 08:00:00';
    $email = 'reserva_' . time() . '@wolfsacatenango.com';

    // 1. Crear o buscar cliente
    $customer = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}amelia_users WHERE email = %s AND type = 'customer'", $email));
    if (!$customer) {
        $wpdb->insert("{$wpdb->prefix}amelia_users", [
            'type'      => 'customer',
            'status'    => 'visible',
            'firstName' => $nombre,
            'lastName'  => '',
            'email'     => $email
        ]);
        $customerId = $wpdb->insert_id;
        log_msg("INFO: Nuevo cliente creado con ID $customerId");
    } else {
        $customerId = $customer->id;
        log_msg("INFO: Cliente existente encontrado con ID $customerId");
    }

    // Seguridad: si customerId quedó en 0, el insert falló — generar email único y reintentar
    if (!$customerId) {
        $correo_fallback = 'fallback_' . time() . '_' . rand(1000,9999) . '@wolfsacatenango.com';
        log_msg("WARN: customerId=0 en create_booking. Reintentando con email fallback: $correo_fallback");
        $wpdb->insert("{$wpdb->prefix}amelia_users", [
            'type'      => 'customer',
            'status'    => 'visible',
            'firstName' => $nombre,
            'lastName'  => '',
            'email'     => $correo_fallback
        ]);
        $customerId = $wpdb->insert_id;
        if (!$customerId) {
            log_msg("ERROR FATAL en create_booking: No se pudo crear el cliente. Abortando.");
            die(json_encode(['error' => 'No se pudo crear el cliente en Amelia']));
        }
    }

    // 2. Crear cita (Appointment) — sin noOfPersons, no existe en esta versión de Amelia
    $wpdb->insert("{$wpdb->prefix}amelia_appointments", [
        'bookingStart'       => $bookingStart,
        'bookingEnd'         => $bookingEnd,
        'notifyParticipants' => 1,
        'status'             => $estado,
        'serviceId'          => $serviceId,
        'providerId'         => $providerId,
        'internalNotes'      => 'link_' . $link_id,
    ]);
    if ($wpdb->last_error) log_msg("ERROR insert appointment: " . $wpdb->last_error);
    $appointmentId = $wpdb->insert_id;
    log_msg("INFO: Cita creada con ID $appointmentId");

    // 3. Crear Customer Booking — duration y token son críticos para que Amelia muestre la cita
    $bookingId = amelia_insert_booking($wpdb, [
        'appointmentId'  => $appointmentId,
        'customerId'     => $customerId,
        'status'         => $estado,
        'price'          => $precio,
        'persons'        => $personas,
        'aggregatedPrice'=> 0,
        'duration'       => 3600,
        'token'          => amelia_token(),
        'customFields'   => '[]',
        'created'        => current_time('mysql')
    ]);
    if (!$bookingId) {
        log_msg("ERROR: No se pudo crear el customer_booking en create_booking.");
        $wpdb->delete("{$wpdb->prefix}amelia_appointments", ['id' => $appointmentId]);
        echo json_encode(['error' => 'Fallo al crear el booking']);
        exit;
    }
    log_msg("INFO: Reservación exitosa para link_$link_id.");

    echo json_encode(['success' => true, 'appointmentId' => $appointmentId]);
    exit;
}

if ($action === 'cancel_booking') {
    $booking_id = (int)($_POST['booking_id'] ?? 0);

    if (!$booking_id) {
        log_msg("ERROR: cancel_booking — booking_id requerido.");
        die(json_encode(['error' => 'booking_id requerido']));
    }

    log_msg("INFO: cancel_booking — cancelando booking $booking_id.");

    // Cancelar el customer_booking
    $wpdb->update(
        "{$wpdb->prefix}amelia_customer_bookings",
        ['status' => 'canceled'],
        ['id' => $booking_id]
    );
    if ($wpdb->last_error) {
        log_msg("ERROR: cancel_booking — fallo al cancelar booking $booking_id: " . $wpdb->last_error);
        die(json_encode(['error' => $wpdb->last_error]));
    }

    // Verificar si quedan bookings activos en el appointment
    $appointment_id = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT appointmentId FROM {$wpdb->prefix}amelia_customer_bookings WHERE id = %d",
        $booking_id
    ));

    if ($appointment_id) {
        $activos = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}amelia_customer_bookings
             WHERE appointmentId = %d AND status != 'canceled'",
            $appointment_id
        ));
        if ($activos === 0) {
            $wpdb->update(
                "{$wpdb->prefix}amelia_appointments",
                ['status' => 'canceled'],
                ['id' => $appointment_id]
            );
            log_msg("INFO: cancel_booking — appointment $appointment_id cancelado (sin bookings activos).");
        }
    }

    log_msg("INFO: cancel_booking — booking $booking_id cancelado correctamente.");
    echo json_encode(['success' => true, 'bookingId' => $booking_id, 'appointmentId' => $appointment_id]);
    exit;
}

if ($action === 'update_status') {
    $link_id        = sanitize_text_field($_POST['link_id']        ?? '');
    $appointment_id = (int)($_POST['appointment_id']               ?? 0);
    $nuevo_estado   = amelia_estado($_POST['estado']               ?? 'pending');
    $fecha_ascenso  = amelia_fecha(sanitize_text_field($_POST['fecha_ascenso'] ?? ''));
    $tipo_cabana    = sanitize_text_field($_POST['tipo_cabana']    ?? '');

    log_msg("INFO: update_status → '$nuevo_estado' (link=$link_id | app_id=$appointment_id | fecha=$fecha_ascenso | tipo=$tipo_cabana)");

    $app = null;

    // 1) Por appointment_id directo
    if ($appointment_id > 0) {
        $app = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}amelia_appointments WHERE id = %d",
            $appointment_id
        ));
        if ($app) log_msg("INFO: appointment {$app->id} encontrado por appointment_id.");
    }

    // 2) Por link_id en internalNotes (formato legacy: 'link_{checkout_id}')
    if (!$app && $link_id) {
        $app = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}amelia_appointments WHERE internalNotes LIKE %s ORDER BY id DESC LIMIT 1",
            'link_' . $link_id . '%'
        ));
        if ($app) log_msg("INFO: appointment {$app->id} encontrado por link_id en internalNotes.");
    }

    // 3) Fallback: por fecha_ascenso + serviceId (para appointments creados con create_manual)
    //    create_manual guarda internalNotes='grupo_{tipo}_{fecha}' en lugar del link_id,
    //    así que usamos fecha+servicio para encontrarlo.
    if (!$app && $fecha_ascenso && isset($service_map[$tipo_cabana])) {
        $service_id_fb = $service_map[$tipo_cabana]['serviceId'];
        $app = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}amelia_appointments
             WHERE serviceId = %d AND DATE(bookingStart) = %s AND status != 'canceled'
             ORDER BY id ASC LIMIT 1",
            $service_id_fb, $fecha_ascenso
        ));
        if ($app) log_msg("INFO: appointment {$app->id} encontrado por fecha+tipo (fallback).");
    }

    if ($app) {
        $wpdb->update("{$wpdb->prefix}amelia_appointments", ['status' => $nuevo_estado], ['id' => $app->id]);
        $wpdb->update("{$wpdb->prefix}amelia_customer_bookings", ['status' => $nuevo_estado], ['appointmentId' => $app->id]);
        log_msg("INFO: Estado de cita {$app->id} actualizado a '$nuevo_estado'.");
        echo json_encode(['success' => true, 'appointmentId' => $app->id]);
    } else {
        log_msg("ERROR: No se encontró cita para link=$link_id | app_id=$appointment_id | fecha=$fecha_ascenso | tipo=$tipo_cabana");
        echo json_encode(['error' => 'Cita no encontrada', 'link_id' => $link_id, 'appointment_id' => $appointment_id]);
    }
    exit;
}

if ($action === 'reschedule') {
    $link_id = sanitize_text_field($_POST['link_id'] ?? '');
    $appointment_id = (int)($_POST['appointment_id'] ?? 0);
    $nueva_fecha = amelia_fecha($_POST['nueva_fecha'] ?? '');

    if (!$nueva_fecha) {
        log_msg("ERROR: Fecha de reprogramación inválida: '{$_POST['nueva_fecha']}'.");
        die(json_encode(['error' => 'Fecha de reprogramación inválida']));
    }

    log_msg("INFO: Solicitud de reprogramación a $nueva_fecha (link_$link_id | app_id_$appointment_id)");

    if ($appointment_id > 0) {
        $app = $wpdb->get_row($wpdb->prepare(
            "SELECT id, bookingStart, bookingEnd FROM {$wpdb->prefix}amelia_appointments WHERE id = %d",
            $appointment_id
        ));
    } else {
        // LIKE para que encuentre aunque internalNotes tenga texto extra después del link_id
        $app = $wpdb->get_row($wpdb->prepare(
            "SELECT id, bookingStart, bookingEnd FROM {$wpdb->prefix}amelia_appointments WHERE internalNotes LIKE %s ORDER BY id DESC LIMIT 1",
            'link_' . $link_id . '%'
        ));
    }

    if ($app) {
        // Extraer la hora original para no romper el calendario de Amelia
        $timeStart = substr($app->bookingStart, 11);
        $timeEnd   = substr($app->bookingEnd, 11);
        if (!$timeStart) $timeStart = '07:00:00';
        if (!$timeEnd)   $timeEnd   = '08:00:00';

        $bookingStart = $nueva_fecha . ' ' . $timeStart;
        $bookingEnd   = $nueva_fecha . ' ' . $timeEnd;

        // Solo actualiza las fechas — no cambia el estado actual de la cita
        $wpdb->update("{$wpdb->prefix}amelia_appointments", [
            'bookingStart' => $bookingStart,
            'bookingEnd'   => $bookingEnd,
        ], ['id' => $app->id]);

        log_msg("INFO: Cita {$app->id} reprogramada a $nueva_fecha correctamente.");
        echo json_encode(['success' => true, 'appointmentId' => $app->id, 'nueva_fecha' => $nueva_fecha]);
    } else {
        log_msg("ERROR: No se encontró cita para reprogramar. link_$link_id | appointment_id=$appointment_id");
        echo json_encode(['error' => 'Cita no encontrada', 'link_id' => $link_id, 'appointment_id' => $appointment_id]);
    }
    exit;
}

if ($action === 'create_manual') {
    /*
     * Cómo funciona la capacidad en esta versión de Amelia:
     *   - La columna noOfPersons NO existe en wp_amelia_appointments.
     *   - Amelia calcula disponibilidad sumando `persons` de wp_amelia_customer_bookings
     *     para todos los bookings del mismo appointment.
     *   - Si hay appointments SEPARADOS por día, cada uno cuenta independiente → sobreventa.
     *   - Solución: UNA sola cita (appointment) por fecha+servicio.
     *     Cada reserva agrega su propio customer_booking con su `persons` real.
     *     Amelia suma todos los bookings del appointment → respeta el límite (ej. 22).
     *   - Guardamos bookingId en Supabase (amelia_booking_{id}) para reschedule individual.
     */
    log_msg("INFO: create_manual — iniciando.");

    $nombre        = amelia_texto($_POST['nombre'] ?? 'Web Cliente') ?: 'Web Cliente';
    $correo        = amelia_email($_POST['correo'] ?? '');
    $fecha_ascenso = amelia_fecha($_POST['fecha_ascenso'] ?? '');
    $tipo_cabana   = sanitize_text_field($_POST['tipo_cabana'] ?? 'Mixta');
    $no_personas   = amelia_int($_POST['no_personas'] ?? 1);
    $precio        = amelia_precio($_POST['precio'] ?? 0);
    $estado        = amelia_estado($_POST['estado'] ?? 'pending');
    $link_id       = sanitize_text_field($_POST['link_id'] ?? '');
    $extra_info    = amelia_texto($_POST['extra_info'] ?? '', 500);

    if (!$fecha_ascenso) {
        log_msg("ERROR: Fecha inválida: '{$_POST['fecha_ascenso']}'.");
        die(json_encode(['error' => 'Fecha de ascenso inválida']));
    }
    if (!isset($service_map[$tipo_cabana])) {
        log_msg("ERROR: Tipo de cabaña '$tipo_cabana' inválido.");
        die(json_encode(['error' => 'Tipo de cabaña inválido']));
    }

    $service_id   = $service_map[$tipo_cabana]['serviceId'];
    $provider_id  = $service_map[$tipo_cabana]['providerId'];
    $bookingStart = $fecha_ascenso . ' 07:00:00';
    $bookingEnd   = $fecha_ascenso . ' 08:00:00';

    // 1. Buscar o crear cliente
    $parts     = explode(' ', $nombre, 2);
    $firstName = $parts[0];
    $lastName  = count($parts) > 1 ? $parts[1] : '';

    $customer   = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}amelia_users WHERE email = %s AND type = 'customer'",
        $correo
    ));
    $customerId = $customer ? $customer->id : 0;

    if (!$customerId) {
        $wpdb->insert("{$wpdb->prefix}amelia_users", [
            'type' => 'customer', 'status' => 'visible',
            'firstName' => $firstName, 'lastName' => $lastName, 'email' => $correo
        ]);
        $customerId = $wpdb->insert_id;
    }
    if (!$customerId) {
        $fb = 'fb_' . time() . '_' . rand(1000,9999) . '@wolfsacatenango.com';
        $wpdb->insert("{$wpdb->prefix}amelia_users", [
            'type' => 'customer', 'status' => 'visible',
            'firstName' => $firstName, 'lastName' => $lastName, 'email' => $fb
        ]);
        $customerId = $wpdb->insert_id;
        if (!$customerId) {
            log_msg("ERROR FATAL: No se pudo crear el cliente.");
            die(json_encode(['error' => 'No se pudo crear el cliente en Amelia']));
        }
    }

    // 2. Buscar appointment existente para esta fecha+servicio (sin noOfPersons — no existe)
    $existing_app_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}amelia_appointments
         WHERE serviceId = %d AND DATE(bookingStart) = %s AND status != 'canceled'
         ORDER BY id ASC LIMIT 1",
        $service_id, $fecha_ascenso
    ));

    if ($existing_app_id) {
        $appointmentId = (int)$existing_app_id;
        log_msg("INFO: Usando appointment existente $appointmentId para $tipo_cabana $fecha_ascenso.");
    } else {
        // Crear nuevo appointment para este día+servicio
        $wpdb->insert("{$wpdb->prefix}amelia_appointments", [
            'bookingStart'       => $bookingStart,
            'bookingEnd'         => $bookingEnd,
            'notifyParticipants' => 1,
            'status'             => $estado,
            'serviceId'          => $service_id,
            'providerId'         => $provider_id,
            'internalNotes'      => 'grupo_' . $tipo_cabana . '_' . $fecha_ascenso,
        ]);
        if ($wpdb->last_error) {
            log_msg("ERROR insert appointment: " . $wpdb->last_error);
            die(json_encode(['error' => 'Fallo al crear appointment: ' . $wpdb->last_error]));
        }
        $appointmentId = $wpdb->insert_id;
        log_msg("INFO: Nuevo appointment $appointmentId para $tipo_cabana el $fecha_ascenso.");
    }

    // 3. Crear customer_booking individual
    //    persons = personas reales → Amelia suma todos los del appointment para el chequeo
    $bookingId = amelia_insert_booking($wpdb, [
        'appointmentId'   => $appointmentId,
        'customerId'      => $customerId,
        'status'          => $estado,
        'price'           => $precio,
        'persons'         => $no_personas,
        'aggregatedPrice' => 0,
        'duration'        => 3600,
        'token'           => amelia_token(),
        'customFields'    => '[]',
        'created'         => current_time('mysql'),
    ]);

    if (!$bookingId) {
        log_msg("ERROR: No se pudo crear customer_booking: " . $wpdb->last_error);
        if (!$existing_app_id) {
            $wpdb->delete("{$wpdb->prefix}amelia_appointments", ['id' => $appointmentId]);
        }
        die(json_encode(['error' => 'Fallo al crear booking: ' . $wpdb->last_error]));
    }

    // 4. Pago dummy
    amelia_insert_payment($wpdb, [
        'customerBookingId' => $bookingId,
        'amount'            => $precio,
        'dateTime'          => current_time('mysql'),
        'status'            => $estado === 'approved' ? 'paid' : 'pending',
        'gateway'           => 'onSite',
        'entity'            => 'appointment',
    ]);

    log_msg("INFO: OK — appointmentId=$appointmentId bookingId=$bookingId link=$link_id");
    echo json_encode([
        'success'       => true,
        'appointmentId' => $appointmentId,
        'bookingId'     => $bookingId,
    ]);
    exit;
}

// ── Mover un booking individual a otra fecha ──────────────────────────────────
if ($action === 'move_booking') {
    /*
     * Mueve un customer_booking al appointment del día destino (o crea uno nuevo).
     * Si el appointment origen queda sin bookings, lo elimina.
     * NO usa noOfPersons (columna inexistente) — calcula personas sumando bookings.
     */
    $booking_id  = (int)($_POST['booking_id']  ?? 0);
    $nueva_fecha = amelia_fecha($_POST['nueva_fecha'] ?? '');

    if (!$booking_id || !$nueva_fecha) {
        log_msg("ERROR: move_booking — faltan booking_id o nueva_fecha.");
        die(json_encode(['error' => 'booking_id y nueva_fecha son requeridos']));
    }

    // Obtener info del booking (sin noOfPersons)
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT acb.id, acb.appointmentId, acb.persons,
                aa.serviceId, aa.providerId
         FROM {$wpdb->prefix}amelia_customer_bookings acb
         INNER JOIN {$wpdb->prefix}amelia_appointments aa ON acb.appointmentId = aa.id
         WHERE acb.id = %d",
        $booking_id
    ));

    if (!$booking) {
        log_msg("ERROR: move_booking — booking $booking_id no encontrado.");
        die(json_encode(['error' => 'Booking no encontrado']));
    }

    $old_app_id  = (int)$booking->appointmentId;
    $personas    = (int)$booking->persons;
    $service_id  = (int)$booking->serviceId;
    $provider_id = (int)$booking->providerId;

    // Buscar appointment destino (sin noOfPersons)
    $dest_app_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}amelia_appointments
         WHERE serviceId = %d AND DATE(bookingStart) = %s AND status != 'canceled'
         ORDER BY id ASC LIMIT 1",
        $service_id, $nueva_fecha
    ));

    if (!$dest_app_id) {
        $wpdb->insert("{$wpdb->prefix}amelia_appointments", [
            'bookingStart'       => $nueva_fecha . ' 07:00:00',
            'bookingEnd'         => $nueva_fecha . ' 08:00:00',
            'notifyParticipants' => 1,
            'status'             => 'approved',
            'serviceId'          => $service_id,
            'providerId'         => $provider_id,
            'internalNotes'      => "Reprogramado desde appointment $old_app_id",
        ]);
        $dest_app_id = $wpdb->insert_id;
        log_msg("INFO: move_booking — nuevo appointment $dest_app_id para $nueva_fecha.");
    }
    $dest_app_id = (int)$dest_app_id;

    // Mover el booking al appointment destino
    $wpdb->update("{$wpdb->prefix}amelia_customer_bookings",
        ['appointmentId' => $dest_app_id],
        ['id' => $booking_id]
    );
    log_msg("INFO: move_booking — booking $booking_id movido a appointment $dest_app_id.");

    // ¿Quedaron otros bookings en el appointment origen?
    $otros = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}amelia_customer_bookings
         WHERE appointmentId = %d AND id != %d AND status != 'canceled'",
        $old_app_id, $booking_id
    ));

    if ($otros === 0) {
        // Appointment origen vacío → eliminar
        $wpdb->delete("{$wpdb->prefix}amelia_appointments", ['id' => $old_app_id]);
        log_msg("INFO: move_booking — appointment origen $old_app_id eliminado (vacío).");
    }

    echo json_encode([
        'success'          => true,
        'bookingId'        => $booking_id,
        'newAppointmentId' => $dest_app_id,
        'oldAppointmentId' => $old_app_id,
    ]);
    exit;
}

// Repara citas existentes del bridge que no tienen token/duration/aggregatedPrice correctos
if ($action === 'fix_existing') {
    log_msg("INFO: Iniciando reparación de citas sin token/duration/aggregatedPrice...");

    // Reparar customer_bookings: agregar token, duration=3600, aggregatedPrice=0 donde faltan
    $bookings_sin_token = $wpdb->get_results("
        SELECT id, duration FROM {$wpdb->prefix}amelia_customer_bookings
        WHERE token IS NULL OR token = '' OR duration IS NULL
    ");
    $fixed_bookings = 0;
    foreach ($bookings_sin_token as $b) {
        $data = ['aggregatedPrice' => 0];
        if (!$b->duration) $data['duration'] = 3600;
        if (empty($b->token)) $data['token'] = amelia_token();
        $wpdb->update("{$wpdb->prefix}amelia_customer_bookings", $data, ['id' => $b->id]);
        $fixed_bookings++;
    }
    log_msg("INFO: Customer bookings reparados: $fixed_bookings");

    // También actualizar notifyParticipants=1 en appointments del bridge
    $fixed_apps = $wpdb->query("
        UPDATE {$wpdb->prefix}amelia_appointments
        SET notifyParticipants = 1
        WHERE notifyParticipants = 0 AND serviceId IN (1,3,4)
    ");
    log_msg("INFO: Appointments con notifyParticipants actualizado: $fixed_apps");

    echo json_encode([
        'success'            => true,
        'bookings_fixed'     => $fixed_bookings,
        'appointments_fixed' => $fixed_apps
    ]);
    exit;
}

if ($action === 'get_log') {
    $log_file = __DIR__ . '/amelia_bridge.log';
    if (!file_exists($log_file)) {
        echo json_encode(['success' => true, 'log' => '(log vacío — ninguna llamada registrada aún)']);
        exit;
    }
    // Últimas 100 líneas
    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $last = array_slice($lines, -100);
    echo json_encode(['success' => true, 'log' => implode("\n", $last), 'total_lines' => count($lines)]);
    exit;
}

if ($action === 'export_all') {
    log_msg("INFO: Exportando reservaciones de Amelia.");

    // Desde cuándo exportar (defecto: hace 1 año para capturar todo lo relevante)
    $desde = !empty($_POST['desde'])
        ? sanitize_text_field($_POST['desde']) . ' 00:00:00'
        : date('Y-m-d H:i:s', strtotime('-365 days'));

    $query = "
        SELECT a.id as appointment_id,
               cb.id as booking_id,
               a.bookingStart,
               a.serviceId,
               a.status as app_status,
               a.internalNotes,
               cb.persons,
               cb.price,
               cb.status as booking_status,
               c.firstName,
               c.lastName,
               c.email,
               c.phone
        FROM {$wpdb->prefix}amelia_appointments a
        JOIN {$wpdb->prefix}amelia_customer_bookings cb ON a.id = cb.appointmentId
        JOIN {$wpdb->prefix}amelia_users c ON cb.customerId = c.id
        WHERE a.bookingStart >= %s
        ORDER BY a.bookingStart ASC
    ";

    $results = $wpdb->get_results($wpdb->prepare($query, $desde), ARRAY_A);
    echo json_encode(['success' => true, 'data' => $results, 'total' => count($results)]);
    exit;
}

if ($action === 'delete_before_date') {
    $fecha_corte = amelia_fecha($_POST['fecha_corte'] ?? '');
    $dry_run     = !empty($_POST['dry_run']);   // si dry_run=1 solo cuenta, no borra

    if (!$fecha_corte) {
        log_msg("ERROR: fecha_corte inválida: '{$_POST['fecha_corte']}'.");
        die(json_encode(['error' => 'fecha_corte inválida']));
    }

    $bookingStart_corte = $fecha_corte . ' 00:00:00';
    log_msg("INFO: " . ($dry_run ? "[DRY-RUN] Contando" : "Borrando") . " citas anteriores a $bookingStart_corte");

    // 1. Obtener IDs de appointments a borrar
    $appointments = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}amelia_appointments WHERE bookingStart < %s",
        $bookingStart_corte
    ));

    if (empty($appointments)) {
        log_msg("INFO: No hay citas anteriores a $fecha_corte.");
        echo json_encode(['success' => true, 'borradas' => 0, 'dry_run' => $dry_run]);
        exit;
    }

    // Si es dry-run, solo devolver el conteo
    if ($dry_run) {
        log_msg("INFO: [DRY-RUN] Se borrarían " . count($appointments) . " citas.");
        echo json_encode(['success' => true, 'borradas' => 0, 'a_borrar' => count($appointments), 'dry_run' => true]);
        exit;
    }

    $ids_placeholder = implode(',', array_map('intval', $appointments));

    // 2. Obtener IDs de bookings relacionados
    $bookings = $wpdb->get_col(
        "SELECT id FROM {$wpdb->prefix}amelia_customer_bookings WHERE appointmentId IN ($ids_placeholder)"
    );

    // 3. Borrar pagos
    if (!empty($bookings)) {
        $bk_placeholder = implode(',', array_map('intval', $bookings));
        $wpdb->query("DELETE FROM {$wpdb->prefix}amelia_payments WHERE customerBookingId IN ($bk_placeholder)");
        log_msg("INFO: Pagos borrados.");
    }

    // 4. Borrar bookings
    $wpdb->query("DELETE FROM {$wpdb->prefix}amelia_customer_bookings WHERE appointmentId IN ($ids_placeholder)");
    log_msg("INFO: Customer bookings borrados.");

    // 5. Borrar appointments
    $wpdb->query("DELETE FROM {$wpdb->prefix}amelia_appointments WHERE id IN ($ids_placeholder)");
    log_msg("INFO: Appointments borrados: " . count($appointments));

    echo json_encode(['success' => true, 'borradas' => count($appointments)]);
    exit;
}

if ($action === 'update_persons') {
    // Actualiza el número de personas de una cita existente.
    // Si personas=0, elimina la cita (ya no quedan reservas en ese día).
    $appointment_id = (int)($_POST['appointment_id'] ?? 0);
    $personas       = (int)($_POST['personas']       ?? -1);

    if (!$appointment_id || $personas < 0) {
        log_msg("ERROR: update_persons — appointment_id o personas inválidos.");
        die(json_encode(['error' => 'appointment_id y personas son requeridos y personas >= 0']));
    }

    if ($personas === 0) {
        // No quedan personas → borrar la cita (y sus bookings/pagos) en cascada
        $bookings = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}amelia_customer_bookings WHERE appointmentId = %d",
            $appointment_id
        ));
        if (!empty($bookings)) {
            $bk_ph = implode(',', array_map('intval', $bookings));
            $wpdb->query("DELETE FROM {$wpdb->prefix}amelia_payments WHERE customerBookingId IN ($bk_ph)");
            $wpdb->query("DELETE FROM {$wpdb->prefix}amelia_customer_bookings WHERE appointmentId = $appointment_id");
        }
        $wpdb->delete("{$wpdb->prefix}amelia_appointments", ['id' => $appointment_id]);
        log_msg("INFO: update_persons — cita $appointment_id eliminada (personas=0).");
        echo json_encode(['success' => true, 'eliminada' => true, 'appointmentId' => $appointment_id]);
        exit;
    }

    // Actualizar noOfPersons en el appointment
    $wpdb->update(
        "{$wpdb->prefix}amelia_appointments",
        ['noOfPersons' => $personas],
        ['id' => $appointment_id]
    );

    if ($wpdb->last_error) {
        log_msg("ERROR: update_persons — fallo al actualizar cita $appointment_id: " . $wpdb->last_error);
        echo json_encode(['error' => $wpdb->last_error]);
        exit;
    }

    // También actualizar persons en el customer_booking asociado (primer booking)
    $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->prefix}amelia_customer_bookings SET persons = %d WHERE appointmentId = %d LIMIT 1",
        $personas, $appointment_id
    ));

    log_msg("INFO: update_persons — cita $appointment_id actualizada a $personas personas.");
    echo json_encode(['success' => true, 'appointmentId' => $appointment_id, 'personas' => $personas]);
    exit;
}

log_msg("ERROR: Acción '$action' no reconocida.");
echo json_encode(['error' => 'Acción no válida']);
?>
