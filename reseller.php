<?php
// ============================================================
// reseller.php - Dashboard riêng cho tài khoản Reseller (do admin tạo
// ở admin.php > Quản lý Reseller). Mỗi reseller có Cấu hình Link, Cấu
// hình kênh, Cấu hình Game và Tạo Key HOÀN TOÀN RIÊNG (tách biệt qua
// cột reseller_id), nhưng vẫn dùng chung bảng `keys` + luồng
// getkey.php/hop.php/confirm.php với hệ thống chính.
//
// Giao diện dùng chung style/sidebar với admin.php để đồng bộ trải
// nghiệm, nhưng có 2 khác biệt quan trọng so với quyền của admin:
// 1. Chỉ có 4 mục: Tạo Key, Cấu hình Game, Cấu hình Link, Cấu hình kênh
//    (không có Quản lý Reseller, Thông báo, Server App, Thống kê toàn hệ thống).
// 2. Tạo Key thủ công CHỈ được chọn đơn vị Giờ hoặc Ngày - KHÔNG được
//    chọn Tuần/Tháng/Vĩnh viễn (validate cả ở client lẫn server).
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
// Reseller CHỈ được tạo key theo Giờ hoặc Ngày - không có Tuần/Tháng/
// Vĩnh viễn (đó là đặc quyền riêng của admin ở admin.php).
const RESELLER_ALLOWED_DURATION_UNITS = ['hour', 'day'];

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
$notice = null;
$me = null;

if ($RID !== null) {
    // Xác nhận tài khoản vẫn tồn tại + còn được bật (admin có thể đã
    // khoá/xoá reseller này giữa lúc họ đang đăng nhập).
    $me = get_reseller_by_id($RID);
    if (!$me || !$me['enabled'] || (!empty($me['expires_at']) && time() > (int)$me['expires_at'])) {
        session_unset();
        session_destroy();
        header('Location: reseller.php');
        exit;
    }

    $tab = $_GET['tab'] ?? 'keys';
    if (!in_array($tab, ['keys', 'game', 'link', 'channel'], true)) $tab = 'keys';

    // ---- Xử lý POST (mọi thao tác đều tự động khoanh vùng theo $RID) ----

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
        $unit = $_POST['duration_unit'] ?? 'hour';
        // CHẶN CỨNG server-side: dù client có cố gửi week/month/forever
        // qua request thủ công, vẫn ép về 'day' - reseller KHÔNG có
        // quyền tạo key Tuần/Tháng/Vĩnh viễn.
        if (!in_array($unit, RESELLER_ALLOWED_DURATION_UNITS, true)) {
            $unit = 'day';
        }
        $maxDevices = max(1, (int)($_POST['max_devices'] ?? 1));
        $quantity = max(1, min(200, (int)($_POST['quantity'] ?? 1)));
        $durationSeconds = duration_to_seconds($value, $unit);
        $stmt = get_db()->prepare("INSERT INTO keys (keycode, token, status, duration_seconds, max_devices, created_at, activated_at, reseller_id)
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

function fmt_time_r($ts) { return $ts ? date('H:i d/m/Y', $ts) : '-'; }
function fmt_duration_r($seconds) { return format_duration_label((int)$seconds); }

function svg_icon(string $name, int $size = 18): string {
    $paths = [
        'chart'   => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'key'     => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
        'gamepad' => '<path d="M6 12h4"/><path d="M8 10v4"/><circle cx="15" cy="13" r=".5" fill="currentColor"/><circle cx="18" cy="11" r=".5" fill="currentColor"/><rect x="2" y="6" width="20" height="12" rx="6"/>',
        'link'    => '<path d="M9 17H7A5 5 0 0 1 7 7h2"/><path d="M15 7h2a5 5 0 1 1 0 10h-2"/><line x1="8" x2="16" y1="12" y2="12"/>',
        'menu'    => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'arrow-left' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
        'chevron' => '<path d="m9 18 6-6-6-6"/>',
        'copy'    => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'globe'   => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'flag'    => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/>',
        'store'   => '<path d="M2 7h20l-2 5H4Z"/><path d="M4 12v9a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-9"/><path d="M10 22v-6h4v6"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;display:inline-block">' . $p . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reseller - Key Server</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">
<style>
:root{
    --bg:#070A10;--surface:#101620;--surface2:#151D2A;--surface3:#1B2635;
    --line:rgba(177,199,224,.12);--line-strong:rgba(177,199,224,.2);
    --cyan:#59F5D5;--violet:#9C8CFF;--text:#F4F7FB;--text-dim:#93A1B5;
    --success:#61E6A4;--warn:#F5C969;--danger:#FF7885;
}
*{box-sizing:border-box}
html{background:var(--bg)}
body{
    position:relative;max-width:1180px;margin:0 auto;padding:0 30px 60px;min-height:100vh;
    font-family:'Inter',-apple-system,Arial,sans-serif;font-size:14px;color:var(--text);
    background:radial-gradient(circle at 8% -10%,rgba(89,245,213,.16),transparent 46rem),radial-gradient(circle at 94% 10%,rgba(156,140,255,.14),transparent 44rem),var(--bg);
}
body::before{
    content:"";position:fixed;inset:0;pointer-events:none;opacity:.3;
    background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);
    background-size:44px 44px;mask-image:linear-gradient(to bottom,#000,transparent 80%)
}
h2,h3{font-family:'Space Grotesk',sans-serif;font-weight:700;letter-spacing:-.025em}
h2{font-size:22px}h3{font-size:17px;margin:0 0 14px}h4{font-size:13px;font-family:'JetBrains Mono',monospace;letter-spacing:.04em}
code{padding:2px 5px;border-radius:5px;background:var(--surface3);color:var(--cyan);font-size:.9em}
input,select{
    min-height:39px;padding:9px 12px;margin:5px 4px 5px 0;border:1px solid var(--line-strong);
    border-radius:10px;background:rgba(21,29,42,.9);color:var(--text);box-shadow:inset 0 1px rgba(255,255,255,.035);font-size:13px;font-family:'Inter',sans-serif
}
input::placeholder{color:#68768A}input:focus,select:focus{outline:none;border-color:var(--cyan);box-shadow:0 0 0 4px rgba(89,245,213,.12),inset 0 1px rgba(255,255,255,.04)}
button{
    min-height:39px;padding:9px 15px;border:none;border-radius:10px;background:linear-gradient(110deg,var(--cyan),#B5FFF0 45%,var(--violet));
    box-shadow:0 8px 18px -13px rgba(89,245,213,.75);font-size:13px;color:#0B0E14;font-family:'Space Grotesk',sans-serif;font-weight:700;cursor:pointer;transition:filter .15s,box-shadow .15s,transform .12s
}
button:hover{filter:brightness(1.08);box-shadow:0 12px 22px -13px rgba(89,245,213,.8)}
button:active{transform:scale(.96)}
button.danger{background:rgba(255,120,133,.14);border:1px solid rgba(255,120,133,.28);color:#FF9CA6;box-shadow:none}
button.warn{background:rgba(245,201,105,.14);border:1px solid rgba(245,201,105,.28);color:#F5D98D;box-shadow:none}
button.small{min-height:31px;padding:6px 10px;border-radius:8px;font-size:12px}
.box{
    position:relative;padding:23px;border:1px solid var(--line);border-radius:18px;margin-bottom:18px;
    background:rgba(16,22,32,.82);box-shadow:0 18px 48px -34px #000,inset 0 1px rgba(255,255,255,.045)
}
.box::before{content:"";position:absolute;left:23px;right:23px;top:0;height:1px;background:linear-gradient(90deg,rgba(89,245,213,.5),transparent 42%)}
.ok,.err{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:10px;font-size:12.5px}
.ok{background:rgba(97,230,164,.08);color:var(--success)}.err{background:rgba(255,120,133,.08);color:var(--danger)}
.tablewrap{border:1px solid var(--line);border-radius:13px;overflow:auto}
table{width:100%;border-collapse:collapse;font-size:12px;min-width:600px}
th,td{padding:12px 11px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}
th{height:39px;background:rgba(21,29,42,.74);font-size:10px;color:#9EABBD;font-family:'JetBrains Mono',monospace;letter-spacing:.06em;text-transform:uppercase;font-weight:600}
tr:last-child td{border-bottom:0}tr:hover td{background:rgba(89,245,213,.035)}
.badge{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border:1px solid transparent;border-radius:999px;font-size:10px;font-family:'JetBrains Mono',monospace}
.badge::before{content:"";width:5px;height:5px;border-radius:50%;background:currentColor}
.badge.on{background:rgba(97,230,164,.1);border-color:rgba(97,230,164,.2);color:var(--success)}
.badge.off{background:rgba(147,161,181,.08);border-color:var(--line);color:var(--text-dim)}
.loginbox{max-width:390px;margin:12vh auto;padding:32px}
.loginbox::after{content:"RESELLER PORTAL";display:block;margin-top:24px;color:#607087;font:10px 'JetBrains Mono',monospace;letter-spacing:.16em;text-align:center}
.loginbox h2{margin:0 0 22px;font-size:27px;background:linear-gradient(110deg,var(--text),var(--cyan));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.loginbox input{width:100%;box-sizing:border-box;height:48px;margin:0 0 10px;border-radius:12px}
.loginbox button{width:100%;height:48px;margin-top:4px}
.region-form{padding:15px;border:1px solid var(--line);border-radius:12px;background:rgba(21,29,42,.68)}
.region-form+.region-form{margin-top:9px}
.region-form input,.region-form select{width:100%;margin:4px 0}
.step-row{display:flex;gap:6px;flex-wrap:wrap}.step-row select{flex:1;min-width:110px;min-height:37px;border-radius:8px}
.game-card{padding:18px;border:1px solid var(--line);border-radius:15px;background:rgba(7,10,16,.52);box-shadow:inset 0 1px rgba(255,255,255,.025);margin-bottom:16px;transition:transform .15s,border-color .15s}
.game-card:hover{transform:translateY(-2px);border-color:rgba(89,245,213,.23)}
.game-head{display:flex;align-items:center;justify-content:space-between;padding-bottom:14px;margin-bottom:14px;border-bottom:1px solid var(--line)}
.getkey-link-card{margin-top:15px;padding:17px;border:1px solid rgba(89,245,213,.14);border-radius:13px;background:linear-gradient(135deg,rgba(89,245,213,.06),rgba(156,140,255,.05))}
.getkey-link-row{display:flex;gap:8px;align-items:stretch}
.getkey-link-row input{flex:1;min-width:0;height:43px;background:var(--bg);border:1px solid #262b38;border-radius:10px;padding:0 14px;color:var(--text);font-family:'JetBrains Mono',monospace;font-size:12.5px;margin:0}
.getkey-link-row button{height:43px;background:#F4F7FB;color:#0B0E14;border:none;border-radius:10px;padding:0 16px;display:flex;align-items:center;gap:6px;white-space:nowrap;flex-shrink:0}
.topbar{
    position:sticky;top:0;margin:0 -30px 28px;padding:16px 30px;border-bottom:1px solid var(--line);
    background:rgba(7,10,16,.78);backdrop-filter:blur(18px);z-index:20;display:flex;align-items:center;justify-content:space-between
}
.topbar b{font-size:15px;letter-spacing:-.01em}.topbar b::before{content:"RS / ";color:var(--cyan);font:10px 'JetBrains Mono',monospace;letter-spacing:.12em}
.topbar>a{padding:8px 12px;border:1px solid var(--line);border-radius:9px;color:#B7C2D1;text-decoration:none;transition:.2s;font-size:13px}
.topbar>a:hover{border-color:rgba(89,245,213,.4);color:var(--cyan)}
.hamburger{width:38px;height:38px;min-height:38px;padding:8px;border:1px solid var(--line);border-radius:10px;background:var(--surface2);color:var(--text);box-shadow:none;cursor:pointer}
.sidebar-overlay{position:fixed;inset:0;background:rgba(1,3,6,.72);backdrop-filter:blur(5px);opacity:0;pointer-events:none;transition:opacity .25s ease;z-index:40}
.sidebar-overlay.open{opacity:1;pointer-events:auto}
.sidebar{
    position:fixed;top:0;left:0;bottom:0;width:290px;max-width:82vw;z-index:41;transform:translateX(-100%);
    transition:transform .28s cubic-bezier(.16,1,.3,1);display:flex;flex-direction:column;
    background:linear-gradient(180deg,#111A27,#0D131D);border-right:1px solid var(--line);box-shadow:18px 0 50px rgba(0,0,0,.45)
}
.sidebar.open{transform:translateX(0)}
.sidebar-section-label{font-family:'JetBrains Mono',monospace;font-size:10px;letter-spacing:.12em;color:#A9B6C8;text-transform:uppercase;padding:24px 22px 16px}
.sidebar-section-label::before{content:"◈ ";color:var(--cyan)}
.sidebar-nav{flex:1;overflow-y:auto;padding:0 12px}
.sidebar-item{
    display:flex;align-items:center;gap:12px;padding:13px 14px;border:1px solid transparent;border-radius:11px;margin-bottom:4px;
    color:var(--text);text-decoration:none;font-size:14.5px;font-weight:500;transition:background .15s,border-color .15s
}
.sidebar-item:not(.active):hover{background:var(--surface2)}
.sidebar-item .ic{font-size:17px;width:20px;text-align:center;flex-shrink:0}
.sidebar-item.active{border-color:rgba(89,245,213,.16);background:linear-gradient(90deg,rgba(89,245,213,.1),rgba(156,140,255,.04))}
.sidebar-item.active .ic{color:var(--cyan);filter:drop-shadow(0 0 7px rgba(89,245,213,.55))}
.sidebar-item.active span.label{font-weight:700}
.sidebar-item .chev{margin-left:auto;color:var(--text-dim);font-size:15px}
.sidebar-item.active .chev{color:var(--cyan)}
.sidebar-footer{padding:16px;border-top:1px solid var(--line)}
.sidebar-back{
    display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:43px;padding:12px;
    border:1px solid var(--line);background:var(--surface2);color:var(--text);border-radius:12px;text-decoration:none;
    font-weight:700;font-family:'Space Grotesk',sans-serif;font-size:14px;margin-bottom:10px;cursor:pointer;transition:transform .12s
}
.sidebar-back:active{transform:scale(.97)}
.sidebar-account{display:flex;align-items:center;gap:10px;padding:6px 4px}
.sidebar-avatar{
    width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--cyan),var(--violet));
    display:flex;align-items:center;justify-content:center;font-weight:700;color:#0B0E14;font-family:'Space Grotesk',sans-serif;flex-shrink:0;
    box-shadow:0 0 22px rgba(89,245,213,.18)
}
.sidebar-account-name{font-size:13.5px;font-weight:600}
.sidebar-account-handle{font-size:11.5px;color:var(--text-dim)}
.provider-row{display:flex;align-items:center;justify-content:space-between;padding:12px 13px;border:1px solid var(--line);border-radius:10px;background:rgba(7,10,16,.48);margin-bottom:6px}
@media (max-width:700px){
    body{padding:0 15px 42px}.topbar{margin:0 -15px 20px;padding:13px 15px}
    .box{padding:18px 15px;border-radius:15px}.box::before{left:15px;right:15px}
}
@media (max-width:430px){.getkey-link-row{flex-direction:column}.getkey-link-row button{padding:11px}}
</style>
</head>
<body>

<?php if ($RID === null): ?>

    <div class="box loginbox">
        <h2>Reseller Login</h2>
        <?php if (isset($timedOut)): ?><p class="err">Phiên đăng nhập đã hết hạn, vui lòng đăng nhập lại</p><?php endif; ?>
        <?php if (isset($error)): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <?php if ($isLocked): ?>
            <p class="err">Tài khoản tạm khoá do sai nhiều lần, thử lại sau ít phút.</p>
        <?php else: ?>
        <form method="post">
            <input type="text" name="reseller_login_username" placeholder="Username" required><br>
            <input type="password" name="reseller_login_password" placeholder="Mật khẩu" required><br>
            <button type="submit">Đăng nhập</button>
        </form>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="topbar">
        <button class="hamburger" onclick="openSidebar()"><?= svg_icon('menu', 20) ?></button>
        <b><?= ['keys' => 'Tạo Key', 'game' => 'Cấu hình Game', 'link' => 'Cấu hình Link', 'channel' => 'Cấu hình kênh'][$tab] ?? '' ?></b>
        <a href="reseller.php?logout=1">Đăng xuất</a>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-section-label">RESELLER PORTAL</div>
        <div class="sidebar-nav">
            <a href="reseller.php?tab=keys" class="sidebar-item <?= $tab==='keys'?'active':'' ?>">
                <span class="ic"><?= svg_icon('key') ?></span><span class="label">Tạo Key</span>
                <?php if ($tab==='keys'): ?><span class="chev"><?= svg_icon('chevron', 15) ?></span><?php endif; ?>
            </a>
            <a href="reseller.php?tab=game" class="sidebar-item <?= $tab==='game'?'active':'' ?>">
                <span class="ic"><?= svg_icon('gamepad') ?></span><span class="label">Cấu hình Game</span>
                <?php if ($tab==='game'): ?><span class="chev"><?= svg_icon('chevron', 15) ?></span><?php endif; ?>
            </a>
            <a href="reseller.php?tab=link" class="sidebar-item <?= $tab==='link'?'active':'' ?>">
                <span class="ic"><?= svg_icon('link') ?></span><span class="label">Cấu hình Link</span>
                <?php if ($tab==='link'): ?><span class="chev"><?= svg_icon('chevron', 15) ?></span><?php endif; ?>
            </a>
            <a href="reseller.php?tab=channel" class="sidebar-item <?= $tab==='channel'?'active':'' ?>">
                <span class="ic"><?= svg_icon('gamepad') ?></span><span class="label">Cấu hình kênh</span>
                <?php if ($tab==='channel'): ?><span class="chev"><?= svg_icon('chevron', 15) ?></span><?php endif; ?>
            </a>
        </div>
        <div class="sidebar-footer">
            <a href="<?= htmlspecialchars($publicGetkeyBase) ?>" class="sidebar-back" target="_blank"><?= svg_icon('store', 16) ?> Xem trang lấy key của tôi</a>
            <div class="sidebar-account">
                <div class="sidebar-avatar"><?= htmlspecialchars(strtoupper(substr($me['username'], 0, 1))) ?></div>
                <div>
                    <div class="sidebar-account-name"><?= htmlspecialchars($me['store_name'] ?: $me['username']) ?></div>
                    <div class="sidebar-account-handle">Reseller</div>
                </div>
            </div>
        </div>
    </div>
    <script>
    function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('sidebarOverlay').classList.add('open');}
    function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebarOverlay').classList.remove('open');}
    </script>

    <?php if ($notice): ?><p class="<?= $notice['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($notice['msg']) ?></p><?php endif; ?>

    <div class="box">
        <h4 style="margin:0 0 6px;color:var(--text-dim)">Tên cửa hàng (hiện làm tiêu đề ở trang lấy key riêng của bạn)</h4>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="text" name="store_name" value="<?= htmlspecialchars($me['store_name'] ?? '') ?>" placeholder="VD: AuraShop, để trống = dùng username (<?= htmlspecialchars($me['username']) ?>)" style="flex:1;min-width:200px">
            <button type="submit" name="save_store_name" value="1">Lưu tên</button>
        </form>
    </div>

    <div class="box getkey-link-card">
        <h4 style="margin:0 0 6px;color:var(--text)"><?= svg_icon('store', 15) ?> Trang lấy key riêng của bạn</h4>
        <div class="getkey-link-row">
            <input readonly id="pubLink" value="<?= htmlspecialchars($publicGetkeyBase) ?>" onclick="this.select()">
            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('pubLink').value);this.textContent='✓ Đã copy'"><?= svg_icon('copy', 15) ?> Copy</button>
        </div>
        <p style="font-size:11px;color:var(--text-dim);margin:10px 0 0">Chỉ hiện các game bạn tạo ở tab "Cấu hình Game". User vào đây tự lấy key qua vượt link - dùng chung hệ thống hop.php/confirm.php với trang chính, nhưng cấu hình Link/Kênh hoàn toàn riêng của bạn.</p>
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
    <div class="box game-card">
        <div class="game-head">
            <b style="font-size:15px"><?= htmlspecialchars($g['icon']) ?> <?= htmlspecialchars($g['name']) ?></b>
            <span style="display:flex;align-items:center;gap:8px">
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
                <b style="font-size:13px"><?= svg_icon($regionInfo[$region]['icon'], 14) ?> <?= $regionInfo[$region]['label'] ?></b>
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
            <button type="submit" style="margin-top:10px">Lưu thứ tự link</button>
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
        <div class="provider-row">
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
                <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--text-dim)"><input type="checkbox" name="channel_enabled" checked style="min-height:auto"> Bật ngay</label>
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
        <p style="font-size:11.5px;color:var(--text-dim);margin-top:-8px">Reseller chỉ được tạo key theo <b>Giờ</b> hoặc <b>Ngày</b>. Key Tuần/Tháng/Vĩnh viễn chỉ admin mới tạo được.</p>
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
            <select name="duration_unit">
                <option value="hour">Giờ</option>
                <option value="day" selected>Ngày</option>
            </select>
            <input type="number" name="max_devices" value="1" min="1" style="width:80px"> thiết bị
            <button type="submit" name="create_key" value="1">Tạo Key</button>
        </form>
    </div>

    <div class="box">
        <h3>Key của bạn (<?= count($myKeys) ?>)</h3>
        <div class="tablewrap">
            <table>
                <tr><th>Key</th><th>Trạng thái</th><th>Thời hạn</th><th>Thiết bị</th><th>Ngày tạo</th></tr>
                <?php foreach ($myKeys as $k):
                    $status = $k['status'];
                    if ($status === 'active' && $k['expires_at'] && time() > (int)$k['expires_at']) $status = 'expired';
                    $devCount = $k['devices'] !== '' ? count(explode(',', $k['devices'])) : 0;
                ?>
                <tr>
                    <td style="font-family:monospace"><?= htmlspecialchars($k['keycode']) ?></td>
                    <td><span class="badge <?= $status==='active'?'on':'off' ?>"><?= htmlspecialchars($status) ?></span></td>
                    <td><?= fmt_duration_r((int)$k['duration_seconds']) ?></td>
                    <td><?= $devCount ?>/<?= (int)$k['max_devices'] ?></td>
                    <td><?= fmt_time_r((int)$k['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($myKeys)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:16px">Chưa có key nào.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>

    <?php endif; ?>

<?php endif; ?>
</body></html>
