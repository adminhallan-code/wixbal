// update.js — Detecta nuevas versiones y recarga la app automáticamente
// Se incluye en todas las páginas. No requiere cambios manuales en cada deploy.
(function () {
  if (!('serviceWorker' in navigator)) return;

  // Método 1: El nuevo Service Worker toma control (skipWaiting activado)
  // Esto dispara cuando el SW nuevo instalado reemplaza al viejo.
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (!window._wolfsReloading) {
      window._wolfsReloading = true;
      console.log('[Update] Nuevo SW activo — recargando...');
      window.location.reload();
    }
  });

  // Método 2: Polling del endpoint /api/version como respaldo
  // Si el build cambia (nuevo deploy), la versión cambia y se recarga.
  let _ver = null;
  async function _chkVer() {
    try {
      const r = await fetch('/api/version', { cache: 'no-store' });
      if (!r.ok) return;
      const { v } = await r.json();
      if (_ver !== null && _ver !== v) {
        console.log('[Update] Nueva versión detectada — recargando...');
        if (!window._wolfsReloading) {
          window._wolfsReloading = true;
          window.location.reload();
        }
        return;
      }
      _ver = v;
    } catch { /* silencioso */ }
  }
  _chkVer();
  setInterval(_chkVer, 60_000); // revisar cada minuto
})();
