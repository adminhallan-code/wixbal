/**
 * nav.js — Componente de navegación compartido.
 * Se carga como <script src="/nav.js"> justo después de <nav class="nav"></nav>
 * en CADA página. Genera e inyecta el header completo de forma síncrona,
 * sin parpadeo y sin DOMContentLoaded.
 *
 * Para cambiar el header en toda la app: edita SOLO este archivo.
 */
(function () {
  /* ── Inject improved mobile nav styles ────────────────────────────── */
  var s = document.createElement('style');
  s.textContent = [
    /* Base nav */
    '.nav{background:#111;color:#fff;display:flex;align-items:center;gap:.4rem;padding:.6rem 1rem;position:sticky;top:0;z-index:100;flex-wrap:wrap}',
    '.nav-logo{font-family:"DM Serif Display",serif;font-size:1.05rem;color:#fff;text-decoration:none;white-space:nowrap;flex:1}',
    '.nav-links{display:flex;align-items:center;gap:.25rem;margin-left:auto}',
    '.nav-link{color:#ccc;text-decoration:none;font-size:.85rem;padding:.35rem .65rem;border-radius:6px;white-space:nowrap;transition:background .15s}',
    '.nav-link:hover,.nav-link.active{background:#2a2a2a;color:#fff}',
    '.dropdown{position:relative}',
    '.dropdown-btn{background:none;border:none;color:#ccc;font-size:.85rem;cursor:pointer;padding:.35rem .65rem;border-radius:6px;font-family:inherit;white-space:nowrap;transition:background .15s}',
    '.dropdown-btn:hover,.dropdown-btn.active{background:#2a2a2a;color:#fff}',
    '.dropdown-content{display:none;position:absolute;top:calc(100% + 4px);left:0;background:#1a1a1a;border:1px solid #333;border-radius:8px;min-width:180px;z-index:200;overflow:hidden}',
    '.dropdown:hover .dropdown-content,.dropdown.open .dropdown-content{display:block}',
    '.dropdown-content a{display:block;padding:.55rem 1rem;color:#ccc;text-decoration:none;font-size:.83rem}',
    '.dropdown-content a:hover{background:#2a2a2a;color:#fff}',
    '.nav-spacer{flex:1}',
    '.nav-hamburger{display:none;background:none;border:none;color:#fff;font-size:1.3rem;cursor:pointer;width:40px;height:40px;align-items:center;justify-content:center;border-radius:8px;line-height:1;flex-shrink:0;transition:background .15s}',
    '.nav-hamburger:hover{background:#2a2a2a}',
    '.nav-logout{background:none;border:1px solid #444;color:#ccc;font-size:.82rem;cursor:pointer;padding:.32rem .75rem;border-radius:6px;font-family:inherit;transition:all .15s;white-space:nowrap}',
    '.nav-logout:hover{background:#2a2a2a;color:#fff}',
    '.notif-bell-wrap{position:relative}',
    '#notif-bell-btn{background:none;border:none;cursor:pointer;font-size:1.1rem;padding:.3rem .5rem;border-radius:6px;color:#ccc;display:flex;align-items:center;gap:4px}',
    '#notif-bell-btn:hover{background:#2a2a2a;color:#fff}',
    '#notif-badge{background:#ef4444;color:#fff;font-size:.65rem;font-weight:700;border-radius:999px;min-width:18px;height:18px;display:none;align-items:center;justify-content:center;padding:0 4px}',
    '#notif-panel{position:absolute;top:calc(100% + 8px);right:0;width:320px;background:#fff;border:1px solid #e0e0e0;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.15);z-index:300;max-height:420px;overflow-y:auto}',
    '.notif-panel-title{padding:.75rem 1rem;font-weight:600;font-size:.85rem;border-bottom:1px solid #e0e0e0;background:#f9f9f9;border-radius:12px 12px 0 0}',
    '.tab-badge{display:inline-block;background:#b45309;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 6px;margin-left:6px;vertical-align:middle}',
    '.nav-mobile-user{display:none;padding:.6rem 1rem;font-size:.78rem;color:#888;border-top:1px solid #2a2a2a;margin-top:.25rem}',

    /* Mobile */
    '@media(max-width:768px){',
    '.nav{padding:.55rem .9rem;gap:.3rem}',
    '.nav-logo{font-size:1rem;flex:1}',
    '#header-user,#conn-dot{display:none!important}',
    '.nav-hamburger{display:flex!important}',
    '.nav-links{',
      'display:none;width:100%;flex-direction:column;align-items:stretch;gap:0;',
      'padding:.4rem 0;border-top:1px solid #2a2a2a;margin-top:.3rem;',
      'order:99;',
    '}',
    '.nav-links.open{display:flex}',
    '.nav-link{padding:.75rem 1rem;border-radius:6px;font-size:.9rem;min-height:44px;display:flex;align-items:center}',
    '.dropdown-btn{width:100%;text-align:left;padding:.75rem 1rem;border-radius:6px;font-size:.9rem;min-height:44px}',
    '.dropdown-content{position:static;border:none;border-radius:0;box-shadow:none;background:#161616;padding-left:1rem}',
    '.dropdown:hover .dropdown-content{display:none}',
    '.dropdown.open .dropdown-content{display:block}',
    '.dropdown-content a{padding:.65rem 1rem;font-size:.88rem;min-height:40px;display:flex;align-items:center}',
    '.nav-spacer{display:none}',
    '.nav-logout{margin:.4rem 1rem;width:calc(100% - 2rem);padding:.75rem;text-align:center;min-height:44px}',
    '.notif-bell-wrap{width:100%;padding:0 1rem}',
    '#notif-bell-btn{width:100%;justify-content:flex-start;padding:.75rem 0;min-height:44px}',
    '#notif-panel{position:fixed;top:0;left:0;right:0;width:100%!important;border-radius:0;max-height:65vh;border-top:none;z-index:9999}',
    '.nav-mobile-user{display:block}',
    '}'
  ].join('');
  document.head.appendChild(s);

  var P = window.location.pathname;
  function act(href) {
    if (href === '/') return P === '/' || P === '';
    return P === href || P.startsWith(href + '/');
  }

  var html =
    '<a href="/" class="nav-logo">🏕 Wolfs</a>' +
    '<button class="nav-hamburger" id="nav-hamburger-btn" onclick="_toggleNav()" aria-label="Menú">☰</button>' +
    '<div class="nav-links" id="nav-links">' +

    '<div class="dropdown' + (act('/') ? ' active-parent' : '') + '" data-req="reservaciones">' +
    '<button class="dropdown-btn' + (act('/') ? ' active' : '') + '" onclick="this.closest(\'.dropdown\').classList.toggle(\'open\')">Reservaciones ▾</button>' +
    '<div class="dropdown-content">' +
    '<a href="/?view=nueva" onclick="_closeNav()">Nueva reservación</a>' +
    '<a href="/?view=lista" onclick="_closeNav()">Lista</a>' +
    '<a href="/?view=pendientes" onclick="_closeNav()">Pendientes <span id="badge-pendientes" class="tab-badge" style="display:none"></span></a>' +
    '<a href="/?view=disponibilidad" onclick="_closeNav()">Disponibilidad</a>' +
    '</div></div>' +

    '<a href="/links"       class="nav-link' + (act('/links')       ? ' active' : '') + '" data-req="links"       onclick="_closeNav()">Links</a>' +
    '<a href="/jefes"       class="nav-link' + (act('/jefes')       ? ' active' : '') + '" data-req="jefes"       onclick="_closeNav()">Jefes</a>' +
    '<a href="/it"          class="nav-link' + (act('/it')          ? ' active' : '') + '" data-req="it"          onclick="_closeNav()">Panel IT</a>' +
    '<a href="/facturacion" class="nav-link' + (act('/facturacion') ? ' active' : '') + '" data-req="facturacion" onclick="_closeNav()">Facturación</a>' +
    '<a href="/cocina"      class="nav-link' + (act('/cocina')      ? ' active' : '') + '" data-req="cocina"      onclick="_closeNav()">Cocina</a>' +
    '<a href="/gestion"     class="nav-link' + (act('/gestion')     ? ' active' : '') + '" data-req="gestion"     onclick="_closeNav()">Gestión</a>' +

    '<div class="nav-spacer"></div>' +

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
    '<div class="nav-mobile-user" id="nav-mobile-user"></div>' +
    '</div>' +

    '<span id="header-user" style="font-size:.78rem;color:#888;white-space:nowrap;margin-left:.5rem"></span>' +
    '<div id="conn-dot" style="width:8px;height:8px;border-radius:50%;background:#888;transition:all .5s;flex-shrink:0" title="Verificando conexión…"></div>';

  var nav = document.querySelector('nav.nav');
  if (nav) nav.innerHTML = html;

  /* ══ API global ══════════════════════════════════════════════════ */

  window._toggleBell = function () {
    var p = document.getElementById('notif-panel');
    if (!p) return;
    var open = p.style.display === 'block';
    p.style.display = open ? 'none' : 'block';
    if (!open && typeof window._renderNotifPanel === 'function') window._renderNotifPanel();
  };

  window._closeNav = function () {
    var nl = document.getElementById('nav-links');
    var btn = document.getElementById('nav-hamburger-btn');
    if (nl) nl.classList.remove('open');
    if (btn) btn.textContent = '☰';
  };

  window._toggleNav = function () {
    var nl = document.getElementById('nav-links');
    var btn = document.getElementById('nav-hamburger-btn');
    if (!nl) return;
    var opening = !nl.classList.contains('open');
    nl.classList.toggle('open');
    if (btn) btn.textContent = opening ? '✕' : '☰';
  };

  window.setConnDot = function (ok) {
    var d = document.getElementById('conn-dot');
    if (!d) return;
    d.style.background = ok ? '#4ade80' : '#f87171';
    d.style.boxShadow  = ok ? '0 0 6px #4ade80' : '0 0 6px #f87171';
    d.title = ok ? 'Conectado a Supabase' : 'Sin conexión';
  };

  /* Mostrar nombre de usuario en mobile menu */
  window._setNavUser = function (nombre) {
    var el = document.getElementById('nav-mobile-user');
    if (el && nombre) el.textContent = '👤 ' + nombre;
  };

  /* Cerrar notif panel al click afuera */
  document.addEventListener('click', function (e) {
    var p = document.getElementById('notif-panel');
    var b = document.getElementById('notif-bell-btn');
    if (p && p.style.display === 'block' &&
        !((b && b.contains(e.target)) || p.contains(e.target))) {
      p.style.display = 'none';
    }
    /* Cerrar nav mobile al click afuera */
    var nl = document.getElementById('nav-links');
    var navEl = document.querySelector('nav.nav');
    if (nl && nl.classList.contains('open') && navEl && !navEl.contains(e.target)) {
      window._closeNav();
    }
  }, true);
})();
