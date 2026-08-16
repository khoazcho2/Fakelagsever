<?php
// ============================================================
// admin.php - Trang quản trị Key Server (chỉ admin dùng)
// Chia 3 danh mục qua menu 3 gạch (☰): Tạo Key, Cấu hình Game,
// Cấu hình Link (nhà cung cấp rút gọn link)
// ============================================================
require_once __DIR__ . '/config.php';

session_start();

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
    $okPass = hash_equals(ADMIN_PASSWORD, $_POST['admin_password']);
    if ($okUser && $okPass) {
        $_SESSION['is_admin'] = true;
        $_SESSION['fail_count'] = 0;
        unset($_SESSION['lock_until']);
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

    if (isset($_POST['create_key'])) {
        $value = max(1, (int)($_POST['duration_value'] ?? 1));
        $unit = $_POST['duration_unit'] ?? 'day';
        $maxDevices = max(1, (int)($_POST['max_devices'] ?? 1));
        $durationSeconds = duration_to_seconds($value, $unit);

        $keycode = generate_keycode();
        $token = random_string(32);

        $stmt = $db->prepare("INSERT INTO keys (keycode, token, status, duration_seconds, max_devices, created_at, activated_at)
                               VALUES (?, ?, 'active', ?, ?, ?, ?)");
        $stmt->execute([$keycode, $token, $durationSeconds, $maxDevices, time(), time()]);
        $createdKey = $keycode;
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

    if (isset($_POST['update_region_game_id'], $_POST['update_region'])) {
        $chainSteps = [];
        for ($i = 1; $i <= 5; $i++) {
            $p = trim($_POST['chain_step_' . $i] ?? '');
            if ($p !== '') $chainSteps[] = $p;
        }
        update_game_region(
            (int)$_POST['update_region_game_id'],
            $_POST['update_region'],
            (int)($_POST['region_hops'] ?? 1),
            (int)($_POST['region_hours'] ?? 24),
            $chainSteps
        );
    }
}

$cfg = get_shortener_config();
$builtins = get_builtin_providers();

$keys = [];
$games = [];
if (isset($_SESSION['is_admin'])) {
    $keys = $db->query("SELECT * FROM keys ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    $games = get_games();
}

// Tab hiện tại: keys | game | link
$tab = $_GET['tab'] ?? 'stats';
if (!in_array($tab, ['keys', 'game', 'link', 'stats'], true)) $tab = 'stats';

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
.stat-card{background:var(--bg);border-radius:10px;padding:14px;text-align:center;transition:transform .15s}
.stat-card:hover{transform:translateY(-2px)}
.stat-num{font-family:'Space Grotesk',sans-serif;font-size:24px;font-weight:700;color:var(--cyan)}
.stat-label{font-size:12px;color:var(--text-dim);margin-top:2px}

@keyframes fadeIn{from{opacity:0}to{opacity:1}}
@keyframes slideUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
@keyframes popIn{from{opacity:0;transform:scale(.94)}to{opacity:1;transform:scale(1)}}
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
        <form method="post">
            <input type="text" name="admin_username" placeholder="Tài khoản" required style="width:100%;box-sizing:border-box"><br>
            <input type="password" name="admin_password" placeholder="Mật khẩu" required style="width:100%;box-sizing:border-box"><br>
            <button type="submit" style="width:100%">Đăng nhập</button>
        </form>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div class="topbar">
        <button class="hamburger" onclick="openSidebar()"><?= svg_icon('menu', 22) ?></button>
        <b style="font-size:15px"><?= ['keys'=>'Tạo Key','game'=>'Cấu hình Game','link'=>'Cấu hình Link','stats'=>'Thống kê'][$tab] ?? '' ?></b>
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
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-num"><?= $stats['total_keys'] ?></div><div class="stat-label">Tổng số key</div></div>
            <div class="stat-card"><div class="stat-num"><?= $stats['active_keys'] ?></div><div class="stat-label">Đang hoạt động</div></div>
            <div class="stat-card"><div class="stat-num"><?= $stats['pending_keys'] ?></div><div class="stat-label">Chưa kích hoạt</div></div>
            <div class="stat-card"><div class="stat-num"><?= $stats['expired_keys'] ?></div><div class="stat-label">Đã hết hạn</div></div>
            <div class="stat-card"><div class="stat-num"><?= $stats['today_claims'] ?></div><div class="stat-label">Lấy key 24h qua</div></div>
            <div class="stat-card"><div class="stat-num"><?= $stats['active_games'] ?>/<?= $stats['total_games'] ?></div><div class="stat-label">Game đang mở</div></div>
        </div>
    </div>

    <div class="box">
        <h3><?= svg_icon('cloud', 17) ?> Sao lưu Firebase</h3>
        <?php if (!FIREBASE_ENABLED): ?>
            <p style="font-size:13px;color:#e0b23f">⚠️ Chưa cấu hình - dữ liệu sẽ MẤT khi Render redeploy/restart. Set biến môi trường <code>FIREBASE_DB_URL</code> và <code>FIREBASE_SERVICE_ACCOUNT_JSON</code> trên Render để bật sao lưu tự động (xem hướng dẫn trong <code>config.php</code>).</p>
        <?php else:
            $statusFile = __DIR__ . '/data/firebase_backup_status.json';
            $backupStatus = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : null;
        ?>
            <?php if (!$backupStatus): ?>
                <p style="font-size:13px;color:#e0b23f">⚠️ Chưa có lần sao lưu nào được ghi nhận. Thử tạo/sửa 1 key hoặc game để kích hoạt lần đầu.</p>
            <?php elseif ($backupStatus['ok']): ?>
                <p style="font-size:13px;color:#4caf50">✅ Đã bật - lần sao lưu gần nhất thành công.</p>
                <p style="font-size:12px;color:#888">Lúc: <?= fmt_time((int)$backupStatus['at']) ?></p>
            <?php else: ?>
                <p style="font-size:13px;color:#f44336">❌ Sao lưu đang LỖI - dữ liệu KHÔNG được bảo vệ, kiểm tra lại cấu hình!</p>
                <p style="font-size:12px;color:#888">Lúc: <?= fmt_time((int)$backupStatus['at']) ?></p>
                <div style="font-family:monospace;font-size:11.5px;color:#e0b23f;background:#0f1115;border-radius:6px;padding:8px;margin-top:6px;word-break:break-all"><?= htmlspecialchars($backupStatus['error'] ?? '') ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="box">
        <h3>Theo từng game</h3>
        <div class="tablewrap">
        <table>
            <tr><th>Game</th><th>Tổng key</th><th>Đang hoạt động</th></tr>
            <?php foreach ($stats['per_game'] as $pg): ?>
            <tr>
                <td><?= htmlspecialchars($pg['icon']) ?> <?= htmlspecialchars($pg['name']) ?></td>
                <td><?= (int)$pg['total'] ?></td>
                <td><?= (int)$pg['active'] ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($stats['per_game'])): ?><tr><td colspan="3" style="color:#888">Chưa có dữ liệu</td></tr><?php endif; ?>
        </table>
        </div>
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
                    <form method="post" style="display:inline">
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

        <?php $configured = array_keys($cfg['keys']); ?>
        <?php if (!empty($configured)): ?>
        <h4 style="color:#aaa;font-weight:normal">Các provider đã lưu key</h4>
        <?php foreach ($configured as $p):
            $label = $builtins[$p]['label'] ?? ($cfg['custom']['label'] ?? $p);
        ?>
        <div class="provider-row">
            <div>
                <?= htmlspecialchars($label) ?>
                <?php if ($cfg['active'] === $p): ?><span class="badge on">active</span><?php endif; ?>
            </div>
            <div>
                <?php if ($cfg['active'] !== $p): ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="switch_active" value="<?= htmlspecialchars($p) ?>">
                    <button class="small" type="submit">Chọn active</button>
                </form>
                <?php endif; ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="delete_provider" value="<?= htmlspecialchars($p) ?>">
                    <button class="danger small" type="submit" onclick="return confirm('Xoá API key provider này?')">Xoá</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <h4 style="margin:16px 0 4px;color:#aaa;font-weight:normal">Thêm / cập nhật API key cho một provider có sẵn</h4>
        <form method="post">
            <select name="provider">
                <?php foreach ($builtins as $key => $p): ?>
                <option value="<?= $key ?>"><?= htmlspecialchars($p['label']) ?><?= isset($cfg['keys'][$key]) ? ' (đã có key)' : '' ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="api_key" placeholder="Vui Lòng Nhập Api Key" style="width:280px">
            <button type="submit">Lưu key cho provider này</button>
        </form>

        <h4 style="margin:16px 0 4px;color:#aaa;font-weight:normal">Hoặc thêm provider khác (không có sẵn trong danh sách)</h4>
        <form method="post">
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

    <?php elseif ($tab === 'game'): ?>

    <div class="box">
        <h3>Cấu hình Game</h3>
        <p style="font-size:12px;color:#888;margin-top:-4px">Cấu hình số lần vượt link + thời hạn key (giờ) riêng cho khách VN và nước ngoài, từng game.</p>

        <form method="post" style="margin-bottom:14px">
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
                    <form method="post" style="display:inline">
                        <input type="hidden" name="toggle_game_id" value="<?= $g['id'] ?>">
                        <button class="warn small" type="submit"><?= $g['enabled'] ? 'Tắt' : 'Bật' ?></button>
                    </form>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="delete_game_id" value="<?= $g['id'] ?>">
                        <button class="danger small" type="submit" onclick="return confirm('Xoá game này?')">Xoá</button>
                    </form>
                </div>
            </div>

            <?php foreach (['vn' => '🇻🇳 Khách Việt Nam', 'intl' => '🌏 Khách nước ngoài'] as $region => $label):
                $hops = $g[$region . '_hops'];
                $hours = $g[$region . '_key_hours'];
                $chain = $g[$region . '_chain'] !== '' ? explode(',', $g[$region . '_chain']) : [];
            ?>
            <div class="region-form">
                <b style="font-size:13px"><?= $label ?></b>
                <form method="post">
                    <input type="hidden" name="update_region_game_id" value="<?= $g['id'] ?>">
                    <input type="hidden" name="update_region" value="<?= $region ?>">
                    <label style="font-size:12px;color:#888">Số lần vượt</label>
                    <input type="number" name="region_hops" value="<?= $hops ?>" min="1" max="5">
                    <label style="font-size:12px;color:#888">Hạn key (giờ)</label>
                    <input type="number" name="region_hours" value="<?= $hours ?>" min="1">
                    <label style="font-size:12px;color:#888">Thứ tự link vượt (tuỳ chọn, để trống = dùng provider active)</label>
                    <div class="step-row">
                        <?php for ($i = 1; $i <= 5; $i++): $cur = $chain[$i - 1] ?? ''; ?>
                        <select name="chain_step_<?= $i ?>">
                            <option value="">Bước <?= $i ?>: -</option>
                            <?php foreach (array_keys($cfg['keys'] ?? []) as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>" <?= $cur===$p?'selected':'' ?>><?= htmlspecialchars($builtins[$p]['label'] ?? $p) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endfor; ?>
                    </div>
                    <button type="submit" class="small" style="margin-top:8px">Lưu cấu hình <?= $region==='vn'?'VN':'nước ngoài' ?></button>
                </form>
            </div>
            <?php endforeach; ?>

            <div style="font-size:12px;color:#888;margin-top:6px">Link lấy key: <?= htmlspecialchars(BASE_URL) ?>/getkey.php?game=<?= htmlspecialchars($g['slug']) ?></div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($games)): ?><p style="font-size:13px;color:#888">Chưa có game nào, thêm game ở trên.</p><?php endif; ?>
    </div>

    <?php else: /* tab === 'keys' */ ?>

    <div class="box">
        <h3>Tạo Key thủ công</h3>
        <?php if (isset($createdKey)): ?><p class="ok">Đã tạo key: <b><?= htmlspecialchars($createdKey) ?></b></p><?php endif; ?>
        <form method="post">
            <input type="number" name="duration_value" id="durValue" value="1" min="1" style="width:70px">
            <select name="duration_unit" id="durUnit" onchange="document.getElementById('durValue').style.display = this.value==='forever' ? 'none' : 'inline-block'">
                <option value="hour">Giờ</option>
                <option value="day" selected>Ngày</option>
                <option value="week">Tuần</option>
                <option value="month">Tháng</option>
                <option value="forever">♾️ Vĩnh viễn</option>
            </select>
            <input type="number" name="max_devices" value="1" min="1" style="width:90px" title="Số thiết bị tối đa">
            <span style="font-size:13px;color:#888">thiết bị</span>
            <button type="submit" name="create_key" value="1">Tạo Key</button>
            <div style="font-size:12px;color:#888;margin-top:6px">Thời hạn chỉ bắt đầu tính từ lúc key được dùng lần đầu trong app. Chọn "Vĩnh viễn" để key không bao giờ hết hạn.</div>
        </form>
    </div>

    <div class="box">
        <h3>Danh sách Key (<?= count($keys) ?>)</h3>
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
                                <form method="post" style="display:inline">
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
                    <form method="post" style="display:inline">
                        <input type="hidden" name="reset_id" value="<?= $k['id'] ?>">
                        <button class="warn small" type="submit" onclick="return confirm('Reset key này về trạng thái chưa dùng?')">Reset</button>
                    </form>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="delete_id" value="<?= $k['id'] ?>">
                        <button class="danger small" type="submit" onclick="return confirm('Xoá key này vĩnh viễn?')">Xoá</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </div>

    <div class="box">
        <h3><?= svg_icon('ban', 17) ?> Thiết bị bị cấm vĩnh viễn</h3>
        <p style="font-size:12px;color:#888;margin-top:-4px">Thiết bị trong danh sách này không đăng nhập được BẤT KỲ key nào, kể cả key mới.</p>
        <form method="post" style="margin-bottom:12px">
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
                    <form method="post" style="display:inline">
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

    <?php endif; ?>


<?php endif; ?>
<script>
function copyKeycode(id, btn) {
    const el = document.getElementById('kc-' + id);
    navigator.clipboard.writeText(el.textContent);
    const original = btn.textContent;
    btn.textContent = '✅';
    setTimeout(() => { btn.textContent = original; }, 1200);
}
</script>
</body>
</html>
