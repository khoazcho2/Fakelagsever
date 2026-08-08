<?php
// ============================================================
// confirm.php - User quay lại đây SAU KHI đã vượt đủ số bước link
// (được hop.php chuyển tới). Kích hoạt key (pending -> active) và
// hiển thị key.
//
// - Trang xem/copy key này chỉ hiển thị được trong 10 PHÚT kể từ
//   lúc kích hoạt - sau đó ẩn key đi (không phải key hết hạn dùng
//   trong app, chỉ là trang web ngừng hiển thị lại để tránh lộ/spam)
// - Set cookie 'gk_claimed_<slug>' (24h) để chặn lấy key lần 2
//   trong ngày, và xoá cookie 'gk_pending_<slug>' vì đã xong việc
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

$game = $row['game_id'] ? $db->query("SELECT slug, name FROM games WHERE id=" . (int)$row['game_id'])->fetch(PDO::FETCH_ASSOC) : null;

if ($row['status'] === 'pending') {
    $upd = $db->prepare("UPDATE keys SET status='active', activated_at=? WHERE id=?");
    $upd->execute([time(), $row['id']]);
    $row['status'] = 'active';
    $row['activated_at'] = time();

    if ($game) {
        setcookie('gk_claimed_' . $game['slug'], '1', time() + 86400, '/');
        setcookie('gk_pending_' . $game['slug'], '', time() - 3600, '/');
    }
}

$viewExpired = $row['activated_at'] && (time() > (int)$row['activated_at'] + VIEW_KEY_WINDOW);
$secondsLeft = $row['activated_at'] ? max(0, ((int)$row['activated_at'] + VIEW_KEY_WINDOW) - time()) : 0;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Key của bạn</title>
<style>
*{box-sizing:border-box}
body{font-family:-apple-system,Arial,sans-serif;max-width:360px;margin:60px auto;background:#0f1115;color:#eee;text-align:center;padding:0 16px;animation:fadeIn .4s ease}
.box{background:#181b22;padding:22px;border-radius:12px;animation:popIn .4s ease}
.key{font-size:19px;font-weight:bold;color:#4f8cff;letter-spacing:1.5px;margin:14px 0;user-select:all;word-break:break-all;background:#0f1115;border-radius:8px;padding:12px;animation:glow 2s ease-in-out infinite}
button{padding:10px 16px;background:#4f8cff;border:none;border-radius:8px;color:#fff;cursor:pointer;font-size:14px;transition:transform .12s,filter .15s,background .2s}
button:active{transform:scale(.95)}
button.copied{background:#4caf50}
p{font-size:13px;color:#aaa}
.timer{font-size:13px;color:#e0b23f;margin-top:16px;font-weight:bold}
.check{font-size:40px;animation:popIn .5s ease}

@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes popIn{from{opacity:0;transform:scale(.9)}to{opacity:1;transform:scale(1)}}
@keyframes glow{0%,100%{box-shadow:0 0 0 0 rgba(79,140,255,.25)}50%{box-shadow:0 0 0 6px rgba(79,140,255,0)}}
</style>
</head>
<body>
<div class="box">
<?php if ($viewExpired): ?>
<div class="check">⏱️</div>
<h3 style="margin:8px 0 4px">Đã hết thời gian xem key</h3>
<p>Trang này chỉ hiển thị key trong 10 phút sau khi kích hoạt để bảo mật. Key vẫn hoạt động bình thường trong app - nếu bạn đã sao chép lúc nãy thì dùng bình thường; nếu chưa kịp lưu, vui lòng liên hệ admin để được hỗ trợ.</p>
<?php else: ?>
<div class="check">✅</div>
<h3 style="margin:8px 0 4px">Key đã kích hoạt<?= $game ? ' - ' . htmlspecialchars($game['name']) : '' ?></h3>
<div class="key" id="keycode"><?= htmlspecialchars($row['keycode']) ?></div>
<p>Thời hạn: <?= round($row['duration_seconds'] / 3600) ?> giờ kể từ lần đầu dùng key trong app</p>
<button id="copyBtn" onclick="copyKey()">Sao chép Key</button>
<div class="timer" id="timer">⏱️ Còn <?= $secondsLeft ?>s để xem key</div>
<script>
function copyKey(){
    const el = document.getElementById('keycode');
    navigator.clipboard.writeText(el.textContent);
    const btn = document.getElementById('copyBtn');
    btn.textContent = '✓ Đã sao chép!';
    btn.classList.add('copied');
    setTimeout(() => { btn.textContent = 'Sao chép Key'; btn.classList.remove('copied'); }, 1800);
}
let left = <?= $secondsLeft ?>;
const timerEl = document.getElementById('timer');
setInterval(() => {
    left--;
    if (left <= 0) { location.reload(); return; }
    const m = Math.floor(left / 60), s = left % 60;
    timerEl.textContent = '⏱️ Còn ' + m + ':' + String(s).padStart(2,'0') + ' để xem key';
}, 1000);
</script>
<?php endif; ?>
</div>
</body>
</html>
