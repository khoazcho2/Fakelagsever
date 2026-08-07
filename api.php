<?php
// ============================================================
// api.php - Endpoint cho app Java/Android gọi để check key
// GET /api.php?key=XXXX&device_id=YYYY
// Trả JSON: {"valid":true/false,"message":"...","expires_at":123}
//
// Quy tắc thời gian: expires_at chỉ được set ở LẦN GỌI ĐẦU TIÊN
// thành công (first_used_at) - tức chỉ khi user thật sự dùng key
// trong app thì đồng hồ đếm ngược mới bắt đầu chạy.
// ============================================================
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$key = trim($_GET['key'] ?? '');
$deviceId = trim($_GET['device_id'] ?? '');

if ($key === '') {
    echo json_encode(['valid' => false, 'message' => 'Thiếu tham số key']);
    exit;
}

$db = get_db();
$stmt = $db->prepare("SELECT * FROM keys WHERE keycode = ?");
$stmt->execute([$key]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['valid' => false, 'message' => 'Key không tồn tại']);
    exit;
}

if ($row['status'] === 'pending') {
    echo json_encode(['valid' => false, 'message' => 'Key chưa được kích hoạt']);
    exit;
}

if ($row['status'] === 'expired') {
    echo json_encode(['valid' => false, 'message' => 'Key đã hết hạn']);
    exit;
}

// Đã hết hạn (nếu đồng hồ đã từng bắt đầu chạy) nhưng chưa kịp cập nhật status
if ($row['expires_at'] !== null && time() > (int)$row['expires_at']) {
    $db->prepare("UPDATE keys SET status='expired' WHERE id=?")->execute([$row['id']]);
    echo json_encode(['valid' => false, 'message' => 'Key đã hết hạn']);
    exit;
}

$devices = $row['devices'] !== '' ? explode(',', $row['devices']) : [];

if ($deviceId !== '' && !in_array($deviceId, $devices, true)) {
    // Thiết bị mới chưa từng dùng key này
    if (count($devices) >= (int)$row['max_devices']) {
        echo json_encode(['valid' => false, 'message' => 'Key đã đủ số thiết bị cho phép']);
        exit;
    }
    $devices[] = $deviceId;
    $newDevicesStr = implode(',', $devices);

    if ($row['first_used_at'] === null) {
        // ĐÂY LÀ LẦN DÙNG ĐẦU TIÊN CỦA KEY -> bắt đầu tính giờ từ bây giờ
        $now = time();
        $expiresAt = $now + (int)$row['duration_seconds'];
        $db->prepare("UPDATE keys SET devices=?, first_used_at=?, expires_at=? WHERE id=?")
           ->execute([$newDevicesStr, $now, $expiresAt, $row['id']]);
        $row['first_used_at'] = $now;
        $row['expires_at'] = $expiresAt;
    } else {
        // Key đã từng dùng ở thiết bị khác (còn slot) -> chỉ thêm thiết bị, không reset giờ
        $db->prepare("UPDATE keys SET devices=? WHERE id=?")->execute([$newDevicesStr, $row['id']]);
    }
}

echo json_encode([
    'valid' => true,
    'message' => 'OK',
    'expires_at' => $row['expires_at'] !== null ? (int)$row['expires_at'] : null,
]);
