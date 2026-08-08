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

        $keycode = strtoupper(substr(random_string(16), 0, 12));
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
        $db->prepare("UPDATE keys SET devices='', first_used_at=NULL, expires_at=NULL, status='active' WHERE id = ?")
           ->execute([(int)$_POST['reset_id']]);
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

function fmt_time($ts) { return $ts ? date('H:i d/m/Y', $ts) : '-'; }
function fmt_duration($seconds) {
    if ($seconds % 2592000 === 0) return ($seconds / 2592000) . ' tháng';
    if ($seconds % 604800 === 0) return ($seconds / 604800) . ' tuần';
    if ($seconds % 86400 === 0) return ($seconds / 86400) . ' ngày';
    return round($seconds / 3600) . ' giờ';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin - Key Server</title>
<style>
*{box-sizing:border-box}
body{font-family:-apple-system,Arial,sans-serif;font-size:14px;max-width:900px;margin:0 auto;background:#0f1115;color:#eee;padding:0 12px 30px;animation:fadeIn .35s ease}
h2{font-size:19px}h3{font-size:16px}h4{font-size:13px}
input,select{padding:8px;margin:5px 4px 5px 0;border-radius:6px;border:1px solid #333;background:#1a1d24;color:#eee;font-size:13px;max-width:100%;transition:border-color .2s,box-shadow .2s}
input:focus,select:focus{outline:none;border-color:#4f8cff;box-shadow:0 0 0 3px rgba(79,140,255,.15)}
button{padding:9px 14px;background:#4f8cff;border:none;border-radius:6px;color:#fff;font-weight:bold;cursor:pointer;font-size:13px;transition:transform .12s,filter .15s,box-shadow .15s}
button:hover{filter:brightness(1.1)}
button:active{transform:scale(.96)}
button.danger{background:#e05353}
button.warn{background:#c98a2d}
button.small{padding:5px 10px;font-size:12px}
.box{background:#181b22;padding:16px;border-radius:10px;margin-bottom:16px;animation:slideUp .3s ease;box-shadow:0 2px 8px rgba(0,0,0,.2);transition:box-shadow .2s}
.ok{color:#4caf50;animation:popIn .3s ease}.err{color:#f44336;animation:popIn .3s ease}
table{width:100%;border-collapse:collapse;font-size:12px}
.tablewrap{overflow-x:auto}
th,td{padding:6px;border-bottom:1px solid #262a33;text-align:left;white-space:nowrap}
tr{transition:background .15s}
tr:hover td{background:#1c1f28}
.badge{padding:2px 7px;border-radius:10px;font-size:11px;transition:transform .15s}
.badge.active{background:#1e4620;color:#4caf50}
.badge.pending{background:#4a3d16;color:#e0b23f}
.badge.expired{background:#4a1e1e;color:#e05353}
.badge.on{background:#1e4620;color:#4caf50}
.badge.off{background:#333;color:#999}
.loginbox{max-width:340px;margin:60px auto;animation:popIn .35s ease}
.game-card{background:#12141a;border-radius:8px;padding:12px;margin-bottom:10px;transition:transform .15s,box-shadow .15s;animation:slideUp .3s ease}
.game-card:hover{transform:translateY(-2px);box-shadow:0 4px 14px rgba(0,0,0,.3)}
.game-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.region-form{background:#0f1115;border-radius:8px;padding:10px;margin-top:6px}
.region-form input,.region-form select{width:100%;margin:4px 0}
.step-row{display:flex;gap:4px;flex-wrap:wrap}
.step-row select{flex:1;min-width:110px}

/* Header + menu 3 gạch */
.topbar{position:sticky;top:0;background:#0f1115ee;backdrop-filter:blur(6px);padding:14px 0;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #262a33;margin-bottom:16px;z-index:10}
.hamburger{background:none;border:none;font-size:22px;color:#eee;cursor:pointer;padding:4px 8px;transition:transform .2s}
.hamburger:active{transform:scale(.85) rotate(90deg)}
.navmenu{display:grid;grid-template-rows:0fr;opacity:0;background:#181b22;border-radius:10px;margin-bottom:0;overflow:hidden;transition:grid-template-rows .25s ease,opacity .2s ease,margin-bottom .25s ease}
.navmenu.open{grid-template-rows:1fr;opacity:1;margin-bottom:16px}
.navmenu-inner{display:flex;flex-direction:column;gap:6px;padding:10px;min-height:0}
.navmenu a{color:#eee;text-decoration:none;padding:10px 12px;border-radius:8px;font-size:14px;transition:background .15s,transform .1s}
.navmenu a:active{transform:scale(.98)}
.navmenu a.active{background:#4f8cff;font-weight:bold}
.navmenu a:not(.active){background:#12141a}
.navmenu a:not(.active):hover{background:#1c1f28}
.provider-row{display:flex;align-items:center;justify-content:space-between;background:#12141a;border-radius:8px;padding:8px 10px;margin-bottom:6px;transition:transform .15s}
.provider-row:hover{transform:translateX(2px)}

/* Thống kê */
.stats-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
.stat-card{background:#12141a;border-radius:10px;padding:14px;text-align:center;transition:transform .15s}
.stat-card:hover{transform:translateY(-2px)}
.stat-num{font-size:24px;font-weight:bold;color:#4f8cff}
.stat-label{font-size:12px;color:#999;margin-top:2px}

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
        <button class="hamburger" onclick="document.getElementById('navmenu').classList.toggle('open')">☰</button>
        <b style="font-size:15px"><?= ['keys'=>'Tạo Key','game'=>'Cấu hình Game','link'=>'Cấu hình Link','stats'=>'Thống kê'][$tab] ?? '' ?></b>
        <a href="admin.php?logout=1" style="color:#888;font-size:13px">Đăng xuất</a>
    </div>

    <div class="navmenu" id="navmenu">
        <div class="navmenu-inner">
            <a href="admin.php?tab=stats" class="<?= $tab==='stats'?'active':'' ?>">📊 Thống kê</a>
            <a href="admin.php?tab=keys" class="<?= $tab==='keys'?'active':'' ?>">🔑 Tạo Key</a>
            <a href="admin.php?tab=game" class="<?= $tab==='game'?'active':'' ?>">🎮 Cấu hình Game</a>
            <a href="admin.php?tab=link" class="<?= $tab==='link'?'active':'' ?>">🔗 Cấu hình Link</a>
        </div>
    </div>

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
            <input type="number" name="duration_value" value="1" min="1" style="width:70px">
            <select name="duration_unit">
                <option value="hour">Giờ</option>
                <option value="day" selected>Ngày</option>
                <option value="week">Tuần</option>
                <option value="month">Tháng</option>
            </select>
            <input type="number" name="max_devices" value="1" min="1" style="width:90px" title="Số thiết bị tối đa">
            <span style="font-size:13px;color:#888">thiết bị</span>
            <button type="submit" name="create_key" value="1">Tạo Key</button>
            <div style="font-size:12px;color:#888;margin-top:6px">Thời hạn chỉ bắt đầu tính từ lúc key được dùng lần đầu trong app.</div>
        </form>
    </div>

    <div class="box">
        <h3>Danh sách Key (<?= count($keys) ?>)</h3>
        <div class="tablewrap">
        <table>
            <tr><th>Key</th><th>Trạng thái</th><th>Thời hạn</th><th>Thiết bị</th><th>Bắt đầu dùng</th><th>Hết hạn</th><th></th></tr>
            <?php foreach ($keys as $k):
                $devList = $k['devices'] !== '' ? explode(',', $k['devices']) : [];
                $status = $k['status'];
                if ($status === 'active' && $k['expires_at'] && time() > (int)$k['expires_at']) $status = 'expired';
            ?>
            <tr>
                <td><?= htmlspecialchars($k['keycode']) ?></td>
                <td><span class="badge <?= $status ?>"><?= $status ?></span></td>
                <td><?= fmt_duration((int)$k['duration_seconds']) ?></td>
                <td><?= count($devList) ?>/<?= $k['max_devices'] ?></td>
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

    <?php endif; ?>

<?php endif; ?>
</body>
</html>
