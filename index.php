<?php
// ============================================================
// index.php - Trang chủ công khai, liệt kê các game đang mở cấp
// key. User bấm "Lấy key miễn phí" -> sang getkey.php?game=slug
// ============================================================
require_once __DIR__ . '/config.php';

// ?r=<id> -> trang chủ RIÊNG của 1 reseller, chỉ hiện game của họ.
$resellerId = null;
$resellerInfo = null;
if (isset($_GET['r']) && ctype_digit((string)$_GET['r'])) {
    $rr = get_reseller_by_id((int)$_GET['r']);
    if ($rr && $rr['enabled']) { $resellerId = (int)$_GET['r']; $resellerInfo = $rr; }
}
$rParam = $resellerId !== null ? ('&r=' . $resellerId) : '';

// Xử lý form "Reset Key" (chỉ Key 40h, mỗi key reset được 1 lần) - xem
// hàm attempt_reset_key() trong config.php. Dùng chung cho cả trang
// admin và trang reseller vì keycode đã là duy nhất toàn hệ thống.
$resetResult = null;
if (isset($_POST['reset_key_submit'])) {
    $resetResult = attempt_reset_key($_POST['reset_keycode'] ?? '');
}

$games = array_filter(get_games($resellerId), function ($g) { return (bool)$g['enabled']; });
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
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lấy Key Game — Hồ Quốc</title>
<?= shared_favicon() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">
<style>
:root{
    --bg:#0B0E14; --surface:#12151F; --surface2:#181C28;
    --cyan:#00E5C7; --violet:#8B7CFF; --text:#E8ECF3; --text-dim:#8891A3;
    --success:#34D399;
}
*{box-sizing:border-box}
body{
    font-family:'Inter',-apple-system,Arial,sans-serif;
    background:radial-gradient(ellipse at top,#151a26 0%,var(--bg) 60%);
    color:var(--text);margin:0;min-height:100vh;
}
.wrap{max-width:440px;margin:0 auto;padding:44px 18px 60px}
.eyebrow{font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.14em;color:var(--text-dim);text-transform:uppercase;text-align:center;animation:fadeIn .4s ease}
.eyebrow span{color:var(--cyan)}
h1{
    font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:30px;
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
.gname{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:16.5px}
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
    text-decoration:none;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:14.5px;
    padding:13px;border-radius:12px;transition:transform .12s,filter .15s;
}
a.btn:active{transform:scale(.97)}
a.btn:hover{filter:brightness(1.08)}

/* Cách hoạt động - 3 bước, tăng độ rõ ràng/tin cậy cho user lần đầu ghé */
.howto{display:flex;gap:10px;margin:28px 0 8px}
.howto-step{flex:1;text-align:center;background:var(--surface);border-radius:12px;padding:14px 8px}
.howto-num{
    width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,var(--cyan),var(--violet));
    color:#0B0E14;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:11.5px;
    display:flex;align-items:center;justify-content:center;margin:0 auto 8px;
}
.howto-text{font-size:11px;color:var(--text-dim);line-height:1.4}

.empty{text-align:center;color:var(--text-dim);font-size:14px;margin-top:60px;animation:fadeIn .5s ease}
.footer-note{text-align:center;font-size:11px;color:var(--text-dim);margin-top:30px;font-family:'JetBrains Mono',monospace;animation:fadeIn .6s ease}

/* Reset Key - chỉ Key 40h, mỗi key reset 1 lần */
.reset-box{max-width:620px;margin:30px auto 0;padding:20px 22px 22px;border:1px solid var(--line);border-radius:20px;background:rgba(16,22,32,.82);box-shadow:0 16px 40px -28px #000,inset 0 1px rgba(255,255,255,.045)}
.reset-title{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:15.5px;display:flex;align-items:center;gap:7px}
.reset-sub{font-size:11.5px;color:var(--text-dim);margin:5px 0 14px;line-height:1.55}
.reset-form{display:flex;gap:8px}
.reset-form input{flex:1;min-width:0;height:46px;padding:0 14px;border-radius:11px;border:1px solid var(--line);background:rgba(9,13,20,.7);color:var(--text);font-family:'JetBrains Mono',monospace;font-size:13px;letter-spacing:.03em}
.reset-form input:focus{outline:none;border-color:rgba(89,245,213,.45)}
.reset-form button{flex-shrink:0;height:46px;padding:0 18px;border:0;border-radius:11px;background:linear-gradient(110deg,var(--cyan),#B5FFF0 45%,var(--violet));color:#071018;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:13px;cursor:pointer}
.reset-form button:active{transform:scale(.97)}
.reset-msg{margin-top:12px;padding:10px 12px;border-radius:11px;font-size:12px;line-height:1.5}
.reset-msg.ok{border:1px solid rgba(97,230,164,.3);background:rgba(97,230,164,.08);color:#8CF0BC}
.reset-msg.err{border:1px solid rgba(255,156,166,.3);background:rgba(255,120,133,.08);color:#FF9CA6}
@media (max-width:560px){.reset-form{flex-direction:column}.reset-form button{height:46px}}

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
    --surface2:#151D2A;
    --surface3:#1B2635;
    --line:rgba(177,199,224,.12);
    --line-strong:rgba(177,199,224,.2);
    --cyan:#59F5D5;
    --violet:#9C8CFF;
    --text:#F4F7FB;
    --text-dim:#93A1B5;
    --success:#61E6A4;
}
html{background:var(--bg);scroll-behavior:smooth}
body{
    position:relative;
    overflow-x:hidden;
    background:
        radial-gradient(circle at 15% -8%,rgba(89,245,213,.16),transparent 28rem),
        radial-gradient(circle at 90% 18%,rgba(156,140,255,.12),transparent 25rem),
        linear-gradient(180deg,#0B1019 0%,var(--bg) 60%);
}
body::before{
    content:"";position:fixed;inset:0;pointer-events:none;opacity:.34;
    background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);
    background-size:44px 44px;mask-image:linear-gradient(to bottom,#000,transparent 78%);
}
.wrap{position:relative;max-width:760px;padding:42px 24px 72px}
.eyebrow{
    display:inline-flex;align-items:center;gap:8px;margin:0 auto 17px;padding:7px 11px;
    border:1px solid var(--line);border-radius:999px;background:rgba(16,22,32,.72);
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
.sample-tag{font-size:10px;letter-spacing:.18em;color:#A9B6C8}
.sample-key{font-size:clamp(22px,5vw,31px);letter-spacing:.12em;margin-top:13px}
.sample-perf{border-color:var(--line)}
.sample-bottom{padding:16px 28px 20px}
.sample-bottom span{display:flex;flex-direction:column;gap:4px;font-size:10px;letter-spacing:.03em}
.sample-bottom b{font-size:11px}
.howto{gap:12px;margin:0 auto 36px;max-width:620px}
.howto-step{
    position:relative;min-height:108px;padding:18px 11px;border:1px solid var(--line);
    border-radius:16px;background:rgba(16,22,32,.68);box-shadow:inset 0 1px rgba(255,255,255,.035)
}
.howto-num{width:28px;height:28px;margin:0 auto 12px;box-shadow:0 0 20px rgba(89,245,213,.16)}
.howto-text{font-size:11px;color:#B2BDCB}
.section-label{
    display:flex;align-items:center;gap:11px;margin:0 auto 15px;max-width:620px;
    color:#A9B6C8;font-size:10px;letter-spacing:.18em
}
.section-label::after{content:"";height:1px;flex:1;background:var(--line)}
.card{
    max-width:620px;margin:0 auto 14px;border:1px solid var(--line);border-radius:20px;
    background:rgba(16,22,32,.82);box-shadow:0 16px 40px -28px #000,inset 0 1px rgba(255,255,255,.045)
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
    .howto{gap:7px}.howto-step{min-height:101px;padding:15px 5px}.howto-text{font-size:10px}
    .card-body{padding:17px 16px}.card-cta{padding:13px 16px 17px}
}
@media (prefers-reduced-motion:reduce){
    *,*::before,*::after{animation-duration:.001ms!important;animation-iteration-count:1!important;scroll-behavior:auto!important;transition-duration:.001ms!important}
}
</style>
</head>
<body>
<div class="wrap">
    <?php if ($resellerInfo): $brandName = $resellerInfo['store_name'] ?: $resellerInfo['username']; ?>
    <div class="eyebrow"><?= htmlspecialchars(mb_strtoupper($brandName)) ?> <span>KEY VAULT</span></div>
    <h1>Lấy Key Game</h1>
    <p class="sub">Vượt link, nhận key ngay — không cần đăng ký · Đại lý chính thức: <b><?= htmlspecialchars($brandName) ?></b></p>
    <?php else: ?>
    <div class="eyebrow">HOQUOC <span>KEY VAULT</span></div>
    <h1>Lấy Key Game</h1>
    <p class="sub">Vượt link, nhận key ngay — không cần đăng ký</p>
    <?php endif; ?>

    <?php if ($totalActivated > 0): ?>
    <div class="proof"><span class="pulse-dot"></span> Đã phát <b><?= number_format($totalActivated) ?></b> key thành công</div>
    <?php endif; ?>

    <div class="sample-card">
        <div class="sample-holo"></div>
        <div class="sample-top">
            <div class="sample-tag">Đây là key bạn sẽ nhận</div>
            <div class="sample-key">HQD-•••••••-•••</div>
        </div>
        <div class="sample-perf"></div>
        <div class="sample-bottom">
            <span>Định dạng key <b>HQD-XXXXXXX-XXX</b></span>
            <span>Giao <b>tức thì</b></span>
        </div>
    </div>

    <div class="howto">
        <div class="howto-step"><div class="howto-num">1</div><div class="howto-text">Chọn game bên dưới</div></div>
        <div class="howto-step"><div class="howto-num">2</div><div class="howto-text">Vượt link rút gọn</div></div>
        <div class="howto-step"><div class="howto-num">3</div><div class="howto-text">Nhận key ngay</div></div>
    </div>

    <div class="reset-box">
        <div class="reset-title"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--cyan);flex-shrink:0"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg> Reset Key</div>
        <p class="reset-sub">Chỉ áp dụng cho <b>Key 40 giờ</b> (vượt 2 lần link) - làm mới lại thời hạn 40h và gỡ thiết bị cũ để đăng nhập máy khác. <b>Mỗi key chỉ được reset 1 lần duy nhất</b> (kể cả khi key đã hết hạn).</p>
        <form class="reset-form" method="post" action="index.php?<?= $resellerId !== null ? 'r=' . $resellerId . '&' : '' ?>reset=1#reset">
            <input type="text" name="reset_keycode" placeholder="Nhập keycode của bạn (VD: HQD-XXXXXXX-XXX)" autocomplete="off" required>
            <input type="hidden" name="reset_key_submit" value="1">
            <button type="submit">Reset Key</button>
        </form>
        <?php if ($resetResult): ?>
        <div id="reset" class="reset-msg <?= $resetResult['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($resetResult['message']) ?></div>
        <?php endif; ?>
    </div>

    <div class="section-label" style="margin-top:26px">Danh sách game</div>

    <?php if (empty($games)): ?>
        <p class="empty">Hiện chưa có game nào mở cấp key.</p>
    <?php else: foreach ($games as $i => $g):
        $hops = $region === 'intl' ? $g['intl_hops'] : $g['vn_hops'];
    ?>
    <div class="card" style="animation-delay:<?= $i * 0.07 ?>s">
        <div class="holo"></div>
        <div class="card-body">
            <div class="icon-badge"><?= htmlspecialchars($g['icon']) ?></div>
            <div class="card-info">
                <div class="gname"><?= htmlspecialchars($g['name']) ?></div>
                <div class="gmeta">
                    <span class="free-pill"><span class="dot"></span> KEY FREE</span>
                </div>
                <div class="hops-note">Vượt <?= (int)$hops ?> lần link rút gọn</div>
            </div>
        </div>
        <div class="perf"></div>
        <div class="card-cta">
            <a class="btn" href="getkey.php?game=<?= urlencode($g['slug']) . $rParam ?>">⚡ Lấy key miễn phí</a>
        </div>
    </div>
    <?php endforeach; endif; ?>

    <div class="footer-note">© Hồ Quốc — KeyAuth System</div>
</div>
</body>
</html>
