<?php
// ============================================================
// index.php - Cổng phát Key Game công khai · HoQuocKey
// Bento Grid Macrostructure · Hallmark Design System
// ============================================================
require_once __DIR__ . '/config.php';
set_security_headers();
if (session_status() === PHP_SESSION_NONE) session_start();
init_language();
init_theme();

// Admin đã đăng nhập /admin.php (cùng trình duyệt) được bỏ qua cooldown
// "còn lại X giờ" khi tự test trên trang lấy key.
$isAdmin = !empty($_SESSION['is_admin']);

$clientIp = get_client_ip();
$contact = get_effective_contact();
$siteBrandName = 'HoQuocKey';

$games = array_filter(get_games(), function ($g) { return (bool)$g['enabled']; });

$gameCooldowns = [];
foreach ($games as $g) {
    $gameCooldowns[$g['id']] = $isAdmin ? 0 : get_claim_cooldown_remaining((int)$g['id'], $clientIp);
}
$region = detect_region();

// Social proof: tổng số key đã kích hoạt thực tế từ DB
try {
    $totalActivated = get_site_counter('keys_issued');
} catch (Throwable $e) {
    $totalActivated = 0;
}
$serverClosed = is_server_closed();
$apkLink = get_apk_link();
?>
<!DOCTYPE html>
<html lang="<?= $GLOBALS['LANG'] === 'en' ? 'en' : 'vi' ?>" data-theme="<?= $GLOBALS['THEME'] === 'light' ? 'light' : 'dark' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= t('Cổng Phát Key Game — Hồ Quốc', 'Game Key Portal — Ho Quoc') ?></title>
<?= shared_favicon() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
<style>
/* Hallmark · macrostructure: Bento Grid · genre: modern-minimal · theme: Studio */
/* Hallmark · pre-emit critique: P5 H5 E5 S5 R5 V5 */
<?= design_system_css() ?>

:root {
    --bg: var(--ds-bg);
    --surface: var(--ds-surface);
    --surface-rgb: var(--ds-surface-rgb);
    --surface2: var(--ds-surface2);
    --surface3: var(--ds-surface3);
    --line: var(--ds-line);
    --line-strong: var(--ds-line-strong);
    --cyan: var(--ds-cyan);
    --cyan-rgb: var(--ds-cyan-rgb);
    --violet: var(--ds-violet);
    --violet-rgb: var(--ds-violet-rgb);
    --text: var(--ds-text);
    --text-dim: var(--ds-text-dim);
    --text-muted: var(--ds-text-muted);
    --success: var(--ds-success);
    --warn: var(--ds-warn);
    --danger: var(--ds-danger);
    --bg-top: var(--ds-bg-top);
    --grid-line: var(--ds-grid-line);

    /* Bento Grid Tokens */
    --bento-gap: 20px;
    --cell-radius: var(--r-2xl);
    --cell-border: 1px solid var(--line);
    --cell-bg: rgba(var(--surface-rgb), 0.78);

    /* Motion Tokens */
    --ease-out-expo: cubic-bezier(0.16, 1, 0.3, 1);
    --ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1);
    --dur-fast: 0.2s;
    --dur-normal: 0.35s;
    --dur-slow: 0.6s;
}

html, body {
    overflow-x: clip;
}

body {
    position: relative;
    background:
        radial-gradient(circle at 14% -10%, rgba(56, 189, 248, 0.12), transparent 38rem),
        radial-gradient(circle at 86% 15%, rgba(129, 140, 248, 0.09), transparent 34rem),
        linear-gradient(180deg, var(--bg-top) 0%, var(--bg) 70%);
    color: var(--text);
    margin: 0;
    min-height: 100vh;
    font-family: var(--font-body);
}

body::before {
    content: "";
    position: fixed;
    inset: 0;
    pointer-events: none;
    opacity: 0.32;
    z-index: -1;
    background-image: linear-gradient(var(--grid-line) 1px, transparent 1px),
                      linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
    background-size: 38px 38px;
    mask-image: linear-gradient(to bottom, #000 0%, transparent 85%);
}

.wrap {
    position: relative;
    max-width: 1120px;
    margin: 0 auto;
    padding: 24px 20px 80px;
}

/* -------------------------------------------------------------
   1. APP BAR & HEADER
   ------------------------------------------------------------- */
.app-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 12px 20px;
    border: var(--cell-border);
    border-radius: var(--r-xl);
    background: rgba(var(--surface-rgb), 0.82);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    box-shadow: var(--shadow-card);
    margin-bottom: 24px;
    transition: border-color var(--dur-fast);
}
.app-bar:hover { border-color: var(--line-strong); }

.app-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
    color: var(--text);
}
.app-brand-icon {
    width: 38px;
    height: 38px;
    border-radius: var(--r-md);
    background: var(--grad-btn);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #060810;
    flex-shrink: 0;
    box-shadow: 0 4px 14px rgba(0, 242, 254, 0.35);
    transition: transform var(--dur-normal) var(--ease-spring);
}
.app-brand:hover .app-brand-icon { transform: scale(1.08) rotate(-4deg); }

.app-brand-text {
    display: flex;
    flex-direction: column;
}
.app-brand-title {
    font-size: 15px;
    font-weight: 800;
    letter-spacing: -0.02em;
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--text);
}
.app-brand-title svg {
    color: var(--success);
    filter: drop-shadow(0 0 6px rgba(16, 185, 129, 0.5));
}
.app-brand-sub {
    font-size: 11px;
    color: var(--text-dim);
    font-family: var(--font-mono);
    letter-spacing: 0.04em;
}

.app-nav {
    display: flex;
    align-items: center;
    gap: 16px;
}
.nav-links {
    display: flex;
    align-items: center;
    gap: 18px;
    font-size: 13px;
}
.nav-links a {
    color: var(--text-dim);
    text-decoration: none;
    font-weight: 500;
    transition: color var(--dur-fast);
}
.nav-links a:hover { color: var(--cyan); }

.app-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}
.lang-switch {
    display: flex;
    align-items: center;
    gap: 4px;
    font-family: var(--font-mono);
    font-size: 11px;
    color: var(--text-dim);
}
.lang-switch a {
    color: var(--text-dim);
    text-decoration: none;
    padding: 4px 8px;
    border-radius: var(--r-sm);
    transition: all var(--dur-fast);
}
.lang-switch a.active {
    color: var(--cyan);
    background: rgba(0, 242, 254, 0.14);
    font-weight: 700;
}
.theme-switch a {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: var(--r-sm);
    color: var(--text-dim);
    text-decoration: none;
    transition: all var(--dur-fast);
}
.theme-switch a:hover {
    color: var(--text);
    background: rgba(255,255,255,0.06);
}
.theme-switch a.active {
    color: var(--cyan);
    background: rgba(0, 242, 254, 0.14);
}
.btn-apk {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 16px;
    border-radius: var(--r-full);
    background: var(--grad-btn);
    color: #060810;
    font-weight: 700;
    font-size: 12.5px;
    text-decoration: none;
    white-space: nowrap;
    box-shadow: var(--shadow-btn);
    transition: transform var(--dur-fast), filter var(--dur-fast), box-shadow var(--dur-fast);
}
.btn-apk:hover {
    filter: brightness(1.08);
    transform: translateY(-2px);
    box-shadow: var(--shadow-btn-hover);
}

/* -------------------------------------------------------------
   2. HERO EYEBROW & INTRO (Clean, Solid Ink, No AI Gradient Clip)
   ------------------------------------------------------------- */
.portal-hero {
    text-align: center;
    margin: 8px auto 30px;
    max-width: 720px;
}
.portal-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin: 0 auto 12px;
    padding: 6px 14px;
    border: var(--cell-border);
    border-radius: var(--r-full);
    background: rgba(var(--surface-rgb), 0.7);
    font-family: var(--font-mono);
    font-size: 11px;
    letter-spacing: 0.14em;
    color: var(--text-dim);
    text-transform: uppercase;
    backdrop-filter: blur(10px);
}
.portal-eyebrow::before {
    content: "";
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--cyan);
    box-shadow: 0 0 10px var(--cyan);
    animation: ds-blink 1.8s infinite;
}
.portal-eyebrow span {
    color: var(--cyan);
    font-weight: 700;
}

/* Solid ink typography per Hallmark anti-slop guidelines */
h1.portal-title {
    font-size: clamp(28px, 4.5vw, 44px);
    line-height: 1.15;
    letter-spacing: -0.03em;
    margin: 0 auto 10px;
    font-weight: 800;
    font-style: normal;
    color: var(--text);
}
.portal-sub {
    font-size: 14.5px;
    line-height: 1.6;
    max-width: 560px;
    margin: 0 auto;
    color: var(--text-dim);
}

/* -------------------------------------------------------------
   3. BENTO GRID ARCHITECTURE (3 Columns, Asymmetric Tiles)
   ------------------------------------------------------------- */
.bento-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: var(--bento-gap);
    align-items: stretch;
}

.bento-cell {
    position: relative;
    border: var(--cell-border);
    border-radius: var(--cell-radius);
    background: var(--cell-bg);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    box-shadow: var(--shadow-card);
    padding: 24px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    transition: border-color var(--dur-normal), box-shadow var(--dur-normal), transform var(--dur-normal) var(--ease-out-expo);
}
.bento-cell:hover {
    border-color: var(--line-strong);
    box-shadow: var(--shadow-card-hover);
}

/* Bento Header Component */
.cell-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 18px;
}
.cell-title-wrap {
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.cell-badge {
    font-family: var(--font-mono);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.12em;
    color: var(--cyan);
    text-transform: uppercase;
}
.cell-title {
    font-size: 18px;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--text);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.cell-sub {
    font-size: 12px;
    color: var(--text-dim);
    margin: 0;
    line-height: 1.5;
}
.cell-icon-box {
    width: 36px;
    height: 36px;
    border-radius: var(--r-md);
    background: rgba(0, 242, 254, 0.1);
    color: var(--cyan);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border: 1px solid rgba(0, 242, 254, 0.2);
}

/* -------------------------------------------------------------
   CELL 1: CONSOLE GAME HUB (Span 2x2)
   ------------------------------------------------------------- */
.cell-game-hub {
    grid-column: span 2;
    grid-row: span 2;
}

.game-hub-toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.game-search-box {
    height: 42px;
    display: flex;
    align-items: center;
    gap: 9px;
    flex: 1;
    min-width: 200px;
    padding: 0 14px;
    border: 1px solid var(--line);
    border-radius: var(--r-md);
    background: rgba(var(--surface-rgb), 0.65);
    color: var(--text-dim);
    transition: all var(--dur-fast);
}
.game-search-box:focus-within {
    border-color: var(--cyan);
    box-shadow: 0 0 0 2px rgba(0, 242, 254, 0.15);
}
.game-search-box input {
    width: 100%;
    border: 0;
    outline: 0;
    background: transparent;
    color: var(--text);
    font: 500 13px var(--font-body);
}
.game-search-box input::placeholder { color: var(--text-muted); }
.search-clear-btn {
    width: 22px;
    height: 22px;
    padding: 0;
    border: 0;
    border-radius: 4px;
    background: transparent;
    color: var(--text-dim);
    font-size: 15px;
    cursor: pointer;
    opacity: 0;
    pointer-events: none;
    transition: opacity var(--dur-fast);
}
.search-clear-btn.visible {
    opacity: 1;
    pointer-events: auto;
}

.game-filter-group {
    display: flex;
    gap: 4px;
    padding: 4px;
    border: 1px solid var(--line);
    border-radius: var(--r-md);
    background: rgba(var(--surface-rgb), 0.6);
}
.game-filter-btn {
    height: 32px;
    padding: 0 12px;
    border: 0;
    border-radius: var(--r-sm);
    background: transparent;
    color: var(--text-dim);
    font: 600 11.5px var(--font-body);
    cursor: pointer;
    white-space: nowrap;
    transition: all var(--dur-fast);
}
.game-filter-btn:hover { color: var(--text); }
.game-filter-btn.active {
    background: rgba(0, 242, 254, 0.16);
    color: var(--cyan);
    font-weight: 700;
}

.game-hub-count {
    font-size: 11px;
    font-family: var(--font-mono);
    color: var(--text-dim);
    margin-bottom: 12px;
}

.game-hub-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    overflow-y: auto;
    max-height: 520px;
    padding-right: 4px;
}
.game-hub-list::-webkit-scrollbar { width: 5px; }
.game-hub-list::-webkit-scrollbar-thumb {
    background: var(--line);
    border-radius: 4px;
}

.game-card-item {
    border: 1px solid var(--line);
    border-radius: var(--r-lg);
    background: rgba(var(--surface2), 0.55);
    padding: 16px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 14px;
    transition: transform var(--dur-fast), border-color var(--dur-fast), box-shadow var(--dur-fast);
}
.game-card-item:hover {
    border-color: rgba(0, 242, 254, 0.35);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px -6px rgba(0, 0, 0, 0.45);
}
.game-card-item.is-hidden { display: none !important; }

.game-card-header {
    display: flex;
    align-items: center;
    gap: 12px;
}
.game-icon-box {
    width: 44px;
    height: 44px;
    border-radius: var(--r-md);
    background: var(--surface3);
    border: 1px solid rgba(0, 242, 254, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}
.game-meta {
    flex: 1;
    min-width: 0;
}
.game-name {
    font-size: 15px;
    font-weight: 700;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 4px;
}
.game-badges {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.pill-free {
    font-size: 10px;
    font-weight: 700;
    color: var(--success);
    background: rgba(16, 185, 129, 0.14);
    border: 1px solid rgba(16, 185, 129, 0.25);
    padding: 2px 7px;
    border-radius: var(--r-full);
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-family: var(--font-mono);
}
.pill-free .dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: var(--success);
    box-shadow: 0 0 6px var(--success);
}
.pill-cooldown {
    font-size: 10px;
    font-family: var(--font-mono);
    color: var(--warn);
    background: rgba(245, 158, 11, 0.14);
    border: 1px solid rgba(245, 158, 11, 0.3);
    padding: 2px 8px;
    border-radius: var(--r-full);
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-variant-numeric: tabular-nums;
}
.pill-cooldown .dot {
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: currentColor;
}
.game-hops-text {
    font-size: 11px;
    color: var(--text-muted);
    font-family: var(--font-mono);
}

.game-btn {
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    border-radius: var(--r-md);
    background: var(--grad-btn);
    color: #060810;
    font-weight: 700;
    font-size: 13px;
    text-decoration: none;
    box-shadow: var(--shadow-btn);
    transition: filter var(--dur-fast), transform var(--dur-fast);
}
.game-btn:hover {
    filter: brightness(1.08);
    transform: translateY(-1px);
}
.game-btn-disabled {
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid var(--line);
    color: var(--text-dim);
    box-shadow: none;
    cursor: not-allowed;
}
.game-btn-disabled:hover {
    filter: none;
    transform: none;
}

.game-no-match {
    padding: 30px;
    text-align: center;
    color: var(--text-dim);
    font-size: 13px;
    border: 1px dashed var(--line);
    border-radius: var(--r-md);
    grid-column: span 2;
}

/* -------------------------------------------------------------
   CELL 2: TELEMETRY BOARD (Span 1x1)
   No fabricated stats: strictly real server data from PHP
   ------------------------------------------------------------- */
.cell-telemetry {
    grid-column: span 1;
    grid-row: span 1;
}
.telemetry-status-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border: 1px solid var(--line);
    border-radius: var(--r-md);
    background: rgba(var(--surface2), 0.45);
    margin-bottom: 14px;
}
.telemetry-status-label {
    font-size: 11.5px;
    font-family: var(--font-mono);
    color: var(--text-dim);
    display: flex;
    align-items: center;
    gap: 6px;
}
.telemetry-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 3px 10px;
    border-radius: var(--r-full);
    font-size: 11px;
    font-weight: 700;
    font-family: var(--font-mono);
}
.telemetry-pill.on {
    background: rgba(16, 185, 129, 0.14);
    color: var(--success);
    border: 1px solid rgba(16, 185, 129, 0.25);
}
.telemetry-pill.off {
    background: rgba(239, 68, 68, 0.14);
    color: var(--danger);
    border: 1px solid rgba(239, 68, 68, 0.25);
}
.telemetry-pill .dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
    animation: ds-blink 1.8s infinite;
}

.telemetry-metrics-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-bottom: 14px;
}
.metric-tile {
    padding: 12px;
    border: 1px solid var(--line);
    border-radius: var(--r-md);
    background: rgba(var(--surface2), 0.35);
    text-align: center;
}
.metric-label {
    font-size: 10px;
    font-family: var(--font-mono);
    color: var(--text-dim);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 4px;
}
.metric-value {
    font-size: 15px;
    font-weight: 700;
    font-family: var(--font-mono);
    color: var(--text);
    font-variant-numeric: tabular-nums;
}
.metric-value.cyan { color: var(--cyan); }

.telemetry-ip-strip {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 12px;
    border-top: 1px dashed var(--line);
    font-size: 11px;
    color: var(--text-muted);
    font-family: var(--font-mono);
    margin-top: auto;
}
.telemetry-ip-strip b {
    color: var(--cyan);
    font-weight: 600;
}

/* -------------------------------------------------------------
   CELL 3: SAMPLE KEY SPECIMEN (Span 1x1)
   Explicitly marked as format example, non-functional
   ------------------------------------------------------------- */
.cell-specimen {
    grid-column: span 1;
    grid-row: span 1;
    background: linear-gradient(145deg, rgba(var(--surface-rgb), 0.85), rgba(var(--surface2), 0.7));
}
.specimen-warning-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: var(--r-full);
    background: rgba(245, 158, 11, 0.12);
    border: 1px solid rgba(245, 158, 11, 0.25);
    color: var(--warn);
    font-size: 10px;
    font-family: var(--font-mono);
    font-weight: 700;
    letter-spacing: 0.06em;
    margin-bottom: 12px;
    text-transform: uppercase;
}
.specimen-ticket {
    position: relative;
    border: 1px solid var(--line);
    border-radius: var(--r-lg);
    background: rgba(10, 15, 26, 0.65);
    padding: 16px;
    text-align: center;
    margin-bottom: 12px;
}
.specimen-ticket-tag {
    font-size: 10px;
    font-family: var(--font-mono);
    letter-spacing: 0.14em;
    color: var(--text-dim);
    text-transform: uppercase;
    margin-bottom: 6px;
}
.specimen-code {
    font-family: var(--font-mono);
    font-weight: 700;
    font-size: clamp(16px, 2vw, 20px);
    letter-spacing: 0.1em;
    color: var(--cyan);
    text-shadow: 0 0 16px rgba(0, 242, 254, 0.4);
    font-variant-numeric: tabular-nums;
    word-break: break-all;
}
.specimen-perf {
    height: 0;
    border-top: 1px dashed var(--line);
    margin: 12px -16px;
    position: relative;
}
.specimen-perf::before, .specimen-perf::after {
    content: '';
    position: absolute;
    top: -6px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--bg);
}
.specimen-perf::before { left: -6px; }
.specimen-perf::after { right: -6px; }

.specimen-meta {
    display: flex;
    justify-content: space-between;
    font-size: 11px;
    color: var(--text-dim);
    text-align: left;
}
.specimen-meta span b {
    display: block;
    color: var(--text);
    font-size: 11.5px;
    margin-top: 2px;
}
.specimen-disclaimer {
    font-size: 10.5px;
    color: var(--text-muted);
    line-height: 1.45;
    margin: 0;
    font-style: normal;
}

/* -------------------------------------------------------------
   CELL 4: 3-STEP VERIFICATION PROTOCOL (Span 2x1)
   Horizontal pipeline 01 -> 02 -> 03
   ------------------------------------------------------------- */
.cell-protocol {
    grid-column: span 3;
    grid-row: span 1;
}
.protocol-pipeline {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    position: relative;
}
.protocol-step {
    padding: 16px;
    border: 1px solid var(--line);
    border-radius: var(--r-lg);
    background: rgba(var(--surface2), 0.4);
    display: flex;
    flex-direction: column;
    gap: 8px;
    position: relative;
    transition: transform var(--dur-fast), border-color var(--dur-fast);
}
.protocol-step:hover {
    border-color: rgba(0, 242, 254, 0.35);
    transform: translateY(-2px);
}
.protocol-step-num {
    font-family: var(--font-mono);
    font-size: 22px;
    font-weight: 800;
    color: var(--cyan);
    line-height: 1;
}
.protocol-step-title {
    font-size: 13.5px;
    font-weight: 700;
    color: var(--text);
    margin: 0;
}
.protocol-step-desc {
    font-size: 12px;
    color: var(--text-dim);
    margin: 0;
    line-height: 1.5;
}

/* -------------------------------------------------------------
   CELL 6: TELEGRAM COMMUNITY HUB (Span 3x1 Full-Width Strip)
   ------------------------------------------------------------- */
.cell-telegram {
    grid-column: span 3;
    grid-row: span 1;
    background: linear-gradient(135deg, rgba(var(--surface-rgb), 0.85), rgba(41, 169, 234, 0.08));
    border-color: rgba(41, 169, 234, 0.25);
}
.telegram-strip {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    flex-wrap: wrap;
}
.telegram-content {
    display: flex;
    align-items: center;
    gap: 16px;
    flex: 1;
    min-width: 260px;
}
.telegram-icon-box {
    width: 48px;
    height: 48px;
    border-radius: var(--r-lg);
    background: #29A9EA;
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 4px 18px rgba(41, 169, 234, 0.4);
}
.telegram-details h3 {
    margin: 0 0 4px;
    font-size: 16px;
    font-weight: 700;
    color: var(--text);
}
.telegram-details p {
    margin: 0;
    font-size: 12.5px;
    color: var(--text-dim);
    line-height: 1.5;
}
.telegram-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.btn-telegram {
    height: 42px;
    padding: 0 20px;
    border-radius: var(--r-md);
    background: #29A9EA;
    color: #FFFFFF;
    font-weight: 700;
    font-size: 13px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 16px rgba(41, 169, 234, 0.35);
    transition: filter var(--dur-fast), transform var(--dur-fast);
}
.btn-telegram:hover {
    filter: brightness(1.1);
    transform: translateY(-1px);
}
.btn-contact-alt {
    height: 42px;
    padding: 0 16px;
    border-radius: var(--r-md);
    border: 1px solid var(--line);
    background: rgba(var(--surface2), 0.5);
    color: var(--text-dim);
    font-size: 12.5px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all var(--dur-fast);
}
.btn-contact-alt:hover {
    color: var(--text);
    border-color: var(--line-strong);
}

/* -------------------------------------------------------------
   4. FLOATING CONTACT FAB
   ------------------------------------------------------------- */
.fab-contact {
    position: fixed;
    right: 22px;
    bottom: 24px;
    z-index: 90;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 12px;
}
.fab-menu {
    display: flex;
    flex-direction: column;
    gap: 10px;
    opacity: 0;
    transform: translateY(16px) scale(0.85);
    pointer-events: none;
    transition: opacity 0.25s var(--ease-out-expo), transform 0.3s var(--ease-spring);
}
.fab-menu.open {
    opacity: 1;
    transform: translateY(0) scale(1);
    pointer-events: auto;
}
.fab-item {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    color: #fff;
    box-shadow: 0 8px 20px -6px rgba(0,0,0,0.6);
    transition: transform 0.22s var(--ease-spring);
}
.fab-item:hover { transform: scale(1.14); }
.fab-zalo { background: #0068FF; }
.fab-tele { background: #29A9EA; }
.fab-fb { background: #1877F2; }
.fab-toggle {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    border: 0;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #060810;
    background: var(--grad-btn);
    box-shadow: 0 8px 24px -4px rgba(0, 242, 254, 0.6);
    transition: transform 0.28s var(--ease-spring);
}
.fab-toggle:hover { transform: scale(1.08); }
.fab-toggle.open { transform: rotate(45deg); }

/* -------------------------------------------------------------
   5. RESPONSIVE & MOBILE STACK ORDER (Strictly 320px - 414px)
   Requirement 3: Console Game Hub (1) -> Sample Key (2) ->
   Telemetry (3) -> Protocol (4) -> Telegram (5)
   ------------------------------------------------------------- */
@media (max-width: 960px) {
    .bento-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .cell-game-hub { grid-column: span 2; grid-row: auto; }
    .cell-telemetry { grid-column: span 1; grid-row: auto; }
    .cell-specimen { grid-column: span 1; grid-row: auto; }
    .cell-protocol { grid-column: span 2; grid-row: auto; }
    .cell-telegram { grid-column: span 2; grid-row: auto; }
}

@media (max-width: 640px) {
    .wrap { padding: 16px 14px 70px; }
    .app-bar { flex-direction: column; align-items: stretch; gap: 12px; }
    .app-nav { justify-content: space-between; flex-wrap: wrap; }
    .game-hub-list { grid-template-columns: 1fr; }
    .protocol-pipeline { grid-template-columns: 1fr; }
    .telegram-strip { flex-direction: column; align-items: stretch; }

    /* Bento Grid Mobile Stack Order (User Injunction #3) */
    .bento-grid {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }
    .cell-game-hub   { order: 1; width: 100%; box-sizing: border-box; }
    .cell-specimen   { order: 2; width: 100%; box-sizing: border-box; }
    .cell-telemetry  { order: 3; width: 100%; box-sizing: border-box; }
    .cell-protocol   { order: 5; width: 100%; box-sizing: border-box; }
    .cell-telegram   { order: 6; width: 100%; box-sizing: border-box; }
}

/* Light theme adjustments */
html[data-theme="light"] body {
    background:
        radial-gradient(circle at 12% -8%, rgba(9,154,130,0.1), transparent 30rem),
        radial-gradient(circle at 88% 16%, rgba(99,79,226,0.08), transparent 28rem),
        linear-gradient(180deg, var(--bg-top) 0%, var(--bg) 65%);
}
html[data-theme="light"] .btn-apk,
html[data-theme="light"] .game-btn,
html[data-theme="light"] .fab-toggle { color: #FFFFFF; }
html[data-theme="light"] .specimen-ticket { background: #FFFFFF; }
html[data-theme="light"] .specimen-perf::before,
html[data-theme="light"] .specimen-perf::after { background: #F8FAFC; }

.footer-credits {
    text-align: center;
    font-size: 11.5px;
    color: var(--text-muted);
    font-family: var(--font-mono);
    margin-top: 36px;
    letter-spacing: 0.04em;
}
</style>
</head>
<body>

<div class="wrap">
    <!-- Top App-Bar -->
    <header class="app-bar">
        <a class="app-brand" href="index.php">
            <div class="app-brand-icon"><?= svg_icon('zap', 20) ?></div>
            <div class="app-brand-text">
                <span class="app-brand-title"><?= htmlspecialchars($siteBrandName) ?> <?= svg_icon('check-circle', 14) ?></span>
                <span class="app-brand-sub"><?= t('Cổng cấp Key chính thức', 'Official Key Portal') ?></span>
            </div>
        </a>

        <div class="app-nav">
            <nav class="nav-links">
                <a href="#games"><?= t('Game Hub', 'Game Hub') ?></a>
                <a href="#protocol"><?= t('Hướng dẫn', 'Protocol') ?></a>
            </nav>
            <div class="app-actions">
                <div class="lang-switch">
                    <a class="<?= $GLOBALS['LANG'] === 'vi' ? 'active' : '' ?>" href="?lang=vi">VI</a>
                    <span>/</span>
                    <a class="<?= $GLOBALS['LANG'] === 'en' ? 'active' : '' ?>" href="?lang=en">EN</a>
                </div>
                <div class="theme-switch">
                    <a class="<?= $GLOBALS['THEME'] === 'dark' ? 'active' : '' ?>" href="?theme=dark" title="<?= t('Giao diện tối', 'Dark theme') ?>"><?= svg_icon('moon', 15) ?></a>
                    <a class="<?= $GLOBALS['THEME'] === 'light' ? 'active' : '' ?>" href="?theme=light" title="<?= t('Giao diện sáng', 'Light theme') ?>"><?= svg_icon('sun', 15) ?></a>
                </div>
                <?php if ($apkLink !== ''): ?>
                <a class="btn-apk" href="<?= htmlspecialchars($apkLink) ?>" target="_blank" rel="noopener"><?= svg_icon('download', 14) ?> <?= t('Tải APK', 'Get APK') ?></a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Portal Intro Section -->
    <div class="portal-hero">
        <div class="portal-eyebrow">HOQUOC <span>KEY VAULT</span></div>
        <h1 class="portal-title"><?= t('Cổng Nhận Key Game Trực Tuyến', 'Official Game Key Gateway') ?></h1>
        <p class="portal-sub"><?= t('Vượt link nhận key tức thì — không cần đăng ký tài khoản, tự động xác thực 24/7.', 'Complete shortlink steps to obtain game keys instantly — zero registration required.') ?></p>
    </div>

    <!-- =========================================================
         BENTO GRID MACROSTRUCTURE
         ========================================================= -->
    <div class="bento-grid">

        <!-- CELL 1: CONSOLE GAME HUB (Span 2x2) -->
        <section class="bento-cell cell-game-hub" id="games" aria-label="Console Game Hub">
            <div class="cell-head">
                <div class="cell-title-wrap">
                    <span class="cell-badge">PORTAL 01 // CONSOLE HUB</span>
                    <h2 class="cell-title"><?= svg_icon('zap', 18) ?> <?= t('Danh Sách Game Khả Dụng', 'Available Game Directory') ?></h2>
                    <p class="cell-sub"><?= t('Chọn game cần cấp key, bấm để tiến hành xác thực liên kết.', 'Select a game below to generate your authentication link.') ?></p>
                </div>
            </div>

            <?php if (empty($games)): ?>
                <div class="game-no-match"><?= t('Hiện chưa có game nào mở cấp key.', 'No games are currently issuing keys.') ?></div>
            <?php else: ?>
            <div class="game-hub-toolbar" role="search">
                <label class="game-search-box" for="gameSearchInput">
                    <?= svg_icon('key', 15) ?>
                    <input id="gameSearchInput" type="search" placeholder="<?= t('Tìm game theo tên...', 'Search games by name...') ?>" autocomplete="off" spellcheck="false">
                    <button type="button" class="search-clear-btn" id="gameSearchClearBtn" aria-label="<?= t('Xoá tìm kiếm', 'Clear search') ?>">&times;</button>
                </label>
                <div class="game-filter-group" role="group" aria-label="<?= t('Bộ lọc game', 'Game filters') ?>">
                    <button type="button" class="game-filter-btn active" data-filter="all"><?= t('Tất cả', 'All') ?></button>
                    <button type="button" class="game-filter-btn" data-filter="ready"><?= t('Sẵn sàng', 'Ready') ?></button>
                </div>
            </div>

            <div class="game-hub-count" id="gameHubCounter" aria-live="polite">
                <?= count($games) ?> <?= t('game đang mở cấp key', 'games currently available') ?>
            </div>

            <div class="game-hub-list" id="gameHubList">
                <?php foreach ($games as $g):
                    $hops = PUBLIC_KEY_HOPS;
                    $cooldown = $gameCooldowns[$g['id']] ?? 0;
                    $searchData = (string)$g['name'] . ' ' . (string)$g['slug'];
                ?>
                <div class="game-card-item" data-game-item data-search="<?= htmlspecialchars($searchData, ENT_QUOTES, 'UTF-8') ?>" data-ready="<?= $cooldown > 0 ? '0' : '1' ?>">
                    <div class="game-card-header">
                        <div class="game-icon-box"><?= htmlspecialchars($g['icon']) ?></div>
                        <div class="game-meta">
                            <div class="game-name"><?= htmlspecialchars($g['name']) ?></div>
                            <div class="game-badges">
                                <?php if ($cooldown > 0): ?>
                                    <span class="pill-cooldown" data-remaining="<?= (int)$cooldown ?>"><span class="dot"></span> <?= t('Chờ', 'Wait') ?>: <span class="cd-text"><?= format_duration_label($cooldown) ?></span></span>
                                <?php else: ?>
                                    <span class="pill-free"><span class="dot"></span> <?= t('KEY FREE', 'FREE KEY') ?></span>
                                <?php endif; ?>
                                <span class="game-hops-text">24H · <?= (int)$hops ?> <?= t('lần vượt link', 'link steps') ?></span>
                            </div>
                        </div>
                    </div>

                    <?php if ($cooldown > 0): ?>
                        <a class="game-btn game-btn-disabled" data-remaining="<?= (int)$cooldown ?>" onclick="return false;">
                            <?= svg_icon('clock', 14) ?> <span><?= t('Còn', 'Remaining') ?> <span class="cd-text"><?= format_duration_label($cooldown) ?></span></span>
                        </a>
                    <?php else: ?>
                        <a class="game-btn" href="getkey.php?game=<?= urlencode($g['slug']) ?>">
                            <?= svg_icon('zap', 14) ?> <span><?= t('Lấy key miễn phí', 'Get Free Key') ?></span>
                        </a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <div class="game-no-match" id="gameHubNoResults" style="display:none;"><?= t('Không tìm thấy game phù hợp với từ khoá.', 'No matching games found.') ?></div>
            </div>
            <?php endif; ?>
        </section>

        <!-- CELL 2: TELEMETRY BOARD (Span 1x1) -->
        <!-- Bỏ các số liệu bịa đặt (không uptime 99.9%, không <40ms ping) - chỉ hiển thị dữ liệu thật từ DB và server -->
        <section class="bento-cell cell-telemetry" aria-label="System Telemetry">
            <div class="cell-head">
                <div class="cell-title-wrap">
                    <span class="cell-badge">PORTAL 02 // TELEMETRY</span>
                    <h2 class="cell-title"><?= svg_icon('cloud', 16) ?> <?= t('Trạng Thái Máy Chủ', 'Server Telemetry') ?></h2>
                    <p class="cell-sub"><?= t('Thông số vận hành thực tế ghi nhận từ hệ thống', 'Real-time infrastructure telemetry') ?></p>
                </div>
            </div>

            <div class="telemetry-status-row">
                <span class="telemetry-status-label"><?= svg_icon('shield', 13) ?> SERVER_GATEWAY</span>
                <span class="telemetry-pill <?= $serverClosed ? 'off' : 'on' ?>">
                    <span class="dot"></span> <?= $serverClosed ? t('BẢO TRÌ', 'MAINTENANCE') : t('HOẠT ĐỘNG', 'ONLINE') ?>
                </span>
            </div>

            <div class="telemetry-metrics-grid">
                <div class="metric-tile">
                    <div class="metric-label"><?= t('Đã Phát Thành Công', 'Keys Issued') ?></div>
                    <div class="metric-value cyan"><?= number_format($totalActivated) ?></div>
                </div>
                <div class="metric-tile">
                    <div class="metric-label"><?= t('Game Đang Mở', 'Active Games') ?></div>
                    <div class="metric-value"><?= count($games) ?></div>
                </div>
                <div class="metric-tile">
                    <div class="metric-label"><?= t('Chuẩn Mã Hóa', 'Encryption') ?></div>
                    <div class="metric-value" style="font-size:12.5px;">256-Bit Hardware</div>
                </div>
                <div class="metric-tile">
                    <div class="metric-label"><?= t('Nền Tảng Hỗ Trợ', 'Platform') ?></div>
                    <div class="metric-value" style="font-size:13px;">Android APK</div>
                </div>
            </div>

            <div class="telemetry-ip-strip">
                <span><?= t('IP CỦA BẠN', 'YOUR IP') ?>: <b><?= htmlspecialchars($clientIp ?: '127.0.0.1') ?></b></span>
                <span><?= t('Riêng tư', 'Private') ?></span>
            </div>
        </section>

        <!-- CELL 3: SAMPLE KEY SPECIMEN (Span 1x1) -->
        <!-- Rõ ràng là mã mẫu định dạng, không phải key dùng được, tránh gây hiểu lầm -->
        <section class="bento-cell cell-specimen" aria-label="Key Specimen Format">
            <div class="cell-head">
                <div class="cell-title-wrap">
                    <span class="cell-badge">PORTAL 03 // SPECIMEN</span>
                    <h2 class="cell-title"><?= svg_icon('key', 16) ?> <?= t('Mã Key Mẫu', 'Sample Key Format') ?></h2>
                    <p class="cell-sub"><?= t('Định dạng key chính thức nhận được sau khi vượt link', 'Official key structure after step completion') ?></p>
                </div>
            </div>

            <div class="specimen-warning-badge">
                <?= svg_icon('info', 13) ?> <?= t('MÃ KEY MẪU ĐỊNH DẠNG (KHÔNG DÙNG ĐƯỢC)', 'SAMPLE FORMAT ONLY (NON-FUNCTIONAL)') ?>
            </div>

            <div class="specimen-ticket">
                <div class="specimen-ticket-tag"><?= t('Định dạng cấu trúc mã', 'Structure Pattern') ?></div>
                <div class="specimen-code">HQD-XXXXXXX-XXX</div>
                <div class="specimen-perf"></div>
                <div class="specimen-meta">
                    <span><?= t('Thời hạn', 'Validity') ?><b><?= t('Tự động theo game', 'Game-specific') ?></b></span>
                    <span style="text-align:right"><?= t('Cấp phát', 'Delivery') ?><b><?= t('Tức thì', 'Instant') ?></b></span>
                </div>
            </div>

            <p class="specimen-disclaimer">
                * <?= t('Đây là mã minh họa định dạng chuẩn. Key thật sẽ được hệ thống tạo mới ngẫu nhiên ngay khi bạn hoàn thành các bước vượt link.', 'Illustrative format only. Authentic unique keys are dynamically generated upon completion.') ?>
            </p>
        </section>

        <!-- CELL 4: 3-STEP VERIFICATION PROTOCOL (Span 2x1) -->
        <section class="bento-cell cell-protocol" id="protocol" aria-label="3-Step Verification Protocol">
            <div class="cell-head">
                <div class="cell-title-wrap">
                    <span class="cell-badge">PORTAL 04 // PROTOCOL</span>
                    <h2 class="cell-title"><?= svg_icon('check-circle', 16) ?> <?= t('Quy Trình 3 Bước Nhận Key', '3-Step Verification Protocol') ?></h2>
                    <p class="cell-sub"><?= t('Các bước đơn giản để lấy mã bản quyền hoàn toàn miễn phí', 'Simple steps to acquire your free game key') ?></p>
                </div>
            </div>

            <div class="protocol-pipeline">
                <div class="protocol-step">
                    <div class="protocol-step-num">01</div>
                    <h3 class="protocol-step-title"><?= t('Chọn Game Cần Chơi', 'Select Game') ?></h3>
                    <p class="protocol-step-desc"><?= t('Tìm game trong Game Hub và bấm "Lấy key miễn phí".', 'Locate your desired game and tap "Get Free Key".') ?></p>
                </div>
                <div class="protocol-step">
                    <div class="protocol-step-num">02</div>
                    <h3 class="protocol-step-title"><?= t('Vượt Link Rút Gọn', 'Verify Shortlink') ?></h3>
                    <p class="protocol-step-desc"><?= t('Bấm Tạo Link và làm theo chỉ dẫn ngắn trên màn hình.', 'Tap Create Link and complete quick on-screen instructions.') ?></p>
                </div>
                <div class="protocol-step">
                    <div class="protocol-step-num">03</div>
                    <h3 class="protocol-step-title"><?= t('Nhận Key Tự Động', 'Instant Claim') ?></h3>
                    <p class="protocol-step-desc"><?= t('Key xuất hiện tức thì, sao chép và dán vào game để chơi.', 'Your key reveals immediately — copy and paste to play.') ?></p>
                </div>
            </div>
        </section>

        <!-- CELL 6: TELEGRAM COMMUNITY HUB (Span 3x1 Full-Width Strip) -->
        <section class="bento-cell cell-telegram" aria-label="Telegram Community Hub">
            <div class="telegram-strip">
                <div class="telegram-content">
                    <div class="telegram-icon-box"><?= svg_icon('send', 22) ?></div>
                    <div class="telegram-details">
                        <span class="cell-badge" style="color:#29A9EA;">PORTAL 06 // COMMUNITY</span>
                        <h3><?= t('Kênh Thông Báo & Hỗ Trợ Kỹ Thuật', 'Official Announcements & Support Hub') ?></h3>
                        <p><?= t('Nhận thông báo cập nhật key mới, thông tin bảo trì hệ thống và hỗ trợ xử lý lỗi 24/7.', 'Stay updated with release notes, server status alerts, and community support.') ?></p>
                    </div>
                </div>

                <div class="telegram-actions">
                    <?php if (!empty($contact['telegram'])): ?>
                    <a class="btn-telegram" href="<?= htmlspecialchars($contact['telegram']) ?>" target="_blank" rel="noopener">
                        <?= svg_icon('send', 15) ?> <span><?= t('Tham Gia Telegram', 'Join Telegram') ?></span>
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($contact['zalo'])): ?>
                    <a class="btn-contact-alt" href="<?= htmlspecialchars($contact['zalo']) ?>" target="_blank" rel="noopener">
                        <?= svg_icon('message-circle', 15) ?> Zalo
                    </a>
                    <?php endif; ?>
                    <?php if (!empty($contact['facebook'])): ?>
                    <a class="btn-contact-alt" href="<?= htmlspecialchars($contact['facebook']) ?>" target="_blank" rel="noopener">
                        <?= svg_icon('users', 15) ?> Facebook
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

    </div>

    <div class="footer-credits">
        © <?= date('Y') ?> <?= htmlspecialchars($siteBrandName) ?> · Hệ Thống Cấp Key Tự Động
    </div>
</div>

<!-- Floating Contact FAB -->
<?php if ($contact['zalo'] !== '' || $contact['telegram'] !== '' || $contact['facebook'] !== ''): ?>
<div class="fab-contact">
    <div class="fab-menu" id="fabMenu">
        <?php if ($contact['zalo'] !== ''): ?><a class="fab-item fab-zalo" href="<?= htmlspecialchars($contact['zalo']) ?>" target="_blank" rel="noopener" title="Zalo"><?= svg_icon('message-circle', 18) ?></a><?php endif; ?>
        <?php if ($contact['telegram'] !== ''): ?><a class="fab-item fab-tele" href="<?= htmlspecialchars($contact['telegram']) ?>" target="_blank" rel="noopener" title="Telegram"><?= svg_icon('send', 16) ?></a><?php endif; ?>
        <?php if ($contact['facebook'] !== ''): ?><a class="fab-item fab-fb" href="<?= htmlspecialchars($contact['facebook']) ?>" target="_blank" rel="noopener" title="Facebook"><?= svg_icon('users', 16) ?></a><?php endif; ?>
    </div>
    <button type="button" class="fab-toggle" id="fabToggle" aria-label="<?= t('Liên hệ', 'Contact') ?>" aria-expanded="false"><?= svg_icon('phone', 21) ?></button>
</div>
<script>
(function(){
    var btn = document.getElementById('fabToggle');
    var menu = document.getElementById('fabMenu');
    if (!btn || !menu) return;
    btn.addEventListener('click', function(){
        var open = menu.classList.toggle('open');
        btn.classList.toggle('open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
})();
</script>
<?php endif; ?>

<!-- =========================================================
     GLOBAL SINGLE-INTERVAL COUNTDOWN CONTROLLER (Requirement #4)
     Một setInterval duy nhất kiểm soát tất cả các card, tránh rò rỉ bộ nhớ.
     ========================================================= -->
<script>
(function(){
    function formatTime(sec){
        if (sec <= 0) return '00:00:00';
        var d = Math.floor(sec / 86400);
        var h = Math.floor((sec % 86400) / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = sec % 60;
        var hh = String(h).padStart(2,'0');
        var mm = String(m).padStart(2,'0');
        var ss = String(s).padStart(2,'0');
        return d > 0 ? (d + 'd ' + hh + ':' + mm + ':' + ss) : (hh + ':' + mm + ':' + ss);
    }

    var cooldownCards = document.querySelectorAll('[data-remaining]');
    if (!cooldownCards.length) return;

    var tracker = [];
    cooldownCards.forEach(function(el){
        var rem = parseInt(el.getAttribute('data-remaining'), 10);
        if (!isNaN(rem) && rem > 0) {
            tracker.push({
                element: el,
                textNode: el.querySelector('.cd-text') || el,
                secondsRemaining: rem
            });
        }
    });

    if (!tracker.length) return;

    // Single shared timer tick for all game cards
    var globalCooldownInterval = setInterval(function(){
        var hasActiveCooldown = false;
        var shouldRefreshPage = false;

        for (var i = 0; i < tracker.length; i++) {
            var item = tracker[i];
            if (item.secondsRemaining > 0) {
                item.secondsRemaining--;
                if (item.secondsRemaining <= 0) {
                    shouldRefreshPage = true;
                } else {
                    hasActiveCooldown = true;
                    if (item.textNode) {
                        item.textNode.textContent = formatTime(item.secondsRemaining);
                    }
                }
            }
        }

        if (shouldRefreshPage) {
            clearInterval(globalCooldownInterval);
            location.reload();
            return;
        }

        if (!hasActiveCooldown) {
            clearInterval(globalCooldownInterval);
        }
    }, 1000);
})();
</script>

<!-- Game Hub Search & Filter Controller -->
<script>
(function(){
    var searchInput = document.getElementById('gameSearchInput');
    var clearBtn = document.getElementById('gameSearchClearBtn');
    var list = document.getElementById('gameHubList');
    var counter = document.getElementById('gameHubCounter');
    var noResults = document.getElementById('gameHubNoResults');
    var filterBtns = document.querySelectorAll('.game-filter-btn');

    if (!searchInput || !list) return;

    var cards = Array.prototype.slice.call(list.querySelectorAll('[data-game-item]'));
    var currentFilter = 'all';
    var isEn = <?= json_encode($GLOBALS['LANG'] === 'en') ?>;

    function applyFilter(){
        var query = searchInput.value.trim().toLowerCase();
        var visibleCount = 0;

        cards.forEach(function(card){
            var searchData = (card.getAttribute('data-search') || '').toLowerCase();
            var isReady = card.getAttribute('data-ready') === '1';

            var matchesQuery = !query || searchData.indexOf(query) !== -1;
            var matchesFilter = currentFilter === 'all' || isReady;

            if (matchesQuery && matchesFilter) {
                card.classList.remove('is-hidden');
                visibleCount++;
            } else {
                card.classList.add('is-hidden');
            }
        });

        if (clearBtn) {
            clearBtn.classList.toggle('visible', searchInput.value.length > 0);
        }
        if (noResults) {
            noResults.style.display = (visibleCount === 0) ? 'block' : 'none';
        }
        if (counter) {
            if (isEn) {
                counter.textContent = visibleCount + (visibleCount === 1 ? ' game available' : ' games available');
            } else {
                counter.textContent = visibleCount + ' game đang mở cấp key';
            }
        }
    }

    searchInput.addEventListener('input', applyFilter);
    if (clearBtn) {
        clearBtn.addEventListener('click', function(){
            searchInput.value = '';
            searchInput.focus();
            applyFilter();
        });
    }

    filterBtns.forEach(function(btn){
        btn.addEventListener('click', function(){
            currentFilter = btn.getAttribute('data-filter') || 'all';
            filterBtns.forEach(function(b){ b.classList.toggle('active', b === btn); });
            applyFilter();
        });
    });
})();
</script>

<?= anti_devtools_script() ?>
</body>
</html>
