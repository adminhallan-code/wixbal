/**
 * nav.js — Componente de navegación compartido.
 * Se carga como <script src="/nav.js"> justo después de <nav class="nav"></nav>
 * en CADA página. Genera e inyecta el header completo de forma síncrona,
 * sin parpadeo y sin DOMContentLoaded.
 *
 * Para cambiar el header en toda la app: edita SOLO este archivo.
 */
(function () {
  var P = window.location.pathname;

  function act(href) {
    if (href === '/') return P === '/' || P === '';
    return P === href || P.startsWith(href + '/');
  }

  var html =
    '<a href="/" class="nav-logo">🏕 Wolfs</a>' +
    '<button class="nav-hamburger" onclick="_toggleNav()">☰</button>' +
    '<div class="nav-links" id="nav-links">' +

    /* ── Reservaciones (dropdown) ── */
    '<div class="dropdown' + (act('/') ? ' active-parent' : '') + '" data-req="reservaciones">' +
    '<button class="dropdown-btn' + (act('/') ? ' active' : '') + '" onclick="this.closest(\'.dropdown\').classList.toggle(\'open\')">Reservaciones ▾</button>' +
    '<div class="dropdown-content">' +
    '<a href="/?view=nueva">Nueva reservación</a>' +
    '<a href="/?view=lista">Lista</a>' +
    '<a href="/?view=pendientes">Pendientes <span id="badge-pendientes" class="tab-badge" style="display:none"></span></a>' +
    '<a href="/?view=disponibilidad">Disponibilidad</a>' +
    '</div></div>' +

    /* ── Links de páginas ── */
    '<a href="/links"       class="nav-link' + (act('/links')       ? ' active' : '') + '" data-req="links">Links</a>' +
    '<a href="/jefes"       class="nav-link' + (act('/jefes')       ? ' active' : '') + '" data-req="jefes">Jefes</a>' +
    '<a href="/it"          class="nav-link' + (act('/it')          ? ' active' : '') + '" data-req="it">Panel IT</a>' +
    '<a href="/facturacion" class="nav-link' + (act('/facturacion') ? ' active' : '') + '" data-req="facturacion">Facturación</a>' +
    '<a href="/cocina"      class="nav-link' + (act('/cocina')      ? ' active' : '') + '" data-req="cocina">Cocina</a>' +
    '<a href="/gestion"     class="nav-link' + (act('/gestion')     ? ' active' : '') + '" data-req="gestion">Gestión</a>' +

    '<div class="nav-spacer"></div>' +

    /* ── Campana de notificaciones ── */
    '<div class="notif-bell-wrap">' +
    '<button id="notif-bell-btn" onclick="_toggleBell()">🔔 ' +
    '<span id="notif-badge" style="background:#ef4444;color:#fff;font-size:.65rem;font-weight:700;' +
    'border-radius:999px;min-width:18px;height:18px;display:none;align-items:center;' +
    'justify-content:center;padding:0 4px;vertical-align:middle">0</span>' +
    '</button>' +
    '<div id="notif-panel" style="display:none">' +
    '<div class="notif-panel-title">Notificaciones</div>' +
    '<div id="notif-list"></div>' +
    '</div></div>' +

    '<button class="nav-logout" onclick="authLogout()">Salir</button>' +
    '</div>' + /* cierra nav-links */

    /* ── Usuario y dot de conexión ── */
    '<span id="header-user" style="font-size:.78rem;color:#888;white-space:nowrap;margin-left:.5rem"></span>' +
    /* Dot empieza gris (cargando). Cada página lo pone verde/rojo via setConnDot() */
  '<div id="conn-dot" style="width:8px;height:8px;border-radius:50%;background:#888;' +
    'transition:all .5s;flex-shrink:0" title="Verificando conexión…"></div>';

  /* Inyectar en la etiqueta <nav class="nav"> — se corre síncronamente */
  var nav = document.querySelector('nav.nav');
  if (nav) nav.innerHTML = html;

  /* ══ API global ══════════════════════════════════════════════════ */

  /** Abre/cierra el panel de notificaciones */
  window._toggleBell = function () {
    var p = document.getElementById('notif-panel');
    if (!p) return;
    var open = p.style.display === 'block';
    p.style.display = open ? 'none' : 'block';
    /* Si notif.js registró un renderer, llamarlo al abrir */
    if (!open && typeof window._renderNotifPanel === 'function') window._renderNotifPanel();
  };

  /** Abre/cierra el menú hamburguesa en mobile */
  window._toggleNav = function () {
    var nl = document.getElementById('nav-links');
    if (nl) nl.classList.toggle('open');
  };

  /** Actualiza el dot de conexión (verde=ok, rojo=error) */
  window.setConnDot = function (ok) {
    var d = document.getElementById('conn-dot');
    if (!d) return;
    d.style.background = ok ? '#4ade80' : '#f87171';
    d.style.boxShadow  = ok ? '0 0 6px #4ade80' : '0 0 6px #f87171';
    d.title = ok ? 'Conectado a Supabase' : 'Sin conexión';
  };

  /* Cerrar panel al hacer click fuera */
  document.addEventListener('click', function (e) {
    var p = document.getElementById('notif-panel');
    var b = document.getElementById('notif-bell-btn');
    if (p && p.style.display === 'block' &&
        !((b && b.contains(e.target)) || p.contains(e.target))) {
      p.style.display = 'none';
    }
  }, true);
})();
