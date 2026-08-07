<?php
// ============================================================
// admin.php - Trang quản trị Key Server (chỉ admin dùng)
// - Đăng nhập bằng tài khoản + mật khẩu
// - Cấu hình API rút gọn link (link4m/yeumoney)
// - Tạo key thủ công: chọn thời hạn (giờ/ngày/tuần/tháng) + số thiết bị
// - Danh sách key: xoá / reset
// ============================================================
require_once __DIR__ . '/config.php';

session_start();

// Thời gian session admin tự hết hạn nếu không thao tác (giây)
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

// Đăng xuất thủ công
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Chống brute-force: khóa đăng nhập 5 phút sau 5 lần sai liên tiếp
$maxAttempts = 5;
$lockSeconds = 5 * 60;
$isLocked = isset($_SESSION['lock_until']) && time() < $_SESSION['lock_until'];

// Xử lý đăng nhập admin bằng tài khoản + mật khẩu, so sánh an toàn chống timing attack
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

    // Lưu cấu hình API shortlink (theo từng provider, không ghi đè provider khác)
    if (isset($_POST['provider'], $_POST['api_key']) && $_POST['provider'] !== 'custom') {
        save_shortener_config(trim($_POST['provider']), trim($_POST['api_key']));
        $saved = true;
    }

    // Lưu provider tuỳ chỉnh (khi dịch vụ không có sẵn trong danh sách)
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

    // Chuyển provider đang active sang provider khác đã có sẵn API key
    if (isset($_POST['switch_active'])) {
        set_active_provider(trim($_POST['switch_active']));
        $saved = true;
    }

    // Tạo key thủ công với thời hạn + số thiết bị tuỳ chọn
    if (isset($_POST['create_key'])) {
        $value = max(1, (int)($_POST['duration_value'] ?? 1));
        $unit = $_POST['duration_unit'] ?? 'day';
        $maxDevices = max(1, (int)($_POST['max_devices'] ?? 1));
        $durationSeconds = duration_to_seconds($value, $unit);

        $keycode = strtoupper(substr(random_string(16), 0, 12));
        $token = random_string(32);

        // Key tạo thủ công ở trạng thái active ngay, nhưng expires_at để NULL
        // -> đồng hồ chỉ bắt đầu chạy khi app gọi api.php dùng key lần đầu
        $stmt = $db->prepare("INSERT INTO keys (keycode, token, status, duration_seconds, max_devices, created_at, activated_at)
                               VALUES (?, ?, 'active', ?, ?, ?, ?)");
        $stmt->execute([$keycode, $token, $durationSeconds, $maxDevices, time(), time()]);
        $createdKey = $keycode;
    }

    // Xoá key
    if (isset($_POST['delete_id'])) {
        $db->prepare("DELETE FROM keys WHERE id = ?")->execute([(int)$_POST['delete_id']]);
    }

    // Reset key: xoá thiết bị đã gắn + xoá mốc thời gian đã dùng, key coi như chưa dùng lần nào
    if (isset($_POST['reset_id'])) {
        $db->prepare("UPDATE keys SET devices='', first_used_at=NULL, expires_at=NULL, status='active' WHERE id = ?")
           ->execute([(int)$_POST['reset_id']]);
    }
}

$cfg = get_shortener_config();

$keys = [];
if (isset($_SESSION['is_admin'])) {
    $keys = $db->query("SELECT * FROM keys ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
}

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
<title>Admin - Key Server</title>
<style>
body{font-family:Arial;max-width:900px;margin:40px auto;background:#0f1115;color:#eee;padding:0 16px}
input,select{padding:10px;margin:6px 4px 6px 0;border-radius:6px;border:1px solid #333;background:#1a1d24;color:#eee}
button{padding:10px 16px;background:#4f8cff;border:none;border-radius:6px;color:#fff;font-weight:bold;cursor:pointer}
button.danger{background:#e05353}
button.warn{background:#c98a2d}
.box{background:#181b22;padding:24px;border-radius:10px;margin-bottom:20px}
.ok{color:#4caf50}.err{color:#f44336}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{padding:8px;border-bottom:1px solid #262a33;text-align:left}
.badge{padding:2px 8px;border-radius:10px;font-size:12px}
.badge.active{background:#1e4620;color:#4caf50}
.badge.pending{background:#4a3d16;color:#e0b23f}
.badge.expired{background:#4a1e1e;color:#e05353}
.loginbox{max-width:380px;margin:80px auto}
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

    <p style="text-align:right"><a href="admin.php?logout=1" style="color:#888;font-size:13px">Đăng xuất</a></p>

    <div class="box">
        <h3>Nhà cung cấp rút gọn link</h3>
        <?php if (isset($saved)): ?><p class="ok">Đã lưu cấu hình!</p><?php endif; ?>

        <?php
        $builtins = get_builtin_providers();
        $configured = array_keys($cfg['keys']);
        if (!empty($configured)):
        ?>
        <p style="font-size:13px;color:#888">Provider đang dùng để tạo link cho user: <b style="color:#4f8cff"><?= htmlspecialchars($cfg['active'] ?: '(chưa chọn)') ?></b></p>
        <form method="post" style="margin-bottom:16px">
            <select name="switch_active">
                <?php foreach ($configured as $p):
                    $label = $builtins[$p]['label'] ?? ($cfg['custom']['label'] ?? $p);
                ?>
                <option value="<?= htmlspecialchars($p) ?>" <?= $cfg['active']===$p?'selected':'' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Chuyển provider active</button>
        </form>
        <?php endif; ?>

        <h4 style="margin:10px 0 4px;color:#aaa;font-weight:normal">Thêm / cập nhật API key cho một provider có sẵn</h4>
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
                        <button class="warn" type="submit" onclick="return confirm('Reset key này về trạng thái chưa dùng?')">Reset</button>
                    </form>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="delete_id" value="<?= $k['id'] ?>">
                        <button class="danger" type="submit" onclick="return confirm('Xoá key này vĩnh viễn?')">Xoá</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

<?php endif; ?>
</body>
</html>
