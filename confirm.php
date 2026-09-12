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
if (session_status() === PHP_SESSION_NONE) session_start();
init_theme();

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
<html lang="vi" data-theme="<?= $GLOBALS['THEME'] === 'light' ? 'light' : 'dark' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Key kích hoạt</title>
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
    --violet: var(--ds-violet);
    --text: var(--ds-text);
    --text-dim: var(--ds-text-dim);
    --success: var(--ds-success);
    --warn: var(--ds-warn);
    --danger: var(--ds-danger);
}

body {
    background:
        radial-gradient(circle at 12% 0%, rgba(89,245,213,.15), transparent 28rem),
        radial-gradient(circle at 90% 16%, rgba(156,140,255,.14), transparent 26rem),
        linear-gradient(180deg, var(--ds-bg-top) 0%, var(--bg) 72%);
    padding: 38px 18px; min-height: 100vh; display: flex; align-items: center; justify-content: center;
    position: relative; overflow-x: hidden;
}
body::before {
    content: ""; position: fixed; inset: 0; pointer-events: none; z-index: -1; opacity: .32;
    background-image: linear-gradient(var(--ds-grid-line) 1px, transparent 1px),
                      linear-gradient(90deg, var(--ds-grid-line) 1px, transparent 1px);
    background-size: 44px 44px; mask-image: linear-gradient(to bottom, #000, transparent 82%);
}
body > div:not(.particle):not(.confetti) { width: 100%; max-width: 410px; }
.eyebrow {
    display: table; margin: 0 auto 16px; padding: 6px 12px; border: 1px solid var(--line);
    border-radius: var(--r-full); background: rgba(var(--surface-rgb), .76); box-shadow: 0 4px 16px rgba(0,0,0,.15);
    font: var(--fw-bold) 10.5px var(--font-mono); letter-spacing: .15em; color: var(--text-dim); text-transform: uppercase;
}
.eyebrow::before { content: ""; display: inline-block; width: 6px; height: 6px; margin-right: 8px; border-radius: 50%; background: var(--cyan); box-shadow: 0 0 10px var(--cyan); }
.eyebrow span { color: var(--cyan); }
.particle { position: fixed; border-radius: 50%; background: var(--cyan); opacity: .14; pointer-events: none; filter: blur(1px); }

.card {
    width: 100%; border: 1px solid var(--line); border-radius: var(--r-2xl);
    background: var(--grad-card); overflow: hidden; position: relative;
    box-shadow: var(--shadow-float); animation: ds-rise .42s var(--ease-out);
}
.holo { height: 4px; background: var(--grad-holo); background-size: 240% 100%; animation: ds-holo-shift 3s linear infinite; }
.card-top { padding: 26px 24px 20px; text-align: center; }
.game-icon { font-size: 38px; line-height: 1; animation: ds-pop-in .5s var(--ease-spring); }
.game-name { font: var(--fw-bold) 18px var(--font-body); letter-spacing: -.02em; margin-top: 8px; color: var(--text); }
.status-line { display: flex; align-items: center; justify-content: center; gap: 6px; font: var(--fw-semi) 11px var(--font-mono); letter-spacing: .1em; color: var(--success); margin-top: 6px; }
.dot { width: 6px; height: 6px; border-radius: 50%; background: var(--success); box-shadow: 0 0 8px var(--success); animation: ds-blink 1.6s ease-in-out infinite; }
.perm-badge { display: inline-flex; align-items: center; gap: 4px; font: var(--fw-bold) 10px var(--font-mono); color: var(--violet); background: rgba(156,140,255,.12); padding: 4px 10px; border-radius: var(--r-full); margin-top: 8px; border: 1px solid rgba(156,140,255,.2); }

.perf { position: relative; height: 0; border-top: 1.5px dashed var(--line); margin: 0 18px; }
.perf::before, .perf::after { content: ''; position: absolute; top: -8px; width: 16px; height: 16px; border-radius: 50%; background: var(--bg); }
.perf::before { left: -26px; }
.perf::after { right: -26px; }

.card-bottom { padding: 22px 24px 26px; }
.key-label { font: var(--fw-semi) 10px var(--font-mono); letter-spacing: .15em; color: var(--text-dim); text-transform: uppercase; margin-bottom: 8px; }
.keybox {
    position: relative; background: var(--surface2); border-radius: var(--r-md); padding: 16px 14px;
    border: 1px solid rgba(89,245,213,.3); overflow: hidden;
    box-shadow: inset 0 1px rgba(255,255,255,.06), 0 8px 24px -16px rgba(89,245,213,.5);
}
.keybox::after {
    content: ''; position: absolute; inset: 0; pointer-events: none;
    background: linear-gradient(115deg, transparent 38%, rgba(89,245,213,.15) 50%, transparent 62%);
    background-size: 250% 250%; animation: ds-holo-shift 3.5s ease-in-out infinite;
}
.keycode {
    font-family: var(--font-mono); font-weight: 700; font-size: clamp(18px, 5vw, 22px);
    letter-spacing: .08em; color: var(--cyan); text-shadow: 0 0 18px rgba(89,245,213,.35);
    word-break: break-all; text-align: center; position: relative; z-index: 1; user-select: all;
    font-variant-numeric: tabular-nums;
}
.copybtn {
    margin-top: 14px; width: 100%; min-height: 46px; border: none; border-radius: var(--r-md);
    background: var(--grad-btn); color: #071018; font-family: var(--font-body); font-weight: 700; font-size: 13.5px;
    cursor: pointer; transition: transform .12s, filter .15s; box-shadow: var(--shadow-btn);
}
.copybtn:hover { filter: brightness(1.08); }
.copybtn:active { transform: scale(.98); }
.copybtn.copied { background: linear-gradient(110deg, var(--success), #B7FFD7); color: #06170D; }

.meta-row { display: flex; justify-content: space-between; align-items: center; margin-top: 14px; font-size: 12px; color: var(--text-dim); }
.meta-row b { color: var(--text); font-weight: 600; }
.timerbar { height: 5px; background: var(--ds-surface2); border-radius: var(--r-full); margin-top: 10px; overflow: hidden; }
.timerfill { height: 100%; background: linear-gradient(90deg, var(--warn), var(--danger)); width: 100%; transition: width 1s linear; }
.devices-note { font: 10.5px var(--font-mono); color: var(--text-dim); text-align: center; margin-top: 14px; }
.qrbtn {
    margin-top: 12px; width: 100%; min-height: 42px; border: 1px solid var(--line-strong); border-radius: var(--r-md);
    background: rgba(var(--surface-rgb), .6); color: var(--text-dim); font: var(--fw-semi) 12px var(--font-body);
    cursor: pointer; transition: all .15s;
}
.qrbtn:hover, .qrbtn:active { border-color: var(--cyan); color: var(--cyan); background: rgba(89,245,213,.06); }
.qrbox { display: none; margin-top: 12px; text-align: center; background: #fff; border-radius: var(--r-lg); padding: 14px; animation: ds-pop-in .3s ease; box-shadow: 0 14px 30px -18px #000; }
.qrbox.open { display: block; }
.qrbox img { width: 130px; height: 130px; display: block; margin: 0 auto; }
.qrbox p { color: #0B0E14; font: 11px var(--font-mono); margin: 8px 0 0; }

.toast {
    position: fixed; top: 18px; right: 18px; z-index: 200; display: flex; align-items: center; gap: 9px;
    padding: 12px 16px; border-radius: var(--r-md); border: 1px solid rgba(97,230,164,.3);
    background: linear-gradient(135deg, rgba(22,31,44,.97), rgba(12,17,26,.99));
    box-shadow: 0 16px 34px -14px rgba(0,0,0,.6); color: var(--text); font-size: 13px; font-weight: 600;
    transform: translateX(calc(100% + 30px)); opacity: 0; transition: transform .32s var(--ease-out), opacity .32s ease;
    max-width: calc(100vw - 36px);
}
.toast.show { transform: translateX(0); opacity: 1; }
.toast-ic { width: 22px; height: 22px; border-radius: 50%; background: rgba(97,230,164,.15); color: var(--success); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
@media (max-width: 480px) { .toast { left: 18px; right: 18px; top: 14px; } }

.expired-icon { font-size: 42px; text-align: center; }
.expired-title { font: var(--fw-bold) 18px var(--font-body); text-align: center; margin: 8px 0 6px; color: var(--text); }
.expired-msg { font-size: 13px; color: var(--text-dim); text-align: center; line-height: 1.6; }
.footer-note { text-align: center; font: 11px var(--font-mono); color: var(--text-dim); margin-top: 20px; }
.confetti { position: fixed; top: -10px; width: 7px; height: 11px; z-index: 3; pointer-events: none; animation: confettiFall 2.1s cubic-bezier(.2,.6,.4,1) forwards; }
@keyframes confettiFall {
    0% { transform: translateY(0) rotate(0); opacity: 1; }
    100% { transform: translateY(100vh) rotate(540deg); opacity: 0; }
}

html[data-theme="light"] body {
    background:
        radial-gradient(circle at 12% 0%, rgba(14,168,142,.10), transparent 27rem),
        radial-gradient(circle at 90% 16%, rgba(109,90,224,.08), transparent 26rem),
        linear-gradient(180deg, #FFFFFF 0%, var(--bg) 72%);
}
html[data-theme="light"] .copybtn { color: #FFFFFF; }
html[data-theme="light"] .perf::before, html[data-theme="light"] .perf::after { background: var(--bg); }
html[data-theme="light"] .keybox { background: #F8FAFC; }
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

<div class="toast" id="toast">
    <span class="toast-ic"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 12 2 2 4-4"/></svg></span>
    <span id="toastText">Đã sao chép vào Clipboard</span>
</div>

<?php if (!$viewExpired): ?>
<script>
function showToast(msg){
    const toast = document.getElementById('toast');
    document.getElementById('toastText').textContent = msg;
    toast.classList.add('show');
    clearTimeout(window._toastTimer);
    window._toastTimer = setTimeout(() => toast.classList.remove('show'), 2200);
}
function copyKey(){
    const raw = document.getElementById('keycode').dataset.raw;
    navigator.clipboard.writeText(raw);
    const btn = document.getElementById('copyBtn');
    btn.textContent = '✓ Đã sao chép!';
    btn.classList.add('copied');
    setTimeout(() => { btn.textContent = 'Sao chép Key'; btn.classList.remove('copied'); }, 1800);
    showToast('Đã sao chép vào Clipboard');
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
