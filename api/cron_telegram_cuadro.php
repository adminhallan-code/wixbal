<?php
// GET /api/cron/telegram-cuadro
// Manda el cuadro del día siguiente al grupo de Telegram.
// Configurar en cPanel: ejecutar cada día a las 6:00 AM (hora GT).

$manana = gmdate('Y-m-d', time() + (-6 * 3600) + 86400); // mañana en GT

$res = sb_get(
    "reservaciones?fecha_ascenso=eq.{$manana}&estado_pago=neq.Cancelado&select=nombre,tipo_cabana,no_personas,paquete,agencia,alergias,notas,es_vegano,es_vegetariano,cantidad_veganos,cantidad_vegetarianos,es_cumpleanos&order=tipo_cabana.asc"
);

$rows = $res['body'] ?? [];

if (empty($rows)) {
    telegram_notify("📋 <b>Cuadro de ascenso — {$manana}</b>\n\nSin reservaciones para mañana.");
    json_response(['ok' => true, 'mensaje' => 'Sin reservaciones', 'fecha' => $manana]);
}

// ── Calcular totales ──────────────────────────────────────────────────────────
$totales = ['Mixta' => 0, 'Privada' => 0, 'Familiar' => 0];
foreach ($rows as $r) {
    $cab = $r['tipo_cabana'] ?? '';
    $n   = (int)($r['no_personas'] ?? 1);
    if ($cab === 'Privada'  && $n <= 1) $n = 2;
    if ($cab === 'Familiar' && $n <= 1) $n = 4;
    $totales[$cab] = ($totales[$cab] ?? 0) + $n;
}
$total_general = array_sum($totales);

// ── Construir mensaje ─────────────────────────────────────────────────────────
$msg  = "📋 <b>Cuadro de ascenso — {$manana}</b>\n";
$msg .= "Total: <b>{$total_general} personas</b>";
$msg .= " (Mixta: {$totales['Mixta']} · Privada: {$totales['Privada']} · Familiar: {$totales['Familiar']})\n";
$msg .= str_repeat('─', 30) . "\n";

$i = 1;
foreach ($rows as $r) {
    $cab  = $r['tipo_cabana']   ?? '?';
    $n    = (int)($r['no_personas'] ?? 1);
    if ($cab === 'Privada'  && $n <= 1) $n = 2;
    if ($cab === 'Familiar' && $n <= 1) $n = 4;

    $paq  = $r['paquete']  ?? '';
    $ag   = $r['agencia']  ?? '';
    $aler = $r['alergias'] ?? '';
    $nota = $r['notas']    ?? '';

    $nVgn = (int)($r['cantidad_veganos']      ?? 0) + ($r['es_vegano']      ? 1 : 0);
    $nVeg = (int)($r['cantidad_vegetarianos'] ?? 0) + ($r['es_vegetariano'] ? 1 : 0);
    $cumple = $r['es_cumpleanos'] ?? false;

    $cabIcon = $cab === 'Mixta' ? '🔵' : ($cab === 'Privada' ? '🩷' : '🟢');

    $msg .= "\n{$i}. <b>" . htmlspecialchars($r['nombre'] ?? '—') . "</b>\n";
    $msg .= "   {$cabIcon} {$cab} · {$n} pers · {$paq}";
    if ($ag) $msg .= " · " . htmlspecialchars($ag);
    $msg .= "\n";
    if ($aler) $msg .= "   ⚠️ Alergia: " . htmlspecialchars($aler) . "\n";
    if ($nVgn) $msg .= "   🌿 Vegano ×{$nVgn}\n";
    if ($nVeg) $msg .= "   🥦 Vegetariano ×{$nVeg}\n";
    if ($cumple) $msg .= "   🎂 ¡Cumpleaños!\n";
    if ($nota)  $msg .= "   📝 " . htmlspecialchars($nota) . "\n";

    $i++;
}

telegram_notify($msg);
json_response(['ok' => true, 'fecha' => $manana, 'reservaciones' => count($rows), 'total_personas' => $total_general]);
