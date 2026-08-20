<?php
// ============================================================
// api.php - Endpoint cho app Java/Android gọi để check key
// GET /api.php?key=XXXX&device_id=YYYY
// Trả JSON: {"valid":true/false,"message":"...","expires_at":123,"cfg":"..."}
//
// Quy tắc thời gian: expires_at chỉ được set ở LẦN GỌI ĐẦU TIÊN
// thành công (first_used_at) - tức chỉ khi user thật sự dùng key
// trong app thì đồng hồ đếm ngược mới bắt đầu chạy.
//
// Bảo mật:
// - "cfg": config VPN thật, mã hoá AES-256-GCM bằng key derive từ
//   chính license key - chỉ nhánh valid:true mới có field này. App
//   phải giải mã được cfg bằng đúng key thì mới hoạt động thật, chống
//   kiểu bypass patch app bỏ qua check "valid".
// - Message lỗi ra ngoài GỘP CHUNG thành "Key không hợp lệ" (không
//   phân biệt không tồn tại / pending / hết hạn) để tránh oracle leak
//   cho kẻ dò key hàng loạt - chi tiết thật vẫn ghi vào error_log.
// - Rate limit theo IP: sai quá nhiều lần liên tiếp sẽ bị tạm khoá,
//   dùng chung bảng ip_blocklist với hệ chống bypass link (ngưỡng
//   riêng, lỏng hơn - xem API_MAX_FAILS_BEFORE_BLOCK trong config.php)
// ============================================================
require_once __DIR__ . '/config.php';

// "Đóng server" - công tắc khẩn cấp của admin. KHÔNG output bất kỳ gì
// (không header, không JSON) - request kết thúc ngay lập tức với body
// rỗng, phía app Android sẽ thấy như server không phản hồi/bị treo.
if (is_server_closed()) {
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$clientIp = get_client_ip();

// Trả lỗi ra ngoài với message gộp chung (chống oracle leak), đồng thời
// ghi log chi tiết thật + tính vào rate-limit theo IP.
function fail(string $ip, string $internalReason): void {
    error_log('[api.php] fail from ' . $ip . ': ' . $internalReason);
    record_bypass_violation($ip, $internalReason, API_MAX_FAILS_BEFORE_BLOCK, API_BLOCK_HOURS);
    echo json_encode(['valid' => false, 'message' => 'Key không hợp lệ']);
    exit;
}

if (is_ip_blocked($clientIp)) {
    echo json_encode(['valid' => false, 'message' => 'Quá nhiều lần thử sai, vui lòng thử lại sau']);
    exit;
}

$key = trim($_GET['key'] ?? '');
$deviceId = trim($_GET['device_id'] ?? '');

// Thiết bị bị admin cấm vĩnh viễn -> chặn ngay, không cho thử key nào cả
if ($deviceId !== '' && is_device_blocked($deviceId)) {
    fail($clientIp, 'Thiết bị đã bị cấm vĩnh viễn: ' . $deviceId);
}

if ($key === '') {
    fail($clientIp, 'Thiếu tham số key');
}

// Chuẩn hoá key trước khi so khớp: bỏ khoảng trắng/gạch ngang, viết hoa.
// Key lưu trong DB dạng "HQD-1234567-ABC" nhưng user gõ "hqd1234567abc"
// hay thiếu dấu "-" vẫn phải đăng nhập được - so khớp theo dạng chuẩn hoá
// thay vì exact string.
function normalize_keycode(string $k): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $k));
}
$keyNormalized = normalize_keycode($key);

$db = get_db();
$stmt = $db->prepare("SELECT * FROM keys WHERE REPLACE(UPPER(keycode), '-', '') = ?");
$stmt->execute([$keyNormalized]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    fail($clientIp, 'Key không tồn tại: ' . $keyNormalized);
}

if ($row['status'] === 'pending') {
    fail($clientIp, 'Key chưa kích hoạt: ' . $row['keycode']);
}

if ($row['status'] === 'expired') {
    fail($clientIp, 'Key đã hết hạn (status=expired): ' . $row['keycode']);
}

// Đã hết hạn (nếu đồng hồ đã từng bắt đầu chạy) nhưng chưa kịp cập nhật status
if ($row['expires_at'] !== null && time() > (int)$row['expires_at']) {
    db_execute($db->prepare("UPDATE keys SET status='expired' WHERE id=?"), [$row['id']]);
    fail($clientIp, 'Key vừa hết hạn theo expires_at: ' . $row['keycode']);
}

$devices = $row['devices'] !== '' ? explode(',', $row['devices']) : [];
$ipMap = json_decode($row['device_ip_map'] ?: '{}', true) ?: [];

if ($deviceId !== '' && !in_array($deviceId, $devices, true)) {
    // Thiết bị mới chưa từng dùng key này
    if (count($devices) >= (int)$row['max_devices']) {
        fail($clientIp, 'Đủ số thiết bị cho phép: ' . $row['keycode']);
    }
    $devices[] = $deviceId;
    $newDevicesStr = implode(',', $devices);
    $ipMap[$deviceId] = $clientIp;
    $newIpMapStr = json_encode($ipMap);

    if ($row['first_used_at'] === null) {
        // ĐÂY LÀ LẦN DÙNG ĐẦU TIÊN CỦA KEY -> bắt đầu tính giờ từ bây giờ
        $now = time();
        $expiresAt = $now + (int)$row['duration_seconds'];
        db_execute(
            $db->prepare("UPDATE keys SET devices=?, first_used_at=?, expires_at=?, device_ip_map=? WHERE id=?"),
            [$newDevicesStr, $now, $expiresAt, $newIpMapStr, $row['id']]
        );
        $row['first_used_at'] = $now;
        $row['expires_at'] = $expiresAt;
    } else {
        // Key đã từng dùng ở thiết bị khác (còn slot) -> chỉ thêm thiết bị, không reset giờ
        db_execute($db->prepare("UPDATE keys SET devices=?, device_ip_map=? WHERE id=?"), [$newDevicesStr, $newIpMapStr, $row['id']]);
    }

    // Ghi lịch sử VĨNH VIỄN - không nằm trong row key nên reset/xoá key
    // sau này không làm mất thông tin thiết bị đã từng đăng nhập
    log_device_history($row['keycode'], $deviceId, $clientIp, $row['game_id'] ?? null);
} elseif ($deviceId !== '' && ($ipMap[$deviceId] ?? '') !== $clientIp) {
    // Thiết bị cũ nhưng đổi IP (đổi mạng/wifi...) -> cập nhật lại cho đúng
    $ipMap[$deviceId] = $clientIp;
    db_execute($db->prepare("UPDATE keys SET device_ip_map=? WHERE id=?"), [json_encode($ipMap), $row['id']]);
}

// Thành công -> reset bộ đếm vi phạm của IP này (gõ sai vài lần trước đó
// không nên cộng dồn mãi nếu cuối cùng gõ đúng)
reset_violation_count($clientIp);

// Config VPN thật - THAY danh sách server thật của bạn vào đây.
// Mã hoá bằng chính $row['keycode'] (giá trị chuẩn trong DB, khớp với
// cách Android format key trước khi derive - xem KeyFormatWatcher).
$vpnConfig = json_encode([
    'servers' => [
        // ['host' => '1.2.3.4', 'port' => 51820, 'name' => 'VN-1'],
    ],
]);

echo json_encode([
    'valid' => true,
    'message' => 'OK',
    'expires_at' => $row['expires_at'] !== null ? (int)$row['expires_at'] : null,
    'cfg' => encrypt_config($vpnConfig, $row['keycode']),
]);
