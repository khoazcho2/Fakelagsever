<?php
// ============================================================
// admin.php - Trang quản trị Key Server (chỉ admin dùng)
// Chia 3 danh mục qua menu 3 gạch (☰): Tạo Key, Cấu hình Game,
// Cấu hình Link (nhà cung cấp rút gọn link)
// ============================================================
require_once __DIR__ . '/config.php';

// Không hiện chi tiết lỗi PHP ra ngoài (tránh lộ đường dẫn file, cấu
// trúc code qua dev tool/view-source nếu có lỗi bất ngờ xảy ra)
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Cookie session an toàn: HttpOnly (JS không đọc được cookie session,
// chặn đánh cắp qua XSS) + SameSite=Strict (chặn CSRF gửi kèm cookie từ
// site khác) + Secure (chỉ gửi qua HTTPS). Phải set TRƯỚC session_start().
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

// Header chống clickjacking (không cho nhúng trang admin vào <iframe> ở
// site khác) + chống MIME-sniffing + hạn chế thông tin referrer rò rỉ
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: frame-ancestors 'none'");

define('ADMIN_SESSION_TIMEOUT', 15 * 60);

if (isset($_SESSION['is_admin']) && isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > ADMIN_SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        session_start();
        $timedOut = true;
    }
}
$_SESSION['last_activity'] = time();

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: admin.php');
    exit;
}

$maxAttempts = 5;
$lockSeconds = 5 * 60;
$isLocked = isset($_SESSION['lock_until']) && time() < $_SESSION['lock_until'];

if (!$isLocked && isset($_POST['admin_username'], $_POST['admin_password'])) {
    $okUser = hash_equals(ADMIN_USERNAME, $_POST['admin_username']);
    $okPass = verify_admin_password($_POST['admin_password']);
    if ($okUser && $okPass) {
        $_SESSION['is_admin'] = true;
        $_SESSION['fail_count'] = 0;
        unset($_SESSION['lock_until']);
        // Sinh CSRF token mới cho phiên đăng nhập này
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } else {
        $_SESSION['fail_count'] = ($_SESSION['fail_count'] ?? 0) + 1;
        if ($_SESSION['fail_count'] >= $maxAttempts) {
            $_SESSION['lock_until'] = time() + $lockSeconds;
            $error = 'Sai quá nhiều lần, thử lại sau ' . ($lockSeconds / 60) . ' phút';
        } else {
            $error = 'Sai tài khoản hoặc mật khẩu';
        }
    }
}
$isLocked = isset($_SESSION['lock_until']) && time() < $_SESSION['lock_until'];

// Session cũ đã đăng nhập từ trước khi có CSRF token -> tự sinh 1 cái
// (chỉ áp dụng khi đang GET, để lần POST kế tiếp đã có token embed sẵn)
if (isset($_SESSION['is_admin']) && !isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Chống CSRF: mọi request POST khi đã đăng nhập (trừ chính form đăng
// nhập ở trên) đều phải kèm đúng csrf_token của phiên hiện tại. Không
// có/sai token -> huỷ request, không cho thực hiện bất kỳ thao tác nào.
// Chặn được kiểu tấn công dụ admin đang đăng nhập bấm vào link/trang lạ
// tự động submit form tới admin.php để âm thầm đóng server, xoá key...
if (isset($_SESSION['is_admin']) && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['admin_username'])) {
    $csrfOk = isset($_POST['csrf_token'], $_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
    if (!$csrfOk) {
        http_response_code(403);
        die('CSRF token không hợp lệ hoặc đã hết hạn. Vui lòng tải lại trang và thử lại.');
    }
}

$db = get_db();

if (isset($_SESSION['is_admin'])) {

    if (isset($_POST['provider'], $_POST['api_key']) && $_POST['provider'] !== 'custom') {
        save_shortener_config(trim($_POST['provider']), trim($_POST['api_key']));
        $saved = true;
    }

    if (isset($_POST['provider']) && $_POST['provider'] === 'custom' && isset($_POST['custom_url'], $_POST['custom_api_key'])) {
        save_custom_provider(
            trim($_POST['custom_label'] ?? 'Custom'),
            trim($_POST['custom_url']),
            $_POST['custom_type'] === 'plain' ? 'plain' : 'json',
            trim($_POST['custom_field'] ?? ''),
            trim($_POST['custom_api_key'])
        );
        $saved = true;
    }

    if (isset($_POST['switch_active'])) {
        set_active_provider(trim($_POST['switch_active']));
        $saved = true;
    }

    // Xoá 1 provider (API key) khỏi cấu hình
    if (isset($_POST['delete_provider'])) {
        delete_shortener_provider(trim($_POST['delete_provider']));
        $saved = true;
    }

    // Thông báo public hiển thị trong luồng Get Key
    if (isset($_POST['add_site_notice'])) {
        $title = trim($_POST['notice_title'] ?? '');
        $message = trim($_POST['notice_message'] ?? '');
        if ($title !== '' && $message !== '') {
            create_site_notice($title, $message, $_POST['notice_type'] ?? 'info', isset($_POST['notice_enabled']));
            $noticeSaved = true;
        } else {
            $noticeError = 'Nhập đủ tiêu đề và nội dung thông báo.';
        }
    }
    if (isset($_POST['toggle_site_notice_id'])) {
        toggle_site_notice((int)$_POST['toggle_site_notice_id']);
        $noticeSaved = true;
    }
    if (isset($_POST['delete_site_notice_id'])) {
        delete_site_notice((int)$_POST['delete_site_notice_id']);
        $noticeSaved = true;
    }

    // Kênh nhiệm vụ: user phải hoàn thành phần này trước shortlink đầu tiên
    if (isset($_POST['add_channel'])) {
        $label = trim($_POST['channel_label'] ?? '');
        $url = trim($_POST['channel_url'] ?? '');
        if ($label === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            $channelError = 'Nhập tên kênh và URL hợp lệ (bắt đầu bằng https://).';
        } else {
            create_channel(
                $_POST['channel_type'] ?? 'other',
                (int)($_POST['channel_sort_order'] ?? 0),
                $label,
                $url,
                trim($_POST['channel_requirement'] ?? ''),
                isset($_POST['channel_enabled'])
            );
            $channelSaved = true;
        }
    }
    if (isset($_POST['toggle_channel_id'])) {
        toggle_channel((int)$_POST['toggle_channel_id']);
        $channelSaved = true;
    }
    if (isset($_POST['delete_channel_id'])) {
        delete_channel((int)$_POST['delete_channel_id']);
        $channelSaved = true;
    }

    if (isset($_POST['create_key'])) {
        $value = max(1, (int)($_POST['duration_value'] ?? 1));
        $unit = $_POST['duration_unit'] ?? 'day';
        $maxDevices = max(1, (int)($_POST['max_devices'] ?? 1));
        $quantity = max(1, min(200, (int)($_POST['quantity'] ?? 1))); // giới hạn 200/lần tránh spam DB
        $durationSeconds = duration_to_seconds($value, $unit);

        $createdKeys = [];
        $stmt = $db->prepare("INSERT INTO keys (keycode, token, status, duration_seconds, max_devices, created_at, activated_at)
                               VALUES (?, ?, 'active', ?, ?, ?, ?)");
        for ($i = 0; $i < $quantity; $i++) {
            $keycode = generate_keycode();
            $token = random_string(32);
            db_execute($stmt, [$keycode, $token, $durationSeconds, $maxDevices, time(), time()]);
            $createdKeys[] = $keycode;
        }
        // Key admin tạo tay cũng tính vào tổng "đã phát" (khớp hành vi cũ:
        // COUNT(*) WHERE activated_at IS NOT NULL từng đếm cả key loại
        // này) - nhưng giờ cộng dồn vĩnh viễn, xoá key sau này không trừ.
        if ($quantity > 0) {
            increment_site_counter('keys_issued', $quantity);
        }
    }

    // Xoá hàng loạt tất cả key đã hết hạn + key pending bỏ dở quá 1 tiếng
    if (isset($_POST['cleanup_expired'])) {
        $now = time();
        $stmtExpired = $db->prepare("DELETE FROM keys WHERE status = 'expired' OR (status = 'active' AND expires_at IS NOT NULL AND expires_at < ?)");
        db_execute($stmtExpired, [$now]);
        $cleanupExpiredCount = $stmtExpired->rowCount();
        $cleanupPendingCount = cleanup_stale_pending_keys($db, 0); // bấm tay -> xoá hết pending ngay, không đợi đủ 1h
    }

    if (isset($_POST['delete_id'])) {
        $db->prepare("DELETE FROM keys WHERE id = ?")->execute([(int)$_POST['delete_id']]);
    }

    if (isset($_POST['reset_id'])) {
        $db->prepare("UPDATE keys SET devices='', device_ip_map='{}', first_used_at=NULL, expires_at=NULL, status='active' WHERE id = ?")
           ->execute([(int)$_POST['reset_id']]);
    }

    // Cấm 1 thiết bị vĩnh viễn (không đăng nhập được bất kỳ key nào nữa)
    if (isset($_POST['ban_device_id'])) {
        ban_device(trim($_POST['ban_device_id']), trim($_POST['ban_device_reason'] ?? 'Bị cấm từ trang Quản lý Key'));
    }

    // Gỡ cấm 1 thiết bị
    if (isset($_POST['unban_device_id'])) {
        unban_device(trim($_POST['unban_device_id']));
    }

    // Đóng / mở lại server (công tắc khẩn cấp)
    if (isset($_POST['close_server'])) {
        set_server_closed(true, trim($_POST['close_reason'] ?? ''));
    }
    if (isset($_POST['open_server'])) {
        set_server_closed(false);
    }

    // Đổi mật khẩu admin
    if (isset($_POST['change_password'])) {
        $curPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $newPass2 = $_POST['new_password_confirm'] ?? '';
        if (!verify_admin_password($curPass)) {
            $pwError = 'Mật khẩu hiện tại không đúng';
        } elseif (strlen($newPass) < 6) {
            $pwError = 'Mật khẩu mới phải từ 6 ký tự trở lên';
        } elseif ($newPass !== $newPass2) {
            $pwError = 'Xác nhận mật khẩu không khớp';
        } else {
            set_admin_password($newPass);
            $pwSuccess = true;
        }
    }

    // Khôi phục dữ liệu thủ công từ file backup đã tải xuống trước đó
    if (isset($_POST['restore_backup']) && isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
        $raw = file_get_contents($_FILES['backup_file']['tmp_name']);
        $backup = json_decode($raw, true);
        if (!$backup) {
            $restoreError = 'File không đúng định dạng JSON';
        } else {
            $n = count($backup['games'] ?? []) + count($backup['keys'] ?? []) + count($backup['channels'] ?? []) + count($backup['site_notices'] ?? []) + count($backup['ip_blocklist'] ?? []) + count($backup['device_history'] ?? []);
            apply_backup_payload($db, $backup);
            $restoreSuccess = $n;
        }
    }

    if (isset($_POST['add_game'])) {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($_POST['game_name'] ?? ''));
        $slug = trim($slug, '-');
        if ($slug !== '') {
            create_game($slug, trim($_POST['game_name']), trim($_POST['game_icon'] ?: '🎮'));
        }
    }

    if (isset($_POST['toggle_game_id'])) {
        toggle_game((int)$_POST['toggle_game_id']);
    }

    if (isset($_POST['unblock_ip'])) {
        unblock_ip(trim($_POST['unblock_ip']));
    }

    if (isset($_POST['delete_game_id'])) {
        delete_game((int)$_POST['delete_game_id']);
    }

    // Lưu cấu hình CẢ 2 vùng (VN + nước ngoài) cùng lúc từ 1 form duy nhất
    if (isset($_POST['save_all_regions_game_id'])) {
        $gameId = (int)$_POST['save_all_regions_game_id'];
        foreach (['vn', 'intl'] as $region) {
            $chainSteps = [];
            for ($i = 1; $i <= 5; $i++) {
                $p = trim($_POST[$region . '_chain_step_' . $i] ?? '');
                if ($p !== '') $chainSteps[] = $p;
            }
            update_game_region(
                $gameId,
                $region,
                (int)($_POST[$region . '_hops'] ?? 1),
                (int)($_POST[$region . '_hours'] ?? 24),
                $chainSteps
            );
        }
    }
}

$cfg = get_shortener_config();
$builtins = get_builtin_providers();

$keys = [];
$games = [];
$searchQ = trim($_GET['q'] ?? '');
$filterStatus = $_GET['status'] ?? 'all';
$perPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalKeysMatching = 0;
$totalPages = 1;
if (isset($_SESSION['is_admin'])) {
    $where = " WHERE 1=1";
    $params = [];
    if ($searchQ !== '') {
        $where .= " AND REPLACE(UPPER(keycode), '-', '') LIKE ?";
        $params[] = '%' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $searchQ)) . '%';
    }
    if (in_array($filterStatus, ['active', 'pending', 'expired'], true)) {
        $where .= " AND status = ?";
        $params[] = $filterStatus;
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM keys" . $where);
    $countStmt->execute($params);
    $totalKeysMatching = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalKeysMatching / $perPage));
    $page = min($page, $totalPages);

    $sql = "SELECT * FROM keys" . $where . " ORDER BY id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $games = get_games();
}

// Tab hiện tại: keys | game | link | channel | notice | server | stats
$tab = $_GET['tab'] ?? 'stats';
if (!in_array($tab, ['keys', 'game', 'link', 'channel', 'notice', 'server', 'stats'], true)) $tab = 'stats';

// Icon nét mảnh (line icon, style Lucide) - dùng currentColor để tự đổi
// màu theo CSS (vd sáng lên màu cyan khi mục đang active), thay cho emoji
// vốn render khác nhau tuỳ thiết bị và không đổi màu được.
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
        'reset'   => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>',
        'trash'   => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
        'ban'     => '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
        'cloud'   => '<path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>',
        'shield'  => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
        'phone'   => '<rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><path d="M12 18h.01"/>',
        'warning' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'x-circle' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        'globe'   => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'flag'    => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" x2="4" y1="22" y2="15"/>',
        'power'   => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
    ];
    $p = $paths[$name] ?? '';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;display:inline-block">' . $p . '</svg>';
}

function fmt_time($ts) { return $ts ? date('H:i d/m/Y', $ts) : '-'; }
function fmt_duration($seconds) { return format_duration_label((int)$seconds); }
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin - Key Server</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">
<style>
:root{
    --bg:#0B0E14; --surface:#12151F; --surface2:#181C28;
    --cyan:#00E5C7; --violet:#8B7CFF; --text:#E8ECF3; --text-dim:#8891A3;
    --success:#34D399; --warn:#FBBF24; --danger:#FF6B6B;
}
*{box-sizing:border-box}
body{font-family:'Inter',-apple-system,Arial,sans-serif;font-size:14px;max-width:900px;margin:0 auto;background:radial-gradient(ellipse at top,#151a26 0%,var(--bg) 60%);color:var(--text);padding:0 12px 30px;animation:fadeIn .35s ease}
h2,h3{font-family:'Space Grotesk',sans-serif;font-weight:700}
h2{font-size:19px}h3{font-size:16px}h4{font-size:13px;font-family:'JetBrains Mono',monospace;letter-spacing:.04em}
input,select{padding:8px;margin:5px 4px 5px 0;border-radius:6px;border:1px solid #262b38;background:var(--surface2);color:var(--text);font-size:13px;max-width:100%;transition:border-color .2s,box-shadow .2s;font-family:'Inter',sans-serif}
input:focus,select:focus{outline:none;border-color:var(--cyan);box-shadow:0 0 0 3px rgba(0,229,199,.15)}
button{padding:9px 14px;background:linear-gradient(135deg,var(--cyan),var(--violet));border:none;border-radius:6px;color:#0B0E14;font-family:'Space Grotesk',sans-serif;font-weight:700;cursor:pointer;font-size:13px;transition:transform .12s,filter .15s,box-shadow .15s}
button:hover{filter:brightness(1.1)}
button:active{transform:scale(.96)}
button.danger{background:var(--danger);color:#fff}
button.warn{background:var(--warn);color:#0B0E14}
button.small{padding:5px 10px;font-size:12px}
.box{background:var(--surface);padding:16px;border-radius:10px;margin-bottom:16px;animation:slideUp .3s ease;box-shadow:0 2px 8px rgba(0,0,0,.3);transition:box-shadow .2s}
.ok{color:var(--success);animation:popIn .3s ease}.err{color:var(--danger);animation:popIn .3s ease}
table{width:100%;border-collapse:collapse;font-size:12px}
.tablewrap{overflow-x:auto}
th,td{padding:6px;border-bottom:1px solid #262a33;text-align:left;white-space:nowrap}
th{font-family:'JetBrains Mono',monospace;font-size:10.5px;letter-spacing:.06em;color:var(--text-dim);text-transform:uppercase;font-weight:600}
tr{transition:background .15s}
tr:hover td{background:var(--surface2)}
.badge{padding:2px 7px;border-radius:10px;font-size:11px;transition:transform .15s;font-family:'JetBrains Mono',monospace}
.badge.active{background:rgba(52,211,153,.15);color:var(--success)}
.badge.pending{background:rgba(251,191,36,.15);color:var(--warn)}
.badge.expired{background:rgba(255,107,107,.15);color:var(--danger)}
.badge.on{background:rgba(52,211,153,.15);color:var(--success)}
.badge.off{background:#262b38;color:var(--text-dim)}
.loginbox{max-width:340px;margin:60px auto;animation:popIn .35s ease}
.game-card{background:var(--bg);border-radius:8px;padding:12px;margin-bottom:10px;transition:transform .15s,box-shadow .15s;animation:slideUp .3s ease}
.game-card:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(0,0,0,.4)}
.game-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.region-form{background:var(--surface2);border-radius:8px;padding:10px;margin-top:6px}
.region-form input,.region-form select{width:100%;margin:4px 0}
.step-row{display:flex;gap:4px;flex-wrap:wrap}
.step-row select{flex:1;min-width:110px}

/* Card "Link lấy key của bạn" - giống mẫu tham khảo: ô input readonly +
   nút Sao chép trắng nổi bật bên phải */
.getkey-link-card{background:var(--surface2);border-radius:12px;padding:16px;margin-top:12px}
.getkey-link-title{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:15px;color:var(--text)}
.getkey-link-sub{font-size:12.5px;color:var(--text-dim);margin-top:3px;margin-bottom:12px}
.getkey-link-row{display:flex;gap:8px;align-items:stretch}
.getkey-link-row input{
    flex:1;min-width:0;background:var(--bg);border:1px solid #262b38;border-radius:10px;
    padding:12px 14px;color:var(--text);font-family:'JetBrains Mono',monospace;font-size:12.5px;
    margin:0;cursor:pointer;text-overflow:ellipsis;
}
.getkey-link-row input:focus{outline:none;border-color:var(--cyan)}
.getkey-link-row button{
    background:#fff;color:#0B0E14;border:none;border-radius:10px;padding:0 16px;
    display:flex;align-items:center;gap:6px;font-family:'Space Grotesk',sans-serif;font-weight:700;
    font-size:13px;cursor:pointer;white-space:nowrap;transition:transform .12s,filter .15s;flex-shrink:0;
}
.getkey-link-row button:active{transform:scale(.96)}
.getkey-link-row button:hover{filter:brightness(.95)}

/* Header + menu 3 gạch */
.topbar{position:sticky;top:0;background:rgba(11,14,20,.9);backdrop-filter:blur(6px);padding:14px 0;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #262a33;margin-bottom:16px;z-index:10}
.topbar b{font-family:'Space Grotesk',sans-serif}
.hamburger{background:none;border:none;font-size:22px;color:var(--text);cursor:pointer;padding:4px 8px;transition:transform .2s}
.hamburger:active{transform:scale(.85) rotate(90deg)}

/* Sidebar off-canvas trượt từ trái, giống app quản lý seller thật */
.sidebar-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);opacity:0;pointer-events:none;transition:opacity .25s ease;z-index:40}
.sidebar-overlay.open{opacity:1;pointer-events:auto}
.sidebar{
    position:fixed;top:0;left:0;bottom:0;width:270px;max-width:82vw;background:var(--surface);
    z-index:41;transform:translateX(-100%);transition:transform .28s cubic-bezier(.16,1,.3,1);
    display:flex;flex-direction:column;box-shadow:8px 0 30px rgba(0,0,0,.4);
}
.sidebar.open{transform:translateX(0)}
.sidebar-section-label{font-family:'JetBrains Mono',monospace;font-size:10.5px;letter-spacing:.12em;color:var(--text-dim);text-transform:uppercase;padding:22px 20px 10px}
.sidebar-nav{flex:1;overflow-y:auto;padding:0 10px}
.sidebar-item{
    display:flex;align-items:center;gap:12px;padding:13px 12px;border-radius:10px;margin-bottom:2px;
    color:var(--text);text-decoration:none;font-size:14.5px;font-family:'Inter',sans-serif;font-weight:500;
    transition:background .15s;
}
.sidebar-item:not(.active):hover{background:var(--surface2)}
.sidebar-item .ic{font-size:17px;width:20px;text-align:center;flex-shrink:0}
.sidebar-item.active{background:var(--surface2)}
.sidebar-item.active .ic{color:var(--cyan)}
.sidebar-item.active span.label{font-weight:700}
.sidebar-item .chev{margin-left:auto;color:var(--text-dim);font-size:15px}
.sidebar-item.active .chev{color:var(--cyan)}
.sidebar-footer{padding:14px;border-top:1px solid #262a33}
.sidebar-back{
    display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;
    background:#fff;color:#0B0E14;border-radius:12px;text-decoration:none;font-weight:700;
    font-family:'Space Grotesk',sans-serif;font-size:14px;margin-bottom:10px;border:none;cursor:pointer;
    transition:transform .12s;
}
.sidebar-back:active{transform:scale(.97)}
.sidebar-account{display:flex;align-items:center;gap:10px;padding:6px 4px}
.sidebar-avatar{
    width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--cyan),var(--violet));
    display:flex;align-items:center;justify-content:center;font-weight:700;color:#0B0E14;font-family:'Space Grotesk',sans-serif;flex-shrink:0;
}
.sidebar-account-name{font-size:13.5px;font-weight:600}
.sidebar-account-handle{font-size:11.5px;color:var(--text-dim)}
.provider-row{display:flex;align-items:center;justify-content:space-between;background:var(--bg);border-radius:8px;padding:8px 10px;margin-bottom:6px;transition:transform .15s}
.provider-row:hover{transform:translateX(2px)}

/* Thống kê */
.stats-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
.stat-card{background:var(--bg);border-radius:12px;padding:16px 14px;text-align:center;transition:transform .15s,box-shadow .15s;border:1px solid rgba(255,255,255,.04);animation:slideUp .3s ease both}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.3)}
.stat-ic{display:block;margin:0 auto 6px;color:var(--cyan);opacity:.85}
.stat-num{font-family:'Space Grotesk',sans-serif;font-size:24px;font-weight:700;color:var(--cyan)}
.stat-label{font-size:12px;color:var(--text-dim);margin-top:2px}

/* Toggle switch lớn - Server App Android */
.power-toggle{
    width:76px;height:42px;border-radius:999px;border:none;cursor:pointer;position:relative;
    transition:background .25s ease;padding:0;
}
.power-toggle.on{background:linear-gradient(135deg,var(--success),#2bb883)}
.power-toggle.off{background:#262b38}
.power-toggle-knob{
    position:absolute;top:4px;width:34px;height:34px;border-radius:50%;background:#fff;
    box-shadow:0 2px 6px rgba(0,0,0,.35);transition:left .25s cubic-bezier(.34,1.56,.64,1);
}
.power-toggle.on .power-toggle-knob{left:38px}
.power-toggle.off .power-toggle-knob{left:4px}
.power-toggle:active .power-toggle-knob{width:38px}

@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes slideUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes popIn{from{opacity:0;transform:scale(.94)}to{opacity:1;transform:scale(1)}}

/* ============================================================
   HoQuoc UI refresh — admin workspace
   Giữ nguyên form/action và chỉ nâng cấp hệ thống trình bày.
   ============================================================ */
:root{
    --bg:#070A10;--surface:#101620;--surface2:#151D2A;--surface3:#1B2635;
    --line:rgba(177,199,224,.12);--line-strong:rgba(177,199,224,.2);
    --cyan:#59F5D5;--violet:#9C8CFF;--text:#F4F7FB;--text-dim:#93A1B5;
    --success:#61E6A4;--warn:#F5C969;--danger:#FF7885;
}
html{background:var(--bg)}
body{
    position:relative;max-width:1180px;padding:0 30px 60px;min-height:100vh;
    background:radial-gradient(circle at 8% -10%,rgba(89,245,213,.16),transparent 46rem),radial-gradient(circle at 94% 10%,rgba(156,140,255,.14),transparent 44rem),var(--bg);
}
body::before{
    content:"";position:fixed;inset:0;pointer-events:none;opacity:.3;
    background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);
    background-size:44px 44px;mask-image:linear-gradient(to bottom,#000,transparent 80%)
}
h2,h3{letter-spacing:-.025em}h2{font-size:22px}h3{font-size:17px;margin:0 0 14px}
code{padding:2px 5px;border-radius:5px;background:var(--surface3);color:var(--cyan);font-size:.9em}
input,select{
    min-height:39px;padding:9px 12px;margin:5px 4px 5px 0;border:1px solid var(--line-strong);
    border-radius:10px;background:rgba(21,29,42,.9);box-shadow:inset 0 1px rgba(255,255,255,.035);font-size:13px
}
input::placeholder{color:#68768A}input:focus,select:focus{border-color:var(--cyan);box-shadow:0 0 0 4px rgba(89,245,213,.12),inset 0 1px rgba(255,255,255,.04)}
button{
    min-height:39px;padding:9px 15px;border-radius:10px;background:linear-gradient(110deg,var(--cyan),#B5FFF0 45%,var(--violet));
    box-shadow:0 8px 18px -13px rgba(89,245,213,.75);font-size:13px
}
button:hover{filter:brightness(1.08);box-shadow:0 12px 22px -13px rgba(89,245,213,.8)}
button.danger{background:rgba(255,120,133,.14);border:1px solid rgba(255,120,133,.28);color:#FF9CA6;box-shadow:none}
button.warn{background:rgba(245,201,105,.14);border:1px solid rgba(245,201,105,.28);color:#F5D98D;box-shadow:none}
button.small{min-height:31px;padding:6px 10px;border-radius:8px}
.box{
    position:relative;padding:23px;border:1px solid var(--line);border-radius:18px;margin-bottom:18px;
    background:rgba(16,22,32,.82);box-shadow:0 18px 48px -34px #000,inset 0 1px rgba(255,255,255,.045)
}
.box::before{content:"";position:absolute;left:23px;right:23px;top:0;height:1px;background:linear-gradient(90deg,rgba(89,245,213,.5),transparent 42%)}
.ok,.err{display:flex;align-items:center;gap:8px;padding:10px 12px;border-radius:10px;font-size:12.5px}
.ok{background:rgba(97,230,164,.08)}.err{background:rgba(255,120,133,.08)}
.tablewrap{border:1px solid var(--line);border-radius:13px;overflow:auto}
table{font-size:12px;min-width:680px}
th,td{padding:12px 11px;border-bottom:1px solid var(--line)}
th{height:39px;background:rgba(21,29,42,.74);font-size:10px;color:#9EABBD}
tr:last-child td{border-bottom:0}tr:hover td{background:rgba(89,245,213,.035)}
.badge{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border:1px solid transparent;border-radius:999px;font-size:10px}
.badge::before{content:"";width:5px;height:5px;border-radius:50%;background:currentColor}
.badge.active,.badge.on{background:rgba(97,230,164,.1);border-color:rgba(97,230,164,.2)}
.badge.pending{background:rgba(245,201,105,.1);border-color:rgba(245,201,105,.2)}
.badge.expired{background:rgba(255,120,133,.1);border-color:rgba(255,120,133,.2)}
.badge.off{background:rgba(147,161,181,.08);border-color:var(--line)}
.loginbox{max-width:390px;margin:12vh auto;padding:32px}
.loginbox::after{
    content:"SECURE CONTROL PLANE";display:block;margin-top:24px;color:#607087;font:10px 'JetBrains Mono',monospace;letter-spacing:.16em;text-align:center
}
.loginbox h2{margin:0 0 22px;font-size:27px;background:linear-gradient(110deg,var(--text),var(--cyan));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent}
.loginbox input{height:48px;margin:0 0 10px;border-radius:12px}
.loginbox button{height:48px;margin-top:4px}
.game-card{padding:18px;border:1px solid var(--line);border-radius:15px;background:rgba(7,10,16,.52);box-shadow:inset 0 1px rgba(255,255,255,.025)}
.game-card:hover{transform:translateY(-2px);border-color:rgba(89,245,213,.23);box-shadow:0 15px 30px -24px #000}
.game-head{padding-bottom:14px;margin-bottom:14px;border-bottom:1px solid var(--line)}
.region-form{padding:15px;border:1px solid var(--line);border-radius:12px;background:rgba(21,29,42,.68)}
.region-form+ .region-form{margin-top:9px}
.step-row{gap:6px}.step-row select{min-height:37px;border-radius:8px}
.getkey-link-card{margin-top:15px;padding:17px;border:1px solid rgba(89,245,213,.14);border-radius:13px;background:linear-gradient(135deg,rgba(89,245,213,.06),rgba(156,140,255,.05))}
.getkey-link-title{font-size:15px}.getkey-link-row input{min-height:43px;border-radius:10px}.getkey-link-row button{min-height:43px;border-radius:10px;background:#F4F7FB}
.topbar{
    position:sticky;top:0;margin:0 -30px 28px;padding:16px 30px;border-bottom:1px solid var(--line);
    background:rgba(7,10,16,.78);backdrop-filter:blur(18px);z-index:20
}
.topbar b{font-size:15px;letter-spacing:-.01em}.topbar b::before{content:"HQ / ";color:var(--cyan);font:10px 'JetBrains Mono',monospace;letter-spacing:.12em}
.topbar>a{padding:8px 12px;border:1px solid var(--line);border-radius:9px;color:#B7C2D1!important;text-decoration:none!important;transition:.2s}
.topbar>a:hover{border-color:rgba(89,245,213,.4);color:var(--cyan)!important}
.hamburger{width:38px;height:38px;min-height:38px;padding:8px;border:1px solid var(--line);border-radius:10px;background:var(--surface2);color:var(--text);box-shadow:none}
.sidebar-overlay{background:rgba(1,3,6,.72);backdrop-filter:blur(5px)}
.sidebar{width:290px;background:linear-gradient(180deg,#111A27,#0D131D);border-right:1px solid var(--line);box-shadow:18px 0 50px rgba(0,0,0,.45)}
.sidebar-section-label{padding:24px 22px 16px;color:#A9B6C8;font-size:10px}
.sidebar-section-label::before{content:"◈ ";color:var(--cyan)}
.sidebar-nav{padding:0 12px}
.sidebar-item{padding:13px 14px;border:1px solid transparent;border-radius:11px;margin-bottom:4px}
.sidebar-item.active{border-color:rgba(89,245,213,.16);background:linear-gradient(90deg,rgba(89,245,213,.1),rgba(156,140,255,.04))}
.sidebar-item.active .ic{filter:drop-shadow(0 0 7px rgba(89,245,213,.55))}
.sidebar-footer{padding:16px;border-color:var(--line)}
.sidebar-back{min-height:43px;border:1px solid var(--line);background:var(--surface2);color:var(--text)}
.sidebar-avatar{box-shadow:0 0 22px rgba(89,245,213,.18)}
.provider-row{padding:12px 13px;border:1px solid var(--line);border-radius:10px;background:rgba(7,10,16,.48)}
.stats-grid{grid-template-columns:repeat(3,1fr);gap:12px}
.stat-card{padding:18px 14px;border:1px solid var(--line);border-radius:14px;background:linear-gradient(145deg,rgba(27,38,53,.65),rgba(7,10,16,.5))}
.stat-card:hover{border-color:rgba(89,245,213,.25);box-shadow:0 12px 28px -22px #000}
.stat-num{font-size:28px;letter-spacing:-.04em}.stat-label{font-size:11px}
.power-toggle{box-shadow:0 0 0 7px rgba(97,230,164,.07),0 12px 25px -15px #000}
.power-toggle.off{box-shadow:0 0 0 7px rgba(255,120,133,.06),0 12px 25px -15px #000}
@media (max-width:700px){
    body{padding:0 15px 42px}.topbar{margin:0 -15px 20px;padding:13px 15px}
    .box{padding:18px 15px;border-radius:15px}.box::before{left:15px;right:15px}
    .stats-grid{grid-template-columns:repeat(2,1fr)}
}
@media (max-width:430px){
    .stats-grid{grid-template-columns:1fr 1fr}.stat-card{padding:14px 8px}.stat-num{font-size:23px}
    .getkey-link-row{flex-direction:column}.getkey-link-row button{padding:11px}
}
@media (prefers-reduced-motion:reduce){
    *,*::before,*::after{animation-duration:.001ms!important;animation-iteration-count:1!important;transition-duration:.001ms!important}
}
</style>
</head>
<body>

<?php if (!isset($_SESSION['is_admin'])): ?>
    <div class="box loginbox">
        <h2>Đăng nhập Admin</h2>
        <?php if (isset($timedOut)): ?><p class="err">Phiên đăng nhập đã hết hạn, vui lòng đăng nhập lại</p><?php endif; ?>
        <?php if (isset($error)): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <?php if ($isLocked): ?>
            <p class="err">Tài khoản tạm khóa do sai nhiều lần, thử lại sau ít phút.</p>
        <?php else: ?>
        <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="text" name="admin_username" placeholder="Tài khoản" required style="width:100%;box-sizing:border-box"><br>
            <input type="password" name="admin_password" placeholder="Mật khẩu" required style="width:100%;box-sizing:border-box"><br>
            <button type="submit" style="width:100%">Đăng nhập</button>
        </form>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="topbar">
        <button class="hamburger" onclick="openSidebar()"><?= svg_icon('menu', 22) ?></button>
        <b style="font-size:15px"><?= ['keys'=>'Tạo Key','game'=>'Cấu hình Game','link'=>'Cấu hình Link','channel'=>'Cấu hình kênh','notice'=>'Thông báo người dùng','server'=>'Server App Android','stats'=>'Thống kê'][$tab] ?? '' ?></b>
        <a href="admin.php?logout=1" style="color:#888;font-size:13px">Đăng xuất</a>
    </div>

    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
    <div class="sidebar" id="sidebar">
        <div class="sidebar-section-label">HOQUOC KEY VAULT</div>
        <div class="sidebar-nav">
            <a href="admin.php?tab=stats" class="sidebar-item <?= $tab==='stats'?'active':'' ?>">
                <span class="ic"><?= svg_icon('chart') ?></span><span class="label">Thống kê</span>
                <?php if ($tab==='stats'): ?><span class="chev"><?= svg_icon('chevron', 15) ?></span><?php endif; ?>
            </a>
            <a href="admin.php?tab=keys" class="sidebar-item <?= $tab==='keys'?'active':'' ?>">
                <span class="ic"><?= svg_icon('key') ?></span><span class="label">Quản lý Key</span>
                <?php if ($tab==='keys'): ?><span class="chev"><?= svg_icon('chevron', 15) ?></span><?php endif; ?>
            </a>
            <a href="admin.php?tab=game" class="sidebar-item <?= $tab==='game'?'active':'' ?>">
                <span class="ic"><?= svg_icon('gamepad') ?></span><span class="label">Cấu hình Game</span>
                <?php if ($tab==='game'): ?><span class="chev"><?= svg_icon('chevron', 15) ?></span><?php endif; ?>
            </a>
            <a href="admin.php?tab=link" class="sidebar-item <?= $tab==='link'?'active':'' ?>">
                <span class="ic"><?= svg_icon('link') ?></span><span class="label">Cấu hình rút gọn</span>
                <?php if ($tab==='link'): ?><span class="chev"><?= svg_icon('chevron', 15) ?></span><?php endif; ?>
            </a>
            <a href="admin.php?tab=channel" class="sidebar-item <?= $tab==='channel'?'active':'' ?>">
                <span class="ic"><?= svg_icon('gamepad') ?></span><span class="label">Cấu hình kênh</span>
                <?php if ($tab==='channel'): ?><span class="chev"><?= svg_icon('chevron', 15) ?></span><?php endif; ?>
            </a>
            <a href="admin.php?tab=notice" class="sidebar-item <?= $tab==='notice'?'active':'' ?>">
                <span class="ic"><?= svg_icon('warning') ?></span><span class="label">Thông báo người dùng</span>
                <?php if ($tab==='notice'): ?><span class="chev"><?= svg_icon('chevron', 15) ?></span><?php endif; ?>
            </a>
            <a href="admin.php?tab=server" class="sidebar-item <?= $tab==='server'?'active':'' ?>">
                <span class="ic"><?= svg_icon('phone') ?></span><span class="label">Server App Android</span>
                <?php if ($tab==='server'): ?><span class="chev"><?= svg_icon('chevron', 15) ?></span><?php endif; ?>
            </a>
        </div>
        <div class="sidebar-footer">
            <a href="<?= htmlspecialchars(BASE_URL) ?>/" class="sidebar-back"><?= svg_icon('arrow-left', 16) ?> Về trang người dùng</a>
            <div class="sidebar-account">
                <div class="sidebar-avatar"><?= htmlspecialchars(strtoupper(substr(ADMIN_USERNAME, 0, 1))) ?></div>
                <div>
                    <div class="sidebar-account-name"><?= htmlspecialchars(ADMIN_USERNAME) ?></div>
                    <div class="sidebar-account-handle">Admin</div>
                </div>
            </div>
        </div>
    </div>
    <script>
    function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('sidebarOverlay').classList.add('open');}
    function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebarOverlay').classList.remove('open');}
    </script>

    <?php if ($tab === 'stats'):
        $stats = get_dashboard_stats();
    ?>

    <div class="box">
        <h3>Tổng quan</h3>
        <?php
            $total = max(1, $stats['total_keys']);
            $pA = round($stats['active_keys'] / $total * 100, 2);
            $pP = round($stats['pending_keys'] / $total * 100, 2);
            $pE = round($stats['expired_keys'] / $total * 100, 2);
            // Circumference = 100 khi r = 15.9155 (thủ thuật SVG donut quen dùng)
            $offA = 25;
            $offP = 25 - $pA;
            $offE = 25 - $pA - $pP;
        ?>
        <div style="display:flex;align-items:center;gap:22px;flex-wrap:wrap;justify-content:center">
            <div style="position:relative;width:140px;height:140px;flex-shrink:0">
                <svg viewBox="0 0 36 36" style="width:100%;height:100%;transform:rotate(0deg)">
                    <circle cx="18" cy="18" r="15.9155" fill="none" stroke="#1c202c" stroke-width="3.6"/>
                    <?php if ($stats['total_keys'] > 0): ?>
                    <circle cx="18" cy="18" r="15.9155" fill="none" stroke="var(--success)" stroke-width="3.6"
                        stroke-dasharray="<?= $pA ?> <?= 100 - $pA ?>" stroke-dashoffset="<?= $offA ?>" stroke-linecap="round"/>
                    <circle cx="18" cy="18" r="15.9155" fill="none" stroke="var(--warn)" stroke-width="3.6"
                        stroke-dasharray="<?= $pP ?> <?= 100 - $pP ?>" stroke-dashoffset="<?= $offP ?>"/>
                    <circle cx="18" cy="18" r="15.9155" fill="none" stroke="var(--danger)" stroke-width="3.6"
                        stroke-dasharray="<?= $pE ?> <?= 100 - $pE ?>" stroke-dashoffset="<?= $offE ?>"/>
                    <?php endif; ?>
                </svg>
                <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center">
                    <div style="font-family:'Space Grotesk',sans-serif;font-size:26px;font-weight:700;color:var(--text)"><?= $stats['total_keys'] ?></div>
                    <div style="font-size:10.5px;color:var(--text-dim);font-family:'JetBrains Mono',monospace">TỔNG KEY</div>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:9px;min-width:140px">
                <div style="display:flex;align-items:center;gap:8px;font-size:13px"><span style="width:9px;height:9px;border-radius:50%;background:var(--success)"></span> Hoạt động <b style="margin-left:auto;font-family:'JetBrains Mono',monospace"><?= $stats['active_keys'] ?></b></div>
                <div style="display:flex;align-items:center;gap:8px;font-size:13px"><span style="width:9px;height:9px;border-radius:50%;background:var(--warn)"></span> Chưa kích hoạt <b style="margin-left:auto;font-family:'JetBrains Mono',monospace"><?= $stats['pending_keys'] ?></b></div>
                <div style="display:flex;align-items:center;gap:8px;font-size:13px"><span style="width:9px;height:9px;border-radius:50%;background:var(--danger)"></span> Hết hạn <b style="margin-left:auto;font-family:'JetBrains Mono',monospace"><?= $stats['expired_keys'] ?></b></div>
            </div>
        </div>

        <div class="stats-grid" style="margin-top:20px">
            <div class="stat-card"><span class="stat-ic"><?= svg_icon('chart', 20) ?></span><div class="stat-num"><?= $stats['today_claims'] ?></div><div class="stat-label">Lấy key 24h qua</div></div>
            <div class="stat-card"><span class="stat-ic" style="color:var(--violet)"><?= svg_icon('phone', 20) ?></span><div class="stat-num"><?= $stats['unique_devices'] ?></div><div class="stat-label">Thiết bị đã đăng nhập</div></div>
            <div class="stat-card"><span class="stat-ic"><?= svg_icon('gamepad', 20) ?></span><div class="stat-num"><?= $stats['active_games'] ?>/<?= $stats['total_games'] ?></div><div class="stat-label">Game đang mở</div></div>
        </div>
    </div>

    <div class="box">
        <h3>Lượt lấy key 7 ngày qua</h3>
        <?php
            $maxDaily = 1;
            foreach ($stats['daily_claims'] as $d) { $maxDaily = max($maxDaily, $d['count']); }
        ?>
        <div style="display:flex;align-items:flex-end;gap:8px;height:120px;padding-top:10px">
            <?php foreach ($stats['daily_claims'] as $d):
                $h = $d['count'] > 0 ? max(6, round($d['count'] / $maxDaily * 100)) : 3;
            ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:6px;height:100%;justify-content:flex-end">
                <span style="font-size:11px;color:var(--text-dim);font-family:'JetBrains Mono',monospace"><?= $d['count'] ?></span>
                <div style="width:100%;max-width:32px;height:<?= $h ?>%;background:linear-gradient(180deg,var(--cyan),var(--violet));border-radius:6px 6px 2px 2px;transition:height .4s ease"></div>
                <span style="font-size:10px;color:var(--text-dim)"><?= $d['label'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="box">
        <h3><?= svg_icon('cloud', 17) ?> Sao lưu Firebase</h3>
        <?php
            $statusFile = __DIR__ . '/data/firebase_backup_status.json';
            $backupStatus = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : null;
            if (!FIREBASE_ENABLED) { $fbState = 'warn'; $fbText = 'Chưa cấu hình'; }
            elseif (!$backupStatus) { $fbState = 'warn'; $fbText = 'Chưa có lần sao lưu nào'; }
            elseif ($backupStatus['ok']) { $fbState = 'ok'; $fbText = 'Đang hoạt động'; }
            else { $fbState = 'err'; $fbText = 'Đang lỗi'; }
            $fbColor = ['ok' => 'var(--success)', 'warn' => 'var(--warn)', 'err' => 'var(--danger)'][$fbState];
            $fbBg = ['ok' => 'rgba(52,211,153,.15)', 'warn' => 'rgba(251,191,36,.15)', 'err' => 'rgba(255,107,107,.15)'][$fbState];
            $fbIcon = ['ok' => 'check-circle', 'warn' => 'warning', 'err' => 'x-circle'][$fbState];
        ?>
        <div style="display:inline-flex;align-items:center;gap:6px;background:<?= $fbBg ?>;color:<?= $fbColor ?>;font-weight:700;font-size:12.5px;padding:5px 12px;border-radius:20px;margin-bottom:8px">
            <?= svg_icon($fbIcon, 15) ?> <?= htmlspecialchars($fbText) ?>
        </div>

        <?php if (!FIREBASE_ENABLED): ?>
            <p style="font-size:12.5px;color:var(--text-dim)">Dữ liệu sẽ MẤT khi Render redeploy/restart. Set biến môi trường <code>FIREBASE_DB_URL</code> và <code>FIREBASE_SERVICE_ACCOUNT_JSON</code> trên Render để bật sao lưu tự động (xem hướng dẫn trong <code>config.php</code>).</p>
        <?php elseif (!$backupStatus): ?>
            <p style="font-size:12.5px;color:var(--text-dim)">Thử tạo/sửa 1 key hoặc game để kích hoạt lần sao lưu đầu tiên.</p>
        <?php elseif ($backupStatus['ok']): ?>
            <p style="font-size:12px;color:var(--text-dim)">Lần gần nhất: <?= fmt_time((int)$backupStatus['at']) ?></p>
        <?php else: ?>
            <p style="font-size:12.5px;color:var(--text-dim)">Dữ liệu KHÔNG được bảo vệ, kiểm tra lại cấu hình! Lúc: <?= fmt_time((int)$backupStatus['at']) ?></p>
            <div style="font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--warn);background:var(--bg);border-radius:6px;padding:8px;margin-top:6px;word-break:break-all"><?= htmlspecialchars($backupStatus['error'] ?? '') ?></div>
        <?php endif; ?>
    </div>

    <div class="box">
        <h3><?= svg_icon('cloud', 17) ?> Sao lưu Supabase</h3>
        <?php
            $sbStatusFile = __DIR__ . '/data/supabase_backup_status.json';
            $sbBackupStatus = file_exists($sbStatusFile) ? json_decode(file_get_contents($sbStatusFile), true) : null;
            if (!SUPABASE_ENABLED) { $sbState = 'warn'; $sbText = 'Chưa cấu hình'; }
            elseif (!$sbBackupStatus) { $sbState = 'warn'; $sbText = 'Chưa có lần sao lưu nào'; }
            elseif ($sbBackupStatus['ok']) { $sbState = 'ok'; $sbText = 'Đang hoạt động'; }
            else { $sbState = 'err'; $sbText = 'Đang lỗi'; }
            $sbColor = ['ok' => 'var(--success)', 'warn' => 'var(--warn)', 'err' => 'var(--danger)'][$sbState];
            $sbBg = ['ok' => 'rgba(52,211,153,.15)', 'warn' => 'rgba(251,191,36,.15)', 'err' => 'rgba(255,107,107,.15)'][$sbState];
            $sbIcon = ['ok' => 'check-circle', 'warn' => 'warning', 'err' => 'x-circle'][$sbState];
        ?>
        <div style="display:inline-flex;align-items:center;gap:6px;background:<?= $sbBg ?>;color:<?= $sbColor ?>;font-weight:700;font-size:12.5px;padding:5px 12px;border-radius:20px;margin-bottom:8px">
            <?= svg_icon($sbIcon, 15) ?> <?= htmlspecialchars($sbText) ?>
        </div>

        <?php if (!SUPABASE_ENABLED): ?>
            <p style="font-size:12.5px;color:var(--text-dim)">Lớp sao lưu thứ 2, độc lập với Firebase. Set biến môi trường <code>SUPABASE_URL</code> và <code>SUPABASE_SERVICE_KEY</code> trên Render để bật (xem hướng dẫn trong <code>config.php</code>).</p>
        <?php elseif (!$sbBackupStatus): ?>
            <p style="font-size:12.5px;color:var(--text-dim)">Thử tạo/sửa 1 key hoặc game để kích hoạt lần sao lưu đầu tiên.</p>
        <?php elseif ($sbBackupStatus['ok']): ?>
            <p style="font-size:12px;color:var(--text-dim)">Lần gần nhất: <?= fmt_time((int)$sbBackupStatus['at']) ?></p>
        <?php else: ?>
            <p style="font-size:12.5px;color:var(--text-dim)">Dữ liệu KHÔNG được bảo vệ qua Supabase! Lúc: <?= fmt_time((int)$sbBackupStatus['at']) ?></p>
            <?php if (!empty($sbBackupStatus['error'])): ?>
            <div style="font-family:'JetBrains Mono',monospace;font-size:11.5px;color:var(--warn);background:var(--bg);border-radius:6px;padding:8px;margin-top:6px;word-break:break-all"><?= htmlspecialchars($sbBackupStatus['error']) ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="box">
        <h3>Theo từng game</h3>
        <?php
            $maxTotal = 1;
            foreach ($stats['per_game'] as $pg) { $maxTotal = max($maxTotal, (int)$pg['total']); }
        ?>
        <?php if (empty($stats['per_game'])): ?>
            <p style="font-size:13px;color:var(--text-dim)">Chưa có dữ liệu</p>
        <?php else: foreach ($stats['per_game'] as $pg):
            $total = (int)$pg['total'];
            $active = (int)$pg['active'];
            $pctTotal = round($total / $maxTotal * 100);
            $pctActive = $total > 0 ? round($active / $total * 100) : 0;
        ?>
        <div style="margin-bottom:14px">
            <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px">
                <span style="font-size:13px;font-weight:600"><?= htmlspecialchars($pg['icon']) ?> <?= htmlspecialchars($pg['name']) ?></span>
                <span style="font-size:11.5px;color:var(--text-dim);font-family:'JetBrains Mono',monospace"><?= $active ?>/<?= $total ?> hoạt động</span>
            </div>
            <div style="height:10px;background:var(--bg);border-radius:6px;overflow:hidden;width:<?= $pctTotal ?>%;min-width:24px">
                <div style="height:100%;width:<?= $pctActive ?>%;background:linear-gradient(90deg,var(--cyan),var(--violet));border-radius:6px;transition:width .4s ease"></div>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="box">
        <h3><?= svg_icon('shield', 17) ?> IP bị chặn (nghi ngờ bypass)</h3>
        <p style="font-size:12px;color:#888;margin-top:-4px">Tự động chặn 24h khi 1 IP vượt link quá nhanh (nghi dùng tool bypass) từ <?= MAX_VIOLATIONS_BEFORE_BLOCK ?> lần trở lên.</p>
        <?php $blocked = get_blocked_ips(); ?>
        <div class="tablewrap">
        <table>
            <tr><th>IP</th><th>Số lần vi phạm</th><th>Lý do gần nhất</th><th>Chặn tới</th><th></th></tr>
            <?php foreach ($blocked as $b): ?>
            <tr>
                <td><?= htmlspecialchars($b['ip']) ?></td>
                <td><?= (int)$b['violation_count'] ?></td>
                <td style="max-width:200px;white-space:normal"><?= htmlspecialchars($b['last_reason']) ?></td>
                <td><?= fmt_time((int)$b['blocked_until']) ?></td>
                <td>
                    <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="unblock_ip" value="<?= htmlspecialchars($b['ip']) ?>">
                        <button class="warn small" type="submit">Gỡ chặn</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($blocked)): ?><tr><td colspan="5" style="color:#888">Không có IP nào bị chặn</td></tr><?php endif; ?>
        </table>
        </div>
    </div>

    <?php elseif ($tab === 'link'): ?>

    <div class="box">
        <h3>Nhà cung cấp rút gọn link</h3>
        <?php if (isset($saved)): ?><p class="ok">Đã lưu cấu hình!</p><?php endif; ?>

        <?php $configured = array_keys($cfg['keys']); $providerStats = get_provider_stats(); ?>
        <?php if (!empty($configured)): ?>
        <h4 style="color:#aaa;font-weight:normal">Các provider đã lưu key</h4>
        <?php foreach ($configured as $p):
            $label = $builtins[$p]['label'] ?? ($cfg['custom']['label'] ?? $p);
            $pStat = $providerStats[$p] ?? ['success' => 0, 'fail' => 0];
            $pTotal = $pStat['success'] + $pStat['fail'];
            $pRate = $pTotal > 0 ? round($pStat['success'] / $pTotal * 100) : null;
        ?>
        <div class="provider-row">
            <div>
                <?= htmlspecialchars($label) ?>
                <?php if ($cfg['active'] === $p): ?><span class="badge on">active</span><?php endif; ?>
                <?php if ($pRate !== null): ?>
                <span style="font-size:11px;color:<?= $pRate >= 80 ? 'var(--success)' : ($pRate >= 40 ? 'var(--warn)' : 'var(--danger)') ?>;font-family:'JetBrains Mono',monospace;margin-left:6px"><?= $pRate ?>% thành công (<?= $pStat['success'] ?>/<?= $pTotal ?>)</span>
                <?php endif; ?>
            </div>
            <div>
                <?php if ($cfg['active'] !== $p): ?>
                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="switch_active" value="<?= htmlspecialchars($p) ?>">
                    <button class="small" type="submit">Chọn active</button>
                </form>
                <?php endif; ?>
                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="delete_provider" value="<?= htmlspecialchars($p) ?>">
                    <button class="danger small" type="submit" onclick="return confirm('Xoá API key provider này?')">Xoá</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <h4 style="margin:16px 0 4px;color:#aaa;font-weight:normal">Thêm / cập nhật API key cho một provider có sẵn</h4>
        <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <select name="provider">
                <?php foreach ($builtins as $key => $p): ?>
                <option value="<?= $key ?>"><?= htmlspecialchars($p['label']) ?><?= isset($cfg['keys'][$key]) ? ' (đã có key)' : '' ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="api_key" placeholder="Vui Lòng Nhập Api Key" style="width:280px">
            <button type="submit">Lưu key cho provider này</button>
        </form>

        <h4 style="margin:16px 0 4px;color:#aaa;font-weight:normal">Hoặc thêm provider khác (không có sẵn trong danh sách)</h4>
        <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="provider" value="custom">
            <input type="text" name="custom_label" placeholder="Tên provider (vd: shortlink.vn)" style="width:180px">
            <input type="text" name="custom_url" placeholder="URL API, dùng {api} và {url} làm placeholder" style="width:320px"><br>
            <select name="custom_type">
                <option value="json">Response JSON</option>
                <option value="plain">Response là link thuần</option>
            </select>
            <input type="text" name="custom_field" placeholder="Tên field chứa link (nếu JSON)" style="width:200px">
            <input type="text" name="custom_api_key" placeholder="Vui Lòng Nhập Api Key" style="width:200px">
            <button type="submit">Lưu provider tuỳ chỉnh</button>
        </form>
    </div>

    <?php elseif ($tab === 'server'):
        $serverStatus = get_server_status();
        $closed = $serverStatus['closed'];
    ?>

    <div class="box" style="text-align:center;padding:32px 20px">
        <div style="width:88px;height:88px;border-radius:50%;margin:0 auto 18px;display:flex;align-items:center;justify-content:center;
                    background:<?= $closed ? 'rgba(255,107,107,.12)' : 'rgba(52,211,153,.12)' ?>;
                    box-shadow:0 0 0 8px <?= $closed ? 'rgba(255,107,107,.06)' : 'rgba(52,211,153,.06)' ?>;">
            <span style="color:<?= $closed ? 'var(--danger)' : 'var(--success)' ?>"><?= svg_icon('power', 40) ?></span>
        </div>

        <div style="font-family:'Space Grotesk',sans-serif;font-size:20px;font-weight:700;color:<?= $closed ? 'var(--danger)' : 'var(--success)' ?>">
            <?= $closed ? 'SERVER ĐANG ĐÓNG' : 'SERVER ĐANG ACTIVE' ?>
        </div>
        <p style="font-size:13px;color:var(--text-dim);margin:8px auto 24px;max-width:280px">
            <?= $closed
                ? 'App Android nhập key sẽ không nhận được phản hồi gì (giống server treo/mất kết nối).'
                : 'App Android đang nhận phản hồi bình thường từ api.php.' ?>
        </p>

        <!-- Toggle switch lớn, bấm để đổi trạng thái -->
        <form method="post" onsubmit="return confirm(<?= $closed ? "'Mở lại server?'" : "'Đóng server ngay? App Android nhập key sẽ không nhận được phản hồi gì cho tới khi bạn mở lại.'" ?>)">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <?php if ($closed): ?>
                <button type="submit" name="open_server" value="1" class="power-toggle off" aria-label="Mở lại server">
                    <span class="power-toggle-knob"></span>
                </button>
                <div style="font-size:12px;color:var(--text-dim);margin-top:10px">Bấm để <b style="color:var(--success)">mở lại</b></div>
            <?php else: ?>
                <input type="text" name="close_reason" placeholder="Lý do đóng (tuỳ chọn, chỉ admin thấy)" style="width:100%;max-width:280px;margin-bottom:14px;text-align:center">
                <br>
                <button type="submit" name="close_server" value="1" class="power-toggle on" aria-label="Đóng server">
                    <span class="power-toggle-knob"></span>
                </button>
                <div style="font-size:12px;color:var(--text-dim);margin-top:10px">Bấm để <b style="color:var(--danger)">đóng ngay</b></div>
            <?php endif; ?>
        </form>

        <?php if ($closed && (!empty($serverStatus['reason']) || !empty($serverStatus['changed_at']))): ?>
        <div style="margin-top:24px;text-align:left;background:var(--bg);border-radius:10px;padding:12px 14px;font-size:12.5px;color:var(--text-dim)">
            <?php if (!empty($serverStatus['reason'])): ?><div>Lý do: <span style="color:var(--text)"><?= htmlspecialchars($serverStatus['reason']) ?></span></div><?php endif; ?>
            <?php if (!empty($serverStatus['changed_at'])): ?><div style="margin-top:4px">Đóng lúc: <span style="color:var(--text)"><?= fmt_time((int)$serverStatus['changed_at']) ?></span></div><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="box">
        <h3>Đổi mật khẩu Admin</h3>
        <?php if (!empty($pwSuccess)): ?><p class="ok">Đã đổi mật khẩu thành công. Dùng mật khẩu mới cho lần đăng nhập sau.</p><?php endif; ?>
        <?php if (!empty($pwError)): ?><p class="err"><?= htmlspecialchars($pwError) ?></p><?php endif; ?>
        <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="password" name="current_password" placeholder="Mật khẩu hiện tại" style="width:100%;margin-bottom:6px" required><br>
            <input type="password" name="new_password" placeholder="Mật khẩu mới (tối thiểu 6 ký tự)" style="width:100%;margin-bottom:6px" required><br>
            <input type="password" name="new_password_confirm" placeholder="Nhập lại mật khẩu mới" style="width:100%;margin-bottom:10px" required><br>
            <button type="submit" name="change_password" value="1">Đổi mật khẩu</button>
        </form>
    </div>

    <div class="box">
        <h3>Sao lưu / Khôi phục thủ công</h3>
        <p style="font-size:12px;color:var(--text-dim);margin-top:-4px">Bổ sung cho Firebase - tải file backup về máy hoặc khôi phục từ file đã lưu trước đó.</p>
        <?php
            $manualBackupPayload = [
                'keys' => $db->query("SELECT * FROM keys")->fetchAll(PDO::FETCH_ASSOC),
                'games' => $db->query("SELECT * FROM games")->fetchAll(PDO::FETCH_ASSOC),
                'channels' => $db->query("SELECT * FROM channels")->fetchAll(PDO::FETCH_ASSOC),
                'site_notices' => $db->query("SELECT * FROM site_notices")->fetchAll(PDO::FETCH_ASSOC),
                'ip_blocklist' => $db->query("SELECT * FROM ip_blocklist")->fetchAll(PDO::FETCH_ASSOC),
                'device_history' => $db->query("SELECT * FROM device_history")->fetchAll(PDO::FETCH_ASSOC),
                'shortener' => file_exists(SHORTENER_CONFIG_PATH) ? json_decode(file_get_contents(SHORTENER_CONFIG_PATH), true) : null,
                'exported_at' => time(),
            ];
        ?>
        <button type="button" onclick='const b=new Blob([<?= json_encode(json_encode($manualBackupPayload)) ?>],{type:"application/json"});const a=document.createElement("a");a.href=URL.createObjectURL(b);a.download="keyserver-backup-<?= date("Y-m-d") ?>.json";a.click();'>Tải xuống backup</button>

        <div style="margin-top:16px;border-top:1px solid #262a33;padding-top:14px">
            <?php if (isset($restoreSuccess)): ?><p class="ok">Đã khôi phục xong (<?= $restoreSuccess ?> dòng dữ liệu được thêm - dòng trùng ID được bỏ qua).</p><?php endif; ?>
            <?php if (!empty($restoreError)): ?><p class="err"><?= htmlspecialchars($restoreError) ?></p><?php endif; ?>
            <form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="file" name="backup_file" accept=".json" required>
                <button type="submit" name="restore_backup" value="1" class="warn" onclick="return confirm('Khôi phục dữ liệu từ file này? Dữ liệu hiện tại được GIỮ NGUYÊN, chỉ thêm mới (không đè lên).')">Khôi phục từ file</button>
            </form>
        </div>
    </div>

    <?php elseif ($tab === 'game'): ?>

    <div class="box">
        <h3>Cấu hình Game</h3>
        <p style="font-size:12px;color:#888;margin-top:-4px">Cấu hình số lần vượt link + thời hạn key (giờ) riêng cho khách VN và nước ngoài, từng game.</p>

        <form method="post" style="margin-bottom:14px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="text" name="game_icon" placeholder="🎮" style="width:60px">
            <input type="text" name="game_name" placeholder="Tên game (vd: DX LOADER)" style="width:220px">
            <button type="submit" name="add_game" value="1">+ Thêm game</button>
        </form>

        <?php foreach ($games as $g): ?>
        <div class="game-card">
            <div class="game-head">
                <div><?= htmlspecialchars($g['icon']) ?> <b><?= htmlspecialchars($g['name']) ?></b>
                    <span class="badge <?= $g['enabled'] ? 'on' : 'off' ?>"><?= $g['enabled'] ? 'đang mở' : 'đã tắt' ?></span>
                </div>
                <div>
                    <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="toggle_game_id" value="<?= $g['id'] ?>">
                        <button class="warn small" type="submit"><?= $g['enabled'] ? 'Tắt' : 'Bật' ?></button>
                    </form>
                    <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="delete_game_id" value="<?= $g['id'] ?>">
                        <button class="danger small" type="submit" onclick="return confirm('Xoá game này?')">Xoá</button>
                    </form>
                </div>
            </div>

            <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="save_all_regions_game_id" value="<?= $g['id'] ?>">

                <?php foreach (['vn' => ['icon' => 'flag', 'label' => 'Khách Việt Nam'], 'intl' => ['icon' => 'globe', 'label' => 'Khách nước ngoài']] as $region => $rinfo):
                    $hops = $g[$region . '_hops'];
                    $hours = $g[$region . '_key_hours'];
                    $chain = $g[$region . '_chain'] !== '' ? explode(',', $g[$region . '_chain']) : [];
                ?>
                <div class="region-form">
                    <b style="font-size:13px"><?= svg_icon($rinfo['icon'], 14) ?> <?= htmlspecialchars($rinfo['label']) ?></b>
                    <label style="font-size:12px;color:#888">Số lần vượt</label>
                    <input type="number" name="<?= $region ?>_hops" value="<?= $hops ?>" min="1" max="5">
                    <label style="font-size:12px;color:#888">Hạn key (giờ)</label>
                    <input type="number" name="<?= $region ?>_hours" value="<?= $hours ?>" min="1">
                    <label style="font-size:12px;color:#888">Thứ tự link vượt (tuỳ chọn, để trống = dùng provider active)</label>
                    <div class="step-row">
                        <?php for ($i = 1; $i <= 5; $i++): $cur = $chain[$i - 1] ?? ''; ?>
                        <select name="<?= $region ?>_chain_step_<?= $i ?>">
                            <option value="">Bước <?= $i ?>: -</option>
                            <?php foreach (array_keys($cfg['keys'] ?? []) as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>" <?= $cur===$p?'selected':'' ?>><?= htmlspecialchars($builtins[$p]['label'] ?? $p) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <button type="submit" style="margin-top:8px;width:100%">Lưu cấu hình</button>
            </form>

            <?php $getKeyLink = htmlspecialchars(BASE_URL) . '/getkey.php?game=' . htmlspecialchars($g['slug']); ?>
            <div class="getkey-link-card">
                <div class="getkey-link-title">Link lấy key của bạn</div>
                <div class="getkey-link-sub">Chia sẻ link này cho người dùng để họ lấy key.</div>
                <div class="getkey-link-row">
                    <input type="text" readonly value="<?= $getKeyLink ?>" id="getkeylink-<?= $g['id'] ?>" onclick="this.select()">
                    <button type="button" class="getkey-copy-btn" data-target="getkeylink-<?= $g['id'] ?>" onclick="copyGetKeyLink(this)">
                        <?= svg_icon('copy', 16) ?> <span class="copy-label">Sao chép</span>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($games)): ?><p style="font-size:13px;color:#888">Chưa có game nào, thêm game ở trên.</p><?php endif; ?>
    </div>

    <?php elseif ($tab === 'channel'):
        $channelRows = get_channels();
        $channelTypes = [
            'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'telegram' => 'Telegram',
            'facebook' => 'Facebook', 'discord' => 'Discord', 'instagram' => 'Instagram', 'other' => 'Khác',
        ];
    ?>

    <div class="box">
        <h3>Cấu hình kênh</h3>
        <p style="font-size:12.5px;color:var(--text-dim);margin-top:-5px;max-width:660px">Các kênh đang bật sẽ xuất hiện như nhiệm vụ bắt buộc trước khi người dùng nhận link rút gọn đầu tiên. Hệ thống yêu cầu user mở từng kênh và xác nhận trước khi tiếp tục.</p>
        <?php if (!empty($channelSaved)): ?><p class="ok">Đã cập nhật cấu hình kênh.</p><?php endif; ?>
        <?php if (!empty($channelError)): ?><p class="err"><?= htmlspecialchars($channelError) ?></p><?php endif; ?>

        <div class="region-form" style="margin-top:17px">
            <h4 style="margin:0 0 9px;color:var(--text)">Thêm kênh nhiệm vụ</h4>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <select name="channel_type" aria-label="Loại kênh">
                    <?php foreach ($channelTypes as $key => $label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?>
                </select>
                <input type="number" name="channel_sort_order" value="0" min="0" max="999" title="Thứ tự hiển thị" style="width:92px" placeholder="Thứ tự">
                <input type="text" name="channel_label" required maxlength="80" placeholder="Tên hiển thị (vd: HoQuoc Official)" style="width:250px">
                <input type="url" name="channel_url" required maxlength="500" placeholder="https://youtube.com/@yourchannel" style="width:min(100%,360px)"><br>
                <input type="text" name="channel_requirement" maxlength="150" placeholder="Yêu cầu (vd: Đăng ký kênh để ủng hộ)" style="width:min(100%,420px)">
                <label style="display:inline-flex;align-items:center;gap:6px;margin:4px 12px 4px 2px;font-size:12px;color:var(--text-dim)"><input type="checkbox" name="channel_enabled" checked style="min-height:auto;margin:0"> Bật ngay</label>
                <button type="submit" name="add_channel" value="1">+ Thêm kênh</button>
            </form>
        </div>

        <div style="margin-top:21px" class="tablewrap">
            <table>
                <tr><th>Thứ tự</th><th>Kênh</th><th>Yêu cầu</th><th>Trạng thái</th><th></th></tr>
                <?php foreach ($channelRows as $channel): ?>
                <tr>
                    <td><?= (int)$channel['sort_order'] ?></td>
                    <td><b><?= htmlspecialchars($channelTypes[$channel['type']] ?? 'Khác') ?></b><br><span style="font-size:11px;color:var(--text-dim)"><?= htmlspecialchars($channel['label']) ?></span></td>
                    <td style="max-width:260px;white-space:normal"><?= htmlspecialchars($channel['requirement'] ?: 'Tham gia kênh') ?></td>
                    <td><span class="badge <?= $channel['enabled'] ? 'on' : 'off' ?>"><?= $channel['enabled'] ? 'đang bật' : 'đã tắt' ?></span></td>
                    <td>
                        <a href="<?= htmlspecialchars($channel['url']) ?>" target="_blank" rel="noopener" style="color:var(--cyan);font-size:11px;margin-right:7px">Mở</a>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="toggle_channel_id" value="<?= (int)$channel['id'] ?>"><button class="warn small" type="submit"><?= $channel['enabled'] ? 'Tắt' : 'Bật' ?></button></form>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="delete_channel_id" value="<?= (int)$channel['id'] ?>"><button class="danger small" type="submit" onclick="return confirm('Xoá kênh này khỏi nhiệm vụ?')">Xoá</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($channelRows)): ?><tr><td colspan="5" style="color:var(--text-dim);text-align:center;padding:24px">Chưa có kênh nào. Nếu chưa thêm kênh, user sẽ đi thẳng vào bước tạo link.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>

    <?php elseif ($tab === 'notice'):
        $noticeRows = get_site_notices();
    ?>

    <div class="box">
        <h3>Thông báo người dùng</h3>
        <p style="font-size:12.5px;color:var(--text-dim);margin-top:-5px;max-width:650px">Thông báo mới nhất đang bật sẽ xuất hiện cho user trong màn tạo link và trang nhiệm vụ kênh.</p>
        <?php if (!empty($noticeSaved)): ?><p class="ok">Đã cập nhật thông báo.</p><?php endif; ?>
        <?php if (!empty($noticeError)): ?><p class="err"><?= htmlspecialchars($noticeError) ?></p><?php endif; ?>

        <div class="region-form" style="margin-top:17px">
            <h4 style="margin:0 0 9px;color:var(--text)">Tạo thông báo mới</h4>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="text" name="notice_title" required maxlength="100" placeholder="Tiêu đề thông báo" style="width:min(100%,340px)">
                <select name="notice_type" aria-label="Màu thông báo">
                    <option value="info">Thông tin</option><option value="success">Tích cực</option><option value="warning">Lưu ý</option>
                </select>
                <label style="display:inline-flex;align-items:center;gap:6px;margin:4px 12px 4px 2px;font-size:12px;color:var(--text-dim)"><input type="checkbox" name="notice_enabled" checked style="min-height:auto;margin:0"> Hiển thị ngay</label><br>
                <textarea name="notice_message" required maxlength="600" placeholder="Nội dung gửi tới người dùng..." style="width:min(100%,640px);min-height:95px;margin:5px 4px 5px 0;padding:10px 12px;border-radius:10px;border:1px solid var(--line-strong);background:rgba(21,29,42,.9);color:var(--text);font:13px 'Inter',sans-serif;resize:vertical"></textarea><br>
                <button type="submit" name="add_site_notice" value="1">Đăng thông báo</button>
            </form>
        </div>

        <div style="margin-top:21px" class="tablewrap">
            <table>
                <tr><th>Thông báo</th><th>Loại</th><th>Trạng thái</th><th></th></tr>
                <?php foreach ($noticeRows as $notice): ?>
                <tr>
                    <td style="max-width:470px;white-space:normal"><b><?= htmlspecialchars($notice['title']) ?></b><br><span style="font-size:11px;color:var(--text-dim)"><?= nl2br(htmlspecialchars($notice['message'])) ?></span></td>
                    <td><?= htmlspecialchars($notice['type']) ?></td>
                    <td><span class="badge <?= $notice['enabled'] ? 'on' : 'off' ?>"><?= $notice['enabled'] ? 'đang bật' : 'đã tắt' ?></span></td>
                    <td>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="toggle_site_notice_id" value="<?= (int)$notice['id'] ?>"><button class="warn small" type="submit"><?= $notice['enabled'] ? 'Ẩn' : 'Hiện' ?></button></form>
                        <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"><input type="hidden" name="delete_site_notice_id" value="<?= (int)$notice['id'] ?>"><button class="danger small" type="submit" onclick="return confirm('Xoá thông báo này?')">Xoá</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($noticeRows)): ?><tr><td colspan="4" style="color:var(--text-dim);text-align:center;padding:24px">Chưa có thông báo nào.</td></tr><?php endif; ?>
            </table>
        </div>
    </div>

    <?php else: /* tab === 'keys' */ ?>

    <div class="box">
        <h3>Tạo Key thủ công</h3>
        <?php if (!empty($createdKeys)): ?>
        <div style="background:var(--bg);border-radius:10px;padding:12px;margin-bottom:12px">
            <p class="ok" style="margin:0 0 8px">Đã tạo <?= count($createdKeys) ?> key:</p>
            <textarea id="createdKeysBox" readonly style="width:100%;min-height:<?= min(160, 24 * count($createdKeys)) ?>px;background:var(--surface2);color:var(--cyan);font-family:'JetBrains Mono',monospace;font-size:12.5px;border:1px solid #262b38;border-radius:8px;padding:8px;resize:vertical"><?= htmlspecialchars(implode("\n", $createdKeys)) ?></textarea>
            <button type="button" class="small" style="margin-top:6px" onclick="const t=document.getElementById('createdKeysBox');navigator.clipboard.writeText(t.value);this.textContent='✓ Đã copy!';setTimeout(()=>this.textContent='Copy tất cả',1200)">Copy tất cả</button>
        </div>
        <?php endif; ?>
        <form method="post"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="number" name="quantity" value="1" min="1" max="200" style="width:70px" title="Số lượng key muốn tạo">
            <span style="font-size:12px;color:#888">key ×</span>
            <input type="number" name="duration_value" id="durValue" value="1" min="1" style="width:70px">
            <select name="duration_unit" id="durUnit" onchange="document.getElementById('durValue').style.display = this.value==='forever' ? 'none' : 'inline-block'">
                <option value="hour">Giờ</option>
                <option value="day" selected>Ngày</option>
                <option value="week">Tuần</option>
                <option value="month">Tháng</option>
                <option value="forever">∞ Vĩnh viễn</option>
            </select>
            <input type="number" name="max_devices" value="1" min="1" style="width:90px" title="Số thiết bị tối đa">
            <span style="font-size:13px;color:#888">thiết bị</span>
            <button type="submit" name="create_key" value="1">Tạo Key</button>
            <div style="font-size:12px;color:#888;margin-top:6px">Thời hạn chỉ bắt đầu tính từ lúc key được dùng lần đầu trong app. Chọn "Vĩnh viễn" để key không bao giờ hết hạn. Tối đa 200 key/lần.</div>
        </form>
    </div>

    <div class="box">
        <h3>Danh sách Key (<?= $totalKeysMatching ?>)</h3>
        <form method="get" style="margin-bottom:10px">
            <input type="hidden" name="tab" value="keys">
            <input type="text" name="q" value="<?= htmlspecialchars($searchQ) ?>" placeholder="Tìm theo mã key..." style="width:180px">
            <select name="status">
                <option value="all" <?= $filterStatus==='all'?'selected':'' ?>>Tất cả trạng thái</option>
                <option value="active" <?= $filterStatus==='active'?'selected':'' ?>>Đang hoạt động</option>
                <option value="pending" <?= $filterStatus==='pending'?'selected':'' ?>>Chưa kích hoạt</option>
                <option value="expired" <?= $filterStatus==='expired'?'selected':'' ?>>Đã hết hạn</option>
            </select>
            <button type="submit">Tìm</button>
            <?php if ($searchQ !== '' || $filterStatus !== 'all'): ?><a href="admin.php?tab=keys" style="font-size:12px;color:var(--text-dim);margin-left:6px">Xoá lọc</a><?php endif; ?>
        </form>
        <form method="post" style="margin-bottom:10px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <button type="submit" name="cleanup_expired" value="1" class="warn small" onclick="return confirm('Xoá toàn bộ key đã hết hạn + TOÀN BỘ key pending (chưa kích hoạt)? Không thể hoàn tác.')">Dọn dẹp key hết hạn / pending</button>
            <?php if (isset($cleanupExpiredCount)): ?><span style="font-size:12px;color:var(--text-dim);margin-left:6px">Đã xoá <?= $cleanupExpiredCount ?> key hết hạn, <?= $cleanupPendingCount ?> key pending bỏ dở</span><?php endif; ?>
        </form>
        <div class="tablewrap">
        <table>
            <tr><th>Key</th><th>Trạng thái</th><th>Thời hạn</th><th>Thiết bị</th><th>Bắt đầu dùng</th><th>Hết hạn</th><th></th></tr>
            <?php foreach ($keys as $k):
                $devList = $k['devices'] !== '' ? explode(',', $k['devices']) : [];
                $ipMap = json_decode($k['device_ip_map'] ?: '{}', true) ?: [];
                $status = $k['status'];
                if ($status === 'active' && $k['expires_at'] && time() > (int)$k['expires_at']) $status = 'expired';
            ?>
            <tr>
                <td>
                    <span id="kc-<?= $k['id'] ?>" style="font-family:monospace"><?= htmlspecialchars($k['keycode']) ?></span>
                    <button class="small" style="margin-left:4px" onclick="copyKeycode('<?= $k['id'] ?>', this)" title="Sao chép key"><?= svg_icon('copy', 13) ?></button>
                </td>
                <td><span class="badge <?= $status ?>"><?= $status ?></span></td>
                <td><?= fmt_duration((int)$k['duration_seconds']) ?></td>
                <td>
                    <?php if (empty($devList)): ?>
                        0/<?= $k['max_devices'] ?>
                    <?php else: ?>
                    <details>
                        <summary style="cursor:pointer;color:var(--cyan)"><?= count($devList) ?>/<?= $k['max_devices'] ?></summary>
                        <div style="margin-top:6px;display:flex;flex-direction:column;gap:4px">
                            <?php foreach ($devList as $dev): $ip = $ipMap[$dev] ?? '?'; ?>
                            <div style="font-family:monospace;font-size:11px;background:var(--bg);border-radius:6px;padding:5px 7px;display:flex;align-items:center;justify-content:space-between;gap:6px">
                                <span title="<?= htmlspecialchars($dev) ?>"><?= htmlspecialchars(substr($dev, 0, 10)) ?>… · IP <?= htmlspecialchars($ip) ?></span>
                                <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                    <input type="hidden" name="ban_device_id" value="<?= htmlspecialchars($dev) ?>">
                                    <button class="danger small" type="submit" onclick="return confirm('Cấm vĩnh viễn thiết bị này? Sẽ không đăng nhập được key nào nữa.')" style="padding:2px 7px;font-size:10px">Cấm</button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <?php endif; ?>
                </td>
                <td><?= fmt_time($k['first_used_at']) ?></td>
                <td><?= fmt_time($k['expires_at']) ?></td>
                <td>
                    <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="reset_id" value="<?= $k['id'] ?>">
                        <button class="warn small" type="submit" onclick="return confirm('Reset key này về trạng thái chưa dùng?')">Reset</button>
                    </form>
                    <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="delete_id" value="<?= $k['id'] ?>">
                        <button class="danger small" type="submit" onclick="return confirm('Xoá key này vĩnh viễn?')">Xoá</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <?php
            $pageLink = function (int $p) use ($searchQ, $filterStatus) {
                return 'admin.php?tab=keys&page=' . $p
                    . ($searchQ !== '' ? '&q=' . urlencode($searchQ) : '')
                    . ($filterStatus !== 'all' ? '&status=' . urlencode($filterStatus) : '');
            };
        ?>
        <div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:14px;flex-wrap:wrap">
            <?php if ($page > 1): ?><a href="<?= $pageLink($page - 1) ?>" class="small" style="padding:6px 10px;background:var(--surface2);border-radius:6px;color:var(--text);text-decoration:none;font-size:12px">‹ Trước</a><?php endif; ?>

            <?php
                $startP = max(1, $page - 2);
                $endP = min($totalPages, $page + 2);
                if ($startP > 1) { echo '<a href="' . $pageLink(1) . '" style="padding:6px 10px;color:var(--text-dim);text-decoration:none;font-size:12px">1</a>'; if ($startP > 2) echo '<span style="color:var(--text-dim)">…</span>'; }
                for ($p = $startP; $p <= $endP; $p++):
                    if ($p === $page): ?>
                        <span style="padding:6px 11px;background:linear-gradient(135deg,var(--cyan),var(--violet));color:#0B0E14;border-radius:6px;font-size:12px;font-weight:700"><?= $p ?></span>
                    <?php else: ?>
                        <a href="<?= $pageLink($p) ?>" style="padding:6px 11px;color:var(--text-dim);text-decoration:none;font-size:12px"><?= $p ?></a>
                    <?php endif;
                endfor;
                if ($endP < $totalPages) { if ($endP < $totalPages - 1) echo '<span style="color:var(--text-dim)">…</span>'; echo '<a href="' . $pageLink($totalPages) . '" style="padding:6px 10px;color:var(--text-dim);text-decoration:none;font-size:12px">' . $totalPages . '</a>'; }
            ?>

            <?php if ($page < $totalPages): ?><a href="<?= $pageLink($page + 1) ?>" class="small" style="padding:6px 10px;background:var(--surface2);border-radius:6px;color:var(--text);text-decoration:none;font-size:12px">Sau ›</a><?php endif; ?>
        </div>
        <div style="text-align:center;font-size:11px;color:var(--text-dim);margin-top:6px">Trang <?= $page ?>/<?= $totalPages ?> — <?= $totalKeysMatching ?> key</div>
        <?php endif; ?>
    </div>

    <div class="box">
        <h3><?= svg_icon('ban', 17) ?> Thiết bị bị cấm vĩnh viễn</h3>
        <p style="font-size:12px;color:#888;margin-top:-4px">Thiết bị trong danh sách này không đăng nhập được BẤT KỲ key nào, kể cả key mới.</p>
        <form method="post" style="margin-bottom:12px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            <input type="text" name="ban_device_id" placeholder="Nhập device_id cần cấm" style="width:220px">
            <input type="text" name="ban_device_reason" placeholder="Lý do (tuỳ chọn)" style="width:180px">
            <button type="submit">Cấm thiết bị</button>
        </form>
        <div class="tablewrap">
        <table>
            <tr><th>Device ID</th><th>Lý do</th><th>Lúc cấm</th><th></th></tr>
            <?php foreach (get_banned_devices() as $bd): ?>
            <tr>
                <td style="font-family:monospace"><?= htmlspecialchars($bd['device_id']) ?></td>
                <td><?= htmlspecialchars($bd['reason']) ?></td>
                <td><?= fmt_time((int)$bd['banned_at']) ?></td>
                <td>
                    <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="unban_device_id" value="<?= htmlspecialchars($bd['device_id']) ?>">
                        <button class="warn small" type="submit">Gỡ cấm</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty(get_banned_devices())): ?><tr><td colspan="4" style="color:#888">Chưa có thiết bị nào bị cấm</td></tr><?php endif; ?>
        </table>
        </div>
    </div>

    <div class="box">
        <h3><?= svg_icon('phone', 17) ?> Lịch sử thiết bị đăng nhập</h3>
        <p style="font-size:12px;color:#888;margin-top:-4px">Ghi lại VĨNH VIỄN mỗi lần 1 thiết bị mới đăng nhập 1 key - không mất dù key đó sau này bị reset hay xoá.</p>
        <form method="get" style="margin-bottom:10px">
            <input type="hidden" name="tab" value="keys">
            <input type="text" name="history_q" value="<?= htmlspecialchars($_GET['history_q'] ?? '') ?>" placeholder="Tìm theo key / device_id / IP..." style="width:220px">
            <button type="submit">Tìm</button>
        </form>
        <div class="tablewrap">
        <table>
            <tr><th>Key</th><th>Device ID</th><th>IP</th><th>Lúc dùng</th><th></th></tr>
            <?php
                $historyQ = trim($_GET['history_q'] ?? '');
                $historyPerPage = 10;
                $historyTotal = count_device_history($historyQ);
                $historyTotalPages = max(1, (int)ceil($historyTotal / $historyPerPage));
                $historyPage = max(1, min($historyTotalPages, (int)($_GET['history_page'] ?? 1)));
                $historyRows = search_device_history($historyQ, $historyPerPage, ($historyPage - 1) * $historyPerPage);
                $historyPageLink = static function (int $targetPage) use ($historyQ): string {
                    return 'admin.php?' . http_build_query([
                        'tab' => 'keys',
                        'history_q' => $historyQ,
                        'history_page' => $targetPage,
                    ]);
                };
            ?>
            <?php foreach ($historyRows as $h): ?>
            <tr>
                <td style="font-family:monospace"><?= htmlspecialchars($h['keycode']) ?></td>
                <td style="font-family:monospace" title="<?= htmlspecialchars($h['device_id']) ?>"><?= htmlspecialchars(substr($h['device_id'], 0, 12)) ?>…</td>
                <td><?= htmlspecialchars($h['ip']) ?></td>
                <td><?= fmt_time((int)$h['used_at']) ?></td>
                <td>
                    <form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="ban_device_id" value="<?= htmlspecialchars($h['device_id']) ?>">
                        <button class="danger small" type="submit" onclick="return confirm('Cấm vĩnh viễn thiết bị này?')">Cấm</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($historyRows)): ?><tr><td colspan="5" style="color:#888">Chưa có dữ liệu</td></tr><?php endif; ?>
        </table>
        </div>
        <?php if ($historyTotal > 0): ?>
        <div style="display:flex;justify-content:center;align-items:center;gap:6px;flex-wrap:wrap;margin-top:15px">
            <?php if ($historyPage > 1): ?><a href="<?= htmlspecialchars($historyPageLink($historyPage - 1)) ?>" class="small" style="padding:6px 10px;background:var(--surface2);border-radius:6px;color:var(--text);text-decoration:none;font-size:12px">‹ Trước</a><?php endif; ?>
            <?php
                $historyStartPage = max(1, $historyPage - 2);
                $historyEndPage = min($historyTotalPages, $historyPage + 2);
                for ($historyDisplayPage = $historyStartPage; $historyDisplayPage <= $historyEndPage; $historyDisplayPage++):
            ?>
                <a href="<?= htmlspecialchars($historyPageLink($historyDisplayPage)) ?>" style="min-width:29px;padding:6px 8px;text-align:center;border-radius:6px;text-decoration:none;font-size:12px;color:<?= $historyDisplayPage === $historyPage ? 'var(--bg)' : 'var(--text-dim)' ?>;background:<?= $historyDisplayPage === $historyPage ? 'var(--cyan)' : 'var(--surface2)' ?>"><?= $historyDisplayPage ?></a>
            <?php endfor; ?>
            <?php if ($historyPage < $historyTotalPages): ?><a href="<?= htmlspecialchars($historyPageLink($historyPage + 1)) ?>" class="small" style="padding:6px 10px;background:var(--surface2);border-radius:6px;color:var(--text);text-decoration:none;font-size:12px">Sau ›</a><?php endif; ?>
        </div>
        <p style="font-size:11px;color:#888;margin-top:7px;text-align:center">Trang <?= $historyPage ?>/<?= $historyTotalPages ?> — <?= $historyTotal ?> thiết bị đăng nhập<?= $historyQ !== '' ? ' khớp tìm kiếm' : '' ?> — hiển thị 10 thiết bị/trang.</p>
        <?php endif; ?>
    </div>

    <?php endif; ?>


<?php endif; ?>
<script>
function copyKeycode(id, btn) {
    const el = document.getElementById('kc-' + id);
    navigator.clipboard.writeText(el.textContent);
    const original = btn.textContent;
    btn.textContent = '✓';
    setTimeout(() => { btn.textContent = original; }, 1200);
}

// Copy link lấy key - chỉ đổi textContent của span label, KHÔNG đụng
// vào innerHTML của nút (nút có chứa SVG, đổi innerHTML bằng chuỗi ghép
// tay dễ vỡ HTML/thuộc tính - xem bug cũ đã gặp)
function copyGetKeyLink(btn) {
    const input = document.getElementById(btn.dataset.target);
    navigator.clipboard.writeText(input.value);
    const label = btn.querySelector('.copy-label');
    const original = label.textContent;
    label.textContent = 'Đã chép';
    setTimeout(() => { label.textContent = original; }, 1500);
}

// Chặn chuột phải / phím tắt mở dev tool / xem nguồn trang - CHỈ LÀ RÀO
// CẢN PHỤ mang tính răn đe, không phải bảo mật thật (JS luôn tắt được).
// Lớp bảo vệ THẬT nằm ở server: CSRF token, session HttpOnly/Secure,
// rate-limit đăng nhập - những cái này không thể tắt qua devtools.
document.addEventListener('contextmenu', e => e.preventDefault());
document.addEventListener('keydown', e => {
    const blocked =
        e.key === 'F12' ||
        (e.ctrlKey && e.shiftKey && ['I', 'J', 'C'].includes(e.key.toUpperCase())) ||
        (e.ctrlKey && e.key.toUpperCase() === 'U');
    if (blocked) e.preventDefault();
});
</script>
</body>
</html>
