<?php
// ============================================================
// config.php - Cấu hình chung cho toàn bộ Key Server
// ============================================================

// Đường dẫn file database SQLite (tự tạo nếu chưa có)
define('DB_PATH', __DIR__ . '/data/keys.db');

// Đường dẫn file lưu cấu hình API shortlink (link4m, yeumoney...)
define('SHORTENER_CONFIG_PATH', __DIR__ . '/data/shortener.json');

// Domain gốc của server (dùng để build link redirect sau khi qua shortlink)
// Ưu tiên: biến BASE_URL tự set > RENDER_EXTERNAL_URL (Render tự cấp) > giá trị mặc định
define('BASE_URL', getenv('BASE_URL') ?: (getenv('RENDER_EXTERNAL_URL') ?: 'https://key-server-14ls.onrender.com'));

// Thời hạn key mặc định (giây) - 24h
define('KEY_LIFETIME', 24 * 60 * 60);

// Tài khoản đăng nhập trang admin (ĐỔI GIÁ TRỊ NÀY khi deploy thật)
define('ADMIN_USERNAME', getenv('ADMIN_USERNAME') ?: 'Quocdz2006');
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: 'Quocdz2006@');

// Số thiết bị tối đa mặc định cho key tạo qua luồng getkey.php công khai
define('DEFAULT_MAX_DEVICES', 1);

// Quy đổi (giá trị, đơn vị) -> số giây, dùng khi admin tạo key thủ công
function duration_to_seconds(int $value, string $unit): int {
    switch ($unit) {
        case 'hour':  return $value * 3600;
        case 'day':   return $value * 86400;
        case 'week':  return $value * 604800;
        case 'month': return $value * 2592000; // tính tròn 30 ngày/tháng
        default:      return $value * 3600;
    }
}

// Thêm cột mới vào bảng đã tồn tại nếu chưa có (dùng khi nâng cấp schema)
function ensure_column(PDO $pdo, string $table, string $col, string $type): void {
    $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array($col, $cols, true)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $col $type");
    }
}

// Khởi tạo kết nối DB (PDO SQLite), tự tạo bảng nếu chưa tồn tại
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE IF NOT EXISTS keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            keycode TEXT UNIQUE NOT NULL,
            token TEXT UNIQUE NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending', -- pending | active | expired
            duration_seconds INTEGER NOT NULL DEFAULT 86400, -- thời hạn sử dụng kể từ lần dùng đầu tiên
            max_devices INTEGER NOT NULL DEFAULT 1,
            devices TEXT NOT NULL DEFAULT '', -- danh sách device_id, ngăn cách bởi dấu phẩy
            created_at INTEGER NOT NULL,
            activated_at INTEGER DEFAULT NULL, -- thời điểm key được kích hoạt (qua shortlink/admin)
            first_used_at INTEGER DEFAULT NULL, -- thời điểm dùng key lần đầu -> mốc bắt đầu tính giờ
            expires_at INTEGER DEFAULT NULL -- chỉ được set khi first_used_at được set
        )");

        // Cột phục vụ luồng nhiều bước vượt link theo từng game/khu vực
        ensure_column($pdo, 'keys', 'game_id', 'INTEGER');
        ensure_column($pdo, 'keys', 'region', "TEXT");
        ensure_column($pdo, 'keys', 'total_hops', 'INTEGER NOT NULL DEFAULT 1');
        ensure_column($pdo, 'keys', 'current_hop', 'INTEGER NOT NULL DEFAULT 0');
        ensure_column($pdo, 'keys', 'chain', "TEXT NOT NULL DEFAULT ''");

        $pdo->exec("CREATE TABLE IF NOT EXISTS games (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            slug TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            icon TEXT NOT NULL DEFAULT '🎮',
            enabled INTEGER NOT NULL DEFAULT 1,
            vn_hops INTEGER NOT NULL DEFAULT 1,
            vn_key_hours INTEGER NOT NULL DEFAULT 24,
            vn_chain TEXT NOT NULL DEFAULT '', -- danh sách provider theo thứ tự, cách nhau dấu phẩy
            intl_hops INTEGER NOT NULL DEFAULT 1,
            intl_key_hours INTEGER NOT NULL DEFAULT 24,
            intl_chain TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        )");
    }
    return $pdo;
}

// Đọc toàn bộ cấu hình shortener: { active, keys: {provider: api_key}, custom: {...} }
function get_shortener_config(): array {
    $default = ['active' => '', 'keys' => [], 'custom' => null];
    if (!file_exists(SHORTENER_CONFIG_PATH)) return $default;
    $data = json_decode(file_get_contents(SHORTENER_CONFIG_PATH), true);
    if (!is_array($data)) return $default;
    return array_merge($default, $data);
}

// Lưu API key cho 1 provider cụ thể (không đụng tới các provider khác) + set làm active
function save_shortener_config(string $provider, string $apiKey): void {
    $cfg = get_shortener_config();
    $cfg['keys'][$provider] = $apiKey;
    $cfg['active'] = $provider;
    file_put_contents(SHORTENER_CONFIG_PATH, json_encode($cfg));
}

// Lưu provider tuỳ chỉnh (khi không có sẵn trong danh sách built-in) rồi set active luôn
function save_custom_provider(string $label, string $urlTemplate, string $type, string $field, string $apiKey): void {
    $cfg = get_shortener_config();
    $cfg['custom'] = ['label' => $label, 'url' => $urlTemplate, 'type' => $type, 'field' => $field];
    $cfg['keys']['custom'] = $apiKey;
    $cfg['active'] = 'custom';
    file_put_contents(SHORTENER_CONFIG_PATH, json_encode($cfg));
}

// Chỉ đổi provider đang active (dùng khi admin đã lưu key cho nhiều provider từ trước)
function set_active_provider(string $provider): void {
    $cfg = get_shortener_config();
    $cfg['active'] = $provider;
    file_put_contents(SHORTENER_CONFIG_PATH, json_encode($cfg));
}

// Danh sách nhà cung cấp rút gọn link được hỗ trợ sẵn.
// url: {api} = API key, {url} = link đích cần rút gọn (đã urlencode)
// type: 'json' -> parse JSON lấy field chỉ định | 'plain' -> response là link luôn
function get_builtin_providers(): array {
    return [
        'link4m'   => ['label' => 'link4m.co',    'url' => 'https://link4m.co/api-shorten/v2?api={api}&url={url}',         'type' => 'json',  'field' => 'shortenedUrl'],
        'yeumoney' => ['label' => 'yeumoney.com',  'url' => 'https://yeumoney.com/QL_api.php?token={api}&format=json&url={url}', 'type' => 'json', 'field' => 'shortenlink'],
        'ouo'      => ['label' => 'ouo.io',        'url' => 'https://ouo.io/api/{api}?s={url}',                             'type' => 'plain'],
        'bitly'    => ['label' => 'bit.ly',        'url' => 'https://api-ssl.bitly.com/v4/shorten',                        'type' => 'bitly'], // xử lý riêng (POST + Bearer token)
    ];
}

// Sinh chuỗi ngẫu nhiên an toàn (dùng cho keycode / token)
function random_string(int $len): string {
    return substr(bin2hex(random_bytes($len)), 0, $len);
}

// ------------------------------------------------------------
// Quản lý Game (mỗi game có cấu hình số bước vượt link + thời hạn
// key riêng cho khách Việt Nam và khách nước ngoài)
// ------------------------------------------------------------

function get_games(): array {
    return get_db()->query("SELECT * FROM games ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
}

function get_game_by_slug(string $slug): ?array {
    $stmt = get_db()->prepare("SELECT * FROM games WHERE slug = ?");
    $stmt->execute([$slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function create_game(string $slug, string $name, string $icon): void {
    $stmt = get_db()->prepare("INSERT INTO games (slug, name, icon, created_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$slug, $name, $icon ?: '🎮', time()]);
}

// Cập nhật cấu hình 1 khu vực (vn/intl) của 1 game: số bước vượt, hạn key (giờ), chuỗi provider
function update_game_region(int $gameId, string $region, int $hops, int $hours, array $chain): void {
    $col = $region === 'intl' ? 'intl' : 'vn';
    $stmt = get_db()->prepare("UPDATE games SET {$col}_hops=?, {$col}_key_hours=?, {$col}_chain=? WHERE id=?");
    $stmt->execute([max(1, $hops), max(1, $hours), implode(',', $chain), $gameId]);
}

function toggle_game(int $gameId): void {
    get_db()->prepare("UPDATE games SET enabled = 1 - enabled WHERE id = ?")->execute([$gameId]);
}

function delete_game(int $gameId): void {
    get_db()->prepare("DELETE FROM games WHERE id = ?")->execute([$gameId]);
}

// Phát hiện khách VN hay nước ngoài dựa trên header trình duyệt gửi lên.
// Cho phép override bằng ?region=vn|intl (dùng khi test hoặc muốn ép tay).
function detect_region(): string {
    if (isset($_GET['region']) && in_array($_GET['region'], ['vn', 'intl'], true)) {
        return $_GET['region'];
    }
    $lang = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    return (stripos($lang, 'vi') !== false) ? 'vn' : 'intl';
}

// ------------------------------------------------------------
// Gọi API rút gọn link theo định nghĩa provider (built-in hoặc custom).
// Trả về URL đã rút gọn, hoặc null nếu gọi API thất bại.
// QUAN TRỌNG: nơi gọi hàm này phải báo lỗi cho user khi trả về null,
// TUYỆT ĐỐI không được tự ý bỏ qua bước rút gọn link để cấp key luôn.
// ------------------------------------------------------------
function shorten_link(string $provider, string $apiKey, string $destUrl, array $cfg): ?string {
    $builtins = get_builtin_providers();

    if ($provider === 'custom' && !empty($cfg['custom'])) {
        $def = $cfg['custom'];
    } elseif (isset($builtins[$provider])) {
        $def = $builtins[$provider];
    } else {
        record_shorten_debug($provider, '(không xác định)', 0, 'Provider không hợp lệ hoặc chưa cấu hình', '');
        return null;
    }

    if ($def['type'] === 'bitly') {
        $ch = curl_init($def['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['long_url' => $destUrl]));
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if (!$resp) {
            record_shorten_debug($provider, $def['url'], $httpCode, $curlErr, '(không có phản hồi)');
            return null;
        }
        $json = json_decode($resp, true);
        $link = $json['link'] ?? null;
        if (!$link) record_shorten_debug($provider, $def['url'], $httpCode, $curlErr, $resp);
        return $link;
    }

    $api = str_replace(['{api}', '{url}'], [urlencode($apiKey), urlencode($destUrl)], $def['url']);

    $ch = curl_init($api);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if (!$resp) {
        record_shorten_debug($provider, $api, $httpCode, $curlErr, '(không có phản hồi)');
        return null;
    }

    if ($def['type'] === 'plain') {
        $trimmed = trim($resp);
        if (filter_var($trimmed, FILTER_VALIDATE_URL)) return $trimmed;
        record_shorten_debug($provider, $api, $httpCode, $curlErr, $resp);
        return null;
    }

    $json = json_decode($resp, true);
    $link = $json[$def['field']] ?? null;
    if (!$link) record_shorten_debug($provider, $api, $httpCode, $curlErr, $resp);
    return $link;
}

// Lưu lại thông tin lỗi lần gọi API rút gọn link gần nhất (chỉ hiện cho admin xem)
function record_shorten_debug(string $provider, string $requestUrl, $httpCode, string $curlErr, string $rawResp): void {
    // Che API key trong URL trước khi lưu debug, tránh lộ ra màn hình
    $safeUrl = preg_replace('/(api|token)=[^&]+/i', '$1=***', $requestUrl);
    $_SESSION['last_shorten_debug'] = [
        'provider'  => $provider,
        'url'       => $safeUrl,
        'http_code' => $httpCode,
        'curl_err'  => $curlErr,
        'raw'       => mb_substr($rawResp, 0, 400),
        'time'      => date('H:i:s d/m/Y'),
    ];
}

// Trang thông báo dùng chung (token không hợp lệ, v.v...) - có style,
// không hiện chữ trơn không style nhìn như màn hình trắng.
function render_notice_screen(string $title, string $message): void {
    ?>
    <!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <style>body{font-family:-apple-system,Arial,sans-serif;max-width:400px;margin:80px auto;background:#0f1115;color:#eee;text-align:center;padding:0 16px}
    .box{background:#181b22;padding:24px;border-radius:10px}a.btn{display:inline-block;margin-top:14px;padding:10px 18px;background:#4f8cff;border-radius:8px;color:#fff;text-decoration:none;font-weight:bold}</style>
    </head><body><div class="box">
    <h3><?= htmlspecialchars($title) ?></h3>
    <p style="font-size:14px;color:#aaa"><?= htmlspecialchars($message) ?></p>
    <a class="btn" href="<?= htmlspecialchars(BASE_URL) ?>/">Về trang chủ</a>
    </div></body></html>
    <?php
}

// Trang lỗi dùng chung khi không rút gọn được link - KHÔNG bao giờ
// cấp key trực tiếp trong trường hợp này.
function render_shorten_error(): void {
    http_response_code(502);
    $isAdmin = session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['is_admin']);
    $debug = $_SESSION['last_shorten_debug'] ?? null;
    ?>
    <!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lỗi tạo link</title>
    <style>body{font-family:Arial;max-width:460px;margin:80px auto;background:#0f1115;color:#eee;text-align:center;padding:0 16px}
    .box{background:#181b22;padding:24px;border-radius:10px}button,a.btn{display:inline-block;margin-top:14px;padding:10px 18px;background:#4f8cff;border-radius:8px;color:#fff;text-decoration:none;border:none}
    .debug{margin-top:18px;text-align:left;background:#0f1115;border-radius:8px;padding:12px;font-size:12px;color:#e0b23f;word-break:break-all}
    .debug b{color:#fff}</style>
    </head><body><div class="box">
    <h3>Không tạo được link rút gọn</h3>
    <p style="font-size:14px;color:#aaa">Hệ thống rút gọn link đang gặp sự cố, vui lòng thử lại sau.</p>
    <?php if ($isAdmin && $debug): ?>
    <div class="debug">
        <b>[Debug - chỉ admin thấy]</b><br>
        Provider: <?= htmlspecialchars($debug['provider']) ?><br>
        URL gọi: <?= htmlspecialchars($debug['url']) ?><br>
        HTTP code: <?= htmlspecialchars((string)$debug['http_code']) ?><br>
        Lỗi cURL: <?= htmlspecialchars($debug['curl_err'] ?: '(không có)') ?><br>
        Phản hồi thô: <?= htmlspecialchars($debug['raw'] ?: '(rỗng)') ?><br>
        Lúc: <?= htmlspecialchars($debug['time']) ?>
    </div>
    <?php endif; ?>
    <a class="btn" href="javascript:history.back()">Quay lại</a>
    </div></body></html>
    <?php
}

// Màn hình chuyển tiếp hiển thị SAU KHI user vượt xong 1 bước link,
// cho thấy rõ tiến độ (vd "Đã vượt 1/2") trước khi server tạo link
// cho bước kế tiếp - thay vì redirect ngầm không hiển thị gì.
function render_transition_screen(int $current, int $total, string $nextUrl, bool $done): void {
    $percent = $total > 0 ? round($current / $total * 100) : 100;
    ?>
    <!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="2;url=<?= htmlspecialchars($nextUrl) ?>">
    <title>Đang xử lý...</title>
    <style>
    *{box-sizing:border-box}
    body{font-family:-apple-system,Arial,sans-serif;max-width:360px;margin:80px auto;background:#0f1115;color:#eee;text-align:center;padding:0 16px}
    .box{background:#181b22;padding:26px 20px;border-radius:12px}
    .bar{background:#262a33;border-radius:8px;height:10px;overflow:hidden;margin:16px 0}
    .fill{background:#4f8cff;height:100%;width:<?= $percent ?>%;transition:width .3s}
    .step{font-size:14px;color:#aaa}
    a.btn{display:inline-block;margin-top:16px;padding:10px 20px;background:#4f8cff;border-radius:8px;color:#fff;text-decoration:none;font-weight:bold}
    </style>
    </head><body><div class="box">
    <h3 style="margin:0 0 6px"><?= $done ? '✅ Đã vượt đủ link!' : '✅ Đã vượt bước ' . $current . '/' . $total ?></h3>
    <div class="bar"><div class="fill"></div></div>
    <p class="step"><?= $done ? 'Đang chuyển tới trang nhận key...' : 'Đang tạo link cho bước ' . ($current + 1) . '/' . $total . '...' ?></p>
    <a class="btn" href="<?= htmlspecialchars($nextUrl) ?>"><?= $done ? 'Nhận key ngay' : 'Tiếp tục' ?></a>
    </div></body></html>
    <?php
}
