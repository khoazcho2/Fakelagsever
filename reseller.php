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
// + Đồng hồ đếm ngược hạn thuê tài khoản (giờ Việt Nam - xem
//   date_default_timezone_set trong config.php).
// + Chuyển ngôn ngữ VIE/EN ở góc trên phải, mặc định VIE, nhớ theo
//   session (xem hàm t() bên dưới).
// + Bảng "Key của bạn" hiện thêm IP tạo key + danh sách thiết bị (device
//   id + IP) đã đăng nhập trên từng key, giống cách admin.php hiển thị
//   (nhưng KHÔNG có quyền Cấm thiết bị - đó là đặc quyền riêng của admin).
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

set_security_headers();

define('RESELLER_SESSION_TIMEOUT', 15 * 60);
// Reseller CHỈ được tạo key theo Giờ hoặc Ngày - không có Tuần/Tháng/
// Vĩnh viễn (đó là đặc quyền riêng của admin ở admin.php).
const RESELLER_ALLOWED_DURATION_UNITS = ['hour', 'day'];

// ---- Ngôn ngữ: mặc định VIE, chọn qua ?lang=vi|en, nhớ theo session ----
// init_language()/t() dùng chung với index.php, định nghĩa trong config.php.
init_language();

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

// Khoá theo IP thật (bảng login_lockout, scope='reseller') - xem ghi chú
// tương tự ở admin.php. Trước đây khoá theo session nên xoá cookie/mở
// tab ẩn danh là dò lại được đủ 5 lượt.
$clientIp = get_client_ip();
$lockRemaining = get_login_lock_remaining('reseller', $clientIp);
$isLocked = $lockRemaining > 0;

if (!$isLocked && isset($_POST['reseller_login_username'], $_POST['reseller_login_password'])) {
    $account = verify_reseller_login($_POST['reseller_login_username'], $_POST['reseller_login_password']);
    if ($account) {
        $_SESSION['reseller_id'] = $account['id'];
        $_SESSION['reseller_username'] = $account['username'];
        clear_login_failures('reseller', $clientIp);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $lockedSeconds = record_login_failure('reseller', $clientIp);
        $error = $lockedSeconds > 0
            ? t('Sai quá nhiều lần, thử lại sau ' . ceil($lockedSeconds / 60) . ' phút', 'Too many failed attempts, try again in ' . ceil($lockedSeconds / 60) . ' minutes')
            : t('Sai username hoặc mật khẩu', 'Wrong username or password');
    }
}
$lockRemaining = get_login_lock_remaining('reseller', $clientIp);
$isLocked = $lockRemaining > 0;

if (isset($_SESSION['reseller_id']) && !isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (isset($_SESSION['reseller_id']) && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['reseller_login_username'])) {
    $csrfOk = isset($_POST['csrf_token'], $_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
    if (!$csrfOk) {
        http_response_code(403);
        die(t('CSRF token không hợp lệ hoặc đã hết hạn. Vui lòng tải lại trang và thử lại.', 'Invalid or expired CSRF token. Please reload the page and try again.'));
    }
}

$RID = $_SESSION['reseller_id'] ?? null; // resellerId dùng xuyên suốt các hàm config.php
$notice = null;
$me = null;

if ($RID !== null) {
    // Xác nhận tài khoản vẫn tồn tại + còn được bật + còn hạn thuê
    // (admin có thể đã khoá/xoá reseller này giữa lúc họ đang đăng nhập,
    // hoặc thời hạn thuê vừa hết hạn giữa phiên làm việc).
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
        $notice = ['ok' => true, 'msg' => t('Đã lưu tên cửa hàng.', 'Store name saved.')];
        $me = get_reseller_by_id($RID);
    }

    // Link liên hệ RIÊNG của reseller này (Zalo/Telegram/Facebook) - ưu
    // tiên hơn link mặc định của admin ở trang lấy key riêng của họ.
    if (isset($_POST['save_contact'])) {
        save_reseller_contact(
            $RID,
            trim($_POST['contact_zalo'] ?? ''),
            trim($_POST['contact_telegram'] ?? ''),
            trim($_POST['contact_facebook'] ?? '')
        );
        $notice = ['ok' => true, 'msg' => t('Đã lưu link liên hệ.', 'Contact links saved.')];
        $me = get_reseller_by_id($RID);
    }

    if (isset($_POST['add_game'])) {
        $slug = trim($_POST['game_slug'] ?? '');
        $name = trim($_POST['game_name'] ?? '');
        if ($slug === '' || $name === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
            $notice = ['ok' => false, 'msg' => t('Slug chỉ gồm chữ thường/số/gạch ngang, và không được bỏ trống tên.', 'Slug can only contain lowercase letters/numbers/hyphens, and name cannot be empty.')];
        } elseif (get_game_by_slug($slug, null) || get_game_by_slug($slug, $RID)) {
            $notice = ['ok' => false, 'msg' => t('Slug này đã được dùng (slug phải DUY NHẤT trên toàn hệ thống, kể cả game của admin/reseller khác).', 'This slug is already taken (slug must be UNIQUE system-wide, including admin/other resellers\' games).')];
        } else {
            create_game($slug, $name, trim($_POST['game_icon'] ?? ''), $RID);
            $notice = ['ok' => true, 'msg' => t('Đã tạo game/sản phẩm mới.', 'New game/product created.')];
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
        $notice = ['ok' => true, 'msg' => t('Đã lưu cấu hình thứ tự link vượt.', 'Link order configuration saved.')];
    }

    if (isset($_POST['provider'], $_POST['api_key']) && $_POST['provider'] !== 'custom') {
        save_shortener_config(trim($_POST['provider']), trim($_POST['api_key']), $RID);
        $notice = ['ok' => true, 'msg' => t('Đã lưu API key cho provider.', 'API key saved for provider.')];
    }
    if (isset($_POST['custom_label'], $_POST['custom_url'], $_POST['custom_api_key'])) {
        save_custom_provider(trim($_POST['custom_label']), trim($_POST['custom_url']), $_POST['custom_type'] ?? 'json', trim($_POST['custom_field'] ?? ''), trim($_POST['custom_api_key']), $RID);
        $notice = ['ok' => true, 'msg' => t('Đã lưu provider tuỳ chỉnh.', 'Custom provider saved.')];
    }
    if (isset($_POST['set_active_provider'])) {
        set_active_provider(trim($_POST['set_active_provider']), $RID);
        $notice = ['ok' => true, 'msg' => t('Đã đổi provider đang dùng.', 'Active provider changed.')];
    }
    if (isset($_POST['delete_provider'])) {
        delete_shortener_provider(trim($_POST['delete_provider']), $RID);
        $notice = ['ok' => true, 'msg' => t('Đã xoá provider.', 'Provider deleted.')];
    }

    if (isset($_POST['add_channel'])) {
        $label = trim($_POST['channel_label'] ?? '');
        $url = trim($_POST['channel_url'] ?? '');
        if ($label === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            $notice = ['ok' => false, 'msg' => t('Nhập tên kênh và URL hợp lệ (bắt đầu bằng https://).', 'Enter a channel name and a valid URL (starting with https://).')];
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
            $notice = ['ok' => true, 'msg' => t('Đã thêm kênh.', 'Channel added.')];
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
    $channelTypes = ['youtube' => 'YouTube', 'tiktok' => 'TikTok', 'telegram' => 'Telegram', 'facebook' => 'Facebook', 'discord' => 'Discord', 'instagram' => 'Instagram', 'other' => t('Khác', 'Other')];
    $regionInfo = ['vn' => ['label' => t('Khách Việt Nam', 'Vietnam visitors'), 'icon' => 'flag'], 'intl' => ['label' => t('Khách nước ngoài', 'International visitors'), 'icon' => 'globe']];

    if ($tab === 'keys') {
        $keysStmt = get_db()->prepare("SELECT * FROM keys WHERE reseller_id = ? ORDER BY id DESC LIMIT 200");
        $keysStmt->execute([$RID]);
        $myKeys = $keysStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $publicGetkeyBase = rtrim(BASE_URL, '/') . '/index.php?r=' . $RID;
}

// date()/time() ở đây đều theo giờ Việt Nam nhờ date_default_timezone_set('Asia/Ho_Chi_Minh') đặt sẵn trong config.php
function fmt_time_r($ts) { return $ts ? date('H:i d/m/Y', $ts) : '-'; }
function fmt_duration_r($seconds) { return format_duration_label((int)$seconds); }

// Icon nét mảnh (svg_icon()) giờ dùng bản DÙNG CHUNG trong config.php
// (gộp chung với admin.php + thêm icon cho index.php) - gỡ bản trùng
// khỏi đây để khỏi lỗi khai báo lại hàm.
?>
<!DOCTYPE html>
<html lang="<?= $LANG === 'en' ? 'en' : 'vi' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reseller - Key Server</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">
<style>
<?= design_system_css() ?>

:root {
    --bg: var(--ds-bg);
    --surface: var(--ds-surface);
    --surface-rgb: var(--ds-surface-rgb);
    --surface2: var(--ds-surface2);
    --surface3: var(--ds-surface3);
    --line: var(--ds-line);
    --line-strong: var(--ds-line-strong);
    --cyan: var(--ds-cyan);
    --violet: var(--ds-violet);
    --text: var(--ds-text);
    --text-dim: var(--ds-text-dim);
    --text-muted: var(--ds-text-muted);
    --success: var(--ds-success);
    --warn: var(--ds-warn);
    --danger: var(--ds-danger);
}

body {
    position: relative;
    max-width: 1180px;
    margin: 0 auto;
    padding: 0 28px 60px;
    min-height: 100vh;
    font-family: var(--font-body);
    font-size: 13.5px;
    color: var(--text);
    background:
        radial-gradient(circle at 8% -10%, rgba(89, 245, 213, 0.15), transparent 46rem),
        radial-gradient(circle at 94% 10%, rgba(156, 140, 255, 0.12), transparent 44rem),
        var(--bg);
}
body::before {
    content: ""; position: fixed; inset: 0; pointer-events: none; opacity: .3;
    background-image: linear-gradient(var(--ds-grid-line) 1px, transparent 1px),
                      linear-gradient(90deg, var(--ds-grid-line) 1px, transparent 1px);
    background-size: 44px 44px; mask-image: linear-gradient(to bottom, #000, transparent 80%);
}

h2, h3 { font-family: var(--font-body); font-weight: 700; letter-spacing: -.025em; color: var(--text); }
h2 { font-size: 20px; margin: 0 0 14px; }
h3 { font-size: 16px; margin: 0 0 12px; }
h4 { font-size: 12px; font-family: var(--font-mono); letter-spacing: .05em; color: var(--text-dim); text-transform: uppercase; margin: 0 0 8px; }
code { padding: 3px 6px; border-radius: var(--r-sm); background: var(--surface3); color: var(--cyan); font-family: var(--font-mono); font-size: .88em; }

/* Form Controls */
input, select, textarea {
    min-height: 40px; padding: 8px 12px; margin: 4px 4px 4px 0;
    border: 1px solid var(--line-strong); border-radius: var(--r-md);
    background: rgba(21, 29, 42, 0.85); color: var(--text); font-family: var(--font-body); font-size: 13px;
    box-shadow: inset 0 1px rgba(255,255,255,0.03); transition: border-color .15s, box-shadow .15s;
}
input::placeholder { color: var(--text-muted); }
input:focus, select:focus, textarea:focus {
    outline: none; border-color: var(--cyan); box-shadow: 0 0 0 3px rgba(89,245,213,0.12);
}

button {
    min-height: 40px; padding: 8px 16px; border: none; border-radius: var(--r-md);
    background: var(--grad-btn); color: #071018; font-family: var(--font-body); font-weight: 700; font-size: 13px;
    cursor: pointer; transition: transform .12s, filter .15s, box-shadow .15s;
    box-shadow: var(--shadow-btn);
}
button:hover { filter: brightness(1.08); }
button:active { transform: scale(.97); }
button.danger {
    background: rgba(255, 120, 133, 0.14); border: 1px solid rgba(255, 120, 133, 0.35);
    color: var(--danger); box-shadow: none;
}
button.danger:hover { background: rgba(255, 120, 133, 0.25); filter: none; }
button.warn {
    background: rgba(245, 201, 105, 0.14); border: 1px solid rgba(245, 201, 105, 0.35);
    color: var(--warn); box-shadow: none;
}
button.warn:hover { background: rgba(245, 201, 105, 0.25); filter: none; }
button.small { min-height: 30px; padding: 4px 10px; font-size: 11.5px; border-radius: var(--r-sm); }

/* Containers */
.box {
    position: relative; padding: 22px; border: 1px solid var(--line); border-radius: var(--r-xl);
    background: rgba(var(--surface-rgb), 0.82); box-shadow: var(--shadow-card);
    margin-bottom: 20px; backdrop-filter: blur(8px);
}
.box::before {
    content: ""; position: absolute; left: 22px; right: 22px; top: 0; height: 1px;
    background: linear-gradient(90deg, rgba(89,245,213,0.5), transparent 45%);
}

.ok, .err {
    display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-radius: var(--r-md);
    font-size: 12.5px; line-height: 1.5; margin-bottom: 12px;
}
.ok { background: rgba(97, 230, 164, 0.1); border: 1px solid rgba(97, 230, 164, 0.25); color: var(--success); }
.err { background: rgba(255, 120, 133, 0.1); border: 1px solid rgba(255, 120, 133, 0.25); color: var(--danger); }

/* Tables */
.tablewrap {
    border: 1px solid var(--line); border-radius: var(--r-lg); overflow: auto;
    background: rgba(var(--surface-rgb), 0.5); -webkit-overflow-scrolling: touch;
}
table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 600px; }
th, td { padding: 11px 12px; border-bottom: 1px solid var(--line); text-align: left; white-space: nowrap; }
th {
    height: 38px; background: rgba(21, 29, 42, 0.8); font-family: var(--font-mono);
    font-size: 10px; letter-spacing: .08em; color: var(--text-dim); text-transform: uppercase; font-weight: 600;
}
tr:last-child td { border-bottom: none; }
tr:hover td { background: rgba(89, 245, 213, 0.035); }

/* Badges */
.badge {
    display: inline-flex; align-items: center; gap: 5px; padding: 3px 8px;
    border: 1px solid transparent; border-radius: var(--r-full); font-family: var(--font-mono); font-size: 10px; font-weight: 600;
}
.badge::before { content: ""; width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
.badge.on, .badge.active { background: rgba(97, 230, 164, 0.12); border-color: rgba(97, 230, 164, 0.25); color: var(--success); }
.badge.warn, .badge.pending { background: rgba(245, 201, 105, 0.12); border-color: rgba(245, 201, 105, 0.25); color: var(--warn); }
.badge.off, .badge.expired { background: rgba(147, 161, 181, 0.1); border-color: var(--line); color: var(--text-dim); }

/* Login Box */
.loginbox {
    max-width: 380px; margin: 12vh auto; padding: 32px 28px; border-radius: var(--r-2xl);
    border: 1px solid var(--line); background: var(--grad-card); box-shadow: var(--shadow-float);
    animation: ds-rise .4s var(--ease-out);
}
.loginbox h2 {
    margin: 0 0 20px; font-size: 24px; text-align: center;
    background: linear-gradient(135deg, var(--text) 40%, var(--cyan) 120%);
    -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
}
.loginbox::after {
    content: "RESELLER PORTAL"; display: block; margin-top: 22px;
    color: var(--text-muted); font: 10px var(--font-mono); letter-spacing: .18em; text-align: center;
}
.loginbox input { width: 100%; min-height: 44px; margin: 0 0 10px; border-radius: var(--r-md); box-sizing: border-box; }
.loginbox button { width: 100%; min-height: 44px; margin-top: 6px; border-radius: var(--r-md); }

/* Topbar & Language Switcher */
.topbar {
    position: sticky; top: 0; margin: 0 -28px 24px; padding: 14px 28px;
    border-bottom: 1px solid var(--line); background: rgba(7, 10, 16, 0.82);
    backdrop-filter: blur(18px); -webkit-backdrop-filter: blur(18px);
    display: flex; align-items: center; justify-content: space-between; gap: 10px; z-index: 20;
}
.topbar b { font-size: 15px; letter-spacing: -.01em; }
.topbar b::before { content: "RS / "; color: var(--cyan); font: 10px var(--font-mono); letter-spacing: .12em; }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.topbar > a, .topbar-right > a {
    padding: 7px 12px; border: 1px solid var(--line); border-radius: var(--r-sm);
    color: var(--text-dim) !important; text-decoration: none !important; font-size: 12.5px; transition: all .15s;
}
.topbar > a:hover, .topbar-right > a:hover { border-color: rgba(89,245,213,0.4); color: var(--cyan) !important; }
.lang-switch {
    display: flex; align-items: center; border: 1px solid var(--line); border-radius: var(--r-md);
    overflow: hidden; font-family: var(--font-mono); font-size: 11px; letter-spacing: .05em;
    background: var(--surface2);
}
.lang-switch a { padding: 6px 10px; color: var(--text-dim); text-decoration: none; transition: .15s; }
.lang-switch a.active { background: var(--grad-btn); color: #071018; font-weight: 700; }
.lang-switch a:not(.active):hover { color: var(--cyan); background: var(--surface3); }
.hamburger {
    width: 38px; height: 38px; min-height: 38px; padding: 7px; border: 1px solid var(--line);
    border-radius: var(--r-md); background: var(--surface2); color: var(--text); cursor: pointer; box-shadow: none;
}

/* Sidebar */
.sidebar-overlay { position: fixed; inset: 0; background: rgba(1, 3, 6, 0.72); backdrop-filter: blur(6px); opacity: 0; pointer-events: none; transition: opacity .25s ease; z-index: 40; }
.sidebar-overlay.open { opacity: 1; pointer-events: auto; }
.sidebar {
    position: fixed; top: 0; left: 0; bottom: 0; width: 290px; max-width: 82vw;
    background: linear-gradient(180deg, #111A27, #0D131D); border-right: 1px solid var(--line);
    z-index: 41; transform: translateX(-100%); transition: transform .28s var(--ease-out);
    display: flex; flex-direction: column; box-shadow: 18px 0 50px rgba(0,0,0,0.5);
}
.sidebar.open { transform: translateX(0); }
.sidebar-section-label { font-family: var(--font-mono); font-size: 10px; letter-spacing: .14em; color: var(--text-dim); text-transform: uppercase; padding: 24px 22px 14px; }
.sidebar-section-label::before { content: "◈ "; color: var(--cyan); }
.sidebar-nav { flex: 1; overflow-y: auto; padding: 0 12px; }
.sidebar-item {
    display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 1px solid transparent;
    border-radius: var(--r-md); margin-bottom: 3px; color: var(--text); text-decoration: none;
    font-size: 13.5px; font-weight: 500; transition: all .15s;
}
.sidebar-item:not(.active):hover { background: var(--surface2); }
.sidebar-item .ic { font-size: 17px; width: 20px; text-align: center; flex-shrink: 0; }
.sidebar-item.active { border-color: rgba(89,245,213,0.2); background: linear-gradient(90deg, rgba(89,245,213,0.1), rgba(156,140,255,0.04)); font-weight: 700; }
.sidebar-item.active .ic { color: var(--cyan); filter: drop-shadow(0 0 7px rgba(89,245,213,0.55)); }
.sidebar-item .chev { margin-left: auto; color: var(--text-dim); font-size: 15px; }
.sidebar-item.active .chev { color: var(--cyan); }
.sidebar-footer { padding: 16px; border-top: 1px solid var(--line); }
.sidebar-back {
    display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; min-height: 42px;
    background: var(--surface2); border: 1px solid var(--line); color: var(--text); border-radius: var(--r-md);
    text-decoration: none; font-weight: 700; font-size: 13px; margin-bottom: 10px; cursor: pointer; transition: all .15s;
}
.sidebar-back:hover { border-color: var(--cyan); color: var(--cyan); }
.sidebar-account { display: flex; align-items: center; gap: 10px; padding: 6px 4px; }
.sidebar-avatar {
    width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, var(--cyan), var(--violet));
    display: flex; align-items: center; justify-content: center; font-weight: 700; color: #071018; font-size: 14px; flex-shrink: 0;
    box-shadow: 0 0 20px rgba(89,245,213,0.2);
}
.sidebar-account-name { font-size: 13px; font-weight: 600; color: var(--text); }
.sidebar-account-handle { font-size: 11px; color: var(--text-dim); }

/* Rental Countdown Card */
.rent-box { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.rent-info { display: flex; align-items: center; gap: 14px; }
.rent-info .ic { color: var(--cyan); flex-shrink: 0; }
.rent-label { font-size: 10.5px; color: var(--text-dim); font-family: var(--font-mono); letter-spacing: .08em; text-transform: uppercase; font-weight: 600; }
.rent-countdown { display: flex; gap: 8px; font-family: var(--font-mono); }
.rent-unit {
    background: rgba(21, 29, 42, 0.85); border: 1px solid var(--line); border-radius: var(--r-md);
    padding: 8px 12px; text-align: center; min-width: 58px; box-shadow: inset 0 1px rgba(255,255,255,0.03);
}
.rent-unit b { display: block; font-size: 20px; color: var(--cyan); font-family: var(--font-mono); font-variant-numeric: tabular-nums; font-weight: 700; line-height: 1.1; }
.rent-unit span { font-size: 9px; color: var(--text-dim); letter-spacing: .08em; text-transform: uppercase; font-weight: 600; }

/* Game & Link Cards */
.game-card {
    padding: 18px; border: 1px solid var(--line); border-radius: var(--r-lg);
    background: rgba(7, 10, 16, 0.5); box-shadow: inset 0 1px rgba(255,255,255,0.025); margin-bottom: 14px;
    transition: border-color .15s, transform .15s;
}
.game-card:hover { transform: translateY(-2px); border-color: rgba(89,245,213,0.25); }
.game-head { display: flex; align-items: center; justify-content: space-between; padding-bottom: 12px; margin-bottom: 12px; border-bottom: 1px solid var(--line); flex-wrap: wrap; gap: 8px; }
.region-form { padding: 14px; border: 1px solid var(--line); border-radius: var(--r-md); background: rgba(21, 29, 42, 0.65); }
.region-form + .region-form { margin-top: 10px; }
.region-form input, .region-form select { width: 100%; margin: 4px 0; }
.step-row { display: flex; gap: 6px; flex-wrap: wrap; }
.step-row select { flex: 1; min-width: 110px; min-height: 38px; }

.getkey-link-card {
    margin-top: 14px; padding: 16px; border: 1px solid rgba(89,245,213,0.18); border-radius: var(--r-lg);
    background: linear-gradient(135deg, rgba(89,245,213,0.06), rgba(156,140,255,0.05));
}
.getkey-link-row { display: flex; gap: 8px; align-items: stretch; margin-top: 8px; }
.getkey-link-row input {
    flex: 1; min-width: 0; min-height: 42px; background: var(--bg); border: 1px solid var(--line-strong);
    border-radius: var(--r-md); padding: 0 14px; color: var(--text); font-family: var(--font-mono); font-size: 12.5px;
    margin: 0; cursor: pointer; text-overflow: ellipsis;
}
.getkey-link-row button {
    min-height: 42px; background: #FFFFFF; color: #071018; border-radius: var(--r-md); padding: 0 16px;
    display: flex; align-items: center; gap: 6px; font-family: var(--font-body); font-weight: 700; font-size: 12.5px;
    flex-shrink: 0; box-shadow: none;
}
.getkey-link-row button:hover { filter: brightness(0.95); }

.provider-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border: 1px solid var(--line); border-radius: var(--r-md); background: rgba(7, 10, 16, 0.48); margin-bottom: 8px; transition: transform .15s; }
.provider-row:hover { transform: translateX(2px); }

.dev-line {
    font-family: var(--font-mono); font-size: 11px; background: var(--surface2);
    border: 1px solid var(--line); border-radius: var(--r-sm); padding: 6px 9px;
    display: flex; align-items: center; gap: 8px; margin-top: 5px;
}
.dev-line .ic { color: var(--text-dim); flex-shrink: 0; }

@media (max-width: 768px) {
    body { padding: 0 16px 48px; }
    .topbar { margin: 0 -16px 20px; padding: 12px 16px; }
    .box { padding: 18px 16px; border-radius: var(--r-lg); }
    .box::before { left: 16px; right: 16px; }
    .rent-box { flex-direction: column; align-items: flex-start; }
}
@media (max-width: 480px) {
    .getkey-link-row { flex-direction: column; }
    .getkey-link-row button { padding: 10px; justify-content: center; }
    .rent-countdown { width: 100%; justify-content: space-between; }
    .rent-unit { flex: 1; min-width: 0; padding: 6px 4px; }
    .rent-unit b { font-size: 17px; }
}
</style>
</head>
<body>

<?php if ($RID === null): ?>

    <div class="topbar" style="margin-bottom:0;justify-content:flex-end">
        <div class="lang-switch">
            <a href="?lang=vi" class="<?= $LANG==='vi'?'active':'' ?>">VIE</a>
            <a href="?lang=en" class="<?= $LANG==='en'?'active':'' ?>">EN</a>
        </div>
    </div>

    <div class="box loginbox">
        <h2><?= t('Đăng nhập Reseller', 'Reseller Login') ?></h2>
        <?php if (isset($timedOut)): ?><p class="err"><?= t('Phiên đăng nhập đã hết hạn, vui lòng đăng nhập lại', 'Session expired, please log in again') ?></p><?php endif; ?>
        <?php if (isset($error)): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <?php if ($isLocked): $lockMin = ceil($lockRemaining / 60); ?>
            <p class="err"><?= t("Tài khoản tạm khoá do sai nhiều lần, thử lại sau {$lockMin} phút.", "Account temporarily locked due to failed attempts, try again in {$lockMin} minutes.") ?></p>
        <?php else: ?>
        <form method="post">
            <input type="text" name="reseller_login_username" placeholder="Username" required><br>
            <input type="password" name="reseller_login_password" placeholder="<?= t('Mật khẩu', 'Password') ?>" required><br>
            <button type="submit"><?= t('Đăng nhập', 'Log in') ?></button>
        </form>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="topbar">
        <button class="hamburger" onclick="openSidebar()"><?= svg_icon('menu', 20) ?></button>
        <b><?= [
            'keys' => t('Tạo Key', 'Create Key'),
            'game' => t('Cấu hình Game', 'Game Config'),
            'link' => t('Cấu hình Link', 'Link Config'),
            'channel' => t('Cấu hình kênh', 'Channel Config'),
        ][$tab] ?? '' ?></b>
        <div class="topbar-right">
            <div class="lang-switch">
                <a href="?tab=<?= $tab ?>&lang=vi" class="<?= $LANG==='vi'?'active':'' ?>">VIE</a>
                <a href="?tab=<?= $tab ?>&lang=en" class="<?= $LANG==='en'?'active':'' ?>">EN</a>
            </div>
            <a href="reseller.php?logout=1"><?= t('Đăng xuất', 'Logout') ?></a>
        </div>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-section-label">RESELLER PORTAL</div>
        <div class="sidebar-nav">
            <a href="reseller.php?tab=keys" class="sidebar-item <?= $tab==='keys'?'active':'' ?>">
                <span class="ic"><?= svg_icon('key') ?></span><span class="label"><?= t('Tạo Key', 'Create Key') ?></span>
                <?php if ($tab==='keys'): ?><span class="chev"><?= svg_icon('chevron', 15) ?></span><?php endif; ?>
            </a>
            <a href="reseller.php?tab=game" class="sidebar-item <?= $tab==='game'?'active':'' ?>">
                <span class="ic"><?= svg_icon('gamepad') ?></span><span class="label"><?= t('Cấu hình Game', 'Game Config') ?></span>
                <?php if ($tab==='game'): ?><span class="chev"><?= svg_icon('chevron', 15) ?></span><?php endif; ?>
            </a>
            <a href="reseller.php?tab=link" class="sidebar-item <?= $tab==='link'?'active':'' ?>">
                <span class="ic"><?= svg_icon('link') ?></span><span class="label"><?= t('Cấu hình Link', 'Link Config') ?></span>
                <?php if ($tab==='link'): ?><span class="chev"><?= svg_icon('chevron', 15) ?></span><?php endif; ?>
            </a>
            <a href="reseller.php?tab=channel" class="sidebar-item <?= $tab==='channel'?'active':'' ?>">
                <span class="ic"><?= svg_icon('gamepad') ?></span><span class="label"><?= t('Cấu hình kênh', 'Channel Config') ?></span>
                <?php if ($tab==='channel'): ?><span class="chev"><?= svg_icon('chevron', 15) ?></span><?php endif; ?>
            </a>
        </div>
        <div class="sidebar-footer">
            <a href="<?= htmlspecialchars($publicGetkeyBase) ?>" class="sidebar-back" target="_blank"><?= svg_icon('store', 16) ?> <?= t('Xem trang lấy key của tôi', 'View my get-key page') ?></a>
            <div class="sidebar-account">
                <div class="sidebar-avatar"><?= htmlspecialchars(strtoupper(substr($me['username'], 0, 1))) ?></div>
                <div>
                    <div class="sidebar-account-name"><?= htmlspecialchars($me['store_name'] ?: $me['username']) ?></div>
                    <div class="sidebar-account-handle"><?= t('Reseller', 'Reseller') ?></div>
                </div>
            </div>
        </div>
    </div>
    <script>
    function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('sidebarOverlay').classList.add('open');}
    function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebarOverlay').classList.remove('open');}
    </script>

    <?php if ($notice): ?><p class="<?= $notice['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars($notice['msg']) ?></p><?php endif; ?>

    <!-- Đồng hồ đếm ngược hạn thuê tài khoản (giờ Việt Nam) -->
    <div class="box rent-box">
        <div class="rent-info">
            <span class="ic"><?= svg_icon('clock', 22) ?></span>
            <div>
                <div class="rent-label"><?= t('HẠN THUÊ TÀI KHOẢN', 'ACCOUNT RENTAL EXPIRES') ?></div>
                <?php if (empty($me['expires_at'])): ?>
                    <div style="font-size:14px;font-weight:700;color:var(--success);margin-top:2px"><?= t('Vĩnh viễn - không giới hạn', 'Permanent - no expiry') ?></div>
                <?php else: ?>
                    <div style="font-size:12.5px;color:var(--text-dim);margin-top:2px"><?= t('Hết hạn lúc', 'Expires at') ?> <b style="color:var(--text)"><?= fmt_time_r((int)$me['expires_at']) ?></b> (<?= t('giờ Việt Nam', 'Vietnam time') ?>)</div>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($me['expires_at'])): ?>
        <div class="rent-countdown" id="rentCountdown" data-expires="<?= (int)$me['expires_at'] ?>" data-now="<?= time() ?>">
            <div class="rent-unit"><b id="cdDays">--</b><span><?= t('NGÀY', 'DAYS') ?></span></div>
            <div class="rent-unit"><b id="cdHours">--</b><span><?= t('GIỜ', 'HRS') ?></span></div>
            <div class="rent-unit"><b id="cdMinutes">--</b><span><?= t('PHÚT', 'MIN') ?></span></div>
            <div class="rent-unit"><b id="cdSeconds">--</b><span><?= t('GIÂY', 'SEC') ?></span></div>
        </div>
        <script>
        (function(){
            var box = document.getElementById('rentCountdown');
            var expiresAt = parseInt(box.dataset.expires, 10) * 1000;
            var serverNow = parseInt(box.dataset.now, 10) * 1000;
            var clientNow = Date.now();
            var offset = clientNow - serverNow; // lệch giờ máy user so với server (giờ VN)
            function tick(){
                var remaining = expiresAt - (Date.now() - offset);
                if (remaining <= 0) {
                    document.getElementById('cdDays').textContent = '00';
                    document.getElementById('cdHours').textContent = '00';
                    document.getElementById('cdMinutes').textContent = '00';
                    document.getElementById('cdSeconds').textContent = '00';
                    return;
                }
                var d = Math.floor(remaining / 86400000);
                var h = Math.floor((remaining % 86400000) / 3600000);
                var m = Math.floor((remaining % 3600000) / 60000);
                var s = Math.floor((remaining % 60000) / 1000);
                document.getElementById('cdDays').textContent = String(d).padStart(2,'0');
                document.getElementById('cdHours').textContent = String(h).padStart(2,'0');
                document.getElementById('cdMinutes').textContent = String(m).padStart(2,'0');
                document.getElementById('cdSeconds').textContent = String(s).padStart(2,'0');
            }
            tick();
            setInterval(tick, 1000);
        })();
        </script>
        <?php endif; ?>
    </div>

    <div class="box">
        <h4 style="margin:0 0 6px;color:var(--text-dim)"><?= t('Tên cửa hàng (hiện làm tiêu đề ở trang lấy key riêng của bạn)', 'Store name (shown as the title on your public get-key page)') ?></h4>
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="text" name="store_name" value="<?= htmlspecialchars($me['store_name'] ?? '') ?>" placeholder="<?= t('VD: AuraShop, để trống = dùng username', 'E.g. AuraShop, leave blank = use username') ?> (<?= htmlspecialchars($me['username']) ?>)" style="flex:1;min-width:200px">
            <button type="submit" name="save_store_name" value="1"><?= t('Lưu tên', 'Save name') ?></button>
        </form>
    </div>

    <div class="box">
        <h4 style="margin:0 0 6px;color:var(--text-dim)"><?= t('Link liên hệ riêng của bạn', 'Your own contact links') ?></h4>
        <p style="font-size:11px;color:var(--text-dim);margin:0 0 10px"><?= t('Hiện ở nút liên hệ nổi trên trang lấy key riêng của bạn, thay cho link mặc định của hệ thống. Để trống ô nào thì ô đó tự dùng lại link mặc định.', 'Shown on the floating contact button on your own get-key page, replacing the system default. Leave a field blank to fall back to the system default for that channel.') ?></p>
        <form method="post" style="display:flex;flex-direction:column;gap:8px;max-width:420px">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="text" name="contact_zalo" value="<?= htmlspecialchars($me['contact_zalo'] ?? '') ?>" placeholder="Zalo (https://zalo.me/09xxxxxxxx)">
            <input type="text" name="contact_telegram" value="<?= htmlspecialchars($me['contact_telegram'] ?? '') ?>" placeholder="Telegram (https://t.me/username)">
            <input type="text" name="contact_facebook" value="<?= htmlspecialchars($me['contact_facebook'] ?? '') ?>" placeholder="Facebook (https://fb.com/fanpage)">
            <button type="submit" name="save_contact" value="1" style="align-self:flex-start"><?= t('Lưu link liên hệ', 'Save contact links') ?></button>
        </form>
    </div>

    <div class="box getkey-link-card">
        <h4 style="margin:0 0 6px;color:var(--text)"><?= svg_icon('store', 15) ?> <?= t('Trang lấy key riêng của bạn', 'Your public get-key page') ?></h4>
        <div class="getkey-link-row">
            <input readonly id="pubLink" value="<?= htmlspecialchars($publicGetkeyBase) ?>" onclick="this.select()">
            <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('pubLink').value);this.textContent='✓'"><?= svg_icon('copy', 15) ?> <?= t('Copy', 'Copy') ?></button>
        </div>
        <p style="font-size:11px;color:var(--text-dim);margin:10px 0 0"><?= t('Chỉ hiện các game bạn tạo ở tab "Cấu hình Game". User vào đây tự lấy key qua vượt link - dùng chung hệ thống hop.php/confirm.php với trang chính, nhưng cấu hình Link/Kênh hoàn toàn riêng của bạn.', 'Only shows games you created in the "Game Config" tab. Users get keys here through the link-verification flow - shares the hop.php/confirm.php system with the main site, but your Link/Channel config is entirely your own.') ?></p>
    </div>

    <?php if ($tab === 'game'): ?>

    <div class="box">
        <h3><?= t('Thêm Game / Sản phẩm', 'Add Game / Product') ?></h3>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="text" name="game_slug" required placeholder="slug (vd: valorant-vip)" style="width:200px">
            <input type="text" name="game_name" required placeholder="<?= t('Tên hiển thị', 'Display name') ?>" style="width:200px">
            <input type="text" name="game_icon" placeholder="<?= t('Icon emoji (vd: 🎮)', 'Emoji icon (e.g. 🎮)') ?>" style="width:120px">
            <button type="submit" name="add_game" value="1">+ <?= t('Thêm', 'Add') ?></button>
            <p style="font-size:11px;color:var(--text-dim);margin:6px 0 0"><?= t('Lưu ý: slug phải DUY NHẤT trên toàn hệ thống (kể cả game của admin hay reseller khác).', 'Note: slug must be UNIQUE system-wide (including admin\'s or other resellers\' games).') ?></p>
        </form>
    </div>

    <?php foreach ($games as $g): $cfgKeys = array_keys($cfg['keys'] ?? []); ?>
    <div class="box game-card">
        <div class="game-head">
            <b style="font-size:15px"><?= htmlspecialchars($g['icon']) ?> <?= htmlspecialchars($g['name']) ?></b>
            <span style="display:flex;align-items:center;gap:8px">
                <span class="badge <?= $g['enabled'] ? 'on' : 'off' ?>"><?= $g['enabled'] ? t('đang mở', 'open') : t('đã tắt', 'off') ?></span>
                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="toggle_game_id" value="<?= $g['id'] ?>"><button class="warn small" type="submit"><?= $g['enabled'] ? t('Tắt', 'Disable') : t('Bật', 'Enable') ?></button></form>
                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="delete_game_id" value="<?= $g['id'] ?>"><button class="danger small" type="submit" onclick="return confirm('<?= t('Xoá game này?', 'Delete this game?') ?>')"><?= t('Xoá', 'Delete') ?></button></form>
            </span>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="save_region_game_id" value="<?= $g['id'] ?>">
            <?php foreach (['vn', 'intl'] as $region): $chain = array_filter(explode(',', $g["{$region}_chain"])); ?>
            <div class="region-form">
                <b style="font-size:13px"><?= svg_icon($regionInfo[$region]['icon'], 14) ?> <?= $regionInfo[$region]['label'] ?></b>
                <p style="font-size:11.5px;color:var(--text-dim);margin:2px 0 0"><?= t('Số lần vượt/hạn key giờ do người dùng chọn ở màn nhiệm vụ (Key 20h/40h). Mục này chỉ còn dùng để chọn thứ tự provider.', 'Number of hops/key duration is now chosen by the user on the task screen (20h/40h Key). This section is only for choosing the provider order.') ?></p>
                <input type="hidden" name="<?= $region ?>_hops" value="<?= (int)$g["{$region}_hops"] ?>">
                <input type="hidden" name="<?= $region ?>_hours" value="<?= (int)$g["{$region}_key_hours"] ?>">
                <label style="font-size:12px;color:#888"><?= t('Thứ tự link vượt (để trống = dùng provider active)', 'Link order (leave blank = use active provider)') ?></label>
                <div class="step-row">
                    <?php for ($i = 1; $i <= 5; $i++): $cur = $chain[$i - 1] ?? ''; ?>
                    <select name="<?= $region ?>_chain_step_<?= $i ?>">
                        <option value=""><?= t('Bước', 'Step') ?> <?= $i ?>: -</option>
                        <?php foreach ($cfgKeys as $p): ?><option value="<?= htmlspecialchars($p) ?>" <?= $cur===$p?'selected':'' ?>><?= htmlspecialchars($p) ?></option><?php endforeach; ?>
                    </select>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <button type="submit" style="margin-top:10px"><?= t('Lưu thứ tự link', 'Save link order') ?></button>
        </form>
    </div>
    <?php endforeach; ?>
    <?php if (empty($games)): ?><div class="box" style="text-align:center;color:var(--text-dim)"><?= t('Chưa có game nào.', 'No games yet.') ?></div><?php endif; ?>

    <?php elseif ($tab === 'link'): ?>

    <div class="box">
        <h3><?= t('Nhà cung cấp rút gọn link (riêng của bạn)', 'Link shortener providers (yours only)') ?></h3>
        <h4 style="color:#aaa;font-weight:normal"><?= t('Provider đã lưu key', 'Providers with saved keys') ?></h4>
        <?php if (empty($cfg['keys'])): ?><p style="color:var(--text-dim);font-size:12.5px"><?= t('Chưa có provider nào.', 'No providers yet.') ?></p><?php endif; ?>
        <?php foreach ($cfg['keys'] as $p => $k): ?>
        <div class="provider-row">
            <span><?= htmlspecialchars($p) ?> <?php if ($cfg['active'] === $p): ?><span class="badge on">active</span><?php endif; ?></span>
            <span>
                <?php if ($cfg['active'] !== $p): ?><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="set_active_provider" value="<?= htmlspecialchars($p) ?>"><button class="small" type="submit"><?= t('Đặt active', 'Set active') ?></button></form><?php endif; ?>
                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="delete_provider" value="<?= htmlspecialchars($p) ?>"><button class="danger small" type="submit" onclick="return confirm('<?= t('Xoá provider này?', 'Delete this provider?') ?>')"><?= t('Xoá', 'Delete') ?></button></form>
            </span>
        </div>
        <?php endforeach; ?>

        <h4 style="color:#aaa;font-weight:normal;margin-top:16px"><?= t('Thêm / cập nhật API key cho provider có sẵn', 'Add / update API key for an existing provider') ?></h4>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <select name="provider">
                <?php foreach (get_builtin_providers() as $key => $info): ?><option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($info['label']) ?><?= isset($cfg['keys'][$key]) ? ' (' . t('đã có key', 'key saved') . ')' : '' ?></option><?php endforeach; ?>
            </select>
            <input type="text" name="api_key" placeholder="API Key" style="width:260px">
            <button type="submit"><?= t('Lưu key cho provider này', 'Save key for this provider') ?></button>
        </form>

        <h4 style="color:#aaa;font-weight:normal;margin-top:16px"><?= t('Hoặc thêm provider khác (không có sẵn trong danh sách)', 'Or add a custom provider (not in the list)') ?></h4>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="text" name="custom_label" placeholder="<?= t('Tên provider (vd: shortlink)', 'Provider name (e.g. shortlink)') ?>" style="width:200px"><br>
            <input type="text" name="custom_url" placeholder="<?= t('URL API, dùng {api} và {url} làm placeholder', 'API URL, use {api} and {url} as placeholders') ?>" style="width:min(100%,420px)"><br>
            <select name="custom_type"><option value="json"><?= t('Response JSON', 'JSON response') ?></option><option value="plain"><?= t('Response Plain Text', 'Plain text response') ?></option></select>
            <input type="text" name="custom_field" placeholder="<?= t('Tên field chứa link (nếu JSON)', 'Field name containing the link (if JSON)') ?>" style="width:220px"><br>
            <input type="text" name="custom_api_key" placeholder="API Key" style="width:260px">
            <button type="submit"><?= t('Lưu provider tuỳ chỉnh', 'Save custom provider') ?></button>
        </form>
    </div>

    <?php elseif ($tab === 'channel'): ?>

    <div class="box">
        <h3><?= t('Cấu hình kênh (riêng của bạn)', 'Channel config (yours only)') ?></h3>
        <p style="font-size:12.5px;color:var(--text-dim);margin-top:-5px"><?= t('Kênh đang bật sẽ là nhiệm vụ bắt buộc trước khi user của bạn nhận link rút gọn đầu tiên.', 'Enabled channels become mandatory tasks before your users get their first shortlink.') ?></p>
        <div class="region-form">
            <h4 style="margin:0 0 9px;color:var(--text)"><?= t('Thêm kênh nhiệm vụ', 'Add task channel') ?></h4>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <select name="channel_type"><?php foreach ($channelTypes as $key => $label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?></select>
                <input type="number" name="channel_sort_order" value="0" min="0" style="width:80px" placeholder="<?= t('Thứ tự', 'Order') ?>">
                <input type="text" name="channel_label" required placeholder="<?= t('Tên hiển thị', 'Display name') ?>" style="width:220px">
                <input type="url" name="channel_url" required placeholder="https://t.me/yourgroup" style="width:min(100%,340px)"><br>
                <input type="text" name="channel_requirement" placeholder="<?= t('Yêu cầu (vd: Tham gia nhóm)', 'Requirement (e.g. Join group)') ?>" style="width:min(100%,380px)"><br>
                <input type="text" name="channel_tg_chat_id" placeholder="<?= t('Telegram Chat ID (để trống nếu không xác minh thật)', 'Telegram Chat ID (leave blank if no real verification needed)') ?>" style="width:min(100%,380px)">
                <label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:var(--text-dim)"><input type="checkbox" name="channel_enabled" checked style="min-height:auto"> <?= t('Bật ngay', 'Enable now') ?></label>
                <button type="submit" name="add_channel" value="1">+ <?= t('Thêm kênh', 'Add channel') ?></button>
            </form>
        </div>
        <div class="tablewrap" style="margin-top:14px">
            <table>
                <tr><th><?= t('Thứ tự', 'Order') ?></th><th><?= t('Kênh', 'Channel') ?></th><th><?= t('Yêu cầu', 'Requirement') ?></th><th><?= t('Trạng thái', 'Status') ?></th><th></th></tr>
                <?php foreach ($channelRows as $c): ?>
                <tr>
                    <td><?= (int)$c['sort_order'] ?></td>
                    <td><b><?= htmlspecialchars($channelTypes[$c['type']] ?? t('Khác', 'Other')) ?></b><br><span style="font-size:11px;color:var(--text-dim)"><?= htmlspecialchars($c['label']) ?></span></td>
                    <td><?= htmlspecialchars($c['requirement'] ?: '-') ?></td>
                    <td><span class="badge <?= $c['enabled'] ? 'on' : 'off' ?>"><?= $c['enabled'] ? t('bật', 'on') : t('tắt', 'off') ?></span></td>
                    <td>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="toggle_channel_id" value="<?= $c['id'] ?>"><button class="warn small" type="submit"><?= $c['enabled'] ? t('Tắt', 'Disable') : t('Bật', 'Enable') ?></button></form>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="delete_channel_id" value="<?= $c['id'] ?>"><button class="danger small" type="submit" onclick="return confirm('<?= t('Xoá kênh?', 'Delete channel?') ?>')"><?= t('Xoá', 'Delete') ?></button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($channelRows)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:16px"><?= t('Chưa có kênh nào.', 'No channels yet.') ?></td></tr><?php endif; ?>
            </table>
        </div>
    </div>

    <?php else: /* tab === 'keys' */ ?>

    <div class="box">
        <h3><?= t('Tạo Key thủ công', 'Create Key manually') ?></h3>
        <p style="font-size:11.5px;color:var(--text-dim);margin-top:-8px"><?= t('Reseller chỉ được tạo key theo', 'Resellers can only create keys in') ?> <b><?= t('Giờ', 'Hours') ?></b> <?= t('hoặc', 'or') ?> <b><?= t('Ngày', 'Days') ?></b>. <?= t('Key Tuần/Tháng/Vĩnh viễn chỉ admin mới tạo được.', 'Week/Month/Permanent keys can only be created by the admin.') ?></p>
        <?php if (!empty($createdKeys)): ?>
        <div style="background:var(--bg);border-radius:10px;padding:12px;margin-bottom:12px">
            <p class="ok"><?= t('Đã tạo', 'Created') ?> <?= count($createdKeys) ?> key:</p>
            <textarea readonly id="ck" style="width:100%;min-height:100px;background:var(--surface2);color:var(--cyan);font-family:monospace;font-size:12.5px;border:1px solid #262b38;border-radius:8px;padding:8px"><?= htmlspecialchars(implode("\n", $createdKeys)) ?></textarea>
            <button type="button" class="small" onclick="navigator.clipboard.writeText(document.getElementById('ck').value)"><?= t('Copy tất cả', 'Copy all') ?></button>
        </div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="number" name="quantity" value="1" min="1" max="200" style="width:70px"> key ×
            <input type="number" name="duration_value" value="1" min="1" style="width:70px">
            <select name="duration_unit">
                <option value="hour"><?= t('Giờ', 'Hour') ?></option>
                <option value="day" selected><?= t('Ngày', 'Day') ?></option>
            </select>
            <input type="number" name="max_devices" value="1" min="1" style="width:80px"> <?= t('thiết bị', 'devices') ?>
            <button type="submit" name="create_key" value="1"><?= t('Tạo Key', 'Create Key') ?></button>
        </form>
    </div>

    <div class="box">
        <h3><?= t('Key của bạn', 'Your keys') ?> (<?= count($myKeys) ?>)</h3>
        <div class="tablewrap">
            <table>
                <tr><th>Key</th><th><?= t('Trạng thái', 'Status') ?></th><th><?= t('Thời hạn', 'Duration') ?></th><th><?= t('IP tạo key', 'Created from IP') ?></th><th><?= t('Thiết bị đăng nhập', 'Logged-in devices') ?></th><th><?= t('Ngày tạo', 'Created') ?></th></tr>
                <?php foreach ($myKeys as $k):
                    $status = $k['status'];
                    if ($status === 'active' && $k['expires_at'] && time() > (int)$k['expires_at']) $status = 'expired';
                    $devList = $k['devices'] !== '' ? explode(',', $k['devices']) : [];
                    $ipMap = json_decode($k['device_ip_map'] ?: '{}', true) ?: [];
                ?>
                <tr>
                    <td style="font-family:monospace"><?= htmlspecialchars($k['keycode']) ?></td>
                    <td><span class="badge <?= $status==='active'?'on':'off' ?>"><?= htmlspecialchars($status) ?></span></td>
                    <td><?= fmt_duration_r((int)$k['duration_seconds']) ?></td>
                    <td style="font-family:monospace;font-size:11px"><?= svg_icon('wifi', 12) ?> <?= htmlspecialchars($k['client_ip'] ?: '-') ?></td>
                    <td>
                        <?php if (empty($devList)): ?>
                            <span style="color:var(--text-dim);font-size:11px">0/<?= (int)$k['max_devices'] ?></span>
                        <?php else: ?>
                        <details>
                            <summary style="cursor:pointer;color:var(--cyan);font-size:11.5px"><?= count($devList) ?>/<?= (int)$k['max_devices'] ?> <?= t('thiết bị', 'devices') ?></summary>
                            <?php foreach ($devList as $dev): $ip = $ipMap[$dev] ?? '?'; ?>
                            <div class="dev-line"><span class="ic"><?= svg_icon('phone', 12) ?></span><span title="<?= htmlspecialchars($dev) ?>"><?= htmlspecialchars(substr($dev, 0, 10)) ?>… · IP <?= htmlspecialchars($ip) ?></span></div>
                            <?php endforeach; ?>
                        </details>
                        <?php endif; ?>
                    </td>
                    <td><?= fmt_time_r((int)$k['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($myKeys)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:16px"><?= t('Chưa có key nào.', 'No keys yet.') ?></td></tr><?php endif; ?>
            </table>
        </div>
    </div>

    <?php endif; ?>

<?php endif; ?>
<?= anti_devtools_script() ?>
</body></html>
