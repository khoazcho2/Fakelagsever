<?php
// ============================================================
// index.php - Trang chủ công khai, liệt kê các game đang mở cấp
// key. User bấm "Lấy key miễn phí" -> sang getkey.php?game=slug
// ============================================================
require_once __DIR__ . '/config.php';
set_security_headers();
if (session_status() === PHP_SESSION_NONE) session_start();
init_language();
init_theme();

// Admin đã đăng nhập /admin.php (cùng trình duyệt) được bỏ qua cooldown
// "còn lại X giờ" khi tự test trên trang lấy key - giống bypass đã có ở
// getkey.php/hop.php.
$isAdmin = !empty($_SESSION['is_admin']);

// ?r=<id> -> trang chủ RIÊNG của 1 reseller, chỉ hiện game của họ.
$resellerId = null;
$resellerInfo = null;
if (isset($_GET['r']) && ctype_digit((string)$_GET['r'])) {
    $rr = get_reseller_by_id((int)$_GET['r']);
    if ($rr && $rr['enabled']) { $resellerId = (int)$_GET['r']; $resellerInfo = $rr; }
}
$rParam = $resellerId !== null ? ('&r=' . $resellerId) : '';
$rQueryOnly = $resellerId !== null ? ('r=' . $resellerId . '&') : '';
$clientIp = get_client_ip();
$contact = get_effective_contact($resellerId);
// Tên hiển thị ở app-bar/logo/eyebrow - ưu tiên tên cửa hàng reseller
// (nếu đang xem trang riêng của họ qua ?r=), fallback về "HoQuocKey".
// Tính SỚM ở đây vì app-bar nằm phía TRÊN khối hero (nơi trước đây biến
// này mới được gán) - để chữ cứng "HoQuocKey" lọt vào cả trang reseller.
$siteBrandName = $resellerInfo ? ($resellerInfo['store_name'] ?: $resellerInfo['username']) : 'HoQuocKey';

// Xử lý form "Reset Key" (chỉ Key 40h, mỗi key reset được 1 lần) - xem
// hàm attempt_reset_key() trong config.php. Dùng chung cho cả trang
// admin và trang reseller vì keycode đã là duy nhất toàn hệ thống.
// Chặn theo IP (RESET_KEY_MAX_FAILS_BEFORE_BLOCK) trước khi thử - form
// này không đăng nhập nên không có gì cản 1 IP dò hàng loạt keycode
// ngẫu nhiên nếu không giới hạn.
$resetResult = null;
if (isset($_POST['reset_key_submit'])) {
    if (is_ip_blocked($clientIp)) {
        $resetResult = ['ok' => false, 'message' => 'Quá nhiều lần thử, vui lòng thử lại sau.'];
    } else {
        $resetResult = attempt_reset_key($_POST['reset_keycode'] ?? '');
        if ($resetResult['ok']) {
            reset_violation_count($clientIp);
        } else {
            record_bypass_violation($clientIp, 'Reset key thất bại: ' . trim($_POST['reset_keycode'] ?? ''), RESET_KEY_MAX_FAILS_BEFORE_BLOCK, RESET_KEY_BLOCK_HOURS);
        }
    }
}

$games = array_filter(get_games($resellerId), function ($g) { return (bool)$g['enabled']; });

// IP của người đang xem trang + trạng thái "còn cooldown lấy key" cho
// từng game - hiển thị ngay trên trang để user tự biết mình đã lấy
// key gần đây chưa, còn phải chờ bao lâu mới lấy được tiếp.
$gameCooldowns = [];
foreach ($games as $g) {
    $gameCooldowns[$g['id']] = $isAdmin ? 0 : get_claim_cooldown_remaining((int)$g['id'], $clientIp);
}
$region = detect_region();

// Social proof: tổng số key đã kích hoạt (tăng độ tin cậy, cho user thấy
// hệ thống thật sự có người dùng chứ không phải trang trống)
// Đọc từ bộ đếm vĩnh viễn (site_counters) thay vì COUNT(*) trên bảng keys
// - vì đếm trực tiếp trên bảng keys khiến con số này bị TRỪ mỗi khi admin
// xoá key cũ/hết hạn, dù key đó đã từng phát thành công thật.
try {
    $totalActivated = get_site_counter('keys_issued');
} catch (Throwable $e) {
    $totalActivated = 0;
}
?>
<!DOCTYPE html>
<html lang="<?= $GLOBALS['LANG'] === 'en' ? 'en' : 'vi' ?>" data-theme="<?= $GLOBALS['THEME'] === 'light' ? 'light' : 'dark' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= t('Lấy Key Game — Hồ Quốc', 'Free Game Keys — Ho Quoc') ?></title>
<?= shared_favicon() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">
<style>
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
}

body {
    position: relative;
    overflow-x: hidden;
    background:
        radial-gradient(circle at 15% -10%, rgba(89, 245, 213, 0.18), transparent 32rem),
        radial-gradient(circle at 88% 18%, rgba(156, 140, 255, 0.15), transparent 30rem),
        linear-gradient(180deg, var(--bg-top) 0%, var(--bg) 65%);
    color: var(--text);
    margin: 0;
    min-height: 100vh;
}
body::before {
    content: ""; position: fixed; inset: 0; pointer-events: none; opacity: 0.35; z-index: -1;
    background-image: linear-gradient(var(--grid-line) 1px, transparent 1px),
                      linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
    background-size: 40px 40px;
    mask-image: linear-gradient(to bottom, #000, transparent 82%);
}

.wrap { position: relative; max-width: 780px; margin: 0 auto; padding: 36px 20px 80px; }

/* App-bar */
.app-bar {
    display: flex; align-items: center; gap: 14px; max-width: 780px; margin: 0 auto 16px;
    padding: 12px 18px; border: 1px solid var(--line); border-radius: var(--r-xl);
    background: rgba(var(--surface-rgb), 0.78); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    box-shadow: var(--shadow-card); transition: border-color var(--dur-fast), transform var(--dur-fast);
}
.app-bar:hover { border-color: var(--line-strong); }
.app-bar-icon {
    width: 42px; height: 42px; border-radius: var(--r-md);
    background: linear-gradient(135deg, var(--cyan), var(--violet));
    display: flex; align-items: center; justify-content: center; color: #071018; flex-shrink: 0;
    box-shadow: 0 4px 16px rgba(89,245,213,0.3); transition: transform var(--dur-normal) var(--ease-spring);
}
.app-bar:hover .app-bar-icon { transform: scale(1.06) rotate(-4deg); }
.app-bar-name { display: flex; flex-direction: column; flex: 1; min-width: 0; }
.app-bar-name b { font-size: 14.5px; font-weight: 700; display: flex; align-items: center; gap: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text); }
.app-bar-name b svg { color: var(--success); flex-shrink: 0; filter: drop-shadow(0 0 6px rgba(97,230,164,0.5)); }
.app-bar-name span { font-size: 11.5px; color: var(--text-dim); }
.app-bar-dl {
    flex-shrink: 0; display: flex; align-items: center; gap: 7px; padding: 9px 16px;
    border-radius: var(--r-full); background: linear-gradient(135deg, var(--cyan), var(--violet));
    color: #071018; font-weight: 700; font-size: 12.5px; text-decoration: none; white-space: nowrap;
    transition: transform var(--dur-fast), box-shadow var(--dur-fast), filter var(--dur-fast);
    box-shadow: 0 4px 15px rgba(89,245,213,0.3);
}
.app-bar-dl:hover { filter: brightness(1.08); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(89,245,213,0.45); }
.app-bar-dl:active { transform: scale(0.97); }

/* Header & Nav */
.site-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px 18px; max-width: 780px; margin: 0 auto 8px; padding: 6px 0 16px; }
.brand { display: flex; align-items: center; gap: 9px; text-decoration: none; color: var(--text); font-weight: 700; transition: opacity var(--dur-fast); }
.brand:hover { opacity: 0.9; }
.brand-mark { color: var(--cyan); display: flex; align-items: center; filter: drop-shadow(0 0 8px rgba(89,245,213,0.5)); }
.brand-name { font-family: var(--font-body); font-weight: 800; font-size: 16px; letter-spacing: -0.02em; }
.brand-name b { color: var(--cyan); }
.site-nav { display: flex; gap: 16px; font-size: 13px; align-items: center; }
.site-nav a { color: var(--text-dim); text-decoration: none; font-weight: 500; transition: color var(--dur-fast); }
.site-nav a:hover { color: var(--cyan); }
.lang-switch { display: flex; align-items: center; gap: 4px; font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); }
.lang-switch a { color: var(--text-dim); text-decoration: none; padding: 4px 8px; border-radius: var(--r-sm); transition: all var(--dur-fast); }
.lang-switch a.active { color: var(--cyan); background: rgba(89,245,213,0.14); font-weight: 700; }
.theme-switch { display: flex; align-items: center; gap: 3px; }
.theme-switch a { display: flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: var(--r-sm); color: var(--text-dim); text-decoration: none; transition: all var(--dur-fast); }
.theme-switch a:hover { color: var(--text); background: rgba(255,255,255,0.06); }
.theme-switch a.active { color: var(--cyan); background: rgba(89,245,213,0.14); }
.breadcrumb { max-width: 780px; margin: 0 auto 20px; font-size: 11px; color: var(--text-dim); font-family: var(--font-mono); letter-spacing: 0.04em; }

/* Hero Section */
.hero-wrapper { position: relative; text-align: center; margin: 10px auto 28px; max-width: 660px; }
.hero-glow {
    position: absolute; top: 15%; left: 50%; transform: translateX(-50%); width: 340px; height: 160px;
    background: radial-gradient(ellipse at center, rgba(89,245,213,0.22), rgba(156,140,255,0.18), transparent 70%);
    filter: blur(48px); pointer-events: none; z-index: -1;
}
.eyebrow {
    display: inline-flex; align-items: center; gap: 8px; margin: 0 auto 16px; padding: 7px 15px;
    border: 1px solid var(--line); border-radius: var(--r-full); background: rgba(var(--surface-rgb), 0.75);
    box-shadow: 0 4px 16px rgba(0,0,0,0.18); font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.16em;
    color: var(--text-dim); text-transform: uppercase; backdrop-filter: blur(12px);
}
.eyebrow::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: var(--cyan); box-shadow: 0 0 12px var(--cyan); animation: ds-blink 1.8s infinite; }
.eyebrow span { color: var(--cyan); font-weight: 700; }
h1 {
    font-size: clamp(32px, 6vw, 54px); line-height: 1.08; letter-spacing: -0.04em; margin: 0 auto 12px;
    max-width: 640px; font-weight: 800; text-align: center;
    background: linear-gradient(135deg, var(--text) 30%, var(--cyan) 95%, var(--violet) 120%);
    -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
    filter: drop-shadow(0 2px 20px rgba(89,245,213,0.15));
}
.sub { font-size: 14.5px; line-height: 1.65; max-width: 520px; margin: 0 auto 18px; text-align: center; color: var(--text-dim); }
.proof {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px; margin: 0 auto 28px; padding: 8px 16px;
    border: 1px solid rgba(97,230,164,0.25); border-radius: var(--r-full); background: rgba(97,230,164,0.08);
    font-size: 12.5px; color: var(--text-dim); backdrop-filter: blur(8px);
}
.proof b { color: var(--success); font-family: var(--font-mono); font-weight: 700; text-shadow: 0 0 10px rgba(97,230,164,0.4); }
.pulse-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--success); box-shadow: 0 0 10px var(--success); animation: ds-blink 1.6s ease-in-out infinite; }

/* Status Card */
.status-card {
    max-width: 640px; margin: 0 auto 22px; padding: 18px 20px; border: 1px solid var(--line);
    border-radius: var(--r-xl); background: rgba(var(--surface-rgb), 0.76);
    backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); box-shadow: var(--shadow-card);
    transition: transform var(--dur-normal), border-color var(--dur-normal);
}
.status-card:hover { border-color: var(--line-strong); }
.status-card-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 14px; }
.status-card-head .label { display: flex; align-items: center; gap: 7px; color: var(--text-dim); font-size: 11px; font-family: var(--font-mono); letter-spacing: 0.08em; font-weight: 600; }
.status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: var(--r-full); font-size: 11px; font-weight: 700; font-family: var(--font-mono); }
.status-pill.on { background: rgba(97,230,164,0.14); color: var(--success); border: 1px solid rgba(97,230,164,0.25); box-shadow: 0 0 12px rgba(97,230,164,0.2); }
.status-pill.off { background: rgba(239,68,68,0.14); color: var(--danger); border: 1px solid rgba(239,68,68,0.25); }
.status-pill .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
.status-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
@media (max-width: 540px) { .status-grid { grid-template-columns: repeat(2, 1fr); } }
.status-box { padding: 12px 14px; border: 1px solid var(--line); border-radius: var(--r-md); text-align: center; background: rgba(var(--surface-rgb), 0.45); }
.status-box span { display: block; font-size: 10px; letter-spacing: 0.08em; color: var(--text-dim); text-transform: uppercase; margin-bottom: 4px; font-family: var(--font-mono); }
.status-box b { font-size: 14px; font-weight: 700; color: var(--text); font-variant-numeric: tabular-nums; }

/* Sample Card */
.sample-card {
    position: relative; max-width: 640px; margin: 0 auto 30px; border: 1px solid var(--line);
    border-radius: var(--r-2xl); background: var(--grad-card); overflow: hidden;
    box-shadow: var(--shadow-float); backdrop-filter: blur(14px);
    transition: transform var(--dur-normal) var(--ease-out), border-color var(--dur-normal);
}
.sample-card:hover { transform: translateY(-2px); border-color: rgba(89,245,213,0.3); }
.sample-holo { height: 4px; background: var(--grad-holo); background-size: 240% 100%; animation: ds-holo-shift 3s linear infinite; }
.sample-top { padding: 24px 26px 20px; text-align: center; }
.sample-tag { font-family: var(--font-mono); font-size: 10.5px; letter-spacing: 0.18em; color: var(--text-dim); text-transform: uppercase; }
.sample-key {
    font-family: var(--font-mono); font-weight: 700; font-size: clamp(22px, 5vw, 30px); letter-spacing: 0.12em;
    color: var(--cyan); text-shadow: 0 0 24px rgba(89,245,213,0.45); margin-top: 10px; font-variant-numeric: tabular-nums;
}
.sample-perf { height: 0; border-top: 1.5px dashed var(--line); margin: 0 22px; position: relative; }
.sample-perf::before, .sample-perf::after { content: ''; position: absolute; top: -8px; width: 16px; height: 16px; border-radius: 50%; background: var(--bg); }
.sample-perf::before { left: -30px; }
.sample-perf::after { right: -30px; }
.sample-bottom { padding: 14px 26px 18px; display: flex; justify-content: space-between; align-items: center; font-size: 11.5px; color: var(--text-dim); }
.sample-bottom span { display: flex; flex-direction: column; gap: 3px; }
.sample-bottom b { color: var(--text); font-weight: 600; }

/* Guide */
.guide-layout { max-width: 640px; margin: 0 auto; display: grid; grid-template-columns: minmax(140px, 0.32fr) minmax(0, 1fr); gap: 22px; align-items: start; }
.guide-heading { position: relative; }
.guide-heading .section-label { display: block; margin: 0; line-height: 1.45; }
.guide-heading .section-label::after { display: block; width: 36px; height: 2px; margin-top: 10px; flex: none; background: var(--cyan); border-radius: 2px; }
.guide-steps { min-width: 0; }
.step-card {
    max-width: none; margin: 0 0 12px; padding: 16px 18px; border: 1px solid var(--line);
    border-radius: var(--r-lg); background: rgba(var(--surface-rgb), 0.7);
    display: flex; gap: 16px; align-items: flex-start; backdrop-filter: blur(10px);
    transition: transform var(--dur-fast), border-color var(--dur-fast), box-shadow var(--dur-fast);
}
.step-card:hover { transform: translateY(-3px); border-color: var(--line-strong); box-shadow: 0 10px 24px -10px rgba(0,0,0,0.5); }
.step-num { font-family: var(--font-mono); font-size: 24px; font-weight: 800; color: var(--cyan); line-height: 1; flex-shrink: 0; min-width: 30px; text-shadow: 0 0 12px rgba(89,245,213,0.3); }
.step-body b { display: block; font-size: 14px; font-weight: 700; margin-bottom: 3px; color: var(--text); }
.step-body p { font-size: 12.5px; color: var(--text-dim); margin: 0; line-height: 1.55; }
@media (max-width: 700px) {
    .guide-layout { display: block; }
    .guide-heading { margin-bottom: 14px; }
    .guide-heading .section-label::after { margin-top: 8px; }
}

/* Sections & Toolbar */
.section-label {
    display: flex; align-items: center; gap: 10px; margin: 0 auto 14px; max-width: 640px;
    font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.16em; color: var(--text-dim); text-transform: uppercase; font-weight: 600;
}
.section-label::after { content: ""; height: 1px; flex: 1; background: var(--line); }
.game-toolbar { max-width: 640px; margin: 0 auto 14px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.search-field {
    height: 44px; display: flex; align-items: center; gap: 10px; flex: 1; min-width: 210px;
    padding: 0 14px; border: 1px solid var(--line); border-radius: var(--r-md);
    background: rgba(var(--surface-rgb), 0.75); color: var(--text-dim); transition: all var(--dur-fast);
    backdrop-filter: blur(8px);
}
.search-field:focus-within { border-color: var(--cyan); box-shadow: 0 0 0 3px rgba(89,245,213,0.18), 0 0 16px rgba(89,245,213,0.12); }
.search-field input {
    width: 100%; min-width: 0; border: 0; outline: 0; background: transparent; color: var(--text);
    font: 500 13.5px var(--font-body); appearance: none;
}
.search-field input::-webkit-search-cancel-button { display: none; }
.search-field input::placeholder { color: var(--text-muted); }
.search-clear {
    width: 24px; height: 24px; padding: 0; border: 0; border-radius: 6px; background: transparent;
    color: var(--text-dim); font-size: 16px; line-height: 1; cursor: pointer; opacity: 0; pointer-events: none; transition: all var(--dur-fast);
}
.search-clear.visible { opacity: 1; pointer-events: auto; }
.search-clear:hover { background: rgba(255,255,255,0.08); color: var(--text); }
.filter-group { display: flex; gap: 4px; padding: 4px; border: 1px solid var(--line); border-radius: var(--r-md); background: rgba(var(--surface-rgb), 0.65); backdrop-filter: blur(8px); }
.filter-btn {
    height: 36px; padding: 0 14px; border: 0; border-radius: var(--r-sm); background: transparent;
    color: var(--text-dim); font: 600 12px var(--font-body); cursor: pointer; white-space: nowrap; transition: all var(--dur-fast);
}
.filter-btn:hover { color: var(--text); }
.filter-btn.active { background: rgba(89,245,213,0.18); color: var(--cyan); font-weight: 700; box-shadow: 0 0 10px rgba(89,245,213,0.15); }
.game-results { max-width: 640px; min-height: 16px; margin: -4px auto 12px; padding-left: 2px; color: var(--text-dim); font: 11px var(--font-mono); letter-spacing: 0.03em; }

/* Game Cards */
.game-list { max-width: 640px; margin: 0 auto; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.card {
    width: auto; max-width: none; margin: 0; border: 1px solid var(--line); border-radius: var(--r-xl);
    background: rgba(var(--surface-rgb), 0.85); box-shadow: var(--shadow-card);
    transition: transform var(--dur-normal) var(--ease-out), border-color var(--dur-normal), box-shadow var(--dur-normal);
    overflow: hidden; display: flex; flex-direction: column; position: relative;
    backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
}
.card:hover {
    transform: translateY(-5px) scale(1.015);
    border-color: rgba(89,245,213,0.45);
    box-shadow: 0 20px 45px -15px rgba(0,0,0,0.85), 0 0 25px rgba(89,245,213,0.22), 0 0 0 1px rgba(89,245,213,0.3);
}
.holo { height: 3px; background: var(--grad-holo); background-size: 240% 100%; animation: ds-holo-shift 3.5s linear infinite; }
.card-body { padding: 20px; display: flex; align-items: center; gap: 16px; flex: 1; }
.icon-badge {
    width: 52px; height: 52px; border-radius: var(--r-lg); background: linear-gradient(145deg, var(--surface3), var(--surface2));
    display: flex; align-items: center; justify-content: center; font-size: 26px; flex-shrink: 0;
    border: 1px solid rgba(89,245,213,0.25); box-shadow: inset 0 1px rgba(255,255,255,0.08), 0 4px 14px rgba(0,0,0,0.4);
    transition: transform 0.35s var(--ease-spring);
}
.card:hover .icon-badge { transform: scale(1.1) rotate(-5deg); box-shadow: 0 6px 20px rgba(89,245,213,0.3); }
.card-info { flex: 1; min-width: 0; }
.gname { font-family: var(--font-body); font-weight: 700; font-size: 16.5px; letter-spacing: -0.02em; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gmeta { display: flex; align-items: center; gap: 7px; margin-top: 5px; flex-wrap: wrap; }
.free-pill {
    font-size: 10.5px; font-weight: 700; color: var(--success); background: rgba(97,230,164,0.14);
    padding: 3px 9px; border-radius: var(--r-full); display: inline-flex; align-items: center; gap: 5px; font-family: var(--font-mono);
    border: 1px solid rgba(97,230,164,0.22);
}
.free-pill .dot { width: 6px; height: 6px; border-radius: 50%; background: var(--success); box-shadow: 0 0 8px var(--success); animation: ds-blink 1.6s ease-in-out infinite; }
.cooldown-pill {
    display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: var(--r-full);
    background: rgba(245,201,105,0.14); border: 1px solid rgba(245,201,105,0.3); color: var(--warn);
    font-size: 11px; font-family: var(--font-mono); font-variant-numeric: tabular-nums;
}
.cooldown-pill .dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; animation: ds-blink 1.4s infinite; }
.hops-note { font-size: 11.5px; color: var(--text-muted); margin-top: 5px; font-family: var(--font-mono); }
.perf { height: 0; border-top: 1px dashed var(--line); margin: 0 20px; }
.card-cta { padding: 15px 20px 18px; }
a.btn {
    position: relative; overflow: hidden;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    background: var(--grad-btn); color: #071018; text-decoration: none;
    font-family: var(--font-body); font-weight: 700; font-size: 14px;
    min-height: 46px; padding: 0 18px; border-radius: var(--r-md);
    box-shadow: var(--shadow-btn); transition: all var(--dur-normal) var(--ease-out);
}
a.btn::after {
    content: ""; position: absolute; top: -50%; left: -60%; width: 40%; height: 200%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.35), transparent);
    transform: rotate(25deg); opacity: 0; transition: transform 0.6s ease, opacity 0.3s ease;
}
a.btn:hover { filter: brightness(1.06); transform: translateY(-2px); box-shadow: var(--shadow-btn-hover); }
a.btn:hover::after { opacity: 1; transform: rotate(25deg) translateX(450%); }
a.btn:active { transform: scale(0.98) translateY(0); }
.btn-disabled { opacity: 0.55; cursor: not-allowed; filter: grayscale(0.3); pointer-events: none; }
.card.is-hidden { display: none; }
.game-no-results { max-width: 640px; margin: 12px auto 0; padding: 26px; border: 1px dashed var(--line); border-radius: var(--r-lg); text-align: center; color: var(--text-dim); font-size: 13.5px; }
.empty { text-align: center; color: var(--text-dim); font-size: 14px; margin: 20px auto; padding: 32px; border: 1px dashed var(--line); border-radius: var(--r-lg); }
@media (max-width: 640px) { .game-list { grid-template-columns: 1fr; } }
@media (max-width: 520px) { .game-toolbar { align-items: stretch; } .search-field { flex-basis: 100%; } .filter-group { flex: 1; } .filter-btn { flex: 1; } }

/* Features */
.feature-card {
    max-width: 640px; margin: 0 auto 12px; padding: 16px 18px; border: 1px solid var(--line);
    border-radius: var(--r-lg); background: rgba(var(--surface-rgb), 0.72); display: flex; gap: 14px;
    align-items: flex-start; backdrop-filter: blur(10px); transition: transform var(--dur-fast), border-color var(--dur-fast);
}
.feature-card:hover { transform: translateY(-2px); border-color: var(--line-strong); }
.feature-icon {
    width: 38px; height: 38px; border-radius: var(--r-md); display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; background: rgba(89,245,213,0.14); color: var(--cyan); box-shadow: 0 0 12px rgba(89,245,213,0.18);
}
.feature-body { flex: 1; min-width: 0; }
.feature-body-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
.feature-body-head b { font-size: 14px; font-weight: 700; color: var(--text); }
.feature-tag { font-family: var(--font-mono); font-size: 9.5px; font-weight: 700; letter-spacing: 0.06em; padding: 3px 8px; border-radius: var(--r-full); background: rgba(89,245,213,0.14); color: var(--cyan); white-space: nowrap; }
.feature-body p { font-size: 12.5px; color: var(--text-dim); margin: 4px 0 0; line-height: 1.55; }

/* Interaction polish shared by the public portal controls. */
button:focus-visible, a:focus-visible, input:focus-visible { outline: 2px solid var(--cyan); outline-offset: 3px; }
button, a, input { -webkit-tap-highlight-color: transparent; }
#top, #games, #reset-section { scroll-margin-top: 18px; }
.app-bar, .status-card, .sample-card, .step-card, .feature-card, .reset-box, .ip-box {
    transition: border-color var(--dur-normal), box-shadow var(--dur-normal), transform var(--dur-normal) var(--ease-out);
}
.status-card:hover, .step-card:hover, .feature-card:hover, .reset-box:hover, .ip-box:hover {
    border-color: var(--line-strong); box-shadow: var(--shadow-card-hover);
}
@media (pointer: coarse) {
    .status-card:hover, .step-card:hover, .feature-card:hover, .reset-box:hover, .ip-box:hover { transform: none; }
}

/* Reset Key */
.reset-box {
    max-width: 640px; margin: 30px auto 0; padding: 22px 24px; border: 1px solid var(--line);
    border-radius: var(--r-xl); background: rgba(var(--surface-rgb), 0.82); box-shadow: var(--shadow-card);
    backdrop-filter: blur(12px);
}
.reset-title { font-family: var(--font-body); font-weight: 700; font-size: 16px; display: flex; align-items: center; gap: 9px; color: var(--text); }
.reset-sub { font-size: 12.5px; color: var(--text-dim); margin: 6px 0 16px; line-height: 1.6; }
.reset-form { display: flex; gap: 10px; }
.reset-form input {
    flex: 1; min-width: 0; height: 46px; padding: 0 16px; border-radius: var(--r-md);
    border: 1px solid var(--line); background: rgba(9,13,20,0.65); color: var(--text);
    font-family: var(--font-mono); font-size: 13px; letter-spacing: 0.03em;
    transition: all var(--dur-fast);
}
.reset-form input:focus { outline: none; border-color: var(--cyan); box-shadow: 0 0 0 3px rgba(89,245,213,0.15); }
.reset-form button {
    flex-shrink: 0; height: 46px; padding: 0 20px; border: 0; border-radius: var(--r-md);
    background: var(--grad-btn); color: #071018; font-family: var(--font-body); font-weight: 700; font-size: 13.5px;
    cursor: pointer; transition: all var(--dur-fast); box-shadow: var(--shadow-btn);
}
.reset-form button:hover { filter: brightness(1.08); transform: translateY(-1px); box-shadow: var(--shadow-btn-hover); }
.reset-form button:active { transform: scale(0.97); }
.reset-msg { margin-top: 14px; padding: 12px 16px; border-radius: var(--r-md); font-size: 12.5px; line-height: 1.55; }
.reset-msg.ok { border: 1px solid rgba(97,230,164,0.35); background: rgba(97,230,164,0.12); color: var(--success); }
.reset-msg.err { border: 1px solid rgba(255,120,133,0.35); background: rgba(255,120,133,0.12); color: var(--danger); }
@media (max-width: 540px) { .reset-form { flex-direction: column; } .reset-form button { width: 100%; } }

/* IP Box */
.ip-box {
    max-width: 640px; margin: 20px auto 0; padding: 14px 20px; border: 1px solid var(--line);
    border-radius: var(--r-lg); background: rgba(var(--surface-rgb), 0.7); display: flex;
    align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; backdrop-filter: blur(10px);
}
.ip-box-left { display: flex; align-items: center; gap: 12px; }
.ip-ic { color: var(--cyan); display: flex; align-items: center; filter: drop-shadow(0 0 6px rgba(89,245,213,0.4)); }
.ip-label { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.1em; color: var(--text-dim); }
.ip-value { font-family: var(--font-mono); font-size: 14px; font-weight: 700; color: var(--cyan); letter-spacing: 0.02em; }
.ip-box-note { font-size: 11.5px; color: var(--text-muted); }
@media (max-width: 480px) { .ip-box { flex-direction: column; align-items: flex-start; } }

.footer-note { text-align: center; font-size: 11.5px; color: var(--text-muted); margin-top: 36px; font-family: var(--font-mono); letter-spacing: 0.05em; }

/* Contact FAB */
.fab-contact { position: fixed; right: 20px; bottom: 24px; z-index: 90; display: flex; flex-direction: column; align-items: center; gap: 12px; }
.fab-menu { display: flex; flex-direction: column; gap: 10px; margin-bottom: 2px; opacity: 0; transform: translateY(10px) scale(0.9); pointer-events: none; transition: all 0.25s var(--ease-spring); }
.fab-menu.open { opacity: 1; transform: none; pointer-events: auto; }
.fab-item { width: 46px; height: 46px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; color: #fff; box-shadow: 0 8px 24px -6px rgba(0,0,0,0.65); transition: transform 0.2s var(--ease-spring); }
.fab-item:hover { transform: scale(1.12); }
.fab-zalo { background: #0068FF; }
.fab-tele { background: #29A9EA; }
.fab-fb { background: #1877F2; }
.fab-toggle { width: 54px; height: 54px; border-radius: 50%; border: 0; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #071018; background: var(--grad-btn); box-shadow: 0 10px 28px -6px rgba(89,245,213,0.7); transition: transform 0.25s var(--ease-spring); }
.fab-toggle:hover { transform: scale(1.06); }
.fab-toggle.open { transform: rotate(45deg); }

/* Motion layer */
[data-motion] { will-change: transform, opacity; }
.motion-init [data-motion] { opacity: 0; transform: translate3d(0, 20px, 0); }
.motion-init .guide-heading { transform: translate3d(0, 0, 0); }

/* Light theme overrides */
html[data-theme="light"] body {
    background:
        radial-gradient(circle at 15% -8%, rgba(9,154,130,0.12), transparent 28rem),
        radial-gradient(circle at 90% 18%, rgba(99,79,226,0.1), transparent 25rem),
        linear-gradient(180deg, var(--bg-top) 0%, var(--bg) 60%);
}
html[data-theme="light"] .reset-form input { background: #FFFFFF; }
html[data-theme="light"] a.btn,
html[data-theme="light"] .reset-form button,
html[data-theme="light"] .fab-toggle,
html[data-theme="light"] .app-bar-dl { color: #FFFFFF; }
html[data-theme="light"] .cooldown-pill { color: #8A6100; border-color: rgba(217,164,6,0.35); background: rgba(217,164,6,0.12); }
html[data-theme="light"] .cooldown-pill .dot { background: #B8860B; }
html[data-theme="light"] .sample-perf::before,
html[data-theme="light"] .sample-perf::after { background: var(--bg); }
@media (max-width: 420px) {
    .site-nav { order: 3; width: 100%; justify-content: center; padding-top: 8px; border-top: 1px dashed var(--line); }
    .wrap { padding: 22px 14px 50px; }
}
</style>
</head>
<body>
<div class="wrap">
    <div class="app-bar" data-motion="hero">
        <div class="app-bar-icon"><?= svg_icon('zap', 20) ?></div>
        <div class="app-bar-name">
            <b><?= htmlspecialchars($siteBrandName) ?> <?= svg_icon('check-circle', 14) ?></b>
            <span><?= $resellerInfo ? t('Đại lý chính thức', 'Official reseller') : t('Cổng phát Key chính thức', 'Official key portal') ?></span>
        </div>
        <?php $apkLink = get_apk_link(); if ($apkLink !== ''): ?>
        <a class="app-bar-dl" href="<?= htmlspecialchars($apkLink) ?>" target="_blank" rel="noopener"><?= svg_icon('download', 15) ?> <?= t('Tải APK', 'Get APK') ?></a>
        <?php endif; ?>
    </div>
    <header class="site-header" data-motion="hero">
        <a class="brand" href="index.php<?= $resellerId !== null ? ('?r=' . $resellerId) : '' ?>">
            <span class="brand-mark"><?= svg_icon('zap', 19) ?></span>
            <span class="brand-name"><?= htmlspecialchars($siteBrandName) ?></span>
        </a>
        <nav class="site-nav">
            <a href="#top"><?= t('Trang chủ', 'Home') ?></a>
            <a href="#games"><?= t('Danh sách Game', 'Games') ?></a>
            <a href="#reset-section"><?= t('Reset Key', 'Reset Key') ?></a>
        </nav>
        <div class="lang-switch">
            <a class="<?= $GLOBALS['LANG'] === 'vi' ? 'active' : '' ?>" href="?<?= $rQueryOnly ?>lang=vi">VI</a>
            <span>/</span>
            <a class="<?= $GLOBALS['LANG'] === 'en' ? 'active' : '' ?>" href="?<?= $rQueryOnly ?>lang=en">EN</a>
        </div>
        <div class="theme-switch">
            <a class="<?= $GLOBALS['THEME'] === 'dark' ? 'active' : '' ?>" href="?<?= $rQueryOnly ?>theme=dark" title="<?= t('Nền tối', 'Dark background') ?>"><?= svg_icon('moon', 16) ?></a>
            <a class="<?= $GLOBALS['THEME'] === 'light' ? 'active' : '' ?>" href="?<?= $rQueryOnly ?>theme=light" title="<?= t('Nền sáng', 'Light background') ?>"><?= svg_icon('sun', 16) ?></a>
        </div>
    </header>
    <div class="breadcrumb" id="top" data-motion="hero"><?= t('Trang chủ', 'Home') ?></div>

    <?php if ($resellerInfo): ?>
    <div class="eyebrow" data-motion="hero"><?= htmlspecialchars(mb_strtoupper($siteBrandName)) ?> <span>KEY VAULT</span></div>
    <h1 data-motion="hero"><?= t('Lấy', 'Get') ?> <span style="color:var(--cyan)">Key</span> <?= t('Game', 'Keys') ?></h1>
    <p class="sub" data-motion="hero"><?= t('Vượt link, nhận key ngay — không cần đăng ký', 'Complete the link steps, get your key instantly — no sign-up needed') ?> · <?= t('Đại lý chính thức', 'Official reseller') ?>: <b><?= htmlspecialchars($siteBrandName) ?></b></p>
    <?php else: ?>
    <div class="eyebrow" data-motion="hero">HOQUOC <span>KEY VAULT</span></div>
    <h1 data-motion="hero"><?= t('Lấy', 'Get') ?> <span style="color:var(--cyan)">Key</span> <?= t('Game', 'Keys') ?></h1>
    <p class="sub" data-motion="hero"><?= t('Vượt link, nhận key ngay — không cần đăng ký', 'Complete the link steps, get your key instantly — no sign-up needed') ?></p>
    <?php endif; ?>

    <?php if ($totalActivated > 0): ?>
    <div class="proof" data-motion="hero"><span class="pulse-dot"></span> <?= t('Đã phát', 'Delivered') ?> <b><?= number_format($totalActivated) ?></b> <?= t('key thành công', 'keys successfully') ?></div>
    <?php endif; ?>

    <?php $serverClosed = is_server_closed(); ?>
    <div class="status-card" data-motion="reveal">
        <div class="status-card-head">
            <span class="label"><?= svg_icon('cloud', 14) ?> HOQUOCKEY_STATUS</span>
            <span class="status-pill <?= $serverClosed ? 'off' : 'on' ?>"><span class="dot"></span> <?= $serverClosed ? t('BẢO TRÌ', 'MAINTENANCE') : 'ONLINE' ?></span>
        </div>
        <div class="status-grid">
            <div class="status-box"><span><?= t('Nền tảng', 'Platform') ?></span><b>Android</b></div>
            <div class="status-box"><span><?= t('Số Game', 'Games') ?></span><b><?= count($games) ?></b></div>
            <div class="status-box"><span><?= t('Định dạng Key', 'Key format') ?></span><b>HQD-XXX</b></div>
            <div class="status-box"><span><?= t('Đã phát', 'Delivered') ?></span><b><?= number_format($totalActivated) ?></b></div>
        </div>
    </div>

    <div class="sample-card" data-motion="reveal">
        <div class="sample-holo"></div>
        <div class="sample-top">
            <div class="sample-tag"><?= t('Đây là key bạn sẽ nhận', 'This is the key you will receive') ?></div>
            <div class="sample-key">HQD-•••••••-•••</div>
        </div>
        <div class="sample-perf"></div>
        <div class="sample-bottom">
            <span><?= t('Định dạng key', 'Key format') ?> <b>HQD-XXXXXXX-XXX</b></span>
            <span><?= t('Giao', 'Delivery') ?> <b><?= t('tức thì', 'instant') ?></b></span>
        </div>
    </div>

    <div class="guide-layout">
    <div class="guide-heading" data-motion="guide-heading"><div class="section-label"><?= t('Hướng dẫn nhận key', 'How to get a key') ?></div></div>
    <div class="guide-steps">
    <div class="step-card" data-motion="reveal">
        <div class="step-num">01</div>
        <div class="step-body"><b><?= t('Chọn game bên dưới', 'Pick a game below') ?></b><p><?= t('Chọn game bạn muốn lấy key trong danh sách phía dưới.', 'Choose the game you want a key for from the list below.') ?></p></div>
    </div>
    <div class="step-card" data-motion="reveal">
        <div class="step-num">02</div>
        <div class="step-body"><b><?= t('Vượt link rút gọn', 'Complete the shortlink steps') ?></b><p><?= t('Bấm Tạo Link rồi mở link, làm theo hướng dẫn trên màn hình.', 'Tap Create Link, open it, and follow the on-screen steps.') ?></p></div>
    </div>
    <div class="step-card" data-motion="reveal">
        <div class="step-num">03</div>
        <div class="step-body"><b><?= t('Nhận key ngay', 'Get your key instantly') ?></b><p><?= t('Key hiện ra ngay sau khi vượt xong, sao chép và dùng trong app.', 'Your key appears right after — copy it and use it in the app.') ?></p></div>
    </div>
    </div>
    </div>

    <div class="section-label" id="games" data-motion="reveal" style="margin-top:26px"><?= t('Danh sách game', 'Game list') ?></div>

    <?php if (empty($games)): ?>
        <p class="empty"><?= t('Hiện chưa có game nào mở cấp key.', 'No games are issuing keys right now.') ?></p>
    <?php else: ?>
    <div class="game-toolbar" data-motion="reveal" role="search">
        <label class="search-field" for="gameSearch">
            <?= svg_icon('key', 16) ?>
            <input id="gameSearch" type="search" placeholder="<?= t('Tìm game theo tên...', 'Search games by name...') ?>" autocomplete="off" spellcheck="false">
            <button type="button" class="search-clear" id="clearGameSearch" aria-label="<?= t('Xoá tìm kiếm', 'Clear search') ?>">&times;</button>
        </label>
        <div class="filter-group" role="group" aria-label="<?= t('Bộ lọc game', 'Game filters') ?>">
            <button type="button" class="filter-btn active" data-filter="all"><?= t('Tất cả', 'All') ?></button>
            <button type="button" class="filter-btn" data-filter="ready"><?= t('Sẵn sàng', 'Ready') ?></button>
        </div>
    </div>
    <div class="game-results" id="gameResults" data-motion="reveal" aria-live="polite"></div>
    <div class="game-list" id="gameList">
    <?php foreach ($games as $i => $g):
        $hops = $region === 'intl' ? $g['intl_hops'] : $g['vn_hops'];
        $cooldown = $gameCooldowns[$g['id']] ?? 0;
        $gameSearchText = (string)$g['name'] . ' ' . (string)$g['slug'];
    ?>
    <div class="card" data-motion="reveal" data-game-card data-game-search="<?= htmlspecialchars($gameSearchText, ENT_QUOTES, 'UTF-8') ?>" data-ready="<?= $cooldown > 0 ? '0' : '1' ?>" style="animation-delay:<?= $i * 0.07 ?>s">
        <div class="holo"></div>
        <div class="card-body">
            <div class="icon-badge"><?= htmlspecialchars($g['icon']) ?></div>
            <div class="card-info">
                <div class="gname"><?= htmlspecialchars($g['name']) ?></div>
                <div class="gmeta">
                    <?php if ($cooldown > 0): ?>
                        <span class="cooldown-pill" data-remaining="<?= $cooldown ?>"><span class="dot"></span> <?= t('Còn lại', 'Remaining') ?> <span class="cd-text"><?= format_duration_label($cooldown) ?></span></span>
                    <?php else: ?>
                        <span class="free-pill"><span class="dot"></span> <?= t('KEY FREE', 'FREE KEY') ?></span>
                    <?php endif; ?>
                </div>
                <div class="hops-note"><?= t('Vượt', 'Complete') ?> <?= (int)$hops ?> <?= t('lần link rút gọn', 'shortlink step(s)') ?></div>
            </div>
        </div>
        <div class="perf"></div>
        <div class="card-cta">
            <?php if ($cooldown > 0): ?>
                <a class="btn btn-disabled" data-remaining="<?= $cooldown ?>" onclick="return false;"><?= svg_icon('clock', 15) ?> <?= t('Còn', 'Wait') ?> <span class="cd-text"><?= format_duration_label($cooldown) ?></span></a>
            <?php else: ?>
                <a class="btn" href="getkey.php?game=<?= urlencode($g['slug']) . $rParam ?>"><?= svg_icon('zap', 15) ?> <?= t('Lấy key miễn phí', 'Get free key') ?></a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <div class="game-no-results" id="gameNoResults" hidden><?= t('Không tìm thấy game phù hợp.', 'No matching games found.') ?></div>
    <?php endif; ?>

    <div class="section-label" style="margin-top:26px"><?= t('Tính năng nổi bật', 'Key features') ?></div>
    <div class="feature-card" data-motion="reveal">
        <div class="feature-icon"><?= svg_icon('reset', 18) ?></div>
        <div class="feature-body">
            <div class="feature-body-head"><b><?= t('Reset Key miễn phí', 'Free key reset') ?></b><span class="feature-tag"><?= t('CÓ SẴN', 'AVAILABLE') ?></span></div>
            <p><?= t('Key 40 giờ được làm mới thời hạn và gỡ thiết bị cũ, dùng được trên máy khác.', 'The 40-hour key can refresh its duration and clear old devices for use on another one.') ?></p>
        </div>
    </div>
    <div class="feature-card" data-motion="reveal">
        <div class="feature-icon"><?= svg_icon('shield', 18) ?></div>
        <div class="feature-body">
            <div class="feature-body-head"><b><?= t('Chống bypass tự động', 'Anti-bypass protection') ?></b><span class="feature-tag"><?= t('CÓ SẴN', 'AVAILABLE') ?></span></div>
            <p><?= t('Hệ thống tự phát hiện tool vượt link tự động, đảm bảo công bằng cho mọi người.', 'The system detects automated link-bypass tools to keep things fair for everyone.') ?></p>
        </div>
    </div>
    <div class="feature-card" data-motion="reveal">
        <div class="feature-icon"><?= svg_icon('key', 18) ?></div>
        <div class="feature-body">
            <div class="feature-body-head"><b><?= t('Nhiều game hỗ trợ', 'Multiple games supported') ?></b><span class="feature-tag"><?= t('CÓ SẴN', 'AVAILABLE') ?></span></div>
            <p><?= t('Mỗi game có thời hạn key và số bước vượt link riêng.', 'Each game has its own key duration and number of link steps.') ?></p>
        </div>
    </div>

    <div class="reset-box" id="reset-section" data-motion="reveal" style="margin-top:26px">
        <div class="reset-title"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--cyan);flex-shrink:0"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg> <?= t('Reset Key', 'Reset Key') ?></div>
        <p class="reset-sub"><?= t('Chỉ áp dụng cho', 'Only applies to') ?> <b><?= t('Key 40 giờ', 'the 40-hour Key') ?></b> <?= t('(vượt 2 lần link) - làm mới lại thời hạn 40h và gỡ thiết bị cũ để đăng nhập máy khác.', '(2 link steps) - refreshes the 40h duration and clears old devices so you can log in on another device.') ?> <b><?= t('Mỗi key chỉ được reset 1 lần duy nhất', 'Each key can only be reset once') ?></b> <?= t('(kể cả khi key đã hết hạn).', '(even if the key has already expired).') ?></p>
        <form class="reset-form" method="post" action="index.php?<?= $rQueryOnly ?>reset=1#reset-section">
            <input type="text" name="reset_keycode" placeholder="<?= t('Nhập keycode của bạn (VD: HQD-XXXXXXX-XXX)', 'Enter your keycode (e.g. HQD-XXXXXXX-XXX)') ?>" autocomplete="off" required>
            <input type="hidden" name="reset_key_submit" value="1">
            <button type="submit"><?= t('Reset Key', 'Reset Key') ?></button>
        </form>
        <?php if ($resetResult): ?>
        <div id="reset" class="reset-msg <?= $resetResult['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($resetResult['message']) ?></div>
        <?php endif; ?>
    </div>

    <div class="ip-box" data-motion="reveal">
        <div class="ip-box-left">
            <span class="ip-ic"><?= svg_icon('globe', 20) ?></span>
            <div>
                <div class="ip-label"><?= t('IP CỦA BẠN', 'YOUR IP') ?></div>
                <div class="ip-value"><?= htmlspecialchars($clientIp ?: t('Không xác định', 'Unknown')) ?></div>
            </div>
        </div>
        <div class="ip-box-note"><?= t('Chỉ mình bạn thấy được thông tin này', 'Only you can see this information') ?></div>
    </div>

    <div class="footer-note" data-motion="reveal">© Hồ Quốc — KeyAuth System</div>
</div>

<?php if ($contact['zalo'] !== '' || $contact['telegram'] !== '' || $contact['facebook'] !== ''): ?>
<div class="fab-contact">
    <div class="fab-menu" id="fabMenu">
        <?php if ($contact['zalo'] !== ''): ?><a class="fab-item fab-zalo" href="<?= htmlspecialchars($contact['zalo']) ?>" target="_blank" rel="noopener" title="Zalo"><?= svg_icon('message-circle', 20) ?></a><?php endif; ?>
        <?php if ($contact['telegram'] !== ''): ?><a class="fab-item fab-tele" href="<?= htmlspecialchars($contact['telegram']) ?>" target="_blank" rel="noopener" title="Telegram"><?= svg_icon('send', 18) ?></a><?php endif; ?>
        <?php if ($contact['facebook'] !== ''): ?><a class="fab-item fab-fb" href="<?= htmlspecialchars($contact['facebook']) ?>" target="_blank" rel="noopener" title="Facebook"><?= svg_icon('users', 18) ?></a><?php endif; ?>
    </div>
    <button type="button" class="fab-toggle" id="fabToggle" aria-label="<?= t('Liên hệ', 'Contact') ?>" aria-expanded="false"><?= svg_icon('phone', 23) ?></button>
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
<script>
// Đếm ngược real-time cho các badge/nút "còn lại" - ban đầu server render
// sẵn nhãn tĩnh (vd "3 ngày"), JS sẽ tự cập nhật chi tiết hơn (HH:MM:SS)
// mỗi giây, và tự bật lại nút "Lấy key miễn phí" khi đếm về 0 (F5 lại
// trang cũng được nhưng làm thế này mượt hơn, không cần user tự bấm F5).
(function(){
    function fmt(sec){
        if (sec <= 0) return '00:00:00';
        var d = Math.floor(sec / 86400);
        var h = Math.floor((sec % 86400) / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = sec % 60;
        var hh = String(h).padStart(2,'0'), mm = String(m).padStart(2,'0'), ss = String(s).padStart(2,'0');
        return d > 0 ? (d + ' ngày ' + hh + ':' + mm + ':' + ss) : (hh + ':' + mm + ':' + ss);
    }
    var els = document.querySelectorAll('[data-remaining]');
    els.forEach(function(el){
        var remaining = parseInt(el.dataset.remaining, 10);
        var textEl = el.querySelector('.cd-text');
        var timer = setInterval(function(){
            remaining--;
            if (remaining <= 0) {
                clearInterval(timer);
                location.reload(); // hết cooldown -> tải lại trang để hiện nút "Lấy key miễn phí"
                return;
            }
            if (textEl) textEl.textContent = fmt(remaining);
        }, 1000);
    });
})();
</script>
<script>
// Game explorer: lọc tại chỗ để thao tác nhanh, không gửi lại form hay
// làm thay đổi URL nên vẫn giữ nguyên trạng thái reseller/ngôn ngữ/theme.
(function(){
    var input = document.getElementById('gameSearch');
    var list = document.getElementById('gameList');
    var result = document.getElementById('gameResults');
    var noResults = document.getElementById('gameNoResults');
    var clear = document.getElementById('clearGameSearch');
    if (!input || !list || !result || !noResults) return;

    var cards = Array.prototype.slice.call(list.querySelectorAll('[data-game-card]'));
    var buttons = Array.prototype.slice.call(document.querySelectorAll('.filter-btn'));
    var locale = <?= json_encode($GLOBALS['LANG'] === 'en' ? 'en' : 'vi') ?>;
    var filter = 'all';

    function update(){
        var query = input.value.trim().toLocaleLowerCase();
        var visible = 0;
        cards.forEach(function(card){
            var matchesQuery = !query || (card.dataset.gameSearch || '').toLocaleLowerCase().indexOf(query) !== -1;
            var matchesFilter = filter === 'all' || card.dataset.ready === '1';
            var show = matchesQuery && matchesFilter;
            card.classList.toggle('is-hidden', !show);
            if (show) visible++;
        });
        clear.classList.toggle('visible', input.value.length > 0);
        noResults.hidden = visible !== 0;
        if (locale === 'en') {
            result.textContent = visible + (visible === 1 ? ' game available' : ' games available');
        } else {
            result.textContent = visible + ' game khả dụng';
        }
    }

    input.addEventListener('input', update);
    clear.addEventListener('click', function(){ input.value = ''; input.focus(); update(); });
    buttons.forEach(function(button){
        button.addEventListener('click', function(){
            filter = button.dataset.filter || 'all';
            buttons.forEach(function(item){ item.classList.toggle('active', item === button); });
            update();
        });
    });
    update();
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js" defer></script>
<script>
// Motion enhancement: nội dung vẫn hiển thị bình thường nếu GSAP hoặc
// ScrollTrigger không tải được, hoặc người dùng bật reduced motion.
(function(){
    function initMotion(){
        if (!window.gsap || !window.ScrollTrigger || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        var root = document.documentElement;
        root.classList.add('motion-init');
        gsap.registerPlugin(ScrollTrigger);

        var hero = gsap.utils.toArray('[data-motion="hero"]');
        if (hero.length) {
            gsap.fromTo(hero, {y:28, opacity:0}, {y:0, opacity:1, duration:.82, stagger:.09, ease:'power3.out', clearProps:'transform'});
        }

        gsap.utils.toArray('[data-motion="reveal"], [data-motion="guide-heading"]').forEach(function(element, index){
            gsap.fromTo(element, {y:30, opacity:0, scale:.985}, {
                y:0, opacity:1, scale:1, duration:.72, delay:(index % 3) * .035,
                ease:'power3.out', clearProps:'transform',
                scrollTrigger:{trigger:element, start:'top 88%', toggleActions:'play none none reverse'}
            });
        });

        var guide = document.querySelector('.guide-layout');
        var guideHeading = document.querySelector('.guide-heading');
        var lastStep = document.querySelector('.guide-steps .step-card:last-child');
        if (guide && guideHeading && lastStep && window.matchMedia('(min-width: 701px)').matches) {
            ScrollTrigger.create({
                trigger:guide, pin:guideHeading, start:'top 14%', endTrigger:lastStep,
                end:'bottom 62%', pinSpacing:false, anticipatePin:1
            });
        }

        var sample = document.querySelector('.sample-card');
        var sampleKey = document.querySelector('.sample-key');
        if (sample && sampleKey) {
            gsap.to(sampleKey, {y:-10, ease:'none', scrollTrigger:{trigger:sample, start:'top bottom', end:'bottom top', scrub:1}});
        }

        document.querySelectorAll('.game-list .card, .sample-card').forEach(function(card){
            var target = card.querySelector('.icon-badge') || card.querySelector('.sample-key');
            if (!target) return;
            var moveX = gsap.quickTo(target, 'x', {duration:.28, ease:'power2.out'});
            var moveY = gsap.quickTo(target, 'y', {duration:.28, ease:'power2.out'});
            card.addEventListener('pointermove', function(event){
                if (event.pointerType === 'touch') return;
                var rect = card.getBoundingClientRect();
                moveX((event.clientX - rect.left - rect.width / 2) * .045);
                moveY((event.clientY - rect.top - rect.height / 2) * .045);
            });
            card.addEventListener('pointerleave', function(){ moveX(0); moveY(0); });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initMotion);
    else initMotion();
})();
</script>
<?= anti_devtools_script() ?>
</body>
</html>
