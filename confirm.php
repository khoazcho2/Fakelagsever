<?php
// ============================================================
// confirm.php - User quay lại đây SAU KHI đã vượt đủ số bước link
// (được hop.php chuyển tới). Kích hoạt key (pending -> active) và
// hiển thị key.
// ============================================================
require_once __DIR__ . '/config.php';

$token = $_GET['token'] ?? '';
$db = get_db();

$stmt = $db->prepare("SELECT * FROM keys WHERE token = ?");
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    die('Token không hợp lệ.');
}

// Chốt an toàn: nếu key còn pending mà CHƯA vượt đủ số bước yêu cầu
// (vd. user tự gõ thẳng URL confirm.php) -> đá ngược lại hop.php,
// tuyệt đối không cấp key khi chưa hoàn thành đủ bước.
if ($row['status'] === 'pending' && (int)$row['current_hop'] < (int)$row['total_hops']) {
    header('Location: ' . BASE_URL . '/hop.php?token=' . urlencode($token));
    exit;
}

if ($row['status'] === 'pending') {
    // Chỉ đánh dấu active, KHÔNG set expires_at ở đây -> thời hạn chỉ
    // bắt đầu tính từ lúc key được dùng lần đầu tiên trong app (xem api.php)
    $upd = $db->prepare("UPDATE keys SET status='active', activated_at=? WHERE id=?");
    $upd->execute([time(), $row['id']]);
    $row['status'] = 'active';
}

$game = $row['game_id'] ? $db->query("SELECT name FROM games WHERE id=" . (int)$row['game_id'])->fetchColumn() : null;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Key của bạn</title>
<style>
*{box-sizing:border-box}
body{font-family:-apple-system,Arial,sans-serif;max-width:360px;margin:60px auto;background:#0f1115;color:#eee;text-align:center;padding:0 16px}
.box{background:#181b22;padding:22px;border-radius:12px}
.key{font-size:19px;font-weight:bold;color:#4f8cff;letter-spacing:1.5px;margin:14px 0;user-select:all;word-break:break-all}
button{padding:10px 16px;background:#4f8cff;border:none;border-radius:8px;color:#fff;cursor:pointer;font-size:14px}
p{font-size:13px;color:#aaa}
</style>
</head>
<body>
<div class="box">
<h3 style="margin:0 0 4px">Key đã kích hoạt<?= $game ? ' - ' . htmlspecialchars($game) : '' ?></h3>
<div class="key" id="keycode"><?= htmlspecialchars($row['keycode']) ?></div>
<p>Thời hạn: <?= round($row['duration_seconds'] / 3600) ?> giờ kể từ lần đầu dùng key trong app</p>
<button onclick="navigator.clipboard.writeText(document.getElementById('keycode').textContent)">Sao chép Key</button>
<p style="margin-top:16px">Dán key này vào ứng dụng để đăng nhập.</p>
</div>
</body>
</html>
