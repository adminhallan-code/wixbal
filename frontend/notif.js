// notif.js — Notificaciones de cancelaciones/reprogramaciones del equipo
// Se inicializa con initNotifBell() después del login.
// Compatible con la campana existente (#notif-dropdown / #notif-badge)
// y con la campana propia de gestion.html (#notif-panel / #notif-bell-btn).

const NOTIF_LS_KEY = 'wolfs_notif_visto';
let _notifInterval = null;
let _notifData     = [];

async function initNotifBell() {
  await _fetchNotificaciones();
  if (_notifInterval) clearInterval(_notifInterval);
  _notifInterval = setInterval(_fetchNotificaciones, 60_000);
}

async function _fetchNotificaciones() {
  try {
    const res  = await fetch('/api/notificaciones');
    _notifData = res.ok ? await res.json() : [];
    _renderBadge();
    _injectIntoExistingDropdown();
  } catch { /* silencioso */ }
}

function _vistaDesde() {
  return localStorage.getItem(NOTIF_LS_KEY) || '1970-01-01T00:00:00.000Z';
}
function _noLeidas() {
  const desde = _vistaDesde();
  return _notifData.filter(n => n.creado_en > desde);
}

// Actualiza el badge de la campana (existente o nueva)
function _renderBadge() {
  const badge = document.getElementById('notif-badge');
  if (!badge) return;
  const count = _noLeidas().length;
  badge.textContent = count > 9 ? '9+' : String(count);
  badge.style.display = count > 0 ? '' : 'none';
}

// Inyecta sección de cancelaciones dentro del dropdown EXISTENTE (#notif-dropdown)
function _injectIntoExistingDropdown() {
  const dropdown = document.getElementById('notif-dropdown');
  if (!dropdown) return;

  // Eliminar sección previa si ya existe
  const prev = dropdown.querySelector('#notif-cancelaciones-section');
  if (prev) prev.remove();

  if (!_notifData.length) return;

  const recent = _notifData.slice(0, 10);
  const iconos = { cancelacion: '🚫', reprogramacion_pagada: '📅' };

  const section = document.createElement('div');
  section.id = 'notif-cancelaciones-section';
  section.innerHTML = `
    <div class="notif-title" style="margin-top:8px">Cancelaciones y reprogramaciones</div>
    ${recent.map(n => `
      <div class="notif-item" style="${_noLeidas().some(x => x.id === n.id) ? 'font-weight:600;' : ''}">
        ${iconos[n.tipo] || '🔔'} ${n.titulo || ''}
        <div style="color:var(--text2);font-size:11px;margin-top:2px">${n.mensaje || ''}</div>
        <div style="color:var(--text3);font-size:10px">${_fmtDatetime(n.creado_en)}</div>
      </div>`).join('')}
    <div style="padding:6px 0;text-align:center">
      <a href="/gestion#tab=notificaciones" style="font-size:11px;color:var(--text3)">Ver todas →</a>
    </div>`;
  dropdown.appendChild(section);

  // Marcar como vistas al pasar el mouse sobre la campana
  const bell = document.getElementById('notif-bell');
  if (bell && !bell._notifListenerAdded) {
    bell.addEventListener('mouseenter', () => {
      localStorage.setItem(NOTIF_LS_KEY, new Date().toISOString());
      _renderBadge();
    });
    bell._notifListenerAdded = true;
  }
}

// Para gestion.html (que tiene su propia campana con click)
function toggleNotifPanel() {
  const panel = document.getElementById('notif-panel');
  if (!panel) return;
  const abierto = panel.style.display === 'block';
  if (abierto) {
    panel.style.display = 'none';
  } else {
    localStorage.setItem(NOTIF_LS_KEY, new Date().toISOString());
    _renderBadge();
    _renderGestionPanel();
    panel.style.display = 'block';
  }
}

document.addEventListener('click', e => {
  const panel = document.getElementById('notif-panel');
  const btn   = document.getElementById('notif-bell-btn');
  if (panel && btn && !panel.contains(e.target) && !btn.contains(e.target)) {
    panel.style.display = 'none';
  }
});

function _renderGestionPanel() {
  const list = document.getElementById('notif-list');
  if (!list) return;
  if (!_notifData.length) {
    list.innerHTML = '<div style="padding:1.2rem;text-align:center;color:#888;font-size:.85rem">Sin notificaciones recientes</div>';
    return;
  }
  const iconos = { cancelacion: '🚫', reprogramacion_pagada: '📅' };
  list.innerHTML = _notifData.slice(0, 20).map(n => `
    <div style="display:flex;gap:.65rem;padding:.7rem 1rem;border-bottom:1px solid #f0f0f0;${_noLeidas().some(x=>x.id===n.id)?'background:#fefce8':''}">
      <span style="font-size:1.1rem;flex-shrink:0">${iconos[n.tipo]||'🔔'}</span>
      <div style="flex:1;min-width:0">
        <div style="font-weight:600;font-size:.82rem">${n.titulo||''}</div>
        <div style="font-size:.78rem;color:#555;line-height:1.4;margin-top:1px">${n.mensaje||''}</div>
        <div style="font-size:.72rem;color:#888;margin-top:3px">${_fmtDatetime(n.creado_en)}${n.creado_por?' · '+n.creado_por:''}</div>
      </div>
    </div>`).join('');
}

function _fmtDatetime(iso) {
  if (!iso) return '';
  try { return new Date(iso).toLocaleString('es-GT',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}); }
  catch { return iso; }
}
