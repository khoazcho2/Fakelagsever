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
        'link4m'   => ['label' => 'link4m.com',   'url' => 'https://link4m.com/api-shorten/v2?api={api}&url={url}',        'type' => 'json',  'field' => 'shortenedUrl'],
        'yeumoney' => ['label' => 'yeumoney.com',  'url' => 'https://yeumoney.com/QL_api.php?token={api}&format=json&url={url}', 'type' => 'json', 'field' => 'shortenlink'],
        'ouo'      => ['label' => 'ouo.io',        'url' => 'https://ouo.io/api/{api}?s={url}',                             'type' => 'plain'],
        'bitly'    => ['label' => 'bit.ly',        'url' => 'https://api-ssl.bitly.com/v4/shorten',                        'type' => 'bitly'], // xử lý riêng (POST + Bearer token)
    ];
}

// Sinh chuỗi ngẫu nhiên an toàn (dùng cho keycode / token)
function random_string(int $len): string {
    return substr(bin2hex(random_bytes($len)), 0, $len);
}
