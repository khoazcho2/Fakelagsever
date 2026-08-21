<?php
// ============================================================
// index.php - Trang chủ công khai, liệt kê các game đang mở cấp
// key. User bấm "Lấy key miễn phí" -> sang getkey.php?game=slug
// ============================================================
require_once __DIR__ . '/config.php';

$games = array_filter(get_games(), function ($g) { return (bool)$g['enabled']; });
$region = detect_region();

// Social proof: tổng số key đã kích hoạt (tăng độ tin cậy, cho user thấy
// hệ thống thật sự có người dùng chứ không phải trang trống)
try {
    $totalActivated = (int)get_db()->query("SELECT COUNT(*) FROM keys WHERE activated_at IS NOT NULL")->fetchColumn();
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

@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes slideDown{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
@keyframes cardIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
@keyframes holoShift{to{background-position:200% 0}}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.35}}
</style>
</head>
<body>
<div class="wrap">
    <div class="eyebrow">HOQUOC <span>KEY VAULT</span></div>
    <h1>Lấy Key Game</h1>
    <p class="sub">Vượt link, nhận key ngay — không cần đăng ký</p>

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
            <a class="btn" href="getkey.php?game=<?= urlencode($g['slug']) ?>">⚡ Lấy key miễn phí</a>
        </div>
    </div>
    <?php endforeach; endif; ?>

    <div class="footer-note">© Hồ Quốc — KeyAuth System</div>
</div>
</body>
</html>
