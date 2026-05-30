-- ══════════════════════════════════════════════════════════════
--  Módulo de Clientes — Wolfs Acatenango
--  Correr en Supabase → SQL Editor
-- ══════════════════════════════════════════════════════════════

-- 1. Tabla principal de clientes
CREATE TABLE IF NOT EXISTS clientes (
  id                  BIGSERIAL PRIMARY KEY,
  nombre              TEXT NOT NULL,
  telefono            TEXT,
  correo              TEXT,
  identificacion      TEXT,
  nit                 TEXT,
  tipo_identificacion TEXT DEFAULT 'CF',
  nombre_fiscal       TEXT,
  notas_internas      TEXT,
  creado_at           TIMESTAMPTZ DEFAULT NOW(),
  actualizado_at      TIMESTAMPTZ DEFAULT NOW()
);

-- 2. Índices para búsqueda rápida
CREATE INDEX IF NOT EXISTS clientes_telefono_idx ON clientes(telefono) WHERE telefono IS NOT NULL;
CREATE INDEX IF NOT EXISTS clientes_correo_idx   ON clientes(correo)   WHERE correo   IS NOT NULL;
CREATE INDEX IF NOT EXISTS clientes_nombre_idx   ON clientes(nombre);

-- 3. Agregar cliente_id a reservaciones y links_pendientes
ALTER TABLE reservaciones     ADD COLUMN IF NOT EXISTS cliente_id BIGINT;
ALTER TABLE links_pendientes  ADD COLUMN IF NOT EXISTS cliente_id BIGINT;

-- 4. Índice para joins rápidos
CREATE INDEX IF NOT EXISTS reservaciones_cliente_id_idx    ON reservaciones(cliente_id)    WHERE cliente_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS links_pendientes_cliente_id_idx ON links_pendientes(cliente_id) WHERE cliente_id IS NOT NULL;
