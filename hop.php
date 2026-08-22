<?php
// ============================================================
// hop.php - Xử lý từng "bước vượt link" theo thứ tự provider đã
// lưu trong keys.chain lúc tạo key (getkey.php).
//
// GET hop.php?token=X            -> tạo shortlink cho bước hiện tại,
//                                    redirect user sang đó, ghi lại
//                                    hop_started_at để đo thời gian
// GET hop.php?token=X&advance=1  -> user vừa vượt xong 1 bước. KIỂM
//                                    TRA CHỐNG BYPASS trước khi ghi
//                                    nhận: IP có đang bị chặn không,
//                                    và thời gian trôi qua từ lúc
//                                    redirect có hợp lý không (quá
//                                    nhanh = nghi ngờ tool bypass)
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
$clientIp = get_client_ip();
$db = get_db();

// Chặn ngay từ đầu nếu IP này đang trong danh sách bị khoá do nghi bypass
if (is_ip_blocked($clientIp)) {
    render_blocked_screen();
    exit;
}

$stmt = $db->prepare("SELECT * FROM keys WHERE token = ?");
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    render_notice_screen('Token không hợp lệ', 'Có thể link đã hết hạn, đã dùng rồi, hoặc server vừa khởi động lại. Vui lòng quay lại trang chủ để lấy key mới.');
    exit;
}

if ($row['status'] !== 'pending') {
    header('Location: ' . BASE_URL . '/confirm.php?token=' . urlencode($token));
    exit;
}

// Không phát shortlink trước khi key pending có dấu mốc hoàn thành nhiệm vụ
// kênh. Mốc này nằm trong database (không chỉ session) nên user không thể
// lách bằng cách dán thẳng URL hop.php.
if (!empty(get_channels(true)) && empty($row['channel_gate_completed_at'])) {
    http_response_code(403);
    render_notice_screen('Bạn chưa hoàn thành nhiệm vụ kênh', 'Hãy quay lại trang lấy key, hoàn thành các kênh bắt buộc rồi tiếp tục nhận link.');
    exit;
}

// User vừa hoàn thành 1 bước vượt link -> KIỂM TRA CHỐNG BYPASS trước
// khi ghi nhận tiến độ. Nếu callback đến quá nhanh so với lúc user được
// redirect sang shortlink (hop_started_at), đây là dấu hiệu tool bypass
// tự bắn thẳng request mà không thật sự vượt link.
if (isset($_GET['advance'])) {
    $startedAt = $row['hop_started_at'];
    $elapsed = $startedAt ? (time() - (int)$startedAt) : null;

    if ($elapsed === null || $elapsed < MIN_HOP_SECONDS) {
        record_bypass_violation($clientIp, 'Vượt link quá nhanh (' . ($elapsed ?? 'null') . 's) tại token=' . substr($token, 0, 8) . '...');
        http_response_code(403);
        render_notice_screen(
            'Phát hiện dấu hiệu bất thường',
            'Yêu cầu của bạn đến quá nhanh so với thời gian vượt link thông thường. Vui lòng vượt link đúng cách qua trình duyệt, không dùng tool tự động.'
        );
        exit;
    }

    $newHop = min((int)$row['current_hop'] + 1, (int)$row['total_hops']);
    $db->prepare("UPDATE keys SET current_hop = ?, hop_started_at = NULL WHERE id = ?")->execute([$newHop, $row['id']]);
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
    render_shorten_error();
    exit;
}

// Ghi lại thời điểm bắt đầu bước này ngay trước khi hiển thị nút mở link,
// để callback advance sau đó tính được đã trôi qua bao lâu.
$db->prepare("UPDATE keys SET hop_started_at = ? WHERE id = ?")->execute([time(), $row['id']]);

// Không redirect ngầm sang shortlink nữa. User nhìn thấy rõ link đã tạo
// xong và chủ động bấm "Mở link để vượt".
render_link_ready_screen((int)$row['current_hop'] + 1, (int)$row['total_hops'], $shortUrl);
exit;
