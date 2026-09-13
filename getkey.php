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
// - User BẮT BUỘC chọn loại Key (Key 20h = vượt 1 lần / Key 40h =
//   vượt 2 lần) ở màn đầu tiên, ÁP DỤNG CHO MỌI GAME - không phụ
//   thuộc game đó có bật nhiệm vụ kênh hay không. Trước đây lựa chọn
//   này chỉ nằm trong màn nhiệm vụ kênh nên game không bật kênh vẫn
//   bị tạo key theo "Hạn key (giờ)" cũ trong Cấu hình Game (bug: chọn
//   24h nhưng ra 30h vì admin từng để giá trị đó).
// ============================================================
require_once __DIR__ . '/config.php';
set_security_headers();
if (session_status() === PHP_SESSION_NONE) session_start();

// Số giây bắt buộc chờ sau khi bấm "Tham Gia" 1 kênh trước khi được tích
// xác nhận. Đổi ở đây là áp dụng đồng bộ cho cả kiểm tra server (chống
// gian lận) lẫn bộ đếm hiển thị trên UI.
const CHANNEL_WAIT_SECONDS = 20;

// Định nghĩa các loại key user có thể chọn: value => [hops, hours, label, sub]
const KEY_TYPE_OPTIONS = [
    '20h' => ['hops' => 1, 'hours' => 20, 'label' => 'Key 20 giờ', 'sub' => 'Vượt 1 lần link'],
    '40h' => ['hops' => 2, 'hours' => 40, 'label' => 'Key 40 giờ', 'sub' => 'Vượt 2 lần link'],
];

function normalize_key_type(?string $value): ?string {
    return ($value !== null && isset(KEY_TYPE_OPTIONS[$value])) ? $value : null;
}

$db = get_db();
$channels = get_channels(true);

// ------------------------------------------------------------
// 1. Endpoint AJAX nhẹ để JS polling trạng thái xác minh Telegram thật
// (Được đặt TRƯỚC kiểm tra game để không bị lỗi 404 khi gọi fetch không kèm slug)
// ------------------------------------------------------------
if (isset($_GET['channel_verify_poll'])) {
    header('Content-Type: application/json; charset=utf-8');
    $pollToken = trim($_GET['token'] ?? '');
    $pollGate = trim($_GET['gate_token'] ?? '');
    $pollChannel = (int)($_GET['channel'] ?? 0);
    $expected = $_SESSION['channel_gate'][$pollToken] ?? '';
    if ($pollToken === '' || $expected === '' || !hash_equals($expected, $pollGate)) {
        echo json_encode(['verified' => false]);
        exit;
    }
    echo json_encode(['verified' => is_channel_verified($pollToken, $pollChannel) !== null]);
    exit;
}

// ------------------------------------------------------------
// 2. Endpoint AJAX kiểm tra trực tiếp theo @username hoặc ID Telegram từ form trên web
// (Được đặt TRƯỚC kiểm tra game để luôn trả về JSON chuẩn, không bị lỗi HTML 404)
// ------------------------------------------------------------
if (isset($_REQUEST['direct_tg_verify'])) {
    header('Content-Type: application/json; charset=utf-8');
    $reqToken = trim($_REQUEST['token'] ?? '');
    $reqGate = trim($_REQUEST['gate_token'] ?? '');
    $identifier = trim($_REQUEST['identifier'] ?? '');
    $expected = $_SESSION['channel_gate'][$reqToken] ?? '';

    if ($reqToken === '' || $expected === '' || !hash_equals($expected, $reqGate)) {
        echo json_encode(['success' => false, 'message' => 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang.']);
        exit;
    }

    if ($identifier === '') {
        echo json_encode(['success' => false, 'message' => 'Vui lòng nhập @username hoặc ID số Telegram của bạn.']);
        exit;
    }

    $tgUserId = 0;
    $cleanId = ltrim($identifier, '@');
    $isUserPremium = false;

    if (ctype_digit($cleanId)) {
        $tgUserId = (int)$cleanId;
        $uInfo = get_telegram_user_info($tgUserId);
        $isUserPremium = !empty($uInfo['is_premium']);
    } else {
        $botCfg = get_telegram_bot_config();
        // 1. Kiểm tra nếu username trùng với Admin trong cấu hình Bot Telegram
        if (!empty($botCfg['username']) && strcasecmp($cleanId, $botCfg['username']) === 0 && !empty($botCfg['admin_chat_id'])) {
            $tgUserId = (int)$botCfg['admin_chat_id'];
            $uInfo = get_telegram_user_info($tgUserId);
            $isUserPremium = !empty($uInfo['is_premium']) || true;
        }

        // 2. Tìm ID số từ username trong bảng tg_users
        if ($tgUserId <= 0) {
            $stmtU = $db->prepare("SELECT tg_user_id, is_premium FROM tg_users WHERE LOWER(username) = LOWER(?) ORDER BY updated_at DESC LIMIT 1");
            $stmtU->execute([$cleanId]);
            $rowU = $stmtU->fetch(PDO::FETCH_ASSOC);
            if ($rowU) {
                $tgUserId = (int)$rowU['tg_user_id'];
                $isUserPremium = !empty($rowU['is_premium']);
            }
        }

        // 3. Tìm ID số từ username trong bảng keys nếu đã từng dùng trước đây
        if ($tgUserId <= 0) {
            $stmtK = $db->prepare("SELECT tg_user_id, is_premium FROM keys WHERE LOWER(tg_username) = LOWER(?) AND tg_user_id > 0 ORDER BY id DESC LIMIT 1");
            $stmtK->execute([$cleanId]);
            $rowK = $stmtK->fetch(PDO::FETCH_ASSOC);
            if ($rowK) {
                $tgUserId = (int)$rowK['tg_user_id'];
                $isUserPremium = !empty($rowK['is_premium']);
            }
        }
    }

    if ($tgUserId <= 0) {
        echo json_encode([
            'success' => false,
            'need_id' => true,
            'message' => "Chưa tìm thấy @{$cleanId} trên hệ thống. Bạn vui lòng bấm nút 'BẤM VÀO ĐÂY ĐỂ MỞ BOT & /START XÁC NHẬN' bên trên để Bot nhận diện, hoặc nhập ID số Telegram nhé."
        ]);
        exit;
    }

    // Danh sách các nhóm cần kiểm tra
    $reqGroups = [];
    $botCfg = get_telegram_bot_config();
    $rawGroupList = trim((string)($botCfg['channel_ids'] ?? ''));
    if ($rawGroupList === '') {
        $rawGroupList = '@hoquoc30, @AuraPingVN';
    }
    $entries = preg_split('/[\s,]+/', $rawGroupList, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($entries as $item) {
        $clean = trim($item);
        if ($clean !== '') $reqGroups[] = $clean;
    }
    foreach ($channels as $ch) {
        if ($ch['type'] === 'telegram' && !empty($ch['tg_chat_id'])) {
            $cId = trim($ch['tg_chat_id']);
            if ($cId !== '' && !in_array($cId, $reqGroups, true)) {
                $reqGroups[] = $cId;
            }
        }
    }
    $reqGroups = array_values(array_unique($reqGroups));

    $missing = [];
    foreach ($reqGroups as $gid) {
        $isM = check_telegram_membership($gid, $tgUserId);
        if (!$isM) {
            $missing[] = $gid;
        }
    }

    if (!empty($missing)) {
        echo json_encode([
            'success'  => false,
            'verified' => false,
            'message'  => 'Bạn chưa tham gia đủ các nhóm: ' . implode(', ', $missing) . '. Vui lòng bấm vào nhóm để tham gia rồi thử lại!'
        ]);
        exit;
    }

    // Đã tham gia đủ -> Đánh dấu hoàn thành toàn bộ kênh Telegram cho token này
    foreach ($channels as $ch) {
        if ($ch['type'] === 'telegram') {
            mark_channel_verified($reqToken, (int)$ch['id'], $tgUserId);
        }
    }
    // Gán ngay tg_user_id vào bảng keys cho pending token này
    try {
        $db->prepare("UPDATE keys SET tg_user_id = ?, tg_username = ?, is_premium = ? WHERE token = ?")->execute([$tgUserId, $cleanId, $isUserPremium ? 1 : 0, $reqToken]);
    } catch (\Throwable $e) {}

    echo json_encode([
        'success'    => true,
        'verified'   => true,
        'is_premium' => (bool)$isUserPremium,
        'message'    => '✅ Xác nhận thành công! Bạn đã tham gia đủ các nhóm Telegram.' . ($isUserPremium ? ' ⭐ (Telegram Premium)' : '')
    ]);
    exit;
}

$slug = trim((string)($_GET['game'] ?? $_POST['game'] ?? $_GET['slug'] ?? $_POST['slug'] ?? ''));
$game = $slug !== '' ? get_game_by_slug($slug) : null;

if (!$game || !$game['enabled']) {
    http_response_code(404);
    render_notice_screen('Game không tồn tại', 'Link lấy key không hợp lệ hoặc game đang tạm ngưng cấp key. Vui lòng vào trang chủ để chọn game.');
    exit;
}

$clientIp = get_client_ip();
$linkFp = get_link_gen_fp();
$publicNotice = get_active_notice();

// Admin đã đăng nhập /admin.php (cùng trình duyệt, cùng session) được
// test KHÔNG GIỚI HẠN: bỏ 1 lần/ngày, bỏ chờ nhiệm vụ kênh, bỏ chờ giữa
// các bước vượt link, bỏ luôn kiểm tra chống bypass. User thường KHÔNG
// thể tự bật cờ này vì is_admin chỉ được set sau khi đăng nhập đúng mật
// khẩu admin ở admin.php.
$isAdmin = !empty($_SESSION['is_admin']);

// Chặn ngay nếu IP HOẶC trình duyệt (cookie gk_fp) này đang bị khoá do
// nghi ngờ bypass ở bước vượt link / spam tạo link. Check thêm fp để user
// đổi mạng (4G/wifi/VPN...) né ip_blocklist vẫn bị nhận ra qua cookie.
if (!$isAdmin && (is_ip_blocked($clientIp) || is_fp_blocked($linkFp))) {
    render_blocked_screen();
    exit;
}

// Ghi nhận lúc user bấm "Tham Gia". JS vẫn giữ bộ đếm để UX rõ
// ràng, còn server lưu mốc này trong session để POST giả không thể xác nhận
// ngay lập tức bằng cách tự gửi đủ checkbox.
if (isset($_GET['channel_open'])) {
    $openToken = trim($_GET['token'] ?? '');
    $openGate = trim($_GET['gate_token'] ?? '');
    $openId = (int)$_GET['channel_open'];
    $expectedGate = $_SESSION['channel_gate'][$openToken] ?? '';
    $validChannel = in_array($openId, array_map(static fn($channel) => (int)$channel['id'], $channels), true);
    if ($openToken !== '' && $validChannel && $expectedGate !== '' && hash_equals($expectedGate, $openGate)) {
        $_SESSION['channel_opened'][$openToken][$openId] = time();
        http_response_code(204);
    } else {
        http_response_code(403);
    }
    exit;
}

// Giới hạn: mỗi trình duyệt chỉ được lấy key thành công 1 lần/ngày/game
$claimCookie = 'gk_claimed_' . $slug;
if (!$isAdmin && (!empty($_COOKIE[$claimCookie]) || ip_already_claimed_today((int)$game['id'], $clientIp))) {
    render_notice_screen('Bạn đã lấy key hôm nay', 'Mỗi ngày chỉ được lấy key 1 lần cho game này. Vui lòng quay lại vào ngày mai.');
    exit;
}

// Cổng nhiệm vụ kênh: user phải mở/tích xác nhận tất cả kênh đang bật
// trước khi được tạo/mở shortlink đầu tiên. Token cổng nằm trong session,
// nên không thể chỉ ghép URL hop.php rồi bỏ qua giao diện nhiệm vụ.
if (isset($_POST['channel_gate'])) {
    $pendingToken = trim($_POST['token'] ?? '');
    $submittedGate = trim($_POST['gate_token'] ?? '');
    $expectedGate = $_SESSION['channel_gate'][$pendingToken] ?? '';
    $doneIds = array_values(array_unique(array_map('strval', $_POST['channel_done'] ?? [])));
    $requiredIds = array_map(static fn($channel) => (string)$channel['id'], $channels);
    sort($doneIds);
    sort($requiredIds);

    $pendingStmt = $db->prepare("SELECT token FROM keys WHERE token = ? AND status = 'pending' AND current_hop < total_hops");
    $pendingStmt->execute([$pendingToken]);
    $isPending = (bool)$pendingStmt->fetchColumn();

    if (!$isPending || $expectedGate === '' || !hash_equals($expectedGate, $submittedGate)) {
        http_response_code(403);
        render_notice_screen('Phiên nhiệm vụ đã hết hạn', 'Vui lòng quay lại trang lấy key và bắt đầu lại để xác nhận các kênh.');
        exit;
    }
    if ($doneIds !== $requiredIds) {
        render_channel_gate($game, $channels, $pendingToken, $expectedGate, $publicNotice, 'Hãy mở và xác nhận đầy đủ tất cả kênh trước khi tiếp tục.', $_SESSION['channel_opened'][$pendingToken] ?? [], $isAdmin);
        exit;
    }
    $openedChannels = $_SESSION['channel_opened'][$pendingToken] ?? [];
    $notReady = [];
    if (!$isAdmin) {
        foreach ($channels as $channel) {
            $cid = (int)$channel['id'];
            if ($channel['type'] === 'telegram' && !empty($channel['tg_chat_id'])) {
                // Kênh Telegram có cấu hình Chat ID -> BẮT BUỘC xác minh
                // thật qua Bot (user thực sự ở trong nhóm), không tính
                // theo thời gian chờ nữa.
                if (is_channel_verified($pendingToken, $cid) === null) $notReady[] = (string)$cid;
                continue;
            }
            if (empty($openedChannels[$cid]) || time() - (int)$openedChannels[$cid] < CHANNEL_WAIT_SECONDS) {
                $notReady[] = (string)$cid;
            }
        }
    }
    if (!empty($notReady)) {
        render_channel_gate($game, $channels, $pendingToken, $expectedGate, $publicNotice, 'Vui lòng hoàn thành đầy đủ (xác minh Telegram/chờ đủ giây) cho từng kênh.', $openedChannels, $isAdmin);
        exit;
    }

    $_SESSION['channel_completed'][$pendingToken] = time();
    unset($_SESSION['channel_gate'][$pendingToken]);
    unset($_SESSION['channel_opened'][$pendingToken]);

    $tgUid = 0;
    try {
        $stmtTg = $db->prepare("SELECT tg_user_id FROM channel_verifications WHERE token = ? AND tg_user_id > 0 ORDER BY verified_at DESC LIMIT 1");
        $stmtTg->execute([$pendingToken]);
        $tgUid = (int)$stmtTg->fetchColumn();
    } catch (\Throwable $e) {}

    $db->prepare("UPDATE keys SET channel_gate_completed_at = ?, channel_gate_signature = ?, tg_user_id = COALESCE(NULLIF(?, 0), tg_user_id) WHERE token = ? AND status = 'pending'")
        ->execute([time(), channels_signature($channels), $tgUid, $pendingToken]);

    // Gửi tin nhắn thông báo user vừa tạo link lấy key thành công trên bot Telegram
    if ($tgUid > 0) {
        send_link_created_telegram_notification($tgUid, (string)($game['name'] ?? 'Game'), $pendingToken);
    }

    header('Location: ' . BASE_URL . '/hop.php?token=' . urlencode($pendingToken));
    exit;
}

// Fix bug F5: nếu đang có key pending THẬT SỰ DỞ DANG (chưa vượt xong hết
// các bước link) thì tiếp tục với key đó, KHÔNG tạo key mới mỗi lần F5.
// QUAN TRỌNG: nếu key đó đã vượt xong đủ số bước rồi (current_hop >=
// total_hops) thì KHÔNG được tái sử dụng - phải tạo key mới và bắt vượt
// link lại từ đầu, tránh bị bấm lại link cũ mà nhảy thẳng qua bước xác
// minh (bug đã gặp: bấm lại link game cũ nhảy thẳng tới trang nhận key).
$pendingCookie = 'gk_pending_' . $slug;
if (!empty($_COOKIE[$pendingCookie])) {
    $stmt = $db->prepare("SELECT token, channel_gate_completed_at, channel_gate_signature FROM keys WHERE token = ? AND status = 'pending' AND current_hop < total_hops");
    $stmt->execute([$_COOKIE[$pendingCookie]]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        // Bắt làm lại gate nếu: chưa từng hoàn thành, HOẶC danh sách kênh
        // bắt buộc hiện tại (admin vừa đổi/thêm/bớt) khác với lúc user đã
        // hoàn thành trước đó - tránh bỏ qua kênh mới thêm.
        $gateStale = empty($existing['channel_gate_completed_at'])
            || $existing['channel_gate_signature'] !== channels_signature($channels);
        if (!empty($channels) && $gateStale) {
            $gateToken = $_SESSION['channel_gate'][$existing['token']] ?? random_string(32);
            $_SESSION['channel_gate'][$existing['token']] = $gateToken;
            render_channel_gate($game, $channels, $existing['token'], $gateToken, $publicNotice, '', $_SESSION['channel_opened'][$existing['token']] ?? [], $isAdmin);
            exit;
        }
        render_getkey_loading(BASE_URL . '/hop.php?token=' . urlencode($existing['token']), $publicNotice);
        exit;
    }
    // Token cũ không còn hợp lệ, hoặc đã vượt xong hết bước rồi -> xoá
    // cookie, bắt buộc tạo key mới + vượt link lại từ đầu bên dưới.
    setcookie($pendingCookie, '', time() - 3600, '/');
}

// BẮT BUỘC user chọn loại Key TRƯỚC khi hệ thống tạo key - áp dụng cho
// MỌI GAME, không phụ thuộc có nhiệm vụ kênh hay không. Nếu chưa chọn
// (chưa có ?key_type= hợp lệ trên URL), hiện màn chọn và dừng lại.
$selectedKeyType = normalize_key_type($_GET['key_type'] ?? null);
if ($selectedKeyType === null) {
    render_key_type_select($game, $publicNotice);
    exit;
}
$typeInfo = KEY_TYPE_OPTIONS[$selectedKeyType];

$cfg = get_shortener_config();
$region = detect_region();

$hops = $typeInfo['hops'];
$hours = $typeInfo['hours'];
$chainStr = $region === 'intl' ? $game['intl_chain'] : $game['vn_chain'];

// Dùng đúng thứ tự provider admin đã cấu hình cho từng bước (Thứ tự link
// vượt trong Cấu hình Game); nếu thiếu bước nào hoặc admin chưa cấu hình
// gì thì lấp bằng provider đang active. Độ dài LUÔN khớp với $hops vừa
// chọn (20h -> 1 bước, 40h -> 2 bước), không phụ thuộc admin cấu hình
// bao nhiêu ô.
$chainRaw = $chainStr !== '' ? array_values(array_filter(explode(',', $chainStr))) : [];
$chain = [];
for ($i = 0; $i < $hops; $i++) {
    $chain[] = $chainRaw[$i] ?? ($cfg['active'] ?? '');
}
if (empty($chain) || $chain[0] === '') {
    http_response_code(500);
    render_notice_screen('Chưa cấu hình', 'Server chưa cấu hình nhà cung cấp rút gọn link. Vào /admin.php để thiết lập.');
    exit;
}

$token = random_string(32);
$keycode = generate_keycode();

$stmt = $db->prepare("INSERT INTO keys
    (keycode, token, status, duration_seconds, max_devices, created_at, game_id, region, total_hops, current_hop, chain, client_ip, client_platform)
    VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)");
$stmt->execute([
    $keycode, $token, $hours * 3600, DEFAULT_MAX_DEVICES, time(),
    $game['id'], $region, count($chain), implode(',', $chain), $clientIp, detect_client_platform(),
]);
record_link_generation_attempt($clientIp, $linkFp);

// Nhớ key pending này 1 giờ (đủ thời gian vượt link), tránh F5 tạo key mới
setcookie($pendingCookie, $token, time() + 3600, '/');

if (!empty($channels)) {
    $gateToken = random_string(32);
    $_SESSION['channel_gate'][$token] = $gateToken;
    render_channel_gate($game, $channels, $token, $gateToken, $publicNotice, '', [], $isAdmin);
    exit;
}

render_getkey_loading(BASE_URL . '/hop.php?token=' . urlencode($token), $publicNotice);
exit;

// Màn BẮT BUỘC chọn loại Key, hiện TRƯỚC KHI tạo key, áp dụng cho mọi
// game (có nhiệm vụ kênh hay không cũng phải chọn ở đây trước).
function render_key_type_select(array $game, ?array $notice): void {
    ?>
    <!DOCTYPE html><html lang="vi"><head>
    <?= shared_page_head('Chọn loại Key') ?>
    <style>
    .options { display: flex; flex-direction: column; gap: var(--sp-3); margin-top: var(--sp-5); }
    .opt {
        display: flex; align-items: center; justify-content: space-between; gap: var(--sp-3);
        padding: var(--sp-4) var(--sp-4); border: 1px solid var(--ds-line); border-radius: var(--r-lg);
        background: rgba(var(--ds-surface-rgb), 0.65); text-decoration: none; color: var(--ds-text);
        transition: transform var(--dur-fast), border-color var(--dur-fast), background var(--dur-fast), box-shadow var(--dur-fast);
    }
    .opt:hover { border-color: rgba(0, 242, 254, .45); transform: translateY(-2px); background: rgba(var(--ds-surface-rgb), 0.95); box-shadow: 0 10px 25px -8px rgba(0, 242, 254, 0.25); }
    .opt:active { transform: scale(.98); }
    .opt-left { display: flex; align-items: center; gap: var(--sp-3); text-align: left; }
    .opt-badge {
        padding: 4px 8px; border-radius: var(--r-sm); font: 700 11px var(--font-mono);
        background: rgba(0, 242, 254, .12); color: var(--ds-cyan); border: 1px solid rgba(0, 242, 254, .25);
    }
    .opt-title { font: var(--fw-bold) 15px var(--font-body); color: var(--ds-text); }
    .opt-sub { font-size: 11.5px; color: var(--ds-text-dim); margin-top: 2px; }
    .opt-arrow { font-size: 18px; color: var(--ds-cyan); transition: transform var(--dur-fast); }
    .opt:hover .opt-arrow { transform: translateX(3px); }
    </style>
    </head><body class="ds-page">
    <div class="ds-page-inner">
    <main class="ds-card" style="animation:ds-rise .4s var(--ease-out)">
        <div class="ds-holo"></div>
        <div class="ds-card-body">
            <div class="ds-eyebrow" style="margin:0 auto var(--sp-2)">AUTH <span>KEY TYPE</span></div>
            <h1 style="font:var(--fw-bold) var(--fs-xl) var(--font-body);letter-spacing:-.03em;margin:0 0 var(--sp-2)">Bạn muốn lấy key nào?</h1>
            <p style="font-size:13px;color:var(--ds-text-dim);line-height:var(--lh-relaxed);margin:0">Chọn loại key phù hợp — số bước vượt link tương ứng với thời hạn key.</p>
            <div class="ds-step-pill" style="margin-top:var(--sp-3);background:rgba(139, 92, 246, .12);color:var(--ds-violet);border-color:rgba(139, 92, 246, .25)">
                <?= htmlspecialchars($game['icon'] ?? '🎮') ?> <?= htmlspecialchars($game['name'] ?? 'Game') ?>
            </div>
            <?php if ($notice): ?>
            <div class="ds-notice ds-notice--<?= htmlspecialchars($notice['type'] ?? 'info') ?>" style="margin-top:var(--sp-4);text-align:left">
                <b><?= htmlspecialchars($notice['title']) ?></b>
                <p style="margin:2px 0 0;font-size:12px"><?= nl2br(htmlspecialchars($notice['message'])) ?></p>
            </div>
            <?php endif; ?>
            <div class="options">
            <?php foreach (KEY_TYPE_OPTIONS as $value => $opt): ?>
                <a class="opt" href="getkey.php?game=<?= urlencode($game['slug']) ?>&key_type=<?= urlencode($value) ?>">
                    <div class="opt-left">
                        <span class="opt-badge"><?= $opt['hops'] ?> bước</span>
                        <div>
                            <div class="opt-title"><?= htmlspecialchars($opt['label']) ?></div>
                            <div class="opt-sub"><?= htmlspecialchars($opt['sub']) ?></div>
                        </div>
                    </div>
                    <span class="opt-arrow">→</span>
                </a>
            <?php endforeach; ?>
            </div>
        </div>
    </main>
    </div>
    <?= anti_devtools_script() ?>
    </body></html>
    <?php
}

// Màn chờ sau khi bấm "Lấy key miễn phí": user nhận phản hồi ngay trong
// khi server chuẩn bị bước shortlink, thay vì bị redirect đột ngột.
function render_getkey_loading(string $nextUrl, ?array $notice): void {
    ?>
    <!DOCTYPE html><html lang="vi"><head>
    <?= shared_page_head('Đang tạo link') ?>
    <style>
    .loader {
        width: 52px; height: 52px; margin: 0 auto var(--sp-4);
        border: 3px solid rgba(89,245,213,.15); border-top-color: var(--ds-cyan);
        border-right-color: var(--ds-violet); border-radius: 50%;
        animation: ds-spin .8s linear infinite;
    }
    .loading-bar { height: 5px; margin: var(--sp-5) 0 0; overflow: hidden; border-radius: var(--r-full); background: var(--ds-surface2); }
    .loading-fill { width: 0; height: 100%; border-radius: inherit; background: var(--grad-btn); animation: ds-fill 1.1s ease forwards; }
    </style>
    </head><body class="ds-page">
    <div class="ds-page-inner">
    <main class="ds-card" style="animation:ds-rise .4s var(--ease-out)">
        <div class="ds-holo"></div>
        <div class="ds-card-body">
            <div class="loader"></div>
            <div class="ds-eyebrow" style="margin:0 auto var(--sp-2)">AUTH <span>LOADING</span></div>
            <h1 style="font:var(--fw-bold) var(--fs-xl) var(--font-body);letter-spacing:-.03em;margin:0 0 var(--sp-2)">Đang tạo link</h1>
            <p style="font-size:13px;color:var(--ds-text-dim);line-height:var(--lh-relaxed);margin:0">Hệ thống đang chuẩn bị nhiệm vụ cho bạn.</p>
            <div class="loading-bar"><div class="loading-fill"></div></div>
            <?php if ($notice): ?>
            <div class="ds-notice ds-notice--<?= htmlspecialchars($notice['type'] ?? 'info') ?>" style="margin-top:var(--sp-4);text-align:left">
                <b><?= htmlspecialchars($notice['title']) ?></b>
                <p style="margin:2px 0 0;font-size:12px"><?= nl2br(htmlspecialchars($notice['message'])) ?></p>
            </div>
            <?php endif; ?>
        </div>
    </main>
    </div>
    <script>setTimeout(() => { location.href = <?= json_encode($nextUrl) ?>; }, 900);</script>
    <?= anti_devtools_script() ?>
    </body></html>
    <?php
}

function render_channel_gate(array $game, array $channels, string $token, string $gateToken, ?array $notice, string $error = '', array $openedChannels = [], bool $isAdmin = false): void {
    $typeIcons = ['youtube' => '▶', 'tiktok' => '♪', 'telegram' => '✈', 'facebook' => 'f', 'discord' => '◉', 'instagram' => '◎', 'other' => '↗'];
    $wait = CHANNEL_WAIT_SECONDS;
    ?>
    <!DOCTYPE html><html lang="vi"><head>
    <?= shared_page_head('Nhiệm vụ kênh') ?>
    <style>
    .channel {
        display: flex; align-items: center; gap: 12px; margin-top: 10px; padding: 12px 14px;
        border: 1px solid var(--ds-line); border-radius: var(--r-md);
        background: rgba(var(--ds-surface-rgb), 0.65); transition: border-color var(--dur-fast);
        text-align: left;
    }
    .channel:hover { border-color: var(--ds-line-strong); }
    .channel-icon {
        width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;
        border-radius: var(--r-sm); background: rgba(0, 242, 254, .1); border: 1px solid rgba(0, 242, 254, .2);
        color: var(--ds-cyan); font-weight: 700; flex-shrink: 0;
    }
    .channel-main { min-width: 0; flex: 1; }
    .channel-title { font: var(--fw-bold) 13px var(--font-body); color: var(--ds-text); }
    .channel-requirement { margin-top: 2px; font-size: 11px; color: var(--ds-text-dim); }
    .channel a.gate-btn {
        display: inline-flex; align-items: center; justify-content: center; min-width: 104px; min-height: 38px;
        padding: 6px 14px; border-radius: var(--r-sm); background: var(--grad-btn); color: #060810;
        font: var(--fw-bold) 12px var(--font-body); text-decoration: none; transition: transform var(--dur-fast), filter var(--dur-fast), box-shadow var(--dur-fast);
        flex-shrink: 0; white-space: nowrap; box-shadow: var(--shadow-btn); letter-spacing: 0.02em;
    }
    .channel a.gate-btn:hover { filter: brightness(1.1); transform: translateY(-1px); }
    .channel a.gate-btn:active { transform: scale(.96); }
    .channel a.gate-btn.tg-btn {
        background: linear-gradient(135deg, #0088cc 0%, #00b4d8 100%);
        color: #ffffff;
        box-shadow: 0 4px 14px rgba(0, 136, 204, 0.4);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }
    .channel a.gate-btn.tg-btn:hover {
        box-shadow: 0 6px 20px rgba(0, 136, 204, 0.6);
        filter: brightness(1.12);
    }
    .channel a.gate-btn.waiting {
        background: var(--ds-surface2); color: var(--ds-cyan); border: 1px solid rgba(0, 242, 254, .45); box-shadow: none;
        animation: pulse-gate 2s infinite ease-in-out;
    }
    @keyframes pulse-gate {
        0%, 100% { box-shadow: 0 0 0 0 rgba(0, 242, 254, 0.4); border-color: rgba(0, 242, 254, 0.4); }
        50% { box-shadow: 0 0 0 5px rgba(0, 242, 254, 0.1); border-color: rgba(0, 242, 254, 0.85); }
    }
    .channel a.gate-btn.done {
        background: rgba(97,230,164,.18); color: var(--ds-success); border: 1px solid rgba(97,230,164,.45);
        box-shadow: 0 2px 8px rgba(97,230,164,0.2);
    }
    .wait-dot {
        display: inline-block; width: 6px; height: 6px; margin-right: 6px; border-radius: 50%;
        background: var(--ds-cyan); animation: ds-blink 1s infinite;
    }
    .check { width: 18px; height: 18px; accent-color: var(--ds-cyan); cursor: pointer; flex-shrink: 0; }
    .channel-error {
        margin-top: 14px; padding: 10px 14px; border-radius: var(--r-md);
        border: 1px solid rgba(255,120,133,.3); background: rgba(255,120,133,.08); color: var(--ds-danger);
        font-size: 12px; line-height: 1.5; text-align: left;
    }
    .gate-hint { text-align: center; margin-top: 14px; font-size: 11px; color: var(--ds-text-muted); }
    .direct-tg-box {
        margin-top: 14px;
        padding: 12px 14px;
        border-radius: var(--r-md);
        background: rgba(0, 136, 204, 0.09);
        border: 1px solid rgba(0, 136, 204, 0.35);
        text-align: left;
        box-shadow: 0 4px 18px rgba(0, 136, 204, 0.12);
        animation: ds-rise .3s ease-out;
    }
    .direct-tg-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 7px;
        font-size: 11.5px;
    }
    .direct-tg-title {
        color: #fff;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .direct-tg-help {
        color: var(--ds-cyan);
        text-decoration: underline;
        font-size: 11px;
        font-weight: 500;
    }
    .direct-tg-help:hover {
        filter: brightness(1.2);
    }
    .direct-tg-input-wrap {
        display: flex;
        gap: 8px;
    }
    .direct-tg-input-wrap input {
        flex: 1;
        background: rgba(6, 8, 16, 0.85);
        border: 1px solid rgba(0, 136, 204, 0.3);
        border-radius: var(--r-sm);
        padding: 9px 12px;
        font-size: 12.5px;
        color: #fff;
        outline: none;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .direct-tg-input-wrap input:focus {
        border-color: #0088cc;
        box-shadow: 0 0 0 3px rgba(0, 136, 204, 0.25);
    }
    .direct-tg-input-wrap button {
        background: linear-gradient(135deg, #0088cc 0%, #00b4d8 100%);
        color: #fff;
        border: none;
        border-radius: var(--r-sm);
        padding: 9px 16px;
        font: var(--fw-bold) 12px var(--font-body);
        cursor: pointer;
        white-space: nowrap;
        box-shadow: 0 4px 12px rgba(0, 136, 204, 0.4);
        transition: filter 0.2s, transform 0.1s;
    }
    .direct-tg-input-wrap button:hover {
        filter: brightness(1.12);
        transform: translateY(-1px);
    }
    .direct-tg-input-wrap button:active {
        transform: scale(0.97);
    }
    .direct-tg-input-wrap button:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }
    .direct-tg-msg {
        margin-top: 8px;
        font-size: 11.5px;
        line-height: 1.5;
        padding: 7px 10px;
        border-radius: 4px;
        display: none;
    }
    .direct-tg-msg.ok {
        background: rgba(97, 230, 164, 0.16);
        border: 1px solid rgba(97, 230, 164, 0.4);
        color: var(--ds-success);
        display: block;
    }
    .direct-tg-msg.err {
        background: rgba(255, 120, 133, 0.14);
        border: 1px solid rgba(255, 120, 133, 0.35);
        color: var(--ds-danger);
        display: block;
    }
    .direct-tg-msg.info {
        background: rgba(0, 242, 254, 0.12);
        border: 1px solid rgba(0, 242, 254, 0.3);
        color: var(--ds-cyan);
        display: block;
    }
    .bot-start-alert {
        margin-top: 14px;
        padding: 14px 16px;
        border-radius: var(--r-md);
        background: linear-gradient(135deg, rgba(0, 136, 204, 0.18) 0%, rgba(139, 92, 246, 0.18) 100%);
        border: 1.5px solid rgba(0, 242, 254, 0.45);
        box-shadow: 0 8px 24px -6px rgba(0, 136, 204, 0.35);
        text-align: left;
        position: relative;
        overflow: hidden;
        animation: ds-rise .35s ease-out;
    }
    .bot-start-alert::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0; height: 3px;
        background: linear-gradient(90deg, #0088cc, #00f2fe, #a855f7, #ec4899);
    }
    .bot-start-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 8px;
        border-radius: 4px;
        background: rgba(255, 184, 0, 0.2);
        border: 1px solid rgba(255, 184, 0, 0.45);
        color: #ffc107;
        font: 700 11px var(--font-mono);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 6px;
    }
    .bot-start-title {
        font: var(--fw-bold) 13.5px var(--font-body);
        color: #ffffff;
        letter-spacing: -0.01em;
        line-height: 1.4;
        margin: 0 0 6px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .bot-start-desc {
        font-size: 12px;
        color: rgba(255, 255, 255, 0.85);
        line-height: 1.55;
        margin: 0 0 12px;
    }
    .bot-start-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        padding: 11px 16px;
        border-radius: var(--r-sm);
        background: linear-gradient(135deg, #0088cc 0%, #00b4d8 45%, #7b2cbf 100%);
        color: #ffffff;
        font: var(--fw-bold) 12.5px var(--font-body);
        text-decoration: none;
        box-shadow: 0 4px 16px rgba(0, 136, 204, 0.45);
        transition: transform var(--dur-fast), filter var(--dur-fast), box-shadow var(--dur-fast);
        border: 1px solid rgba(255, 255, 255, 0.25);
        box-sizing: border-box;
    }
    .bot-start-btn:hover {
        filter: brightness(1.15);
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0, 136, 204, 0.6);
    }
    .bot-start-btn:active {
        transform: scale(0.98);
    }
    .bot-start-footer {
        margin-top: 8px;
        font-size: 10.5px;
        color: var(--ds-text-dim);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    @media (max-width: 420px) {
        .channel { gap: 8px; padding: 10px; }
        .channel a.gate-btn { min-width: 80px; font-size: 11px; padding: 6px 8px; }
        .direct-tg-input-wrap { flex-direction: column; }
        .bot-start-footer { flex-direction: column; gap: 4px; align-items: flex-start; }
    }
    </style>
    </head><body class="ds-page">
    <div class="ds-page-inner" style="max-width:480px">
    <main class="ds-card" style="animation:ds-rise .4s var(--ease-out)">
        <div class="ds-holo"></div>
        <div class="ds-card-body">
            <div class="ds-eyebrow" style="margin:0 auto var(--sp-2)">AUTH <span>GATE</span></div>
            <h1 style="font:var(--fw-bold) var(--fs-xl) var(--font-body);letter-spacing:-.03em;margin:0 0 var(--sp-2)">Yêu cầu nhiệm vụ</h1>
            <p style="font-size:13px;color:var(--ds-text-dim);line-height:var(--lh-relaxed);margin:0">Vui lòng thực hiện các nhiệm vụ bên dưới để kích hoạt nút Lấy Key.</p>
            <div class="ds-step-pill" style="margin-top:var(--sp-3);background:rgba(156,140,255,.12);color:var(--ds-violet);border-color:rgba(156,140,255,.25)">
                <?= htmlspecialchars($game['icon'] ?? '🎮') ?> <?= htmlspecialchars($game['name'] ?? 'Game') ?>
            </div>
            <?php if ($notice): ?>
            <div class="ds-notice ds-notice--<?= htmlspecialchars($notice['type'] ?? 'info') ?>" style="margin-top:var(--sp-4);text-align:left">
                <b><?= htmlspecialchars($notice['title']) ?></b>
                <p style="margin:2px 0 0;font-size:12px"><?= nl2br(htmlspecialchars($notice['message'])) ?></p>
            </div>
            <?php endif; ?>
            <?php if ($error): ?><div class="channel-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="post" action="getkey.php?game=<?= urlencode($game['slug']) ?>" id="channelForm" style="margin-top:var(--sp-4)">
                <input type="hidden" name="channel_gate" value="1">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="gate_token" value="<?= htmlspecialchars($gateToken) ?>">
                <?php 
                    $tgBot = get_telegram_bot_config();
                    $hasTelegramTask = false;
                    $firstTgChannelId = 0;
                    foreach ($channels as $c) {
                        if ($c['type'] === 'telegram') {
                            $hasTelegramTask = true;
                            if (!$firstTgChannelId) $firstTgChannelId = (int)$c['id'];
                        }
                    }
                    $showBotBanner = ($tgBot['username'] !== '' || $hasTelegramTask);
                    $botStartDirectUrl = ($tgBot['username'] !== '')
                        ? ('https://t.me/' . $tgBot['username'] . '?start=' . $token . ($firstTgChannelId > 0 ? ('_' . $firstTgChannelId) : ''))
                        : 'https://t.me/hoquoc30';
                ?>

                <?php if ($showBotBanner): ?>
                <div class="bot-start-alert">
                    <div class="bot-start-badge">⚠️ BƯỚC BẮT BUỘC</div>
                    <div class="bot-start-title">
                        <span>🤖</span> VUI LÒNG MỞ BOT VÀ /START ĐỂ BOT XÁC NHẬN
                    </div>
                    <div class="bot-start-desc">
                        Để kích hoạt nút <b>Tạo Link Lấy Key</b>, bạn vui lòng bấm nút bên dưới mở Bot Telegram và bấm <b>START</b> để bot tự động kiểm tra xem bạn đã vào đủ các nhóm bắt buộc (<b>@hoquoc30</b>, <b>@AuraPingVN</b>...) hay chưa.
                    </div>
                    <a href="<?= htmlspecialchars($botStartDirectUrl) ?>" target="_blank" rel="noopener" class="bot-start-btn" id="botDirectStartLink" onclick="onBotDirectStartClick(this)">
                        <span>🚀</span> BẤM VÀO ĐÂY ĐỂ MỞ BOT &amp; /START XÁC NHẬN
                    </a>
                    <div class="bot-start-footer">
                        <span>⭐ Hỗ trợ tự động nhận diện Telegram Premium</span>
                        <span>⚡ Duyệt tự động 100%</span>
                    </div>
                </div>
                <?php endif; ?>
                <?php foreach ($channels as $channel):
                    $cid = (int)$channel['id'];
                    $tgVerifyRequired = ($channel['type'] === 'telegram' && !empty($channel['tg_chat_id']) && $tgBot['username'] !== '');
                    if ($tgVerifyRequired) {
                        $verifiedAt = is_channel_verified($token, $cid);
                        $isDone = $isAdmin || $verifiedAt !== null;
                        $remaining = null;
                        $channelHref = 'https://t.me/' . $tgBot['username'] . '?start=' . $token . '_' . $cid;
                    } else {
                        $openedAt = $openedChannels[$cid] ?? null;
                        $elapsed = $openedAt ? (time() - (int)$openedAt) : null;
                        $isDone = $isAdmin || ($elapsed !== null && $elapsed >= $wait);
                        $remaining = ($isDone || $elapsed === null) ? null : max(0, $wait - $elapsed);
                        $channelHref = $channel['url'];
                    }
                ?>
                <div class="channel">
                    <div class="channel-icon"><?= $typeIcons[$channel['type']] ?? '↗' ?></div>
                    <div class="channel-main">
                        <div class="channel-title"><?= htmlspecialchars($channel['label']) ?><?= $tgVerifyRequired ? ' <span style="font-size:10px;color:var(--ds-cyan);font-weight:400">· bot verify</span>' : '' ?></div>
                        <div class="channel-requirement"><?= htmlspecialchars($channel['requirement'] ?: 'Tham gia kênh') ?></div>
                    </div>
                    <a href="<?= htmlspecialchars($channelHref) ?>" target="_blank" rel="noopener"
                        data-channel-open="<?= $cid ?>"
                        data-verify="<?= $tgVerifyRequired ? '1' : '0' ?>"
                        data-remaining="<?= $isDone ? 0 : (int)($remaining ?? -1) ?>"
                        class="gate-btn <?= $isDone ? 'done' : ($remaining !== null || $tgVerifyRequired ? 'waiting' : '') ?> <?= $tgVerifyRequired && !$isDone ? 'tg-btn' : '' ?>"
                    ><?= $isDone ? '✓ Đã tham gia' : ($tgVerifyRequired ? '🤖 Vào Bot Xác Minh' : ($remaining !== null ? 'Chờ ' . (int)$remaining . 's' : 'Tham Gia')) ?></a>
                    <input class="check" type="checkbox" name="channel_done[]" value="<?= $cid ?>" <?= $isDone ? 'checked' : 'disabled' ?> aria-label="Đã hoàn thành <?= htmlspecialchars($channel['label']) ?>">
                </div>
                <?php endforeach; ?>

                <?php if ($hasTelegramTask): ?>
                <div class="direct-tg-box">
                    <div class="direct-tg-head">
                        <span class="direct-tg-title">✈ <b>Xác nhận nhanh bằng Username hoặc ID:</b></span>
                        <a href="https://t.me/userinfobot" target="_blank" rel="noopener" class="direct-tg-help" title="Lấy ID số của bạn tại @userinfobot">Lấy ID ở đâu?</a>
                    </div>
                    <div class="direct-tg-input-wrap">
                        <input type="text" id="tgIdentifierInput" placeholder="Nhập @username hoặc ID số (vd: 123456789)..." autocomplete="off">
                        <button type="button" id="tgDirectVerifyBtn" onclick="runDirectTgVerify()">Kiểm Tra</button>
                    </div>
                    <div id="tgDirectVerifyMsg" class="direct-tg-msg"></div>
                </div>
                <?php endif; ?>

                <button class="ds-btn" id="continueBtn" type="submit" disabled style="margin-top:var(--sp-5);width:100%">🔒 &nbsp;Xác Nhận &amp; Lấy Key</button>
            </form>
            <div class="gate-hint">Bấm Tham Gia và chờ đủ <?= $wait ?> giây để xác nhận nhiệm vụ.</div>
        </div>
    </main>
    </div>
    <script>
    const checks=[...document.querySelectorAll('.check')],btn=document.getElementById('continueBtn'),openWait=<?= $wait ?>,openToken=<?= json_encode($token) ?>,gateToken=<?= json_encode($gateToken) ?>,gameSlug=<?= json_encode($game['slug']) ?>;
    function sync(){const ready=checks.length>0&&checks.every(c=>c.checked);btn.disabled=!ready;btn.classList.toggle('ready',ready);btn.innerHTML=ready?'🚀 &nbsp;Tạo Link':'🔒 &nbsp;Xác Nhận &amp; Lấy Key'}
    function startCountdown(link,left){
        const box=link.parentElement.querySelector('.check');
        link.dataset.started='1';link.classList.add('waiting');link.classList.remove('done');
        link.innerHTML='<span class="wait-dot"></span> Chờ '+left+'s';
        const timer=setInterval(()=>{left--;link.innerHTML='<span class="wait-dot"></span> Chờ '+left+'s';
            if(left<=0){clearInterval(timer);box.disabled=false;box.checked=true;link.classList.remove('waiting');link.classList.add('done');link.textContent='✓ Đã tham gia';sync()}
        },1000);
    }
    document.querySelectorAll('[data-channel-open]').forEach(link=>{
        const remaining=parseInt(link.dataset.remaining,10);
        if(link.classList.contains('done')){
            link.dataset.started='1';
        } else if(link.dataset.verify==='1'){
            link.dataset.started='1';
            startVerifyPoll(link);
        } else if(remaining>=0){
            startCountdown(link,remaining);
        }
    });
    sync();
    function startVerifyPoll(link){
        const box=link.parentElement.querySelector('.check');
        const cid=link.dataset.channelOpen;
        const poll=setInterval(()=>{
            fetch('getkey.php?channel_verify_poll=1&game='+encodeURIComponent(gameSlug)+'&token='+encodeURIComponent(openToken)+'&gate_token='+encodeURIComponent(gateToken)+'&channel='+encodeURIComponent(cid),{credentials:'same-origin',cache:'no-store'})
                .then(r=>r.json()).then(data=>{
                    if(data.verified){
                        clearInterval(poll);
                        box.disabled=false;box.checked=true;
                        link.classList.remove('waiting');link.classList.add('done');
                        link.textContent='✓ Đã tham gia';
                        sync();
                    }
                }).catch(()=>{});
        },2500);
    }
    document.querySelectorAll('[data-channel-open]').forEach(link=>link.addEventListener('click',event=>{
        if(link.dataset.verify==='1'){
            if(link.dataset.started!=='1'){
                link.dataset.started='1';
                link.classList.add('waiting');
                startVerifyPoll(link);
            }
            return;
        }
        event.preventDefault();
        if(link.dataset.started==='1')return;
        const id=link.dataset.channelOpen,popup=window.open(link.href,'_blank','noopener');
        if(!popup)window.location.href=link.href;
        fetch('getkey.php?game='+encodeURIComponent(gameSlug)+'&channel_open='+encodeURIComponent(id)+'&token='+encodeURIComponent(openToken)+'&gate_token='+encodeURIComponent(gateToken),{credentials:'same-origin',cache:'no-store'}).catch(()=>{});
        startCountdown(link,openWait);
    }));

    function onBotDirectStartClick(elem) {
        if (!elem) return;
        elem.classList.add('waiting');
        elem.innerHTML = '<span class="wait-dot"></span> ĐANG CHỜ BOT XÁC NHẬN... VUI LÒNG BẤM START';
        
        // Kích hoạt ngay polling cho tất cả các nút Telegram trên trang
        document.querySelectorAll('[data-verify="1"]').forEach(link => {
            if (link.dataset.started !== '1') {
                link.dataset.started = '1';
                link.classList.add('waiting');
                startVerifyPoll(link);
            }
        });

        // Polling kiểm tra chung
        const globalPoll = setInterval(() => {
            fetch('getkey.php?channel_verify_poll=1&game=' + encodeURIComponent(gameSlug) + '&token=' + encodeURIComponent(openToken) + '&gate_token=' + encodeURIComponent(gateToken) + '&channel=0', { credentials: 'same-origin', cache: 'no-store' })
                .then(r => r.json()).then(data => {
                    if (data.verified) {
                        clearInterval(globalPoll);
                        elem.classList.remove('waiting');
                        elem.classList.add('done');
                        elem.style.background = 'rgba(97,230,164,.2)';
                        elem.style.borderColor = 'rgba(97,230,164,.5)';
                        elem.style.color = 'var(--ds-success)';
                        elem.innerHTML = '✓ ĐÃ XÁC NHẬN XONG TRÊN TELEGRAM!';
                        document.querySelectorAll('[data-verify="1"]').forEach(link => {
                            const box = link.parentElement.querySelector('.check');
                            if (box) { box.disabled = false; box.checked = true; }
                            link.classList.remove('waiting');
                            link.classList.add('done');
                            link.textContent = '✓ Đã tham gia';
                        });
                        sync();
                    }
                }).catch(() => {});
        }, 2000);
    }

    function runDirectTgVerify() {
        const input = document.getElementById('tgIdentifierInput');
        const btn = document.getElementById('tgDirectVerifyBtn');
        const msg = document.getElementById('tgDirectVerifyMsg');
        if (!input || !btn || !msg) return;
        const val = (input.value || '').trim();
        if (!val) {
            msg.className = 'direct-tg-msg err';
            msg.textContent = 'Vui lòng nhập @username hoặc ID số Telegram của bạn.';
            input.focus();
            return;
        }
        btn.disabled = true;
        btn.textContent = 'Đang kiểm tra...';
        msg.className = 'direct-tg-msg info';
        msg.textContent = 'Đang kết nối tới Telegram để kiểm tra thành viên...';

        fetch('getkey.php?direct_tg_verify=1&game=' + encodeURIComponent(gameSlug) + '&token=' + encodeURIComponent(openToken) + '&gate_token=' + encodeURIComponent(gateToken) + '&identifier=' + encodeURIComponent(val), {
            credentials: 'same-origin', cache: 'no-store'
        })
        .then(r => {
            if (!r.ok) {
                return r.text().then(t => { throw new Error('HTTP ' + r.status); });
            }
            return r.json();
        })
        .then(data => {
            btn.disabled = false;
            btn.textContent = 'Kiểm Tra';
            if (data.success && data.verified) {
                msg.className = 'direct-tg-msg ok';
                if (data.is_premium) {
                    msg.innerHTML = (data.message || '✅ Đã xác nhận thành công!') + ' <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:12px;background:linear-gradient(135deg, rgba(168,85,247,0.25), rgba(59,130,246,0.25));border:1px solid rgba(168,85,247,0.45);color:#d8b4fe;font-size:11px;font-weight:700">⭐ Telegram Premium</span>';
                } else {
                    msg.textContent = data.message || '✅ Đã xác nhận thành công!';
                }
                // Tự động tích xanh tất cả các checkbox nhiệm vụ Telegram
                document.querySelectorAll('[data-verify="1"]').forEach(link => {
                    const box = link.parentElement.querySelector('.check');
                    if (box) { box.disabled = false; box.checked = true; }
                    link.classList.remove('waiting');
                    link.classList.add('done');
                    link.textContent = '✓ Đã tham gia';
                });
                sync();
            } else {
                msg.className = 'direct-tg-msg err';
                msg.textContent = data.message || 'Chưa tham gia đủ các nhóm yêu cầu.';
            }
        })
        .catch(err => {
            console.error('Direct TG verify error:', err);
            btn.disabled = false;
            btn.textContent = 'Kiểm Tra';
            msg.className = 'direct-tg-msg err';
            msg.textContent = 'Lỗi kết nối máy chủ. Vui lòng thử lại hoặc bấm vào bot để xác nhận.';
        });
    }

    const tgInputElem = document.getElementById('tgIdentifierInput');
    if (tgInputElem) {
        tgInputElem.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                runDirectTgVerify();
            }
        });
    }
    </script>
    <?= anti_devtools_script() ?>
    </body></html>
    <?php
}
