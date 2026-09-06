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
:root{
    --bg:#0B0E14; --surface:#12151F; --surface2:#181C28;
    --cyan:#00E5C7; --violet:#8B7CFF; --text:#E8ECF3; --text-dim:#8891A3;
    --success:#34D399;
}
*{box-sizing:border-box}
body{
    font-family:'Be Vietnam Pro',-apple-system,Arial,sans-serif;
    background:radial-gradient(ellipse at top,#151a26 0%,var(--bg) 60%);
    color:var(--text);margin:0;min-height:100vh;
}
.wrap{max-width:440px;margin:0 auto;padding:44px 18px 60px}
.eyebrow{font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.14em;color:var(--text-dim);text-transform:uppercase;text-align:center;animation:fadeIn .4s ease}
.eyebrow span{color:var(--cyan)}
h1{
    font-family:'Be Vietnam Pro',sans-serif;font-weight:700;font-size:30px;
    text-align:center;margin:8px 0 6px;
    background:linear-gradient(135deg,var(--text),var(--cyan) 120%);
    -webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;
    animation:slideDown .45s ease;
}
.sub{font-size:13.5px;color:var(--text-dim);text-align:center;margin:0 0 16px;animation:fadeIn .5s ease}

/* Social proof - số key đã phát, tăng độ tin cậy */
.proof{
    display:flex;align-items:center;justify-content:center;gap:6px;font-size:12px;
    color:var(--text-dim);margin-bottom:24px;animation:fadeIn .55s ease;
}
.proof b{color:var(--success);font-family:'JetBrains Mono',monospace}
.proof .pulse-dot{width:6px;height:6px;border-radius:50%;background:var(--success);animation:blink 1.6s ease-in-out infinite}

/* Hero: 1 tấm thẻ key MẪU - chính là thứ user sẽ nhận được, không phải
   hàng badge liệt kê tính năng (đây là "thesis" của trang, không phải
   trang trí). Số bị che dấu • để rõ đây là mẫu minh hoạ. */
.sample-card{
    background:var(--surface);border-radius:16px;overflow:hidden;margin-bottom:34px;
    box-shadow:0 16px 40px -18px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.04);
    animation:cardIn .55s cubic-bezier(.16,1,.3,1) .1s both;
}
.sample-holo{height:3px;background:linear-gradient(90deg,var(--cyan),var(--violet),var(--cyan));background-size:200% 100%;animation:holoShift 3s linear infinite}
.sample-top{padding:16px 20px 14px;text-align:center}
.sample-tag{font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:.12em;color:var(--text-dim);text-transform:uppercase}
.sample-key{
    font-family:'JetBrains Mono',monospace;font-weight:700;font-size:20px;letter-spacing:.08em;
    color:var(--cyan);text-shadow:0 0 16px rgba(0,229,199,.3);margin-top:6px;
}
.sample-perf{height:0;border-top:1.5px dashed #262b38;margin:0 20px;position:relative}
.sample-perf::before,.sample-perf::after{content:'';position:absolute;top:-7px;width:14px;height:14px;border-radius:50%;background:var(--bg)}
.sample-perf::before{left:-14px}
.sample-perf::after{right:-14px}
.sample-bottom{padding:12px 20px 16px;display:flex;justify-content:space-between;font-size:11px;color:var(--text-dim)}
.sample-bottom b{color:var(--text);font-weight:600}

.section-label{font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.1em;color:var(--text-dim);text-transform:uppercase;margin:0 0 12px 2px}

.card{
    background:var(--surface);border-radius:18px;margin-bottom:16px;overflow:hidden;
    position:relative;box-shadow:0 12px 30px -14px rgba(0,0,0,.55),0 0 0 1px rgba(255,255,255,.04);
    transition:transform .2s,box-shadow .2s;opacity:0;animation:cardIn .5s cubic-bezier(.16,1,.3,1) forwards;
}
.card:hover{transform:translateY(-4px);box-shadow:0 18px 38px -14px rgba(0,0,0,.6),0 0 0 1px rgba(0,229,199,.2)}
.holo{height:3px;background:linear-gradient(90deg,var(--cyan),var(--violet),var(--cyan));background-size:200% 100%;animation:holoShift 3s linear infinite}
.card-body{padding:20px 20px 22px;display:flex;align-items:center;gap:14px}
.icon-badge{
    width:52px;height:52px;border-radius:14px;background:var(--surface2);
    display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0;
    border:1px solid rgba(0,229,199,.15);transition:transform .25s;
}
.card:hover .icon-badge{transform:scale(1.08) rotate(-4deg)}
.card-info{flex:1;min-width:0}
.gname{font-family:'Be Vietnam Pro',sans-serif;font-weight:700;font-size:16.5px}
.gmeta{display:flex;align-items:center;gap:6px;margin-top:4px;flex-wrap:wrap}
.free-pill{
    font-size:10.5px;font-weight:600;color:var(--success);background:rgba(52,211,153,.12);
    padding:2px 9px;border-radius:10px;display:inline-flex;align-items:center;gap:4px;
}
.free-pill .dot{width:5px;height:5px;border-radius:50%;background:var(--success);animation:blink 1.6s ease-in-out infinite}
.hops-note{font-size:11px;color:var(--text-dim);margin-top:3px;font-family:'JetBrains Mono',monospace}

.perf{height:0;border-top:1.5px dashed #262b38;margin:0 20px}
.card-cta{padding:14px 20px 20px}
a.btn{
    display:flex;align-items:center;justify-content:center;gap:8px;
    background:linear-gradient(135deg,var(--cyan),var(--violet));color:#0B0E14;
    text-decoration:none;font-family:'Be Vietnam Pro',sans-serif;font-weight:700;font-size:14.5px;
    padding:13px;border-radius:12px;transition:transform .12s,filter .15s;
}
a.btn:active{transform:scale(.97)}
a.btn:hover{filter:brightness(1.08)}

.empty{text-align:center;color:var(--text-dim);font-size:14px;margin-top:60px;animation:fadeIn .5s ease}
.footer-note{text-align:center;font-size:11px;color:var(--text-dim);margin-top:30px;font-family:'JetBrains Mono',monospace;animation:fadeIn .6s ease}

/* Reset Key - chỉ Key 40h, mỗi key reset 1 lần */
.reset-box{max-width:620px;margin:30px auto 0;padding:20px 22px 22px;border:1px solid var(--line);border-radius:20px;background:rgba(var(--surface-rgb),.82);box-shadow:0 16px 40px -28px #000,inset 0 1px rgba(255,255,255,.045)}
.reset-title{font-family:'Be Vietnam Pro',sans-serif;font-weight:700;font-size:15.5px;display:flex;align-items:center;gap:7px}
.reset-sub{font-size:11.5px;color:var(--text-dim);margin:5px 0 14px;line-height:1.55}
.reset-form{display:flex;gap:8px}
.reset-form input{flex:1;min-width:0;height:46px;padding:0 14px;border-radius:11px;border:1px solid var(--line);background:rgba(9,13,20,.7);color:var(--text);font-family:'JetBrains Mono',monospace;font-size:13px;letter-spacing:.03em}
.reset-form input:focus{outline:none;border-color:rgba(89,245,213,.45)}
.reset-form button{flex-shrink:0;height:46px;padding:0 18px;border:0;border-radius:11px;background:linear-gradient(110deg,var(--cyan),#B5FFF0 45%,var(--violet));color:#071018;font-family:'Be Vietnam Pro',sans-serif;font-weight:700;font-size:13px;cursor:pointer}
.reset-form button:active{transform:scale(.97)}
.reset-msg{margin-top:12px;padding:10px 12px;border-radius:11px;font-size:12px;line-height:1.5}
.reset-msg.ok{border:1px solid rgba(97,230,164,.3);background:rgba(97,230,164,.08);color:#8CF0BC}
.reset-msg.err{border:1px solid rgba(255,156,166,.3);background:rgba(255,120,133,.08);color:#FF9CA6}
@media (max-width:560px){.reset-form{flex-direction:column}.reset-form button{height:46px}}

/* IP của bạn */
.ip-box{max-width:620px;margin:20px auto 0;padding:14px 18px;border:1px solid var(--line);border-radius:16px;background:rgba(var(--surface-rgb),.7);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.ip-box-left{display:flex;align-items:center;gap:11px}
.ip-ic{font-size:20px}
.ip-label{font-family:'JetBrains Mono',monospace;font-size:9.5px;letter-spacing:.1em;color:var(--text-dim)}
.ip-value{font-family:'JetBrains Mono',monospace;font-size:14px;font-weight:700;color:var(--cyan);letter-spacing:.02em}
.ip-box-note{font-size:10.5px;color:var(--text-dim)}
@media (max-width:480px){.ip-box{flex-direction:column;align-items:flex-start}}

/* Badge/nút cooldown "còn lại" trên mỗi game */
.cooldown-pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:rgba(245,201,105,.1);border:1px solid rgba(245,201,105,.25);color:#F5D98D;font-size:11px;font-family:'JetBrains Mono',monospace}
.cooldown-pill .dot{width:6px;height:6px;border-radius:50%;background:#F5D98D;animation:pulse 1.4s infinite}
.btn-disabled{opacity:.5;cursor:not-allowed;filter:grayscale(.4)}
.btn-disabled:hover{transform:none!important}

@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes slideDown{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
@keyframes cardIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes holoShift{to{background-position:200% 0}}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.35}}

/* ============================================================
   HoQuoc UI refresh — public experience
   Chỉ thay lớp trình bày, không thay đổi luồng PHP.
   ============================================================ */
:root{
    --bg:#070A10;
    --surface:#101620;
    --surface-rgb:16,22,32;
    --surface2:#151D2A;
    --surface3:#1B2635;
    --line:rgba(177,199,224,.12);
    --line-strong:rgba(177,199,224,.2);
    --cyan:#59F5D5;
    --violet:#9C8CFF;
    --text:#F4F7FB;
    --text-dim:#93A1B5;
    --success:#61E6A4;
    --bg-top:#0B1019;
    --grid-line:rgba(255,255,255,.025);
}
html{background:var(--bg);scroll-behavior:smooth}
body{
    position:relative;
    overflow-x:hidden;
    background:
        radial-gradient(circle at 15% -8%,rgba(89,245,213,.16),transparent 28rem),
        radial-gradient(circle at 90% 18%,rgba(156,140,255,.12),transparent 25rem),
        linear-gradient(180deg,var(--bg-top) 0%,var(--bg) 60%);
}
body::before{
    content:"";position:fixed;inset:0;pointer-events:none;opacity:.34;
    background-image:linear-gradient(var(--grid-line) 1px,transparent 1px),linear-gradient(90deg,var(--grid-line) 1px,transparent 1px);
    background-size:44px 44px;mask-image:linear-gradient(to bottom,#000,transparent 78%);
}
.wrap{position:relative;max-width:760px;padding:42px 24px 72px}
.eyebrow{
    display:inline-flex;align-items:center;gap:8px;margin:0 auto 17px;padding:7px 11px;
    border:1px solid var(--line);border-radius:999px;background:rgba(var(--surface-rgb),.72);
    box-shadow:0 8px 24px rgba(0,0,0,.18);font-size:10px;letter-spacing:.18em;
}
.eyebrow::before{content:"";width:6px;height:6px;border-radius:50%;background:var(--cyan);box-shadow:0 0 12px var(--cyan)}
.eyebrow span{color:var(--cyan)}
h1{font-size:clamp(34px,6vw,56px);line-height:1.02;letter-spacing:-.055em;margin:0 auto 13px;max-width:620px;font-weight:700}
.sub{font-size:15px;line-height:1.65;max-width:480px;margin:0 auto 16px}
.proof{display:inline-flex;margin:0 auto 30px;padding:8px 12px;border:1px solid rgba(97,230,164,.18);border-radius:999px;background:rgba(97,230,164,.06)}
.sample-card{
    position:relative;max-width:620px;margin:0 auto 30px;border:1px solid var(--line);
    border-radius:24px;background:linear-gradient(145deg,rgba(22,31,44,.96),rgba(12,17,26,.98));
    box-shadow:0 30px 80px -32px rgba(0,0,0,.9),inset 0 1px rgba(255,255,255,.06);
}
.sample-card::after{
    content:"";position:absolute;inset:0;pointer-events:none;border-radius:inherit;
    background:linear-gradient(120deg,rgba(255,255,255,.06),transparent 28%,transparent 68%,rgba(89,245,213,.04));
}
.sample-holo,.holo{height:4px;background:linear-gradient(90deg,var(--cyan),var(--violet),#F0A6FF,var(--cyan));background-size:240% 100%}
.sample-top{padding:27px 28px 24px}
.sample-tag{font-size:10px;letter-spacing:.18em;color:var(--text-dim)}
.sample-key{font-size:clamp(22px,5vw,31px);letter-spacing:.12em;margin-top:13px}
.sample-perf{border-color:var(--line)}
.sample-bottom{padding:16px 28px 20px}
.sample-bottom span{display:flex;flex-direction:column;gap:4px;font-size:10px;letter-spacing:.03em}
.sample-bottom b{font-size:11px}
.section-label{
    display:flex;align-items:center;gap:11px;margin:0 auto 15px;max-width:620px;
    color:var(--text-dim);font-size:10px;letter-spacing:.18em
}
.section-label::after{content:"";height:1px;flex:1;background:var(--line)}
.card{
    max-width:620px;margin:0 auto 14px;border:1px solid var(--line);border-radius:20px;
    background:rgba(var(--surface-rgb),.82);box-shadow:0 16px 40px -28px #000,inset 0 1px rgba(255,255,255,.045)
}
.card:hover{transform:translateY(-3px);border-color:rgba(89,245,213,.35);box-shadow:0 22px 50px -28px #000,0 0 0 1px rgba(89,245,213,.07)}
.card-body{padding:20px 22px;gap:16px}
.icon-badge{width:54px;height:54px;border-radius:17px;background:linear-gradient(145deg,var(--surface3),#101721);border-color:rgba(89,245,213,.22);box-shadow:inset 0 1px rgba(255,255,255,.08)}
.gname{font-size:17px;letter-spacing:-.02em}
.free-pill{padding:4px 9px;font-size:10px;background:rgba(97,230,164,.1)}
.hops-note{margin-top:6px;color:#8290A4;font-size:10.5px}
.perf{border-color:var(--line)}
.card-cta{padding:15px 22px 21px}
a.btn{min-height:47px;border-radius:13px;color:#071018;background:linear-gradient(110deg,var(--cyan),#B5FFF0 45%,var(--violet));box-shadow:0 10px 24px -12px rgba(89,245,213,.65);font-size:14px;font-weight:700}
a.btn:hover{filter:brightness(1.08);box-shadow:0 14px 28px -12px rgba(89,245,213,.72)}
.empty{padding:30px;border:1px dashed var(--line);border-radius:18px}
.footer-note{margin-top:36px;color:#657287;font-size:10px;letter-spacing:.08em}
@media (min-width:700px){
    .card{display:inline-block;width:calc(50% - 8px);vertical-align:top;margin-right:12px}
    .card:nth-of-type(even){margin-right:0}
    .card-body{min-height:116px}
}
@media (max-width:560px){
    .wrap{padding:28px 16px 54px}
    .sample-top{padding:23px 20px 21px}.sample-bottom{padding:14px 20px 17px}
    .sample-bottom span{font-size:9px}.sample-bottom b{font-size:10px}
    .card-body{padding:17px 16px}.card-cta{padding:13px 16px 17px}
}
@media (prefers-reduced-motion:reduce){
    *,*::before,*::after{animation-duration:.001ms!important;animation-iteration-count:1!important;scroll-behavior:auto!important;transition-duration:.001ms!important}
}

/* Header/nav + breadcrumb + nút liên hệ nổi - thêm sau, không đụng CSS cũ */
.site-header{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px 16px;max-width:760px;margin:0 auto 6px;padding:6px 0 18px}
.brand{display:flex;align-items:center;gap:8px;text-decoration:none;color:var(--text)}
.brand-mark{font-size:19px;line-height:1}
.brand-name{font-family:'Be Vietnam Pro',sans-serif;font-weight:700;font-size:15px;letter-spacing:-.01em}
.brand-name b{color:var(--cyan)}
.site-nav{display:flex;gap:16px;font-size:12.5px}
.site-nav a{color:var(--text-dim);text-decoration:none;transition:color .15s}
.site-nav a:hover{color:var(--cyan)}
.lang-switch{display:flex;align-items:center;gap:4px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text-dim)}
.lang-switch a{color:var(--text-dim);text-decoration:none;padding:3px 6px;border-radius:6px;transition:color .15s,background .15s}
.lang-switch a.active{color:var(--cyan);background:rgba(89,245,213,.1)}
.breadcrumb{max-width:760px;margin:0 auto 22px;font-size:11px;color:var(--text-dim);font-family:'JetBrains Mono',monospace;letter-spacing:.04em}

.fab-contact{position:fixed;right:18px;bottom:22px;z-index:80;display:flex;flex-direction:column;align-items:center;gap:10px}
.fab-menu{display:flex;flex-direction:column;gap:10px;margin-bottom:2px;opacity:0;transform:translateY(10px) scale(.9);pointer-events:none;transition:all .22s cubic-bezier(.16,1,.3,1)}
.fab-menu.open{opacity:1;transform:none;pointer-events:auto}
.fab-item{width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;text-decoration:none;color:#fff;box-shadow:0 10px 22px -8px rgba(0,0,0,.6);transition:transform .15s}
.fab-item:hover{transform:scale(1.08)}
.fab-zalo{background:#0068FF}
.fab-tele{background:#29A9EA}
.fab-fb{background:#1877F2}
.fab-toggle{width:56px;height:56px;border-radius:50%;border:0;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#071018;background:linear-gradient(135deg,var(--cyan),var(--violet));box-shadow:0 12px 28px -10px rgba(89,245,213,.7);transition:transform .2s ease}
.fab-toggle.open{transform:rotate(45deg)}
@media (max-width:420px){.site-nav{order:3;width:100%;justify-content:center;padding-top:4px;border-top:1px dashed var(--line)}}

/* Theme sáng - bật qua html[data-theme="light"], chọn ở nút mặt trời/mặt
   trăng trong header (giống cơ chế VI/EN: lưu session, không cần JS để
   render đúng ngay lần load đầu). Chỉ override biến + vài chỗ hardcode
   màu nền tối (rgba(16,22,32,...) đã đổi thành rgba(var(--surface-rgb))
   ở trên) - phần còn lại (bo góc, khoảng cách, animation...) giữ nguyên. */
html[data-theme="light"]{
    --bg:#F4F6FA;
    --surface:#FFFFFF;
    --surface-rgb:255,255,255;
    --surface2:#EDF0F6;
    --surface3:#E3E8F0;
    --line:rgba(30,41,59,.12);
    --line-strong:rgba(30,41,59,.22);
    --cyan:#0EA88E;
    --violet:#6D5AE0;
    --text:#151A23;
    --text-dim:#5B6472;
    --success:#0F9D63;
    --bg-top:#FFFFFF;
    --grid-line:rgba(15,23,42,.045);
}
html[data-theme="light"] body{
    background:
        radial-gradient(circle at 15% -8%,rgba(14,168,142,.10),transparent 28rem),
        radial-gradient(circle at 90% 18%,rgba(109,90,224,.08),transparent 25rem),
        linear-gradient(180deg,var(--bg-top) 0%,var(--bg) 60%);
}
html[data-theme="light"] .card,
html[data-theme="light"] .reset-box,
html[data-theme="light"] .status-card,
html[data-theme="light"] .step-card,
html[data-theme="light"] .feature-card,
html[data-theme="light"] .app-bar{
    box-shadow:0 10px 26px -20px rgba(30,41,59,.25),inset 0 1px rgba(255,255,255,.6);
}
html[data-theme="light"] .card:hover{box-shadow:0 16px 34px -20px rgba(30,41,59,.28),0 0 0 1px rgba(14,168,142,.18)}
html[data-theme="light"] a.btn,
html[data-theme="light"] .reset-form button,
html[data-theme="light"] .fab-toggle{color:#FFFFFF}
html[data-theme="light"] .cooldown-pill{color:#8A6100;border-color:rgba(217,164,6,.35);background:rgba(217,164,6,.12)}
html[data-theme="light"] .cooldown-pill .dot{background:#B8860B}
.theme-switch{display:flex;align-items:center;gap:2px}
.theme-switch a{display:flex;align-items:center;padding:4px 6px;border-radius:6px;color:var(--text-dim);text-decoration:none;transition:color .15s,background .15s}
.theme-switch a.active{color:var(--cyan);background:rgba(89,245,213,.1)}

/* App-bar kiểu "trang tải app" + status card + step card + feature card */
.app-bar{display:flex;align-items:center;gap:12px;max-width:760px;margin:0 auto 18px;padding:12px 16px;border:1px solid var(--line);border-radius:18px;background:rgba(var(--surface-rgb),.75)}
.app-bar-icon{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,var(--cyan),var(--violet));display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0}
.app-bar-name{display:flex;flex-direction:column;flex:1;min-width:0}
.app-bar-name b{font-size:14.5px;font-weight:700;display:flex;align-items:center;gap:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.app-bar-name b svg{color:var(--success);flex-shrink:0}
.app-bar-name span{font-size:10.5px;color:var(--text-dim)}
.app-bar-dl{flex-shrink:0;display:flex;align-items:center;gap:6px;padding:10px 16px;border-radius:999px;background:linear-gradient(135deg,var(--cyan),var(--violet));color:#fff;font-weight:700;font-size:12.5px;text-decoration:none;white-space:nowrap;transition:filter .15s}
.app-bar-dl:hover{filter:brightness(1.08)}

.status-card{max-width:620px;margin:0 auto 22px;padding:16px 18px;border:1px solid var(--line);border-radius:18px;background:rgba(var(--surface-rgb),.7)}
.status-card-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:14px}
.status-card-head .label{display:flex;align-items:center;gap:6px;color:var(--text-dim);font-size:11px;font-family:'JetBrains Mono',monospace;letter-spacing:.04em}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;font-size:10.5px;font-weight:700;font-family:'JetBrains Mono',monospace}
.status-pill.on{background:rgba(52,211,153,.12);color:var(--success)}
.status-pill.off{background:rgba(239,68,68,.12);color:#EF4444}
.status-pill .dot{width:6px;height:6px;border-radius:50%;background:currentColor}
.status-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.status-box{padding:10px 12px;border:1px solid var(--line);border-radius:12px;text-align:center}
.status-box span{display:block;font-size:9px;letter-spacing:.06em;color:var(--text-dim);text-transform:uppercase;margin-bottom:4px}
.status-box b{font-size:13px;font-weight:700}

.step-card{max-width:620px;margin:0 auto 10px;padding:16px 18px;border:1px solid var(--line);border-radius:16px;background:rgba(var(--surface-rgb),.68);display:flex;gap:14px;align-items:flex-start}
.step-num{font-size:24px;font-weight:800;color:var(--cyan);line-height:1;flex-shrink:0;min-width:32px}
.step-body b{display:block;font-size:14px;font-weight:700;margin-bottom:3px}
.step-body p{font-size:12px;color:var(--text-dim);margin:0;line-height:1.5}

.feature-card{max-width:620px;margin:0 auto 10px;padding:14px 16px;border:1px solid var(--line);border-radius:16px;background:rgba(var(--surface-rgb),.68);display:flex;gap:12px;align-items:flex-start}
.feature-icon{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:rgba(89,245,213,.12);color:var(--cyan)}
.feature-body{flex:1;min-width:0}
.feature-body-head{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.feature-body-head b{font-size:13.5px;font-weight:700}
.feature-tag{font-size:9px;font-weight:700;letter-spacing:.04em;padding:2px 8px;border-radius:999px;background:rgba(89,245,213,.12);color:var(--cyan);white-space:nowrap}
.feature-body p{font-size:12px;color:var(--text-dim);margin:4px 0 0;line-height:1.5}
</style>
</head>
<body>
<div class="wrap">
    <div class="app-bar">
        <div class="app-bar-icon"><?= svg_icon('zap', 20) ?></div>
        <div class="app-bar-name">
            <b>HoQuocKey <?= svg_icon('check-circle', 14) ?></b>
            <span><?= t('Cổng phát Key chính thức', 'Official key portal') ?></span>
        </div>
        <?php $apkLink = get_apk_link(); if ($apkLink !== ''): ?>
        <a class="app-bar-dl" href="<?= htmlspecialchars($apkLink) ?>" target="_blank" rel="noopener"><?= svg_icon('download', 15) ?> <?= t('Tải APK', 'Get APK') ?></a>
        <?php endif; ?>
    </div>
    <header class="site-header">
        <a class="brand" href="index.php<?= $resellerId !== null ? ('?r=' . $resellerId) : '' ?>">
            <span class="brand-mark"><?= svg_icon('zap', 19) ?></span>
            <span class="brand-name">HoQuoc<b>Key</b></span>
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
    <div class="breadcrumb" id="top"><?= t('Trang chủ', 'Home') ?></div>

    <?php if ($resellerInfo): $brandName = $resellerInfo['store_name'] ?: $resellerInfo['username']; ?>
    <div class="eyebrow"><?= htmlspecialchars(mb_strtoupper($brandName)) ?> <span>KEY VAULT</span></div>
    <h1><?= t('Lấy', 'Get') ?> <span style="color:var(--cyan)">Key</span> <?= t('Game', 'Keys') ?></h1>
    <p class="sub"><?= t('Vượt link, nhận key ngay — không cần đăng ký', 'Complete the link steps, get your key instantly — no sign-up needed') ?> · <?= t('Đại lý chính thức', 'Official reseller') ?>: <b><?= htmlspecialchars($brandName) ?></b></p>
    <?php else: ?>
    <div class="eyebrow">HOQUOC <span>KEY VAULT</span></div>
    <h1><?= t('Lấy', 'Get') ?> <span style="color:var(--cyan)">Key</span> <?= t('Game', 'Keys') ?></h1>
    <p class="sub"><?= t('Vượt link, nhận key ngay — không cần đăng ký', 'Complete the link steps, get your key instantly — no sign-up needed') ?></p>
    <?php endif; ?>

    <?php if ($totalActivated > 0): ?>
    <div class="proof"><span class="pulse-dot"></span> <?= t('Đã phát', 'Delivered') ?> <b><?= number_format($totalActivated) ?></b> <?= t('key thành công', 'keys successfully') ?></div>
    <?php endif; ?>

    <?php $serverClosed = is_server_closed(); ?>
    <div class="status-card">
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

    <div class="sample-card">
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

    <div class="section-label"><?= t('Hướng dẫn nhận key', 'How to get a key') ?></div>
    <div class="step-card">
        <div class="step-num">01</div>
        <div class="step-body"><b><?= t('Chọn game bên dưới', 'Pick a game below') ?></b><p><?= t('Chọn game bạn muốn lấy key trong danh sách phía dưới.', 'Choose the game you want a key for from the list below.') ?></p></div>
    </div>
    <div class="step-card">
        <div class="step-num">02</div>
        <div class="step-body"><b><?= t('Vượt link rút gọn', 'Complete the shortlink steps') ?></b><p><?= t('Bấm Tạo Link rồi mở link, làm theo hướng dẫn trên màn hình.', 'Tap Create Link, open it, and follow the on-screen steps.') ?></p></div>
    </div>
    <div class="step-card">
        <div class="step-num">03</div>
        <div class="step-body"><b><?= t('Nhận key ngay', 'Get your key instantly') ?></b><p><?= t('Key hiện ra ngay sau khi vượt xong, sao chép và dùng trong app.', 'Your key appears right after — copy it and use it in the app.') ?></p></div>
    </div>

    <div class="section-label" id="games" style="margin-top:26px"><?= t('Danh sách game', 'Game list') ?></div>

    <?php if (empty($games)): ?>
        <p class="empty"><?= t('Hiện chưa có game nào mở cấp key.', 'No games are issuing keys right now.') ?></p>
    <?php else: foreach ($games as $i => $g):
        $hops = $region === 'intl' ? $g['intl_hops'] : $g['vn_hops'];
        $cooldown = $gameCooldowns[$g['id']] ?? 0;
    ?>
    <div class="card" style="animation-delay:<?= $i * 0.07 ?>s">
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
    <?php endforeach; endif; ?>

    <div class="section-label" style="margin-top:26px"><?= t('Tính năng nổi bật', 'Key features') ?></div>
    <div class="feature-card">
        <div class="feature-icon"><?= svg_icon('reset', 18) ?></div>
        <div class="feature-body">
            <div class="feature-body-head"><b><?= t('Reset Key miễn phí', 'Free key reset') ?></b><span class="feature-tag"><?= t('CÓ SẴN', 'AVAILABLE') ?></span></div>
            <p><?= t('Key 40 giờ được làm mới thời hạn và gỡ thiết bị cũ, dùng được trên máy khác.', 'The 40-hour key can refresh its duration and clear old devices for use on another one.') ?></p>
        </div>
    </div>
    <div class="feature-card">
        <div class="feature-icon"><?= svg_icon('shield', 18) ?></div>
        <div class="feature-body">
            <div class="feature-body-head"><b><?= t('Chống bypass tự động', 'Anti-bypass protection') ?></b><span class="feature-tag"><?= t('CÓ SẴN', 'AVAILABLE') ?></span></div>
            <p><?= t('Hệ thống tự phát hiện tool vượt link tự động, đảm bảo công bằng cho mọi người.', 'The system detects automated link-bypass tools to keep things fair for everyone.') ?></p>
        </div>
    </div>
    <div class="feature-card">
        <div class="feature-icon"><?= svg_icon('key', 18) ?></div>
        <div class="feature-body">
            <div class="feature-body-head"><b><?= t('Nhiều game hỗ trợ', 'Multiple games supported') ?></b><span class="feature-tag"><?= t('CÓ SẴN', 'AVAILABLE') ?></span></div>
            <p><?= t('Mỗi game có thời hạn key và số bước vượt link riêng.', 'Each game has its own key duration and number of link steps.') ?></p>
        </div>
    </div>

    <div class="reset-box" id="reset-section" style="margin-top:26px">
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

    <div class="ip-box">
        <div class="ip-box-left">
            <span class="ip-ic"><?= svg_icon('globe', 20) ?></span>
            <div>
                <div class="ip-label"><?= t('IP CỦA BẠN', 'YOUR IP') ?></div>
                <div class="ip-value"><?= htmlspecialchars($clientIp ?: t('Không xác định', 'Unknown')) ?></div>
            </div>
        </div>
        <div class="ip-box-note"><?= t('Chỉ mình bạn thấy được thông tin này', 'Only you can see this information') ?></div>
    </div>

    <div class="footer-note">© Hồ Quốc — KeyAuth System</div>
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
<?= anti_devtools_script() ?>
</body>
</html>
