<?php
// ============================================================
// getkey.php - User bấm "Lấy key miễn phí" ở 1 game trên trang chủ
// vào đây. Tạo key pending với cấu hình số bước vượt link + thời
// hạn riêng theo game/khu vực, rồi chuyển qua hop.php để bắt đầu
// bước vượt link đầu tiên.
// GET /getkey.php?game=<slug>
// ============================================================
require_once __DIR__ . '/config.php';

$slug = trim($_GET['game'] ?? '');
$game = $slug !== '' ? get_game_by_slug($slug) : null;

if (!$game || !$game['enabled']) {
    http_response_code(404);
    die('Game không tồn tại hoặc đang tạm ngưng cấp key.');
}

$cfg = get_shortener_config();
$region = detect_region();

$hops = (int)($region === 'intl' ? $game['intl_hops'] : $game['vn_hops']);
$hours = (int)($region === 'intl' ? $game['intl_key_hours'] : $game['vn_key_hours']);
$chainStr = $region === 'intl' ? $game['intl_chain'] : $game['vn_chain'];

// Nếu admin không chỉ định chuỗi provider cụ thể -> lặp lại provider đang active đủ số bước
$chain = $chainStr !== '' ? array_filter(explode(',', $chainStr)) : array_fill(0, $hops, $cfg['active']);
$chain = array_values($chain);
if (empty($chain) || $chain[0] === '') {
    http_response_code(500);
    die('Server chưa cấu hình nhà cung cấp rút gọn link. Vào /admin.php để thiết lập.');
}

$db = get_db();
$token = random_string(32);
$keycode = strtoupper(substr(random_string(16), 0, 12));

$stmt = $db->prepare("INSERT INTO keys
    (keycode, token, status, duration_seconds, max_devices, created_at, game_id, region, total_hops, current_hop, chain)
    VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, 0, ?)");
$stmt->execute([
    $keycode, $token, $hours * 3600, DEFAULT_MAX_DEVICES, time(),
    $game['id'], $region, count($chain), implode(',', $chain),
]);

header('Location: ' . BASE_URL . '/hop.php?token=' . urlencode($token));
exit;
