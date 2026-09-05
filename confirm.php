<?php
// ============================================================
// confirm.php - Trang "Keycard": user quay lại đây SAU KHI đã vượt
// đủ số bước link (từ hop.php). Kích hoạt key (pending -> active)
// và hiển thị key dưới dạng thẻ kích hoạt.
//
// - Trang xem/copy key chỉ hiển thị trong 10 PHÚT kể từ lúc kích
//   hoạt (bảo mật hiển thị, KHÔNG phải hạn dùng key trong app)
// - Set cookie 'gk_claimed_<slug>' (24h) chặn lấy key lần 2/ngày,
//   xoá cookie 'gk_pending_<slug>' vì đã xong việc
// ============================================================
require_once __DIR__ . '/config.php';
set_security_headers();

define('VIEW_KEY_WINDOW', 10 * 60); // 10 phút

$token = $_GET['token'] ?? '';
$db = get_db();

$stmt = $db->prepare("SELECT * FROM keys WHERE token = ?");
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    render_notice_screen('Token không hợp lệ', 'Có thể link đã hết hạn, đã dùng rồi, hoặc server vừa khởi động lại. Vui lòng quay lại trang chủ để lấy key mới.');
    exit;
}

if ($row['status'] === 'pending' && (int)$row['current_hop'] < (int)$row['total_hops']) {
    header('Location: ' . BASE_URL . '/hop.php?token=' . urlencode($token));
    exit;
}

$game = $row['game_id'] ? $db->query("SELECT slug, name, icon FROM games WHERE id=" . (int)$row['game_id'])->fetch(PDO::FETCH_ASSOC) : null;

// Chỉ chạy hiệu ứng ăn mừng lúc VỪA kích hoạt (không phải mỗi lần F5 lại xem)
$justActivated = false;

if ($row['status'] === 'pending') {
    $upd = $db->prepare("UPDATE keys SET status='active', activated_at=? WHERE id=?");
    db_execute($upd, [time(), $row['id']]);
    $row['status'] = 'active';
    $row['activated_at'] = time();
    $justActivated = true;
    // Cộng dồn bộ đếm vĩnh viễn "Đã phát X key thành công" (site_counters,
    // xem index.php) đúng 1 lần tại thời điểm key được kích hoạt thật sự -
    // nằm trong nhánh $row['status']==='pending' nên không bao giờ chạy lại
    // khi user F5 lại trang confirm.php sau đó (status lúc này đã 'active').
    increment_site_counter('keys_issued');

    if ($game) {
        // Thời gian chặn lấy key lần kế = đúng thời hạn của key vừa lấy
        // (24h -> chờ 24h, 36h -> chờ 36h), KHÔNG cố định 24h như trước,
        // để khớp với lựa chọn Key 24h/36h ở màn nhiệm vụ.
        $cooldownSeconds = max(1, (int)$row['duration_seconds']);
        setcookie('gk_claimed_' . $game['slug'], '1', time() + $cooldownSeconds, '/');
        setcookie('gk_pending_' . $game['slug'], '', time() - 3600, '/');
    }
}

$viewExpired = $row['activated_at'] && (time() > (int)$row['activated_at'] + VIEW_KEY_WINDOW);
$secondsLeft = $row['activated_at'] ? max(0, ((int)$row['activated_at'] + VIEW_KEY_WINDOW) - time()) : 0;
$durationLabel = format_duration_label((int)$row['duration_seconds']);
$isPermanent = (int)$row['duration_seconds'] >= PERMANENT_SECONDS;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Key kích hoạt</title>
<?= shared_favicon() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">
<style>
:root{
    --bg:#0B0E14; --surface:#12151F; --surface2:#181C28;
    --cyan:#00E5C7; --violet:#8B7CFF; --text:#E8ECF3; --text-dim:#8891A3;
    --success:#34D399; --warn:#FBBF24; --danger:#FF6B6B;
}
*{box-sizing:border-box}
body{
    font-family:'Inter',-apple-system,Arial,sans-serif;
    background:radial-gradient(ellipse at top,#151a26 0%,var(--bg) 60%);
    color:var(--text);margin:0;padding:48px 16px;min-height:100vh;
    display:flex;align-items:center;justify-content:center;position:relative;overflow-x:hidden;
}

/* Particle nền rất nhẹ, tĩnh - gợi cảm giác "vault số" mà không gây rối mắt */
.particle{position:fixed;border-radius:50%;background:var(--cyan);opacity:.15;pointer-events:none;filter:blur(1px)}

.eyebrow{font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.14em;color:var(--text-dim);text-transform:uppercase;text-align:center;margin-bottom:10px;position:relative;z-index:2}
.eyebrow span{color:var(--cyan)}

/* ---- Keycard ---- */
.card{
    width:340px;max-width:100%;background:var(--surface);border-radius:20px;
    position:relative;overflow:hidden;z-index:2;
    box-shadow:0 20px 60px -20px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.04);
    animation:cardRise .5s cubic-bezier(.16,1,.3,1);
}
.card-top{padding:26px 24px 20px;text-align:center;position:relative}
.holo{position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--cyan),var(--violet),var(--cyan));background-size:200% 100%;animation:holoShift 3s linear infinite}
.game-icon{font-size:32px;line-height:1;animation:popIn .5s cubic-bezier(.34,1.56,.64,1) .15s both}
.game-name{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:17px;margin-top:6px}
.status-line{display:flex;align-items:center;justify-content:center;gap:6px;font-size:12px;color:var(--success);margin-top:6px;font-weight:600}
.dot{width:6px;height:6px;border-radius:50%;background:var(--success);box-shadow:0 0 8px var(--success);animation:blink 1.6s ease-in-out infinite}
.perm-badge{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;color:var(--violet);background:rgba(139,124,255,.12);padding:2px 9px;border-radius:10px;margin-top:6px}

/* ---- Perforation (đường đục lỗ kiểu vé xé) ---- */
.perf{position:relative;height:0;border-top:1.5px dashed #2a2f3d;margin:0 14px}
.perf::before,.perf::after{content:'';position:absolute;top:-9px;width:18px;height:18px;border-radius:50%;background:radial-gradient(circle at 30% 30%,#151a26,var(--bg));}
.perf::before{left:-23px}
.perf::after{right:-23px}

.card-bottom{padding:22px 24px 26px}
.key-label{font-family:'JetBrains Mono',monospace;font-size:10.5px;letter-spacing:.12em;color:var(--text-dim);text-transform:uppercase;margin-bottom:8px}
.keybox{
    position:relative;background:var(--surface2);border-radius:12px;padding:16px 14px;
    border:1px solid rgba(0,229,199,.25);overflow:hidden;
}
.keybox::after{
    content:'';position:absolute;inset:0;
    background:linear-gradient(115deg,transparent 40%,rgba(0,229,199,.12) 50%,transparent 60%);
    background-size:250% 250%;animation:shimmer 3.2s ease-in-out infinite;
}
.keycode{
    font-family:'JetBrains Mono',monospace;font-weight:700;font-size:19px;
    letter-spacing:.06em;color:var(--cyan);text-shadow:0 0 18px rgba(0,229,199,.35);
    word-break:break-all;position:relative;z-index:1;user-select:all;
}
.copybtn{
    margin-top:14px;width:100%;padding:13px;border:none;border-radius:10px;
    background:linear-gradient(135deg,var(--cyan),var(--violet));color:#0B0E14;
    font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:14px;cursor:pointer;
    transition:transform .12s,filter .15s;
}
.copybtn:active{transform:scale(.97)}
.copybtn.copied{background:linear-gradient(135deg,var(--success),#2bb883);color:#06170D}

.meta-row{display:flex;justify-content:space-between;align-items:center;margin-top:16px;font-size:12px;color:var(--text-dim)}
.meta-row b{color:var(--text);font-weight:600}
.timerbar{height:4px;background:#1c202c;border-radius:4px;margin-top:14px;overflow:hidden}
.timerfill{height:100%;background:linear-gradient(90deg,var(--warn),var(--danger));width:100%;transition:width 1s linear}
.devices-note{font-size:11px;color:var(--text-dim);text-align:center;margin-top:14px;font-family:'JetBrains Mono',monospace}
.qrbtn{margin-top:10px;width:100%;padding:11px;border:1px solid #262b38;border-radius:10px;background:transparent;color:var(--text-dim);font-family:'Inter',sans-serif;font-weight:600;font-size:12.5px;cursor:pointer;transition:border-color .15s,color .15s}
.qrbtn:hover,.qrbtn:active{border-color:var(--cyan);color:var(--cyan)}
.qrbox{display:none;margin-top:12px;text-align:center;background:#fff;border-radius:12px;padding:14px;animation:popIn .3s ease}
.qrbox.open{display:block}
.qrbox img{width:130px;height:130px;display:block;margin:0 auto}
.qrbox p{color:#0B0E14;font-size:10.5px;margin:8px 0 0;font-family:'JetBrains Mono',monospace}

/* ---- Expired state ---- */
.expired-icon{font-size:38px;text-align:center}
.expired-title{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:17px;text-align:center;margin:8px 0 6px}
.expired-msg{font-size:13px;color:var(--text-dim);text-align:center;line-height:1.6}

.footer-note{text-align:center;font-size:11px;color:var(--text-dim);margin-top:16px;font-family:'JetBrains Mono',monospace;position:relative;z-index:2}

/* ---- Confetti ăn mừng - chỉ chạy 1 lần lúc vừa nhận key, tự dọn sau 2.2s ---- */
.confetti{position:fixed;top:-10px;width:7px;height:11px;z-index:3;pointer-events:none;animation:confettiFall 2.1s cubic-bezier(.2,.6,.4,1) forwards}

@keyframes cardRise{from{opacity:0;transform:translateY(18px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes popIn{from{opacity:0;transform:scale(.6)}to{opacity:1;transform:scale(1)}}
@keyframes holoShift{to{background-position:200% 0}}
@keyframes shimmer{0%,100%{background-position:0% 0%}50%{background-position:100% 100%}}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
@keyframes confettiFall{
    0%{transform:translateY(0) rotate(0);opacity:1}
    100%{transform:translateY(100vh) rotate(540deg);opacity:0}
}

/* ============================================================
   HoQuoc UI refresh — activation keycard
   Đồng bộ với index.php và admin.php, không thay đổi logic.
   ============================================================ */
:root{
    --bg:#070A10;--surface:#101620;--surface2:#151D2A;--surface3:#1B2635;
    --line:rgba(177,199,224,.12);--line-strong:rgba(177,199,224,.2);
    --cyan:#59F5D5;--violet:#9C8CFF;--text:#F4F7FB;--text-dim:#93A1B5;
    --success:#61E6A4;--warn:#F5C969;--danger:#FF7885;
}
html{background:var(--bg)}
body{
    background:
        radial-gradient(circle at 12% 0%,rgba(89,245,213,.16),transparent 27rem),
        radial-gradient(circle at 90% 16%,rgba(156,140,255,.14),transparent 26rem),
        linear-gradient(180deg,#0B1019 0%,var(--bg) 72%);
    padding:38px 18px;isolation:isolate
}
body::before{
    content:"";position:fixed;inset:0;pointer-events:none;z-index:-1;opacity:.32;
    background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);
    background-size:44px 44px;mask-image:linear-gradient(to bottom,#000,transparent 82%)
}
body>div:not(.particle):not(.confetti){width:100%;max-width:410px}
.eyebrow{
    display:table;margin:0 auto 18px;padding:7px 11px;border:1px solid var(--line);
    border-radius:999px;background:rgba(16,22,32,.76);box-shadow:0 8px 24px rgba(0,0,0,.2);
    font-size:10px;letter-spacing:.18em
}
.eyebrow::before{content:"";display:inline-block;width:6px;height:6px;margin-right:8px;border-radius:50%;background:var(--cyan);box-shadow:0 0 12px var(--cyan)}
.eyebrow span{color:var(--cyan)}
.particle{background:var(--cyan);opacity:.13;box-shadow:0 0 10px rgba(89,245,213,.3)}
.card{
    width:100%;border:1px solid var(--line);border-radius:24px;
    background:linear-gradient(145deg,rgba(22,31,44,.97),rgba(12,17,26,.99));
    box-shadow:0 30px 80px -30px rgba(0,0,0,.95),inset 0 1px rgba(255,255,255,.07)
}
.card::after{
    content:"";position:absolute;inset:0;pointer-events:none;border-radius:inherit;
    background:linear-gradient(120deg,rgba(255,255,255,.055),transparent 28%,transparent 68%,rgba(89,245,213,.035))
}
.holo{height:4px;background:linear-gradient(90deg,var(--cyan),var(--violet),#F0A6FF,var(--cyan));background-size:240% 100%}
.card-top{padding:30px 26px 24px}
.game-icon{font-size:38px;filter:drop-shadow(0 8px 12px rgba(0,0,0,.3))}
.game-name{font-size:19px;letter-spacing:-.02em;margin-top:10px}
.status-line{font-size:10px;letter-spacing:.12em;margin-top:9px}
.dot{width:7px;height:7px}
.perm-badge{margin-top:10px;padding:5px 10px;border:1px solid rgba(156,140,255,.2);font-size:10px}
.perf{border-color:var(--line);margin:0 18px}
.perf::before,.perf::after{background:var(--bg)}
.card-bottom{padding:25px 26px 29px}
.key-label{margin-bottom:10px;color:#A9B6C8;font-size:10px;letter-spacing:.18em}
.keybox{
    padding:18px 16px;border:1px solid rgba(89,245,213,.3);border-radius:14px;
    background:linear-gradient(135deg,rgba(89,245,213,.08),rgba(21,29,42,.95) 58%);
    box-shadow:inset 0 1px rgba(255,255,255,.06),0 10px 28px -20px rgba(89,245,213,.55)
}
.keybox::after{background:linear-gradient(115deg,transparent 38%,rgba(89,245,213,.16) 50%,transparent 62%)}
.keycode{font-size:clamp(19px,5.5vw,24px);letter-spacing:.08em;text-align:center}
.copybtn{
    min-height:48px;margin-top:14px;border-radius:13px;background:linear-gradient(110deg,var(--cyan),#B5FFF0 45%,var(--violet));
    box-shadow:0 12px 25px -14px rgba(89,245,213,.8);font-size:14px
}
.copybtn:hover{filter:brightness(1.08)}
.copybtn.copied{background:linear-gradient(110deg,var(--success),#B7FFD7)}
.meta-row{margin-top:18px;font-size:11.5px}
.meta-row b{max-width:62%;text-align:right;line-height:1.4}
.timerbar{height:5px;margin-top:12px;background:#202A38}
.timerfill{background:linear-gradient(90deg,var(--warn),var(--danger))}
.devices-note{margin-top:15px;color:#8290A4;font-size:10px;letter-spacing:.03em}
.qrbtn{
    min-height:44px;margin-top:14px;border:1px solid var(--line-strong);border-radius:12px;
    background:rgba(21,29,42,.6);color:#B7C2D1;font-size:12px
}
.qrbtn:hover,.qrbtn:active{border-color:rgba(89,245,213,.4);background:rgba(89,245,213,.05)}
.qrbox{border:1px solid var(--line);box-shadow:0 14px 30px -18px #000}
.expired-icon{font-size:44px;margin-bottom:8px}
.expired-title{font-size:19px;letter-spacing:-.02em}
.expired-msg{font-size:13px;line-height:1.7}
.footer-note{margin-top:21px;color:#657287;font-size:10px;letter-spacing:.08em}
@media (max-width:430px){
    body{padding:27px 14px}
    .card-top{padding:27px 20px 22px}.card-bottom{padding:23px 20px 25px}
    .keycode{font-size:18px}
}
@media (prefers-reduced-motion:reduce){
    *,*::before,*::after{animation-duration:.001ms!important;animation-iteration-count:1!important;transition-duration:.001ms!important}
}
</style>
</head>
<body>

<?php
// Particle nền tĩnh, vị trí cố định theo id key (không random mỗi lần
// load lại trang, tránh giật/nhấp nháy khi F5)
$seed = crc32($row['keycode']);
for ($i = 0; $i < 8; $i++):
    $x = ($seed * ($i + 3)) % 100;
    $y = ($seed * ($i + 7)) % 100;
    $size = 2 + ($i % 3) * 2;
?>
<div class="particle" style="left:<?= $x ?>%;top:<?= $y ?>%;width:<?= $size ?>px;height:<?= $size ?>px"></div>
<?php endfor; ?>

<div>
<div class="eyebrow">HOQUOC <span>KEY VAULT</span></div>
<div class="card">
    <div class="holo"></div>

    <?php if ($viewExpired): ?>
    <div class="card-top" style="padding-top:34px;padding-bottom:34px">
        <div class="expired-icon">⏳</div>
        <div class="expired-title">Đã hết thời gian xem key</div>
        <p class="expired-msg">Thẻ key này chỉ hiển thị trong 10 phút sau khi kích hoạt để bảo mật.<br><br>Key <b style="color:var(--text)">vẫn hoạt động bình thường</b> trong app — nếu bạn đã sao chép lúc nãy cứ dùng bình thường. Nếu chưa kịp lưu, liên hệ admin để được hỗ trợ lấy lại.</p>
    </div>

    <?php else: ?>
    <div class="card-top">
        <div class="game-icon"><?= htmlspecialchars($game['icon'] ?? '🎮') ?></div>
        <div class="game-name"><?= htmlspecialchars($game['name'] ?? 'Key kích hoạt') ?></div>
        <div class="status-line"><span class="dot"></span> ĐÃ KÍCH HOẠT</div>
        <?php if ($isPermanent): ?><div class="perm-badge">♾️ KEY VĨNH VIỄN</div><?php endif; ?>
    </div>

    <div class="perf"></div>

    <div class="card-bottom">
        <div class="key-label">Mã kích hoạt</div>
        <div class="keybox">
            <div class="keycode" id="keycode" data-raw="<?= htmlspecialchars($row['keycode']) ?>"><?= htmlspecialchars($row['keycode']) ?></div>
        </div>
        <button class="copybtn" id="copyBtn" onclick="copyKey()">Sao chép Key</button>

        <div class="meta-row">
            <span>Thời hạn dùng</span>
            <b><?= htmlspecialchars($durationLabel) ?><?= $isPermanent ? '' : ' (tính từ lần đầu dùng trong app)' ?></b>
        </div>
        <div class="meta-row" id="timerRow">
            <span>Xem key còn lại</span>
            <b id="timerText"><?= gmdate('i:s', $secondsLeft) ?></b>
        </div>
        <div class="timerbar"><div class="timerfill" id="timerFill"></div></div>
        <div class="devices-note">Tối đa <?= (int)$row['max_devices'] ?> thiết bị cho key này</div>

        <button class="qrbtn" id="qrToggleBtn" onclick="toggleQr()">📱 Mở trên điện thoại khác</button>
        <div class="qrbox" id="qrBox">
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=<?= urlencode(BASE_URL . '/confirm.php?token=' . $token) ?>" alt="QR code" loading="lazy">
            <p>Quét để xem key này trên máy khác<br>(còn hiệu lực trong thời gian xem 10 phút)</p>
        </div>
    </div>
    <?php endif; ?>
</div>
<div class="footer-note">© Hồ Quốc — KeyAuth System</div>
</div>

<?php if (!$viewExpired): ?>
<script>
function copyKey(){
    const raw = document.getElementById('keycode').dataset.raw;
    navigator.clipboard.writeText(raw);
    const btn = document.getElementById('copyBtn');
    btn.textContent = '✓ Đã sao chép!';
    btn.classList.add('copied');
    setTimeout(() => { btn.textContent = 'Sao chép Key'; btn.classList.remove('copied'); }, 1800);
}
function toggleQr(){
    const box = document.getElementById('qrBox');
    const btn = document.getElementById('qrToggleBtn');
    const open = box.classList.toggle('open');
    btn.textContent = open ? '✕ Ẩn mã QR' : '📱 Mở trên điện thoại khác';
}
let left = <?= $secondsLeft ?>;
const total = <?= VIEW_KEY_WINDOW ?>;
const timerText = document.getElementById('timerText');
const timerFill = document.getElementById('timerFill');
const timer = setInterval(() => {
    left--;
    if (left <= 0) { clearInterval(timer); location.reload(); return; }
    const m = Math.floor(left / 60), s = left % 60;
    timerText.textContent = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
    timerFill.style.width = (left / total * 100) + '%';
}, 1000);

<?php if ($justActivated): ?>
// Confetti ăn mừng - CHỈ chạy đúng 1 lần lúc vừa kích hoạt xong, không
// chạy lại mỗi lần F5 xem lại trang (đã kích hoạt rồi thì $justActivated
// = false ở lần load sau)
(function(){
    const colors = ['#00E5C7', '#8B7CFF', '#34D399', '#FBBF24'];
    for (let i = 0; i < 40; i++) {
        const el = document.createElement('div');
        el.className = 'confetti';
        el.style.left = Math.random() * 100 + 'vw';
        el.style.background = colors[i % colors.length];
        el.style.animationDelay = (Math.random() * 0.4) + 's';
        el.style.borderRadius = Math.random() > 0.5 ? '50%' : '2px';
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 2600);
    }
})();
<?php endif; ?>
</script>
<?php endif; ?>
<?= anti_devtools_script() ?>
</body>
</html>
