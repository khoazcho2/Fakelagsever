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

$justActivated = false;

if ($row['status'] === 'pending') {
    $upd = $db->prepare("UPDATE keys SET status='active', activated_at=? WHERE id=?");
    db_execute($upd, [time(), $row['id']]);
    $row['status'] = 'active';
    $row['activated_at'] = time();
    $justActivated = true;

    if ($game) {
        setcookie('gk_claimed_' . $game['slug'], '1', time() + 86400, '/');
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
<meta name="description" content="Key kích hoạt của bạn đã sẵn sàng - sao chép và sử dụng ngay.">
<title>Key kích hoạt</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">
<style>
:root{
    --bg:#0B0E14; --surface:#12151F; --surface2:#181C28;
    --cyan:#2FD9C4; --accent-dark:#0E9E8C; --text:#E8ECF3; --text-dim:#8891A3;
    --success:#34D399; --warn:#FBBF24; --danger:#FF6B6B;
}
*{box-sizing:border-box}
body{
    font-family:'Inter',-apple-system,Arial,sans-serif;
    background:radial-gradient(ellipse at top,#151a26 0%,var(--bg) 60%);
    color:var(--text);margin:0;padding:48px 16px;min-height:100vh;
    display:flex;align-items:center;justify-content:center;position:relative;overflow-x:hidden;
}
.particle{position:fixed;border-radius:50%;background:var(--cyan);opacity:.15;pointer-events:none;filter:blur(1px)}
.eyebrow{font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:.14em;color:var(--text-dim);text-transform:uppercase;text-align:center;margin-bottom:10px;position:relative;z-index:2}
.eyebrow span{color:var(--cyan)}
.card{
    width:340px;max-width:100%;background:var(--surface);border-radius:20px;
    position:relative;overflow:hidden;z-index:2;
    box-shadow:0 20px 60px -20px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.04);
    animation:cardRise .5s cubic-bezier(.16,1,.3,1);
}
.card-top{padding:26px 24px 20px;text-align:center;position:relative}
.holo{position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--accent-dark),var(--cyan),var(--accent-dark));background-size:200% 100%;animation:holoShift 3s linear infinite}
.game-icon{font-size:32px;line-height:1;animation:popIn .5s cubic-bezier(.34,1.56,.64,1) .15s both}
.game-name{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:17px;margin-top:6px}
.status-line{display:flex;align-items:center;justify-content:center;gap:6px;font-size:12px;color:var(--success);margin-top:6px;font-weight:600}
.dot{width:6px;height:6px;border-radius:50%;background:var(--success);box-shadow:0 0 8px var(--success);animation:blink 1.6s ease-in-out infinite}
.perm-badge{display:inline-flex;align-items:center;gap:4px;font-size:10.5px;font-weight:700;color:var(--warn);background:rgba(251,191,36,.12);padding:2px 9px;border-radius:10px;margin-top:6px}
.perf{position:relative;height:0;border-top:1.5px dashed #2a2f3d;margin:0 14px}
.perf::before,.perf::after{content:'';position:absolute;top:-9px;width:18px;height:18px;border-radius:50%;background:radial-gradient(circle at 30% 30%,#151a26,var(--bg));}
.perf::before{left:-23px}
.perf::after{right:-23px}
.card-bottom{padding:22px 24px 26px}
.key-label{font-family:'JetBrains Mono',monospace;font-size:10.5px;letter-spacing:.12em;color:var(--text-dim);text-transform:uppercase;margin-bottom:8px}
.keybox{
    position:relative;background:var(--surface2);border-radius:12px;padding:16px 14px;
    border:1px solid rgba(47,217,196,.25);overflow:hidden;
}
.keybox::after{
    content:'';position:absolute;inset:0;
    background:linear-gradient(115deg,transparent 40%,rgba(47,217,196,.12) 50%,transparent 60%);
    background-size:250% 250%;animation:shimmer 3.2s ease-in-out infinite;
}
.keycode{
    font-family:'JetBrains Mono',monospace;font-weight:700;font-size:19px;
    letter-spacing:.06em;color:var(--cyan);text-shadow:0 0 18px rgba(47,217,196,.35);
    word-break:break-all;position:relative;z-index:1;user-select:all;
}
.copybtn{
    margin-top:14px;width:100%;padding:13px;border:none;border-radius:10px;
    background:linear-gradient(135deg,var(--cyan),var(--accent-dark));color:#08110F;
    font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:14px;cursor:pointer;
    transition:transform .12s,filter .15s;box-shadow:0 4px 16px rgba(47,217,196,.2);
}
.copybtn:active{transform:scale(.97)}
.copybtn.copied{background:linear-gradient(135deg,var(--success),#2bb883);color:#06170D}
.meta-row{display:flex;justify-content:space-between;align-items:center;margin-top:16px;font-size:12px;color:var(--text-dim)}
.meta-row b{color:var(--text);font-weight:600}
.timerbar{height:4px;background:#1c202c;border-radius:4px;margin-top:14px;overflow:hidden}
.timerfill{height:100%;background:linear-gradient(90deg,var(--warn),var(--danger));width:100%;transition:width 1s linear}
.devices-note{font-size:11px;color:var(--text-dim);text-align:center;margin-top:14px;font-family:'JetBrains Mono',monospace}
.expired-icon{font-size:38px;text-align:center}
.expired-title{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:17px;text-align:center;margin:8px 0 6px}
.expired-msg{font-size:13px;color:var(--text-dim);text-align:center;line-height:1.6}
.footer-note{text-align:center;font-size:11px;color:var(--text-dim);margin-top:16px;font-family:'JetBrains Mono',monospace;position:relative;z-index:2}
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
</style>
</head>
<body>

<?php
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
(function(){
    const colors = ['#2FD9C4', '#0E9E8C', '#34D399', '#FBBF24'];
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
</body>
</html>
