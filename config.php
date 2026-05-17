<?php
// config.php — Configuración central del sistema
// No subir a Git con credenciales reales. En producción usar variables de entorno.

define('SUPABASE_URL',  getenv('SUPABASE_URL')  ?: 'https://vimymnzlagzhfpbpjrvl.supabase.co');
define('SUPABASE_KEY',  getenv('SUPABASE_KEY')  ?:
    'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InZpbXltbnpsYWd6' .
    'aGZwYnBqcnZsIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzY5NzcwNzEsImV4cCI6MjA5MjU1MzA3MX0' .
    '.7p8ob8zr2dYkAvCV-8p_Mmp65laYlsG5KwCuyj7MdLc'
);

define('RECURRENTE_API_KEY', getenv('RECURRENTE_API_KEY') ?: '');
define('RECURRENTE_SECRET',  getenv('RECURRENTE_SECRET')  ?: '');
define('RECURRENTE_BASE',    'https://app.recurrente.com/api');

define('RESEND_API_KEY',     getenv('RESEND_API_KEY') ?: '');
define('FROM_EMAIL',         'Wolfs Reservaciones <noreply@wixbal.com>');
define('NOTIFY_EMAIL',       'reservaciones@wolfsacatenango.com');

define('FELPLEX_API_KEY', getenv('FELPLEX_API_KEY') ?: '');

define('AMELIA_SECRET',  'WOLFS_RESERVACIONES_SECRET_2026');
define('WP_DOMAINS',     ['https://wolfsacatenango.com', 'https://wolfsacatenango.com.gt']);

// Zona horaria Guatemala (UTC-6, sin cambio de horario)
define('GT_OFFSET', -6);

// Cabañas y su mapa a Amelia
define('SERVICE_MAP', [
    'Mixta'    => ['serviceId' => 1, 'providerId' => 6, 'capacidad' => 22],
    'Privada'  => ['serviceId' => 3, 'providerId' => 7, 'capacidad' => 1],
    'Familiar' => ['serviceId' => 4, 'providerId' => 3, 'capacidad' => 1],
]);

define('AGENCIAS_WOLFS', ['wolfs acatenango', 'wolfs']);
