<?php
// ============================================================
// reseller.php - Dashboard riêng cho tài khoản Reseller (do admin tạo
// ở admin.php > Quản lý Reseller). Mỗi reseller có Cấu hình Link, Cấu
// hình kênh, Cấu hình Game và Tạo Key HOÀN TOÀN RIÊNG (tách biệt qua
// cột reseller_id), nhưng vẫn dùng chung bảng `keys` + luồng
// getkey.php/hop.php/confirm.php với hệ thống chính.
//
// Trang getkey công khai riêng cho reseller: index.php?r=<id> và
// getkey.php?game=<slug>&r=<id>
// ============================================================
require_once __DIR__ . '/config.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: frame-ancestors 'none'");

define('RESELLER_SESSION_TIMEOUT', 15 * 60);

if (isset($_SESSION['reseller_id']) && isset($_SESSION['reseller_last_activity'])) {
    if (time() - $_SESSION['reseller_last_activity'] > RESELLER_SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        session_start();
        $timedOut = true;
    }
}
$_SESSION['reseller_last_activity'] = time();

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: reseller.php');
    exit;
}

$maxAttempts = 5;
$lockSeconds = 5 * 60;
$isLocked = isset($_SESSION['reseller_lock_until']) && time() < $_SESSION['reseller_lock_until'];

if (!$isLocked && isset($_POST['reseller_login_username'], $_POST['reseller_login_password'])) {
    $account = verify_reseller_login($_POST['reseller_login_username'], $_POST['reseller_login_password']);
    if ($account) {
        $_SESSION['reseller_id'] = $account['id'];
        $_SESSION['reseller_username'] = $account['username'];
        $_SESSION['reseller_fail_count'] = 0;
        unset($_SESSION['reseller_lock_until']);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $_SESSION['reseller_fail_count'] = ($_SESSION['reseller_fail_count'] ?? 0) + 1;
        if ($_SESSION['reseller_fail_count'] >= $maxAttempts) {
            $_SESSION['reseller_lock_until'] = time() + $lockSeconds;
            $error = 'Sai quá nhiều lần, thử lại sau ' . ($lockSeconds / 60) . ' phút';
        } else {
            $error = 'Sai username hoặc mật khẩu';
        }
    }
}
$isLocked = isset($_SESSION['reseller_lock_until']) && time() < $_SESSION['reseller_lock_until'];

if (isset($_SESSION['reseller_id']) && !isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_SESSION['reseller_id']) && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['reseller_login_username'])) {
    $csrfOk = isset($_POST['csrf_token'], $_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
    if (!$csrfOk) {
        http_response_code(403);
        die('CSRF token không hợp lệ hoặc đã hết hạn. Vui lòng tải lại trang và thử lại.');
    }
}

$RID = $_SESSION['reseller_id'] ?? null; // resellerId dùng xuyên suốt các hàm config.php

if ($RID !== null) {
    // Xác nhận tài khoản vẫn tồn tại + còn được bật (admin có thể đã
    // khoá/xoá reseller này giữa lúc họ đang đăng nhập).
    $me = get_reseller_by_id($RID);
    if (!$me || !$me['enabled']) {
        session_unset();
        session_destroy();
        header('Location: reseller.php');
        exit;
    }

    $tab = $_GET['tab'] ?? 'keys';
    if (!in_array($tab, ['keys', 'game', 'link', 'channel'], true)) $tab = 'keys';

    // ---- Xử lý POST (mọi thao tác đều tự động khoanh vùng theo $RID) ----
    $notice = null;

    if (isset($_POST['save_store_name'])) {
        save_reseller_store_name($RID, $_POST['store_name'] ?? '');
        $notice = ['ok' => true, 'msg' => 'Đã lưu tên cửa hàng.'];
        $me = get_reseller_by_id($RID);
    }

    if (isset($_POST['add_game'])) {
        $slug = trim($_POST['game_slug'] ?? '');
        $name = trim($_POST['game_name'] ?? '');
        if ($slug === '' || $name === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
            $notice = ['ok' => false, 'msg' => 'Slug chỉ gồm chữ thường/số/gạch ngang, và không được bỏ trống tên.'];
        } elseif (get_game_by_slug($slug, null) || get_game_by_slug($slug, $RID)) {
            $notice = ['ok' => false, 'msg' => 'Slug này đã được dùng (slug phải DUY NHẤT trên toàn hệ thống, kể cả game của admin/reseller khác).'];
        } else {
            create_game($slug, $name, trim($_POST['game_icon'] ?? ''), $RID);
            $notice = ['ok' => true, 'msg' => 'Đã tạo game/sản phẩm mới.'];
        }
    }
    if (isset($_POST['toggle_game_id'])) { toggle_game((int)$_POST['toggle_game_id'], $RID); }
    if (isset($_POST['delete_game_id'])) { delete_game((int)$_POST['delete_game_id'], $RID); }
    if (isset($_POST['save_region_game_id'])) {
        $gid = (int)$_POST['save_region_game_id'];
        foreach (['vn', 'intl'] as $region) {
            $chain = [];
            for ($i = 1; $i <= 5; $i++) {
                $v = trim($_POST["{$region}_chain_step_{$i}"] ?? '');
                if ($v !== '') $chain[] = $v;
            }
            update_game_region($gid, $region, (int)($_POST["{$region}_hops"] ?? 1), (int)($_POST["{$region}_hours"] ?? 24), $chain, $RID);
        }
        $notice = ['ok' => true, 'msg' => 'Đã lưu cấu hình thứ tự link vượt.'];
    }

    if (isset($_POST['provider'], $_POST['api_key']) && $_POST['provider'] !== 'custom') {
        save_shortener_config(trim($_POST['provider']), trim($_POST['api_key']), $RID);
        $notice = ['ok' => true, 'msg' => 'Đã lưu API key cho provider.'];
    }
    if (isset($_POST['custom_label'], $_POST['custom_url'], $_POST['custom_api_key'])) {
        save_custom_provider(trim($_POST['custom_label']), trim($_POST['custom_url']), $_POST['custom_type'] ?? 'json', trim($_POST['custom_field'] ?? ''), trim($_POST['custom_api_key']), $RID);
        $notice = ['ok' => true, 'msg' => 'Đã lưu provider tuỳ chỉnh.'];
    }
    if (isset($_POST['set_active_provider'])) {
        set_active_provider(trim($_POST['set_active_provider']), $RID);
        $notice = ['ok' => true, 'msg' => 'Đã đổi provider đang dùng.'];
    }
    if (isset($_POST['delete_provider'])) {
        delete_shortener_provider(trim($_POST['delete_provider']), $RID);
        $notice = ['ok' => true, 'msg' => 'Đã xoá provider.'];
    }

    if (isset($_POST['add_channel'])) {
        $label = trim($_POST['channel_label'] ?? '');
        $url = trim($_POST['channel_url'] ?? '');
        if ($label === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            $notice = ['ok' => false, 'msg' => 'Nhập tên kênh và URL hợp lệ (bắt đầu bằng https://).'];
        } else {
            create_channel(
                $_POST['channel_type'] ?? 'other',
                (int)($_POST['channel_sort_order'] ?? 0),
                $label, $url,
                trim($_POST['channel_requirement'] ?? ''),
                isset($_POST['channel_enabled']),
                $_POST['channel_tg_chat_id'] ?? null,
                $RID
            );
            $notice = ['ok' => true, 'msg' => 'Đã thêm kênh.'];
        }
    }
    if (isset($_POST['toggle_channel_id'])) { toggle_channel((int)$_POST['toggle_channel_id'], $RID); }
    if (isset($_POST['delete_channel_id'])) { delete_channel((int)$_POST['delete_channel_id'], $RID); }

    $createdKeys = [];
    if (isset($_POST['create_key'])) {
        $value = max(1, (int)($_POST['duration_value'] ?? 1));
        $unit = $_POST['duration_unit'] ?? 'day';
        $maxDevices = max(1, (int)($_POST['max_devices'] ?? 1));
        $quantity = max(1, min(200, (int)($_POST['quantity'] ?? 1)));
        $durationSeconds = duration_to_seconds($value, $unit);
        $stmt = $db_stmt = get_db()->prepare("INSERT INTO keys (keycode, token, status, duration_seconds, max_devices, created_at, activated_at, reseller_id)
                               VALUES (?, ?, 'active', ?, ?, ?, ?, ?)");
        for ($i = 0; $i < $quantity; $i++) {
            $keycode = generate_keycode();
            $token = random_string(32);
            db_execute($stmt, [$keycode, $token, $durationSeconds, $maxDevices, time(), time(), $RID]);
            $createdKeys[] = $keycode;
        }
    }

    // ---- Dữ liệu cho view ----
    $games = get_games($RID);
    $cfg = get_shortener_config($RID);
    $builtins = get_builtin_providers();
    $channelRows = get_channels(false, $RID);
    $channelTypes = ['youtube' => 'YouTube', 'tiktok' => 'TikTok', 'telegram' => 'Telegram', 'facebook' => 'Facebook', 'discord' => 'Discord', 'instagram' => 'Instagram', 'other' => 'Khác'];
    $regionInfo = ['vn' => ['label' => 'Khách Việt Nam', 'icon' => 'flag'], 'intl' => ['label' => 'Khách nước ngoài', 'icon' => 'globe']];

    if ($tab === 'keys') {
        $keysStmt = get_db()->prepare("SELECT * FROM keys WHERE reseller_id = ? ORDER BY id DESC LIMIT 200");
        $keysStmt->execute([$RID]);
        $myKeys = $keysStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $publicGetkeyBase = rtrim(BASE_URL, '/') . '/index.php?r=' . $RID;
}

function fmt_duration($seconds) { return format_duration_label((int)$seconds); }

function r_svg(string $name, int $size = 18): string {
    $paths = [
        'key' => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
        'link' => '<path d="M9 17H7A5 5 0 0 1 7 7h2"/><path d="M15 7h2a5 5 0 1 1 0 10h-2"/><line x1="8" x2="16" y1="12" y2="12"/>',
        'gamepad' => '<path d="M6 12h4"/><path d="M8 10v4"/><circle cx="15" cy="13" r=".5" fill="currentColor"/><circle cx="18" cy="11" r=".5" fill="currentColor"/><rect x="2" y="6" width="20" height="12" rx="6"/>',
        'flag' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'copy' => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;display:inline-block">' . $p . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reseller Dashboard</title>
<style>
:root{ --bg:#0B0E14; --surface:#12151F; --surface2:#181C28; --cyan:#00E5C7; --violet:#8B7CFF; --text:#E8ECF3; --text-dim:#8891A3; --success:#34D399; --warn:#FBBF24; --danger:#FF6B6B; }
*{box-sizing:border-box}
body{font-family:'Inter',-apple-system,Arial,sans-serif;font-size:14px;max-width:900px;margin:0 auto;background:radial-gradient(ellipse at top,#151a26 0%,var(--bg) 60%);color:var(--text);padding:0 12px 30px}
h2,h3{font-family:'Space Grotesk',sans-serif;font-weight:700}
h2{font-size:19px}h3{font-size:16px}h4{font-size:13px;font-family:'JetBrains Mono',monospace;letter-spacing:.04em}
input,select{padding:8px;margin:5px 4px 5px 0;border-radius:6px;border:1px solid #262b38;background:var(--surface2);color:var(--text);font-size:13px;max-width:100%;font-family:'Inter',sans-serif}
input:focus,select:focus{outline:none;border-color:var(--cyan)}
button{padding:9px 14px;background:linear-gradient(135deg,var(--cyan),var(--violet));border:none;border-radius:6px;color:#0B0E14;font-family:'Space Grotesk',sans-serif;font-weight:700;cursor:pointer;font-size:13px}
button:active{transform:scale(.96)}
button.danger{background:var(--danger);color:#fff}
button.warn{background:var(--warn);color:#0B0E14}
button.small{padding:5px 10px;font-size:12px}
.box{background:var(--surface);padding:16px;border-radius:10px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.3)}
.ok{color:var(--success)}.err{color:var(--danger)}
table{width:100%;border-collapse:collapse;font-size:12px}
.tablewrap{overflow-x:auto}
th,td{padding:6px;border-bottom:1px solid #262a33;text-align:left;white-space:nowrap}
th{font-family:'JetBrains Mono',monospace;font-size:10.5px;letter-spacing:.06em;color:var(--text-dim);text-transform:uppercase}
.badge{padding:2px 7px;border-radius:10px;font-size:11px;font-family:'JetBrains Mono',monospace}
.badge.on{background:rgba(52,211,153,.15);color:var(--success)}
.badge.off{background:#262b38;color:var(--text-dim)}
.loginbox{max-width:340px;margin:60px auto}
.region-form{background:var(--surface2);border-radius:8px;padding:10px;margin-top:6px}
.region-form input,.region-form select{width:100%;margin:4px 0}
.step-row{display:flex;gap:4px;flex-wrap:wrap}
.step-row select{flex:1;min-width:110px}
.topbar{position:sticky;top:0;background:rgba(11,14,20,.9);backdrop-filter:blur(6px);padding:14px 0;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #262a33;margin-bottom:16px;z-index:10}
.tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
.tabs a{padding:8px 13px;border-radius:8px;background:var(--surface2);color:var(--text-dim);text-decoration:none;font-size:12.5px;font-family:'Space Grotesk',sans-serif;font-weight:700}
.tabs a.active{background:linear-gradient(135deg,var(--cyan),var(--violet));color:#0B0E14}
.getkey-link-card{background:var(--surface2);border-radius:12px;padding:16px;margin-top:12px}
.getkey-link-row{display:flex;gap:8px;align-items:stretch}
.getkey-link-row input{flex:1;min-width:0;background:var(--bg);border:1px solid #262b38;border-radius:10px;padding:12px 14px;color:var(--text);font-family:'JetBrains Mono',monospace;font-size:12.5px;margin:0}
.getkey-link-row button{background:#fff;color:#0B0E14;border:none;border-radius:10px;padding:0 16px;display:flex;align-items:center;gap:6px;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:13px;cursor:pointer;white-space:nowrap;flex-shrink:0}
</style></head><body>

<?php if ($RID === null): ?>

    <div class="loginbox">
        <div class="box">
            <h2 style="margin-top:0">🔑 Reseller Login</h2>
            <?php if ($isLocked): ?><p class="err">Tài khoản tạm khoá do sai quá nhiều lần. Thử lại sau vài phút.</p>
            <?php else: ?>
                <?php if (!empty($error)): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
                <form method="post">
                    <input type="text" name="reseller_login_username" required placeholder="Username" style="width:100%"><br>
                    <input type="password" name="reseller_login_password" required placeholder="Mật khẩu" style="width:100%"><br>
                    <button type="submit" style="width:100%;margin-top:6px">Đăng nhập</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

<?php else: ?>

    <div class="topbar">
        <b><?= r_svg('key', 20) ?> Reseller: <?= htmlspecialchars($me['username']) ?></b>
        <a href="reseller.php?logout=1" style="color:var(--text-dim);font-size:12px;text-decoration:none">Đăng xuất</a>
    </div>

    <div class="tabs">
        <a href="reseller.php?tab=keys" class="<?= $tab==='keys'?'active':'' ?>"><?= r_svg('key',14) ?> Tạo Key</a>
        <a href="reseller.php?tab=game" class="<?= $tab==='game'?'active':'' ?>"><?= r_svg('gamepad',14) ?> Cấu hình Game</a>
        <a href="reseller.php?tab=link" class="<?= $tab==='link'?'active':'' ?>"><?= r_svg('link',14) ?> Cấu hình Link</a>
        <a href="reseller.php?tab=channel" class="<?= $tab==='channel'?'active':'' ?>"><?= r_svg('gamepad',14) ?> Cấu hình kênh</a>
    </div>

    <?php if ($notice): ?><p class="<?= $notice['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($notice['msg']) ?></p><?php endif; ?>

    <div class="box">
        <h4 style="margin:0 0 6px;color:var(--text-dim)">Tên cửa hàng (hiện làm tiêu đề ở trang lấy key riêng của bạn)</h4>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="text" name="store_name" value="<?= htmlspecialchars($me['store_name'] ?? '') ?>" placeholder="VD: AuraShop, để trống = dùng username (<?= htmlspecialchars($me['username']) ?>)" style="flex:1;min-width:200px">
            <button type="submit" name="save_store_name" value="1">Lưu tên</button>
        </form>
    </div>

    <div class="box">
        <h4 style="margin:0 0 6px;color:var(--text-dim)">Trang lấy key riêng của bạn</h4>
        <div class="getkey-link-row">
            <input readonly id="pubLink" value="<?= htmlspecialchars($publicGetkeyBase) ?>" onclick="this.select()">
            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('pubLink').value);this.textContent='✓ Đã copy'">Copy</button>
        </div>
        <p style="font-size:11px;color:var(--text-dim);margin:8px 0 0">Chỉ hiện các game bạn tạo ở tab "Cấu hình Game" bên dưới. User vào trang này để tự lấy key qua vượt link - dùng chung hệ thống hop.php/confirm.php với trang chính.</p>
    </div>

    <?php if ($tab === 'game'): ?>

    <div class="box">
        <h3>Thêm Game / Sản phẩm</h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="text" name="game_slug" required placeholder="slug (vd: valorant-vip)" style="width:200px">
            <input type="text" name="game_name" required placeholder="Tên hiển thị" style="width:200px">
            <input type="text" name="game_icon" placeholder="Icon emoji (vd: 🎮)" style="width:120px">
            <button type="submit" name="add_game" value="1">+ Thêm</button>
            <p style="font-size:11px;color:var(--text-dim);margin:6px 0 0">Lưu ý: slug phải DUY NHẤT trên toàn hệ thống (kể cả game của admin hay reseller khác).</p>
        </form>
    </div>

    <?php foreach ($games as $g): $cfgKeys = array_keys($cfg['keys'] ?? []); ?>
    <div class="box">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <b><?= htmlspecialchars($g['icon']) ?> <?= htmlspecialchars($g['name']) ?></b>
            <span>
                <span class="badge <?= $g['enabled'] ? 'on' : 'off' ?>"><?= $g['enabled'] ? 'đang mở' : 'đã tắt' ?></span>
                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="toggle_game_id" value="<?= $g['id'] ?>"><button class="warn small" type="submit"><?= $g['enabled'] ? 'Tắt' : 'Bật' ?></button></form>
                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="delete_game_id" value="<?= $g['id'] ?>"><button class="danger small" type="submit" onclick="return confirm('Xoá game này?')">Xoá</button></form>
            </span>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="save_region_game_id" value="<?= $g['id'] ?>">
            <?php foreach (['vn', 'intl'] as $region): $chain = array_filter(explode(',', $g["{$region}_chain"])); ?>
            <div class="region-form">
                <b style="font-size:13px"><?= r_svg($regionInfo[$region]['icon'], 14) ?> <?= $regionInfo[$region]['label'] ?></b>
                <p style="font-size:11.5px;color:var(--text-dim);margin:2px 0 0">Số lần vượt/hạn key giờ do người dùng chọn ở màn nhiệm vụ (Key 20h/40h). Mục này chỉ còn dùng để chọn thứ tự provider.</p>
                <input type="hidden" name="<?= $region ?>_hops" value="<?= (int)$g["{$region}_hops"] ?>">
                <input type="hidden" name="<?= $region ?>_hours" value="<?= (int)$g["{$region}_key_hours"] ?>">
                <label style="font-size:12px;color:#888">Thứ tự link vượt (để trống = dùng provider active)</label>
                <div class="step-row">
                    <?php for ($i = 1; $i <= 5; $i++): $cur = $chain[$i - 1] ?? ''; ?>
                    <select name="<?= $region ?>_chain_step_<?= $i ?>">
                        <option value="">Bước <?= $i ?>: -</option>
                        <?php foreach ($cfgKeys as $p): ?><option value="<?= htmlspecialchars($p) ?>" <?= $cur===$p?'selected':'' ?>><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
                    </select>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <button type="submit" style="margin-top:8px">Lưu thứ tự link</button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php if (empty($games)): ?><div class="box" style="text-align:center;color:var(--text-dim)">Chưa có game nào.</div><?php endif; ?>

    <?php elseif ($tab === 'link'): ?>

    <div class="box">
        <h3>Nhà cung cấp rút gọn link (riêng của bạn)</h3>
        <h4 style="color:#aaa;font-weight:normal">Provider đã lưu key</h4>
        <?php if (empty($cfg['keys'])): ?><p style="color:var(--text-dim);font-size:12.5px">Chưa có provider nào.</p><?php endif; ?>
        <?php foreach ($cfg['keys'] as $p => $k): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;background:var(--surface2);padding:9px 11px;border-radius:8px;margin-bottom:6px">
            <span><?= htmlspecialchars($p) ?> <?php if ($cfg['active'] === $p): ?><span class="badge on">active</span><?php endif; ?></span>
            <span>
                <?php if ($cfg['active'] !== $p): ?><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="set_active_provider" value="<?= htmlspecialchars($p) ?>"><button class="small" type="submit">Đặt active</button></form><?php endif; ?>
                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="delete_provider" value="<?= htmlspecialchars($p) ?>"><button class="danger small" type="submit" onclick="return confirm('Xoá provider này?')">Xoá</button></form>
            </span>
        </div>
        <?php endforeach; ?>

        <h4 style="color:#aaa;font-weight:normal;margin-top:16px">Thêm / cập nhật API key cho provider có sẵn</h4>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <select name="provider">
                <?php foreach (get_builtin_providers() as $key => $info): ?><option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($info['label']) ?><?= isset($cfg['keys'][$key]) ? ' (đã có key)' : '' ?></option><?php endforeach; ?>
            </select>
            <input type="text" name="api_key" placeholder="Vui lòng nhập API Key" style="width:260px">
            <button type="submit">Lưu key cho provider này</button>
        </form>

        <h4 style="color:#aaa;font-weight:normal;margin-top:16px">Hoặc thêm provider khác (không có sẵn trong danh sách)</h4>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="text" name="custom_label" placeholder="Tên provider (vd: shortlink)" style="width:200px"><br>
            <input type="text" name="custom_url" placeholder="URL API, dùng {api} và {url} làm placeholder" style="width:min(100%,420px)"><br>
            <select name="custom_type"><option value="json">Response JSON</option><option value="plain">Response Plain Text</option></select>
            <input type="text" name="custom_field" placeholder="Tên field chứa link (nếu JSON)" style="width:220px"><br>
            <input type="text" name="custom_api_key" placeholder="Vui lòng nhập API Key" style="width:260px">
            <button type="submit">Lưu provider tuỳ chỉnh</button>
        </form>
    </div>

    <?php elseif ($tab === 'channel'): ?>

    <div class="box">
        <h3>Cấu hình kênh (riêng của bạn)</h3>
        <p style="font-size:12.5px;color:var(--text-dim);margin-top:-5px">Kênh đang bật sẽ là nhiệm vụ bắt buộc trước khi user của bạn nhận link rút gọn đầu tiên.</p>
        <div class="region-form">
            <h4 style="margin:0 0 9px;color:var(--text)">Thêm kênh nhiệm vụ</h4>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <select name="channel_type"><?php foreach ($channelTypes as $key => $label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?></select>
                <input type="number" name="channel_sort_order" value="0" min="0" style="width:80px" placeholder="Thứ tự">
                <input type="text" name="channel_label" required placeholder="Tên hiển thị" style="width:220px">
                <input type="url" name="channel_url" required placeholder="https://t.me/yourgroup" style="width:min(100%,340px)"><br>
                <input type="text" name="channel_requirement" placeholder="Yêu cầu (vd: Tham gia nhóm)" style="width:min(100%,380px)"><br>
                <input type="text" name="channel_tg_chat_id" placeholder="Telegram Chat ID (để trống nếu không xác minh thật)" style="width:min(100%,380px)">
                <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--text-dim)"><input type="checkbox" name="channel_enabled" checked> Bật ngay</label>
                <button type="submit" name="add_channel" value="1">+ Thêm kênh</button>
            </form>
        </div>
        <div class="tablewrap" style="margin-top:14px">
            <table>
                <tr><th>Thứ tự</th><th>Kênh</th><th>Yêu cầu</th><th>Trạng thái</th><th></th></tr>
                <?php foreach ($channelRows as $c): ?>
                <tr>
                    <td><?= (int)$c['sort_order'] ?></td>
                    <td><b><?= htmlspecialchars($channelTypes[$c['type']] ?? 'Khác') ?></b><br><span style="font-size:11px;color:var(--text-dim)"><?= htmlspecialchars($c['label']) ?></span></td>
                    <td><?= htmlspecialchars($c['requirement'] ?: '-') ?></td>
                    <td><span class="badge <?= $c['enabled'] ? 'on' : 'off' ?>"><?= $c['enabled'] ? 'bật' : 'tắt' ?></span></td>
                    <td>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="toggle_channel_id" value="<?= $c['id'] ?>"><button class="warn small" type="submit"><?= $c['enabled'] ? 'Tắt' : 'Bật' ?></button></form>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="delete_channel_id" value="<?= $c['id'] ?>"><button class="danger small" type="submit" onclick="return confirm('Xoá kênh?')">Xoá</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($channelRows)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:16px">Chưa có kênh nào.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>

    <?php else: /* tab === 'keys' */ ?>

    <div class="box">
        <h3>Tạo Key thủ công</h3>
        <?php if (!empty($createdKeys)): ?>
        <div style="background:var(--bg);border-radius:10px;padding:12px;margin-bottom:12px">
            <p class="ok">Đã tạo <?= count($createdKeys) ?> key:</p>
            <textarea readonly id="ck" style="width:100%;min-height:100px;background:var(--surface2);color:var(--cyan);font-family:monospace;font-size:12.5px;border:1px solid #262b38;border-radius:8px;padding:8px"><?= htmlspecialchars(implode("\n", $createdKeys)) ?></textarea>
            <button type="button" class="small" onclick="navigator.clipboard.writeText(document.getElementById('ck').value)">Copy tất cả</button>
        </div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="number" name="quantity" value="1" min="1" max="200" style="width:70px"> key ×
            <input type="number" name="duration_value" value="1" min="1" style="width:70px">
            <select name="duration_unit"><option value="hour">Giờ</option><option value="day" selected>Ngày</option><option value="week">Tuần</option><option value="month">Tháng</option><option value="forever">∞ Vĩnh viễn</option></select>
            <input type="number" name="max_devices" value="1" min="1" style="width:80px"> thiết bị
            <button type="submit" name="create_key" value="1">Tạo Key</button>
        </form>
    </div>

    <div class="box">
        <h3>Key của bạn (<?= count($myKeys) ?>)</h3>
        <div class="tablewrap">
            <table>
                <tr><th>Key</th><th>Trạng thái</th><th>Thời hạn</th><th>Thiết bị</th></tr>
                <?php foreach ($myKeys as $k):
                    $status = $k['status'];
                    if ($status === 'active' && $k['expires_at'] && time() > (int)$k['expires_at']) $status = 'expired';
                    $devCount = $k['devices'] !== '' ? count(explode(',', $k['devices'])) : 0;
                ?>
                <tr>
                    <td style="font-family:monospace"><?= htmlspecialchars($k['keycode']) ?></td>
                    <td><span class="badge <?= $status==='active'?'on':'off' ?>"><?= htmlspecialchars($status) ?></span></td>
                    <td><?= fmt_duration((int)$k['duration_seconds']) ?></td>
                    <td><?= $devCount ?>/<?= (int)$k['max_devices'] ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($myKeys)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:16px">Chưa có key nào.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>

    <?php endif; ?>

<?php endif; ?>
</body></html>
