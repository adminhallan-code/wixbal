const _WOLFS_KEY = 'wolfs_session';

const _USUARIOS = [
  { pw: 'Ana@@2810',    nombre: 'ANA LAURA VALDEZ LOPEZ',     acceso: ['reservaciones','links','gestion'] },
  { pw: 'Ceci@@2726',   nombre: 'ANA CECILIA HERMOSILLA',      acceso: ['reservaciones','links'], rol: 'ventas' },
  { pw: 'Alison@@0506', nombre: 'ALLISON STEPHANIA MORALES MONROY', acceso: ['reservaciones','links'], rol: 'ventas' },
  { pw: 'Pancho@@1642', nombre: 'JUAN FRANCISCO VALDEZ LOPEZ', acceso: ['reservaciones','links','jefes','it','facturacion','cocina','gestion'] },
  { pw: 'Hallan@@2026', nombre: 'HALLAN YINNG GARCIA NAJERA',  acceso: ['reservaciones','links','jefes','facturacion','cocina','gestion'] },
  { pw: 'Maria@@2026',  nombre: 'MARIA',                       acceso: ['reservaciones','links','jefes','facturacion','cocina','gestion'] },
];

function authLogin(pw) {
  const u = _USUARIOS.find(x => x.pw === pw);
  if (!u) return null;
  const s = { nombre: u.nombre, acceso: u.acceso, rol: u.rol || 'aprobador' };
  sessionStorage.setItem(_WOLFS_KEY, JSON.stringify(s));
  return s;
}

function authLogout() {
  sessionStorage.removeItem(_WOLFS_KEY);
  window.location.href = '/';
}

function authUser() {
  try { return JSON.parse(sessionStorage.getItem(_WOLFS_KEY)); } catch { return null; }
}

// Retorna el usuario si tiene acceso, null si no está logueado, redirige si no tiene permiso
function authGuard(pagina) {
  const u = authUser();
  if (!u) return null;
  if (!u.acceso.includes(pagina)) { window.location.href = '/'; return null; }
  return u;
}

// Oculta los nav links que el usuario no puede ver
function authNav(user) {
  document.querySelectorAll('[data-req]').forEach(el => {
    if (!user.acceso.includes(el.dataset.req)) el.style.display = 'none';
  });
}
