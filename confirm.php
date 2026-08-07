<?php
// ============================================================
// confirm.php - User quay lại đây sau khi hoàn thành shortlink
// Kích hoạt key (chuyển pending -> active) và hiển thị key
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

if ($row['status'] === 'pending') {
    // Chỉ đánh dấu active, KHÔNG set expires_at ở đây -> thời hạn chỉ
    // bắt đầu tính từ lúc key được dùng lần đầu tiên trong app (xem api.php)
    $upd = $db->prepare("UPDATE keys SET status='active', activated_at=? WHERE id=?");
    $upd->execute([time(), $row['id']]);
    $row['status'] = 'active';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Key của bạn</title>
<style>
body{font-family:Arial;max-width:420px;margin:80px auto;background:#0f1115;color:#eee;text-align:center}
.box{background:#181b22;padding:30px;border-radius:10px}
.key{font-size:22px;font-weight:bold;color:#4f8cff;letter-spacing:2px;margin:16px 0;user-select:all}
button{padding:10px 18px;background:#4f8cff;border:none;border-radius:6px;color:#fff;cursor:pointer}
</style>
</head>
<body>
<div class="box">
<h2>Key đã kích hoạt</h2>
<div class="key" id="keycode"><?= htmlspecialchars($row['keycode']) ?></div>
<p>Thời hạn: <?= round($row['duration_seconds'] / 3600) ?> giờ kể từ lần đầu dùng key trong app</p>
<button onclick="navigator.clipboard.writeText(document.getElementById('keycode').textContent)">Sao chép Key</button>
<p style="font-size:13px;color:#888;margin-top:20px">Dán key này vào ứng dụng để đăng nhập.</p>
</div>
</body>
</html>
