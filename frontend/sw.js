// Service Worker — Wolfs Reservaciones
// Estrategia: network-first para todo. Solo cachea assets estáticos.
// skipWaiting + clients.claim → la nueva versión activa DE INMEDIATO
// sin necesidad de desinstalar la app.

const CACHE_NAME = 'wolfs-__BUILD_ID__';
const STATIC_ASSETS = ['/icon-192.png', '/icon-512.png', '/manifest.json'];

// ── Instalación: cachea solo assets estáticos y activa inmediatamente ──
self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE_NAME)
      .then(c => c.addAll(STATIC_ASSETS))
      .then(() => self.skipWaiting())   // ← activa sin esperar que se cierren tabs
  );
});

// ── Activación: elimina cachés viejos y toma control de todas las páginas ──
self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(k => k !== CACHE_NAME)
          .map(k => {
            console.log('[SW] Eliminando caché viejo:', k);
            return caches.delete(k);
          })
      )
    ).then(() => self.clients.claim())  // ← toma control de tabs abiertos
  );
});

// ── Fetch: network-first para todo ──
// Las páginas HTML y datos siempre van a la red.
// Solo usa caché como fallback si no hay red (modo offline).
self.addEventListener('fetch', e => {
  // No interceptar peticiones de otras origins (Supabase, Recurrente, Google Fonts)
  if (!e.request.url.startsWith(self.location.origin)) return;

  e.respondWith(
    fetch(e.request)
      .then(res => {
        // Si es un asset estático (iconos), actualizar caché
        if (STATIC_ASSETS.some(a => e.request.url.endsWith(a))) {
          const clone = res.clone();
          caches.open(CACHE_NAME).then(c => c.put(e.request, clone));
        }
        return res;
      })
      .catch(() => caches.match(e.request))  // offline fallback
  );
});

// ── Mensaje desde la app para forzar actualización ──
self.addEventListener('message', e => {
  if (e.data === 'SKIP_WAITING') self.skipWaiting();
});
