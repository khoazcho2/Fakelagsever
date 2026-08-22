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
if (session_status() === PHP_SESSION_NONE) session_start();

$slug = trim($_GET['game'] ?? '');
$game = $slug !== '' ? get_game_by_slug($slug) : null;

if (!$game || !$game['enabled']) {
    http_response_code(404);
    render_notice_screen('Game không tồn tại', 'Link lấy key không hợp lệ hoặc game đang tạm ngưng cấp key. Vui lòng vào trang chủ để chọn game.');
    exit;
}

$db = get_db();
$clientIp = get_client_ip();
$channels = get_channels(true);
$publicNotice = get_active_notice();

// Chặn ngay nếu IP này đang bị khoá do nghi ngờ bypass ở bước vượt link
if (is_ip_blocked($clientIp)) {
    render_blocked_screen();
    exit;
}

// Giới hạn: mỗi trình duyệt chỉ được lấy key thành công 1 lần/ngày/game
$claimCookie = 'gk_claimed_' . $slug;
if (!empty($_COOKIE[$claimCookie]) || ip_already_claimed_today((int)$game['id'], $clientIp)) {
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
        render_channel_gate($game, $channels, $pendingToken, $expectedGate, $publicNotice, 'Hãy mở và xác nhận đầy đủ tất cả kênh trước khi tiếp tục.');
        exit;
    }

    $_SESSION['channel_completed'][$pendingToken] = time();
    unset($_SESSION['channel_gate'][$pendingToken]);
    $db->prepare("UPDATE keys SET channel_gate_completed_at = ? WHERE token = ? AND status = 'pending'")
        ->execute([time(), $pendingToken]);
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
    $stmt = $db->prepare("SELECT token, channel_gate_completed_at FROM keys WHERE token = ? AND status = 'pending' AND current_hop < total_hops");
    $stmt->execute([$_COOKIE[$pendingCookie]]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        if (!empty($channels) && empty($existing['channel_gate_completed_at'])) {
            $gateToken = $_SESSION['channel_gate'][$existing['token']] ?? random_string(32);
            $_SESSION['channel_gate'][$existing['token']] = $gateToken;
            render_channel_gate($game, $channels, $existing['token'], $gateToken, $publicNotice);
            exit;
        }
        render_getkey_loading(BASE_URL . '/hop.php?token=' . urlencode($existing['token']), $publicNotice);
        exit;
    }
    // Token cũ không còn hợp lệ, hoặc đã vượt xong hết bước rồi -> xoá
    // cookie, bắt buộc tạo key mới + vượt link lại từ đầu bên dưới.
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
$keycode = generate_keycode();

$stmt = $db->prepare("INSERT INTO keys
    (keycode, token, status, duration_seconds, max_devices, created_at, game_id, region, total_hops, current_hop, chain, client_ip)
    VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, 0, ?, ?)");
$stmt->execute([
    $keycode, $token, $hours * 3600, DEFAULT_MAX_DEVICES, time(),
    $game['id'], $region, count($chain), implode(',', $chain), $clientIp,
]);

// Nhớ key pending này 1 giờ (đủ thời gian vượt link), tránh F5 tạo key mới
setcookie($pendingCookie, $token, time() + 3600, '/');

if (!empty($channels)) {
    $gateToken = random_string(32);
    $_SESSION['channel_gate'][$token] = $gateToken;
    render_channel_gate($game, $channels, $token, $gateToken, $publicNotice);
    exit;
}

render_getkey_loading(BASE_URL . '/hop.php?token=' . urlencode($token), $publicNotice);
exit;

// Màn chờ sau khi bấm "Lấy key miễn phí": user nhận phản hồi ngay trong
// khi server chuẩn bị bước shortlink, thay vì bị redirect đột ngột.
function render_getkey_loading(string $nextUrl, ?array $notice): void {
    ?>
    <!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1"><title>Đang tạo link</title>
    <?= shared_notice_head() ?>
    <style>
    :root{--bg:#070A10;--surface:#101620;--cyan:#59F5D5;--violet:#9C8CFF;--text:#F4F7FB;--dim:#93A1B5;--line:rgba(177,199,224,.14)}
    *{box-sizing:border-box}body{min-height:100vh;margin:0;padding:24px 16px;display:grid;place-items:center;font-family:"Inter",-apple-system,Arial,sans-serif;color:var(--text);background:radial-gradient(circle at 12% 0%,rgba(89,245,213,.16),transparent 28rem),radial-gradient(circle at 90% 12%,rgba(156,140,255,.13),transparent 26rem),var(--bg)}
    .card{width:min(100%,420px);overflow:hidden;border:1px solid var(--line);border-radius:24px;background:linear-gradient(145deg,rgba(22,31,44,.97),rgba(12,17,26,.99));box-shadow:0 30px 80px -30px #000,inset 0 1px rgba(255,255,255,.07);text-align:center}.holo{height:4px;background:linear-gradient(90deg,var(--cyan),var(--violet),#F0A6FF,var(--cyan));background-size:240% 100%;animation:shift 3s linear infinite}.content{padding:32px 26px 29px}.loader{width:56px;height:56px;margin:0 auto 17px;border:4px solid rgba(89,245,213,.14);border-top-color:var(--cyan);border-right-color:var(--violet);border-radius:50%;animation:spin .8s linear infinite}.eyebrow{font:10px "JetBrains Mono",monospace;letter-spacing:.16em;color:var(--cyan)}h1{font:700 24px "Space Grotesk",sans-serif;letter-spacing:-.03em;margin:10px 0 9px}p{margin:0;color:var(--dim);font-size:13px;line-height:1.65}.bar{height:5px;margin:20px 0 0;overflow:hidden;border-radius:999px;background:#202A38}.fill{width:0;height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--cyan),var(--violet));animation:fill 1.1s ease forwards}.notice{margin:18px 0 -4px;padding:11px 12px;border:1px solid rgba(89,245,213,.18);border-radius:12px;background:rgba(89,245,213,.06);text-align:left}.notice strong{display:block;margin-bottom:3px;font-size:11px}.notice p{font-size:11px}.notice.warning{border-color:rgba(245,201,105,.25);background:rgba(245,201,105,.07)}.notice.success{border-color:rgba(97,230,164,.25);background:rgba(97,230,164,.07)}@keyframes spin{to{transform:rotate(360deg)}}@keyframes shift{to{background-position:240% 0}}@keyframes fill{to{width:88%}}
    </style></head><body><main class="card"><div class="holo"></div><div class="content"><div class="loader"></div><div class="eyebrow">AUTH LOADING</div><h1>Đang tạo link</h1><p>Hệ thống đang chuẩn bị nhiệm vụ cho bạn.</p><div class="bar"><div class="fill"></div></div>
    <?php if ($notice): ?><aside class="notice <?= htmlspecialchars($notice['type']) ?>"><strong><?= htmlspecialchars($notice['title']) ?></strong><p><?= nl2br(htmlspecialchars($notice['message'])) ?></p></aside><?php endif; ?>
    </div></main><script>setTimeout(()=>location.href=<?= json_encode($nextUrl) ?>,900);</script></body></html>
    <?php
}

function render_channel_gate(array $game, array $channels, string $token, string $gateToken, ?array $notice, string $error = ''): void {
    $typeIcons = ['youtube' => '▶', 'tiktok' => '♪', 'telegram' => '✈', 'facebook' => 'f', 'discord' => '◉', 'instagram' => '◎', 'other' => '↗'];
    ?>
    <!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1"><title>Nhiệm vụ kênh</title>
    <?= shared_notice_head() ?>
    <style>
    :root{--bg:#070A10;--surface:#101620;--surface2:#151D2A;--cyan:#59F5D5;--violet:#9C8CFF;--text:#F4F7FB;--dim:#93A1B5;--line:rgba(177,199,224,.14);--danger:#FF9CA6}
    *{box-sizing:border-box}body{min-height:100vh;margin:0;padding:24px 16px;font-family:"Inter",-apple-system,Arial,sans-serif;color:var(--text);background:radial-gradient(circle at 12% 0%,rgba(89,245,213,.16),transparent 28rem),radial-gradient(circle at 90% 12%,rgba(156,140,255,.13),transparent 26rem),var(--bg)}.wrap{width:min(100%,520px);margin:0 auto}.brand{text-align:center;margin:7px 0 17px;color:#A9B6C8;font:10px "JetBrains Mono",monospace;letter-spacing:.16em}.brand b{color:var(--cyan)}.card{overflow:hidden;border:1px solid var(--line);border-radius:24px;background:linear-gradient(145deg,rgba(22,31,44,.97),rgba(12,17,26,.99));box-shadow:0 30px 80px -30px #000,inset 0 1px rgba(255,255,255,.07)}.holo{height:4px;background:linear-gradient(90deg,var(--cyan),var(--violet),#F0A6FF,var(--cyan));background-size:240% 100%;animation:shift 3s linear infinite}.content{padding:26px}.eyebrow{font:10px "JetBrains Mono",monospace;letter-spacing:.16em;color:var(--cyan)}h1{font:700 24px "Space Grotesk",sans-serif;letter-spacing:-.03em;margin:8px 0 7px}p{margin:0;color:var(--dim);font-size:13px;line-height:1.6}.game{display:inline-flex;align-items:center;gap:7px;margin-top:15px;padding:7px 10px;border-radius:999px;background:rgba(156,140,255,.1);color:#CAC3FF;font-size:11px}.channel{display:flex;align-items:center;gap:12px;margin-top:11px;padding:13px;border:1px solid var(--line);border-radius:14px;background:rgba(21,29,42,.65)}.channel-icon{width:37px;height:37px;display:grid;place-items:center;flex:0 0 auto;border-radius:12px;background:rgba(89,245,213,.1);border:1px solid rgba(89,245,213,.17);color:var(--cyan);font-weight:700}.channel-main{min-width:0;flex:1}.channel-title{font-size:13px;font-weight:700}.channel-requirement{margin-top:3px;font-size:11px;color:var(--dim)}.channel a{display:inline-flex;align-items:center;justify-content:center;min-width:66px;min-height:34px;padding:7px 9px;border-radius:9px;background:var(--surface2);border:1px solid var(--line);color:var(--text);font-size:11px;font-weight:700;text-decoration:none}.channel a:active{transform:scale(.96)}.check{width:18px;height:18px;accent-color:var(--cyan);cursor:pointer}.error{margin:15px 0 0;padding:10px 11px;border-radius:10px;border:1px solid rgba(255,156,166,.22);background:rgba(255,120,133,.08);color:var(--danger);font-size:11.5px}.notice{margin:16px 0 0;padding:11px 12px;border:1px solid rgba(89,245,213,.18);border-radius:12px;background:rgba(89,245,213,.06)}.notice strong{display:block;margin-bottom:3px;font-size:11px}.notice p{font-size:11px}.notice.warning{border-color:rgba(245,201,105,.25);background:rgba(245,201,105,.07)}.notice.success{border-color:rgba(97,230,164,.25);background:rgba(97,230,164,.07)}.btn{width:100%;min-height:48px;margin-top:20px;border:0;border-radius:13px;color:#071018;background:linear-gradient(110deg,var(--cyan),#B5FFF0 45%,var(--violet));box-shadow:0 12px 25px -14px rgba(89,245,213,.8);font:700 14px "Space Grotesk",sans-serif;cursor:pointer}.btn:disabled{cursor:not-allowed;opacity:.42;box-shadow:none}.hint{text-align:center;margin-top:12px;font-size:10.5px;color:#6F7E92}@keyframes shift{to{background-position:240% 0}}@media(max-width:420px){body{padding:16px 12px}.content{padding:22px 17px}.channel{gap:9px;padding:11px}.channel a{min-width:57px}}
    </style></head><body><main class="wrap"><div class="brand">HOQUOC <b>KEY VAULT</b></div><section class="card"><div class="holo"></div><div class="content"><div class="eyebrow">BƯỚC BẮT BUỘC</div><h1>Tham gia kênh</h1><p>Hoàn thành các nhiệm vụ bên dưới trước khi nhận link vượt key.</p><div class="game"><?= htmlspecialchars($game['icon'] ?? '🎮') ?> <?= htmlspecialchars($game['name'] ?? 'Game') ?></div>
    <?php if ($notice): ?><aside class="notice <?= htmlspecialchars($notice['type']) ?>"><strong><?= htmlspecialchars($notice['title']) ?></strong><p><?= nl2br(htmlspecialchars($notice['message'])) ?></p></aside><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post" action="getkey.php?game=<?= urlencode($game['slug']) ?>" id="channelForm"><input type="hidden" name="channel_gate" value="1"><input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"><input type="hidden" name="gate_token" value="<?= htmlspecialchars($gateToken) ?>">
    <?php foreach ($channels as $channel): ?><div class="channel"><div class="channel-icon"><?= $typeIcons[$channel['type']] ?? '↗' ?></div><div class="channel-main"><div class="channel-title"><?= htmlspecialchars($channel['label']) ?></div><div class="channel-requirement"><?= htmlspecialchars($channel['requirement'] ?: 'Tham gia kênh') ?></div></div><a href="<?= htmlspecialchars($channel['url']) ?>" target="_blank" rel="noopener" data-channel-open="<?= (int)$channel['id'] ?>">Mở kênh</a><input class="check" type="checkbox" name="channel_done[]" value="<?= (int)$channel['id'] ?>" aria-label="Đã hoàn thành <?= htmlspecialchars($channel['label']) ?>"></div><?php endforeach; ?>
    <button class="btn" id="continueBtn" type="submit" disabled>Đã tham gia tất cả — tiếp tục</button></form><div class="hint">Mở từng kênh, sau đó xác nhận để tạo link nhiệm vụ.</div></div></section></main>
    <script>const checks=[...document.querySelectorAll('.check')],btn=document.getElementById('continueBtn');function sync(){btn.disabled=!checks.length||!checks.every(c=>c.checked)}checks.forEach(c=>c.addEventListener('change',sync));document.querySelectorAll('[data-channel-open]').forEach(link=>link.addEventListener('click',()=>{const box=link.parentElement.querySelector('.check');box.checked=true;sync()}));</script></body></html>
    <?php
}
