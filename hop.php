<?php
// ============================================================
// hop.php - Xử lý từng "bước vượt link" theo thứ tự provider đã
// lưu trong keys.chain lúc tạo key (getkey.php).
//
// GET hop.php?token=X            -> tạo shortlink cho bước hiện tại,
//                                    redirect user sang đó
// GET hop.php?token=X&advance=1  -> user vừa vượt xong 1 bước, ghi
//                                    nhận tiến độ rồi HIỂN THỊ màn
//                                    hình chuyển tiếp (vd "1/2") thay
//                                    vì redirect ngầm ngay lập tức
// GET hop.php?token=X&next=1     -> từ màn chuyển tiếp bấm tiếp tục,
//                                    server mới thật sự tạo shortlink
//                                    cho bước kế / hoặc sang confirm.php
//                                    nếu đã đủ bước
//
// QUAN TRỌNG: nếu gọi API rút gọn link thất bại, hiển thị lỗi và
// DỪNG LẠI - không được để user lọt qua mà chưa vượt đủ link.
// ============================================================
require_once __DIR__ . '/config.php';
session_start();

$token = trim($_GET['token'] ?? '');
$db = get_db();

$stmt = $db->prepare("SELECT * FROM keys WHERE token = ?");
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    die('Token không hợp lệ.');
}

if ($row['status'] !== 'pending') {
    // Key đã kích hoạt rồi (user bấm lại link cũ) -> đưa thẳng tới trang xem key
    header('Location: ' . BASE_URL . '/confirm.php?token=' . urlencode($token));
    exit;
}

// User vừa hoàn thành 1 bước vượt link -> ghi nhận tiến độ, KHÔNG redirect
// ngay mà hiện màn hình chuyển tiếp cho user thấy rõ tiến độ
if (isset($_GET['advance'])) {
    $newHop = min((int)$row['current_hop'] + 1, (int)$row['total_hops']);
    $db->prepare("UPDATE keys SET current_hop = ? WHERE id = ?")->execute([$newHop, $row['id']]);
    $row['current_hop'] = $newHop;

    $done = $row['current_hop'] >= $row['total_hops'];
    $nextUrl = $done
        ? (BASE_URL . '/confirm.php?token=' . urlencode($token))
        : (BASE_URL . '/hop.php?token=' . urlencode($token) . '&next=1');
    render_transition_screen((int)$row['current_hop'], (int)$row['total_hops'], $nextUrl, $done);
    exit;
}

// Đã vượt đủ số bước yêu cầu (vd bấm lại link cũ) -> kích hoạt key
if ((int)$row['current_hop'] >= (int)$row['total_hops']) {
    header('Location: ' . BASE_URL . '/confirm.php?token=' . urlencode($token));
    exit;
}

// Chưa đủ bước -> tạo shortlink cho bước kế tiếp (gọi trực tiếp khi vào lần đầu,
// hoặc khi user bấm "Tiếp tục" từ màn chuyển tiếp qua ?next=1)
$chain = explode(',', $row['chain']);
$provider = $chain[$row['current_hop']] ?? $chain[0];

$cfg = get_shortener_config();
$apiKey = $cfg['keys'][$provider] ?? '';

if ($provider === '' || $apiKey === '') {
    render_shorten_error();
    exit;
}

// Đích đến sau khi user vượt xong bước này: quay lại hop.php để ghi nhận tiến độ
$destUrl = BASE_URL . '/hop.php?token=' . urlencode($token) . '&advance=1';
$shortUrl = shorten_link($provider, $apiKey, $destUrl, $cfg);

if (!$shortUrl) {
    // KHÔNG bypass sang confirm.php khi lỗi - báo lỗi và dừng lại
    render_shorten_error();
    exit;
}

header('Location: ' . $shortUrl);
exit;
