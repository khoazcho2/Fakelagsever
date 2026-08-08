<?php
// ============================================================
// getkey.php - User bấm "Lấy key miễn phí" ở 1 game trên trang chủ
// vào đây. Tạo key pending với cấu hình số bước vượt link + thời
// hạn riêng theo game/khu vực, rồi chuyển qua hop.php để bắt đầu
// bước vượt link đầu tiên.
// GET /getkey.php?game=<slug>
//
// - Giới hạn 1 lần lấy key thành công / ngày / game (theo cookie
//   trình duyệt) - cookie 'gk_claimed_<slug>'
// - Chống bug bấm F5 tạo key mới liên tục: nếu đang có 1 key pending
//   dở dang (chưa vượt xong) thì tái sử dụng lại, không tạo mới -
//   cookie 'gk_pending_<slug>'
// ============================================================
require_once __DIR__ . '/config.php';

$slug = trim($_GET['game'] ?? '');
$game = $slug !== '' ? get_game_by_slug($slug) : null;

if (!$game || !$game['enabled']) {
    http_response_code(404);
    render_notice_screen('Game không tồn tại', 'Link lấy key không hợp lệ hoặc game đang tạm ngưng cấp key. Vui lòng vào trang chủ để chọn game.');
    exit;
}

$db = get_db();

// Giới hạn: mỗi trình duyệt chỉ được lấy key thành công 1 lần/ngày/game
$claimCookie = 'gk_claimed_' . $slug;
$clientIp = get_client_ip();
if (!empty($_COOKIE[$claimCookie]) || ip_already_claimed_today((int)$game['id'], $clientIp)) {
    render_notice_screen('Bạn đã lấy key hôm nay', 'Mỗi ngày chỉ được lấy key 1 lần cho game này. Vui lòng quay lại vào ngày mai.');
    exit;
}

// Fix bug F5: nếu đang có key pending dở dang (chưa vượt xong link)
// thì tiếp tục với key đó, KHÔNG tạo key mới mỗi lần load trang
$pendingCookie = 'gk_pending_' . $slug;
if (!empty($_COOKIE[$pendingCookie])) {
    $stmt = $db->prepare("SELECT token FROM keys WHERE token = ? AND status = 'pending'");
    $stmt->execute([$_COOKIE[$pendingCookie]]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        header('Location: ' . BASE_URL . '/hop.php?token=' . urlencode($existing));
        exit;
    }
    // Token cũ không còn hợp lệ -> xoá cookie, tạo mới bên dưới
    setcookie($pendingCookie, '', time() - 3600, '/');
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
    render_notice_screen('Chưa cấu hình', 'Server chưa cấu hình nhà cung cấp rút gọn link. Vào /admin.php để thiết lập.');
    exit;
}

$token = random_string(32);
$keycode = strtoupper(substr(random_string(16), 0, 12));

$stmt = $db->prepare("INSERT INTO keys
    (keycode, token, status, duration_seconds, max_devices, created_at, game_id, region, total_hops, current_hop, chain, client_ip)
    VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, 0, ?, ?)");
$stmt->execute([
    $keycode, $token, $hours * 3600, DEFAULT_MAX_DEVICES, time(),
    $game['id'], $region, count($chain), implode(',', $chain), $clientIp,
]);

// Nhớ key pending này 1 giờ (đủ thời gian vượt link), tránh F5 tạo key mới
setcookie($pendingCookie, $token, time() + 3600, '/');

header('Location: ' . BASE_URL . '/hop.php?token=' . urlencode($token));
exit;
