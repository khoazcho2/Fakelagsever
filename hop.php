<?php
// ============================================================
// hop.php - Xử lý từng "bước vượt link" theo thứ tự provider đã
// lưu trong keys.chain lúc tạo key (getkey.php).
//
// GET hop.php?token=X            -> hiển thị màn "Tạo Link" (chưa gọi
//                                    API rút gọn), chờ user chủ động bấm
// GET hop.php?token=X&create=1   -> user vừa bấm nút "Tạo Link". Đây là
//                                    lúc DUY NHẤT server gọi API rút gọn
//                                    cho bước hiện tại, rồi hiển thị nút
//                                    "Mở link để vượt"
// GET hop.php?token=X&advance=1  -> user vừa vượt xong 1 bước. KIỂM
//                                    TRA CHỐNG BYPASS trước khi ghi
//                                    nhận: IP có đang bị chặn không,
//                                    và thời gian trôi qua từ lúc
//                                    redirect có hợp lý không (quá
//                                    nhanh = nghi ngờ tool bypass)
// GET hop.php?token=X&next=1     -> từ màn chuyển tiếp bấm tiếp tục,
//                                    quay lại màn "Tạo Link" cho bước kế
//                                    (KHÔNG tự tạo shortlink ngay)
//
// QUAN TRỌNG: nếu gọi API rút gọn link thất bại, hiển thị lỗi và
// DỪNG LẠI - không được để user lọt qua mà chưa vượt đủ link.
//
// FIX "database is locked": mọi lệnh ghi (UPDATE/INSERT) dùng
// db_execute() thay vì $stmt->execute() trực tiếp - hàm này đã định
// nghĩa sẵn trong config.php, tự động thử lại vài lần nếu SQLite tạm
// thời bị khoá do nhiều request ghi cùng lúc, thay vì throw exception
// ngay và làm crash trang (lộ cả stack trace ra ngoài cho user thấy).
// Toàn bộ logic còn được bọc try/catch tổng ở cuối file: nếu vẫn có
// lỗi DB không lường trước, hiển thị màn thông báo thân thiện thay vì
// màn lỗi PHP thô.
// ============================================================
require_once __DIR__ . '/config.php';
set_security_headers();
session_start();

try {
    hop_main();
} catch (Throwable $e) {
    // Không bao giờ để lộ stack trace / thông tin nội bộ ra cho user.
    // Ghi log để admin xem trong log server, còn user chỉ thấy 1 màn
    // thông báo thân thiện và được hướng dẫn quay lại lấy key mới.
    error_log('[hop.php] Lỗi không lường trước: ' . $e->getMessage() . ' tại ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    render_notice_screen(
        'Hệ thống đang bận',
        'Server đang xử lý nhiều yêu cầu cùng lúc, vui lòng đợi vài giây rồi thử lại. Nếu vẫn lỗi, hãy quay lại trang chủ để lấy key mới.'
    );
}
exit;

function hop_main(): void {
    $token = trim($_GET['token'] ?? '');
    $clientIp = get_client_ip();
    $db = get_db();

    // Admin đã đăng nhập /admin.php (cùng session) được test không giới
    // hạn: bỏ chặn IP, bỏ kiểm tra chống bypass, bỏ chờ 15s giữa các bước.
    $isAdmin = !empty($_SESSION['is_admin']);

    // Chặn ngay từ đầu nếu IP này đang trong danh sách bị khoá do nghi bypass
    if (!$isAdmin && is_ip_blocked($clientIp)) {
        render_blocked_screen();
        return;
    }

    $stmt = $db->prepare("SELECT * FROM keys WHERE token = ?");
    db_execute($stmt, [$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        render_notice_screen('Token không hợp lệ', 'Có thể link đã hết hạn, đã dùng rồi, hoặc server vừa khởi động lại. Vui lòng quay lại trang chủ để lấy key mới.');
        return;
    }

    if ($row['status'] !== 'pending') {
        header('Location: ' . BASE_URL . '/confirm.php?token=' . urlencode($token));
        return;
    }

    // Không phát shortlink trước khi key pending có dấu mốc hoàn thành nhiệm vụ
    // kênh. Mốc này nằm trong database (không chỉ session) nên user không thể
    // lách bằng cách dán thẳng URL hop.php.
    if (!empty(get_channels(true)) && empty($row['channel_gate_completed_at'])) {
        http_response_code(403);
        render_notice_screen('Bạn chưa hoàn thành nhiệm vụ kênh', 'Hãy quay lại trang lấy key, hoàn thành các kênh bắt buộc rồi tiếp tục nhận link.');
        return;
    }

    // User vừa hoàn thành 1 bước vượt link -> KIỂM TRA CHỐNG BYPASS trước
    // khi ghi nhận tiến độ. Nếu callback đến quá nhanh so với lúc user được
    // redirect sang shortlink (hop_started_at), đây là dấu hiệu tool bypass
    // tự bắn thẳng request mà không thật sự vượt link.
    if (isset($_GET['advance'])) {
        $startedAt = $row['hop_started_at'];
        $elapsed = $startedAt ? (time() - (int)$startedAt) : null;

        if (!$isAdmin && ($elapsed === null || $elapsed < MIN_HOP_SECONDS)) {
            record_bypass_violation($clientIp, 'Vượt link quá nhanh (' . ($elapsed ?? 'null') . 's) tại token=' . substr($token, 0, 8) . '...');
            http_response_code(403);
            render_notice_screen(
                'Phát hiện dấu hiệu bất thường',
                'Yêu cầu của bạn đến quá nhanh so với thời gian vượt link thông thường. Vui lòng vượt link đúng cách qua trình duyệt, không dùng tool tự động.'
            );
            return;
        }

        $newHop = min((int)$row['current_hop'] + 1, (int)$row['total_hops']);
        $ok = db_execute(
            $db->prepare("UPDATE keys SET current_hop = ?, hop_started_at = NULL WHERE id = ?"),
            [$newHop, $row['id']]
        );
        if (!$ok) {
            // Ghi tiến độ thất bại (DB vẫn bận sau khi đã retry) - KHÔNG
            // được coi như đã vượt xong bước này. Báo user thử lại thay vì
            // âm thầm cho qua (tránh mất tiến độ hoặc cấp key sai bước).
            render_shorten_error();
            return;
        }
        $row['current_hop'] = $newHop;

        $done = $row['current_hop'] >= $row['total_hops'];
        $nextUrl = $done
            ? (BASE_URL . '/confirm.php?token=' . urlencode($token))
            : (BASE_URL . '/hop.php?token=' . urlencode($token) . '&next=1');
        render_transition_screen((int)$row['current_hop'], (int)$row['total_hops'], $nextUrl, $done, $isAdmin);
        return;
    }

    // Đã vượt đủ số bước yêu cầu (vd bấm lại link cũ) -> kích hoạt key
    if ((int)$row['current_hop'] >= (int)$row['total_hops']) {
        header('Location: ' . BASE_URL . '/confirm.php?token=' . urlencode($token));
        return;
    }

    // Chưa tạo link cho bước này - CHỈ tạo khi user chủ động bấm nút "Tạo
    // Link" (tức là sau khi đã bấm "Xác Nhận & Lấy Key" ở màn nhiệm vụ kênh
    // và được đưa tới đây). Tránh trường hợp tốn lượt gọi API (và có thể
    // tốn phí/quota với nhà cung cấp rút gọn) cho user vào tới trang này
    // nhưng bỏ đi mà chưa thực sự vượt link.
    if (!isset($_GET['create'])) {
        $createUrl = BASE_URL . '/hop.php?token=' . urlencode($token) . '&create=1';
        render_create_link_screen((int)$row['current_hop'] + 1, (int)$row['total_hops'], $createUrl);
        return;
    }

    // Chưa đủ bước -> tạo shortlink cho bước kế tiếp (chỉ chạy tới đây khi
    // user đã bấm nút "Tạo Link" ở màn trên, tức ?create=1 có mặt)
    $chain = explode(',', $row['chain']);
    $provider = $chain[$row['current_hop']] ?? $chain[0];

    $cfg = get_shortener_config();
    $apiKey = $cfg['keys'][$provider] ?? '';

    if ($provider === '' || $apiKey === '') {
        render_shorten_error();
        return;
    }

    // Đích đến sau khi user vượt xong bước này: quay lại hop.php để ghi nhận
    // tiến độ. QUAN TRỌNG: thêm số thứ tự bước (h=) + mã ngẫu nhiên (r=) để
    // mỗi bước có 1 LINK GỐC DUY NHẤT, không trùng với bước khác của cùng
    // 1 key. Nếu không, key 36h (2 bước) sẽ rút gọn 2 lần trên CÙNG 1 link
    // gốc (chỉ khác mỗi query advance=1) - vi phạm quy định "không rút gọn
    // trùng lặp" của nhà cung cấp, khiến lượt thứ 2 không được tính tiền.
    $destUrl = BASE_URL . '/hop.php?token=' . urlencode($token)
        . '&advance=1&h=' . ((int)$row['current_hop'] + 1)
        . '&r=' . bin2hex(random_bytes(5));
    $shortUrl = shorten_link($provider, $apiKey, $destUrl, $cfg);

    if (!$shortUrl) {
        render_shorten_error();
        return;
    }

    // Ghi lại thời điểm bắt đầu bước này ngay trước khi hiển thị nút mở link,
    // để callback advance sau đó tính được đã trôi qua bao lâu.
    db_execute(
        $db->prepare("UPDATE keys SET hop_started_at = ? WHERE id = ?"),
        [time(), $row['id']]
    );

    // Không redirect ngầm sang shortlink nữa. User nhìn thấy rõ link đã tạo
    // xong và chủ động bấm "Mở link để vượt".
    render_link_ready_screen((int)$row['current_hop'] + 1, (int)$row['total_hops'], $shortUrl);
}

// Màn hình hiển thị TRƯỚC khi gọi API rút gọn link - user phải chủ động
// bấm "Tạo Link" thì server mới thực sự gọi API nhà cung cấp. Tách riêng
// khỏi màn "link đã sẵn sàng" (render_link_ready_screen) vì lúc này link
// CHƯA tồn tại.
function render_create_link_screen(int $step, int $total, string $createUrl): void {
    ?>
    <!DOCTYPE html><html lang="vi"><head>
    <?= shared_page_head('Sẵn sàng tạo link') ?>
    </head><body class="ds-page">
    <div class="ds-page-inner">
    <main class="ds-card" style="animation:ds-rise .4s var(--ease-out)">
        <div class="ds-holo"></div>
        <div class="ds-card-body">
            <div class="ds-icon-badge ds-icon-badge--cyan" style="animation:ds-pop-in .5s var(--ease-spring)"><?= svg_icon('zap', 26) ?></div>
            <div class="ds-eyebrow" style="margin:0 auto var(--sp-2)">AUTH <span>STEP LINK</span></div>
            <h1 style="font:var(--fw-bold) var(--fs-xl) var(--font-body);letter-spacing:-.03em;margin:0 0 var(--sp-2)">Sẵn sàng vượt link</h1>
            <p style="font-size:13px;color:var(--ds-text-dim);line-height:var(--lh-relaxed);margin:0">Bấm nút bên dưới để hệ thống tạo link rút gọn cho bước này.</p>
            <div class="ds-step-pill" style="margin-top:var(--sp-4)">BƯỚC <?= $step ?>/<?= $total ?></div>
            <a class="ds-btn" href="<?= htmlspecialchars($createUrl) ?>" style="margin-top:var(--sp-6)">Tạo Link <span>→</span></a>
            <p style="margin-top:var(--sp-3);font-size:11px;color:var(--ds-text-muted)">Link chỉ được tạo sau khi bạn bấm nút này.</p>
        </div>
    </main>
    </div>
    <?= anti_devtools_script() ?>
    </body></html>
    <?php
}
