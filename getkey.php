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

$slug = trim($_GET['game'] ?? '');

// ?r=<id> -> trang getkey RIÊNG của 1 reseller (index.php?r=<id> trỏ
// sang đây kèm r=). Không có/không hợp lệ -> coi như trang chính (admin).
$resellerId = null;
if (isset($_GET['r']) && ctype_digit((string)$_GET['r'])) {
    $rr = get_reseller_by_id((int)$_GET['r']);
    if ($rr && $rr['enabled']) $resellerId = (int)$_GET['r'];
}
$rParam = $resellerId !== null ? ('&r=' . $resellerId) : '';

$game = $slug !== '' ? get_game_by_slug($slug, $resellerId) : null;

if (!$game || !$game['enabled']) {
    http_response_code(404);
    render_notice_screen('Game không tồn tại', 'Link lấy key không hợp lệ hoặc game đang tạm ngưng cấp key. Vui lòng vào trang chủ để chọn game.');
    exit;
}

$db = get_db();
$clientIp = get_client_ip();
$linkFp = get_link_gen_fp();
$channels = get_channels(true, $resellerId);
$publicNotice = get_active_notice();

// Endpoint nhẹ để JS polling trạng thái xác minh Telegram thật (được Bot
// ghi nhận qua telegram_webhook.php sau khi user /start và đã ở trong
// nhóm). KHÔNG tính giờ đếm ngược cho tới khi endpoint này báo verified.
if (isset($_GET['channel_verify_poll'])) {
    header('Content-Type: application/json');
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
        render_channel_gate($game, $channels, $pendingToken, $expectedGate, $publicNotice, 'Hãy mở và xác nhận đầy đủ tất cả kênh trước khi tiếp tục.', $_SESSION['channel_opened'][$pendingToken] ?? [], $isAdmin, $rParam);
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
        render_channel_gate($game, $channels, $pendingToken, $expectedGate, $publicNotice, 'Vui lòng hoàn thành đầy đủ (xác minh Telegram/chờ đủ giây) cho từng kênh.', $openedChannels, $isAdmin, $rParam);
        exit;
    }

    $_SESSION['channel_completed'][$pendingToken] = time();
    unset($_SESSION['channel_gate'][$pendingToken]);
    unset($_SESSION['channel_opened'][$pendingToken]);
    $db->prepare("UPDATE keys SET channel_gate_completed_at = ?, channel_gate_signature = ? WHERE token = ? AND status = 'pending'")
        ->execute([time(), channels_signature($channels), $pendingToken]);
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
            render_channel_gate($game, $channels, $existing['token'], $gateToken, $publicNotice, '', $_SESSION['channel_opened'][$existing['token']] ?? [], $isAdmin, $rParam);
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
    render_key_type_select($game, $publicNotice, $rParam);
    exit;
}
$typeInfo = KEY_TYPE_OPTIONS[$selectedKeyType];

$cfg = get_shortener_config($resellerId);
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
    (keycode, token, status, duration_seconds, max_devices, created_at, game_id, region, total_hops, current_hop, chain, client_ip, client_platform, reseller_id)
    VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)");
$stmt->execute([
    $keycode, $token, $hours * 3600, DEFAULT_MAX_DEVICES, time(),
    $game['id'], $region, count($chain), implode(',', $chain), $clientIp, detect_client_platform(), $resellerId,
]);
record_link_generation_attempt($clientIp, $linkFp);

// Nhớ key pending này 1 giờ (đủ thời gian vượt link), tránh F5 tạo key mới
setcookie($pendingCookie, $token, time() + 3600, '/');

if (!empty($channels)) {
    $gateToken = random_string(32);
    $_SESSION['channel_gate'][$token] = $gateToken;
    render_channel_gate($game, $channels, $token, $gateToken, $publicNotice, '', [], $isAdmin, $rParam);
    exit;
}

render_getkey_loading(BASE_URL . '/hop.php?token=' . urlencode($token), $publicNotice);
exit;

// Màn BẮT BUỘC chọn loại Key, hiện TRƯỚC KHI tạo key, áp dụng cho mọi
// game (có nhiệm vụ kênh hay không cũng phải chọn ở đây trước).
function render_key_type_select(array $game, ?array $notice, string $rParam = ''): void {
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
                <a class="opt" href="getkey.php?game=<?= urlencode($game['slug']) ?>&key_type=<?= urlencode($value) ?><?= $rParam ?>">
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

function render_channel_gate(array $game, array $channels, string $token, string $gateToken, ?array $notice, string $error = '', array $openedChannels = [], bool $isAdmin = false, string $rParam = ''): void {
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
        display: inline-flex; align-items: center; justify-content: center; min-width: 96px; min-height: 36px;
        padding: 6px 12px; border-radius: var(--r-sm); background: var(--grad-btn); color: #060810;
        font: var(--fw-bold) 12px var(--font-body); text-decoration: none; transition: transform var(--dur-fast), filter var(--dur-fast);
        flex-shrink: 0; white-space: nowrap; box-shadow: var(--shadow-btn);
    }
    .channel a.gate-btn:hover { filter: brightness(1.08); }
    .channel a.gate-btn:active { transform: scale(.96); }
    .channel a.gate-btn.waiting {
        background: var(--ds-surface2); color: var(--ds-cyan); border: 1px solid rgba(0, 242, 254, .35); box-shadow: none;
    }
    .channel a.gate-btn.done {
        background: rgba(97,230,164,.15); color: var(--ds-success); border: 1px solid rgba(97,230,164,.35);
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
    @media (max-width: 420px) {
        .channel { gap: 8px; padding: 10px; }
        .channel a.gate-btn { min-width: 80px; font-size: 11px; padding: 6px 8px; }
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
            <form method="post" action="getkey.php?game=<?= urlencode($game['slug']) . $rParam ?>" id="channelForm" style="margin-top:var(--sp-4)">
                <input type="hidden" name="channel_gate" value="1">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="gate_token" value="<?= htmlspecialchars($gateToken) ?>">
                <?php $tgBot = get_telegram_bot_config(); ?>
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
                        class="gate-btn <?= $isDone ? 'done' : ($remaining !== null || $tgVerifyRequired ? 'waiting' : '') ?>"
                    ><?= $isDone ? '✓ Đã tham gia' : ($tgVerifyRequired ? 'Tham Gia (Bot)' : ($remaining !== null ? 'Chờ ' . (int)$remaining . 's' : 'Tham Gia')) ?></a>
                    <input class="check" type="checkbox" name="channel_done[]" value="<?= $cid ?>" <?= $isDone ? 'checked' : 'disabled' ?> aria-label="Đã hoàn thành <?= htmlspecialchars($channel['label']) ?>">
                </div>
                <?php endforeach; ?>
                <button class="ds-btn" id="continueBtn" type="submit" disabled style="margin-top:var(--sp-5);width:100%">🔒 &nbsp;Xác Nhận &amp; Lấy Key</button>
            </form>
            <div class="gate-hint">Bấm Tham Gia và chờ đủ <?= $wait ?> giây để xác nhận nhiệm vụ.</div>
        </div>
    </main>
    </div>
    <script>
    const checks=[...document.querySelectorAll('.check')],btn=document.getElementById('continueBtn'),openWait=<?= $wait ?>,openToken=<?= json_encode($token) ?>,gateToken=<?= json_encode($gateToken) ?>,gameSlug=<?= json_encode($game['slug']) ?>,rParam=<?= json_encode($rParam) ?>;
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
            fetch('getkey.php?channel_verify_poll=1&token='+encodeURIComponent(openToken)+'&gate_token='+encodeURIComponent(gateToken)+'&channel='+encodeURIComponent(cid),{credentials:'same-origin',cache:'no-store'})
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
        fetch('getkey.php?game='+encodeURIComponent(gameSlug)+'&channel_open='+encodeURIComponent(id)+'&token='+encodeURIComponent(openToken)+'&gate_token='+encodeURIComponent(gateToken)+rParam,{credentials:'same-origin',cache:'no-store'}).catch(()=>{});
        startCountdown(link,openWait);
    }));
    </script>
    <?= anti_devtools_script() ?>
    </body></html>
    <?php
}
