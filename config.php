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

// ------------------------------------------------------------
// Firebase Realtime Database - dùng làm nơi SAO LƯU dữ liệu, tránh
// mất trắng khi Render redeploy/restart (ổ đĩa tạm thời, SQLite bị
// xoá mỗi lần container mới khởi động). Để trống thì hệ thống vẫn
// chạy bình thường bằng SQLite thôi, chỉ là không có sao lưu.
//
// Lấy 2 giá trị này ở đâu (Database secrets kiểu cũ đã bị Google bỏ,
// giờ dùng Service Account JSON của Admin SDK):
//   1. Vào https://console.firebase.google.com -> tạo project ->
//      Build -> Realtime Database -> Create Database (chọn "Start
//      in test mode" cho nhanh) -> copy URL hiện ra, đó là
//      FIREBASE_DB_URL (dạng https://<id>-default-rtdb.<vùng>.firebasedatabase.app)
//   2. Vào ⚙️ Project Settings -> tab "Service accounts" -> bấm
//      "Generate new private key" -> tải về file .json -> mở file
//      đó, copy TOÀN BỘ nội dung (nguyên khối JSON) -> dán làm giá
//      trị biến FIREBASE_SERVICE_ACCOUNT_JSON
// ------------------------------------------------------------
define('FIREBASE_DB_URL', rtrim(trim(getenv('FIREBASE_DB_URL') ?: ''), "/ \t\n\r\0\x0B"));
define('FIREBASE_SERVICE_ACCOUNT_JSON', trim(getenv('FIREBASE_SERVICE_ACCOUNT_JSON') ?: ''));
define('FIREBASE_ENABLED', FIREBASE_DB_URL !== '' && FIREBASE_SERVICE_ACCOUNT_JSON !== '');

// Coi như "vĩnh viễn": ~31 năm, đủ xa để không bao giờ thực sự hết hạn
// trong vòng đời thực tế của hệ thống. Không dùng NULL/0 để tránh phải
// sửa lại toàn bộ logic tính expires_at ở chỗ khác.
define('PERMANENT_SECONDS', 999999999);

// Quy đổi (giá trị, đơn vị) -> số giây, dùng khi admin tạo key thủ công
function duration_to_seconds(int $value, string $unit): int {
    switch ($unit) {
        case 'hour':    return $value * 3600;
        case 'day':     return $value * 86400;
        case 'week':    return $value * 604800;
        case 'month':   return $value * 2592000; // tính tròn 30 ngày/tháng
        case 'forever': return PERMANENT_SECONDS;
        default:        return $value * 3600;
    }
}

// "12 giờ" / "3 ngày" / "Vĩnh viễn" tuỳ giá trị - dùng ở cả admin lẫn confirm.php
function format_duration_label(int $seconds): string {
    if ($seconds >= PERMANENT_SECONDS) return 'Vĩnh viễn';
    if ($seconds % 2592000 === 0) return ($seconds / 2592000) . ' tháng';
    if ($seconds % 604800 === 0) return ($seconds / 604800) . ' tuần';
    if ($seconds % 86400 === 0) return ($seconds / 86400) . ' ngày';
    return round($seconds / 3600) . ' giờ';
}

// Lấy IP thật của client, có xét header X-Forwarded-For (Render chạy sau proxy
// nên REMOTE_ADDR thường là IP nội bộ của proxy, không phải IP thật user)
function get_client_ip(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

// Kiểm tra IP này đã lấy key thành công (status active) cho game này trong
// vòng 24h qua chưa - dùng làm lớp giới hạn thứ 2 song song với cookie
// (chống trường hợp user xoá cookie để lấy key nhiều lần).
function ip_already_claimed_today(int $gameId, string $ip): bool {
    if ($ip === '') return false;
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM keys WHERE game_id = ? AND client_ip = ? AND status = 'active' AND activated_at > ?");
    $stmt->execute([$gameId, $ip, time() - 86400]);
    return (int)$stmt->fetchColumn() > 0;
}

// Lấy số liệu thống kê tổng quan cho trang admin
function get_dashboard_stats(): array {
    $db = get_db();
    $stats = [
        'total_keys'   => (int)$db->query("SELECT COUNT(*) FROM keys")->fetchColumn(),
        'active_keys'  => (int)$db->query("SELECT COUNT(*) FROM keys WHERE status='active'")->fetchColumn(),
        'pending_keys' => (int)$db->query("SELECT COUNT(*) FROM keys WHERE status='pending'")->fetchColumn(),
        'expired_keys' => (int)$db->query("SELECT COUNT(*) FROM keys WHERE status='expired'")->fetchColumn(),
        'today_claims' => 0,
        'total_games'  => (int)$db->query("SELECT COUNT(*) FROM games")->fetchColumn(),
        'active_games' => (int)$db->query("SELECT COUNT(*) FROM games WHERE enabled=1")->fetchColumn(),
        'per_game'     => [],
    ];
    $stmt = $db->prepare("SELECT COUNT(*) FROM keys WHERE activated_at > ?");
    $stmt->execute([time() - 86400]);
    $stats['today_claims'] = (int)$stmt->fetchColumn();

    $stats['per_game'] = $db->query("
        SELECT g.name, g.icon,
            COUNT(k.id) AS total,
            SUM(CASE WHEN k.status='active' THEN 1 ELSE 0 END) AS active
        FROM games g
        LEFT JOIN keys k ON k.game_id = g.id
        GROUP BY g.id
        ORDER BY total DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    return $stats;
}
// Bọc PDOStatement->execute() an toàn: tự động thử lại tối đa 3 lần nếu
// gặp "database is locked" (SQLite dưới tải cao, nhiều request ghi cùng
// lúc), nghỉ tăng dần giữa các lần thử. Nếu vẫn lỗi sau cùng, KHÔNG để
// crash cả trang (Fatal error/stack trace lộ ra ngoài) - chỉ log lại và
// trả về false để nơi gọi tự quyết định xử lý tiếp (thường là bỏ qua,
// vì các UPDATE tiến độ không phải lỗi chí mạng nếu 1 lần bị miss).
function db_execute(PDOStatement $stmt, array $params = [], int $retries = 3): bool {
    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        try {
            return $stmt->execute($params);
        } catch (PDOException $e) {
            $isLocked = stripos($e->getMessage(), 'locked') !== false || stripos($e->getMessage(), 'busy') !== false;
            if ($isLocked && $attempt < $retries) {
                usleep(150000 * $attempt); // 150ms, 300ms, ... tăng dần
                continue;
            }
            error_log('[db_execute] thất bại sau ' . $attempt . ' lần thử: ' . $e->getMessage());
            return false;
        }
    }
    return false;
}

function ensure_column(PDO $pdo, string $table, string $col, string $type): void {
    $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array($col, $cols, true)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $col $type");
    }
}

// ------------------------------------------------------------
// Giao tiếp Firebase Realtime Database qua REST API bằng OAuth2:
// tự ký JWT (RS256) từ Service Account JSON, đổi lấy access_token
// từ Google, rồi dùng token đó gọi REST API (Bearer header) - không
// cần cài SDK/thư viện gì, chỉ dùng openssl có sẵn trong PHP.
// Token cache lại ra file (~1h/lần), tránh phải ký + gọi Google mỗi
// request. Mọi hàm đều fail-safe: lỗi mạng/chưa cấu hình sẽ KHÔNG
// làm crash trang, chỉ âm thầm bỏ qua sao lưu lần đó.
// ------------------------------------------------------------
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function get_firebase_access_token(): ?string {
    static $memCache = null;
    if ($memCache && $memCache['exp'] > time() + 60) return $memCache['token'];

    $cacheFile = __DIR__ . '/data/firebase_token_cache.json';
    if (file_exists($cacheFile)) {
        $c = json_decode(file_get_contents($cacheFile), true);
        if ($c && !empty($c['exp']) && $c['exp'] > time() + 60) {
            $memCache = $c;
            return $c['token'];
        }
    }

    $sa = json_decode(FIREBASE_SERVICE_ACCOUNT_JSON, true);
    if (!$sa || empty($sa['private_key']) || empty($sa['client_email'])) return null;

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claim = [
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.database https://www.googleapis.com/auth/userinfo.email',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];
    $segments = [base64url_encode(json_encode($header)), base64url_encode(json_encode($claim))];
    $signInput = implode('.', $segments);

    $signature = '';
    $ok = openssl_sign($signInput, $signature, $sa['private_key'], 'sha256WithRSAEncryption');
    if (!$ok) return null;
    $segments[] = base64url_encode($signature);
    $jwt = implode('.', $segments);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return null;

    $json = json_decode($resp, true);
    if (empty($json['access_token'])) {
        error_log('[firebase auth] lỗi lấy access token: ' . $resp);
        return null;
    }

    $tokenData = ['token' => $json['access_token'], 'exp' => $now + (int)($json['expires_in'] ?? 3600)];
    @file_put_contents($cacheFile, json_encode($tokenData));
    $memCache = $tokenData;
    return $tokenData['token'];
}

function firebase_put(string $path, $data): bool {
    if (!FIREBASE_ENABLED) return false;
    $token = get_firebase_access_token();
    if (!$token) {
        $GLOBALS['__fb_last_error'] = 'Không lấy được access_token (kiểm tra FIREBASE_SERVICE_ACCOUNT_JSON)';
        return false;
    }

    $url = FIREBASE_DB_URL . '/' . ltrim($path, '/') . '.json';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $ok = $resp !== false && $httpCode === 200;
    if (!$ok) {
        $GLOBALS['__fb_last_error'] = "HTTP $httpCode - " . ($curlErr ?: $resp);
        error_log('[firebase_put] thất bại path=' . $path . ' http=' . $httpCode . ' resp=' . substr((string)$resp, 0, 300) . ' curlErr=' . $curlErr);
    }
    return $ok;
}

function firebase_get(string $path) {
    if (!FIREBASE_ENABLED) return null;
    $token = get_firebase_access_token();
    if (!$token) return null;

    $url = FIREBASE_DB_URL . '/' . ltrim($path, '/') . '.json';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $httpCode !== 200) {
        $GLOBALS['__fb_last_error'] = "HTTP $httpCode - " . substr((string)$resp, 0, 300);
        return null;
    }
    $data = json_decode($resp, true);
    return $data ?: null;
}

// Đóng gói toàn bộ dữ liệu (3 bảng SQLite + file config shortener) thành
// 1 JSON, đẩy lên Firebase tại path backup/latest. Gọi ở cuối mỗi request
// có khả năng đã ghi dữ liệu (xem register_shutdown_function trong get_db).
function backup_to_firebase(PDO $pdo): void {
    if (!FIREBASE_ENABLED) return;
    try {
        $payload = [
            'keys'        => $pdo->query("SELECT * FROM keys")->fetchAll(PDO::FETCH_ASSOC),
            'games'       => $pdo->query("SELECT * FROM games")->fetchAll(PDO::FETCH_ASSOC),
            'ip_blocklist'=> $pdo->query("SELECT * FROM ip_blocklist")->fetchAll(PDO::FETCH_ASSOC),
            'shortener'   => file_exists(SHORTENER_CONFIG_PATH) ? json_decode(file_get_contents(SHORTENER_CONFIG_PATH), true) : null,
            'backed_up_at'=> time(),
        ];
        $ok = firebase_put('backup/latest', $payload);
        // Lưu kết quả lần backup gần nhất ra file riêng để admin xem được
        // (không phụ thuộc phải đọc lại được từ chính Firebase nếu nó lỗi)
        @file_put_contents(__DIR__ . '/data/firebase_backup_status.json', json_encode([
            'ok' => $ok,
            'at' => time(),
            'error' => $ok ? null : ($GLOBALS['__fb_last_error'] ?? 'không rõ lý do'),
        ]));
    } catch (Throwable $e) {
        // Sao lưu lỗi thì bỏ qua, không được làm hỏng request của user
        error_log('[firebase backup] failed: ' . $e->getMessage());
        @file_put_contents(__DIR__ . '/data/firebase_backup_status.json', json_encode([
            'ok' => false, 'at' => time(), 'error' => $e->getMessage(),
        ]));
    }
}

// Khôi phục dữ liệu từ bản sao lưu Firebase gần nhất - CHỈ gọi khi phát
// hiện SQLite là file mới toanh (container vừa khởi động lại, mất hết dữ
// liệu cũ). Bơm lại từng dòng vào 3 bảng vừa tạo trống.
function restore_from_firebase(PDO $pdo): void {
    if (!FIREBASE_ENABLED) return;
    try {
        $backup = firebase_get('backup/latest');
        if (!$backup || empty($backup['keys']) && empty($backup['games'])) return;

        foreach (($backup['games'] ?? []) as $g) {
            $cols = array_keys($g);
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT OR IGNORE INTO games (" . implode(',', $cols) . ") VALUES ($ph)")
                ->execute(array_values($g));
        }
        foreach (($backup['keys'] ?? []) as $k) {
            $cols = array_keys($k);
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT OR IGNORE INTO keys (" . implode(',', $cols) . ") VALUES ($ph)")
                ->execute(array_values($k));
        }
        foreach (($backup['ip_blocklist'] ?? []) as $b) {
            $cols = array_keys($b);
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $pdo->prepare("INSERT OR IGNORE INTO ip_blocklist (" . implode(',', $cols) . ") VALUES ($ph)")
                ->execute(array_values($b));
        }
        if (!empty($backup['shortener'])) {
            file_put_contents(SHORTENER_CONFIG_PATH, json_encode($backup['shortener']));
        }
        error_log('[firebase restore] đã khôi phục dữ liệu từ bản sao lưu lúc ' . date('H:i:s d/m/Y', $backup['backed_up_at'] ?? time()));
    } catch (Throwable $e) {
        error_log('[firebase restore] failed: ' . $e->getMessage());
    }
}

// Khởi tạo kết nối DB (PDO SQLite), tự tạo bảng nếu chưa tồn tại
function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        // QUAN TRỌNG: phải check file tồn tại chưa TRƯỚC KHI connect,
        // vì PDO sqlite tự động tạo file rỗng ngay lúc gọi `new PDO(...)`
        // - check sau thì lúc nào cũng thấy "đã tồn tại" (sai).
        $isFreshContainer = !file_exists(DB_PATH);

        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Fix "database is locked": SQLite mặc định chỉ 1 tiến trình ghi
        // cùng lúc, nhiều request đồng thời (vd nhiều user vượt link cùng
        // lúc) dễ bị lock ngay lập tức và fatal error. 2 dòng dưới:
        // - WAL mode: cho phép đọc/ghi song song tốt hơn nhiều so với
        //   journal mode mặc định (rollback journal)
        // - busy_timeout: nếu vẫn đụng lock, SQLite tự động CHỜ tối đa
        //   5 giây rồi thử lại thay vì throw exception ngay
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 10000');
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
        ensure_column($pdo, 'keys', 'client_ip', "TEXT NOT NULL DEFAULT ''");
        // Thời điểm bắt đầu bước vượt link hiện tại (lúc redirect user sang
        // shortlink) - dùng để tính đã vượt "quá nhanh" hay chưa khi nhận
        // callback advance, chống tool tự bắn thẳng request bypass.
        ensure_column($pdo, 'keys', 'hop_started_at', 'INTEGER DEFAULT NULL');
        // Map device_id -> IP đã dùng để đăng nhập key này (JSON), phục vụ
        // hiển thị trong admin "IP đã dùng key" + cấm thiết bị theo IP
        ensure_column($pdo, 'keys', 'device_ip_map', "TEXT NOT NULL DEFAULT '{}'");

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

        // Danh sách IP bị nghi ngờ/chặn do có dấu hiệu bypass bước vượt link
        // (gọi thẳng callback advance mà không thật sự qua shortlink)
        $pdo->exec("CREATE TABLE IF NOT EXISTS ip_blocklist (
            ip TEXT PRIMARY KEY,
            violation_count INTEGER NOT NULL DEFAULT 0,
            blocked_until INTEGER DEFAULT NULL,
            last_reason TEXT NOT NULL DEFAULT '',
            last_violation_at INTEGER NOT NULL
        )");

        // Thiết bị bị admin cấm VĨNH VIỄN thủ công (khác ip_blocklist - cái
        // đó tự động, tạm thời; cái này admin bấm tay, không hết hạn)
        $pdo->exec("CREATE TABLE IF NOT EXISTS device_blocklist (
            device_id TEXT PRIMARY KEY,
            reason TEXT NOT NULL DEFAULT '',
            banned_at INTEGER NOT NULL
        )");

        // Container vừa khởi động lại (SQLite trống trơn) -> thử khôi phục
        // dữ liệu từ bản sao lưu Firebase gần nhất, nếu có cấu hình
        if ($isFreshContainer) {
            restore_from_firebase($pdo);
        }

        // Tự động sao lưu lên Firebase khi request này kết thúc (best-effort,
        // không chặn/làm chậm response - chạy sau khi PHP đã xử lý xong).
        // Đơn giản hoá: sao lưu ở MỌI request có đụng tới DB thay vì chỉ
        // request có ghi dữ liệu, để không bỏ sót chỗ nào quên gọi thủ công.
        if (FIREBASE_ENABLED) {
            register_shutdown_function(function () use ($pdo) {
                backup_to_firebase($pdo);
            });
        }
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

// Xoá hẳn 1 provider (API key) khỏi cấu hình. Nếu đang là active thì
// tự chuyển active sang provider khác còn lại (nếu có).
function delete_shortener_provider(string $provider): void {
    $cfg = get_shortener_config();
    unset($cfg['keys'][$provider]);
    if ($provider === 'custom') $cfg['custom'] = null;
    if ($cfg['active'] === $provider) {
        $remaining = array_keys($cfg['keys']);
        $cfg['active'] = $remaining[0] ?? '';
    }
    file_put_contents(SHORTENER_CONFIG_PATH, json_encode($cfg));
}

// Danh sách nhà cung cấp rút gọn link được hỗ trợ sẵn.
// url: {api} = API key, {url} = link đích cần rút gọn (đã urlencode)
// type: 'json' -> parse JSON lấy field chỉ định | 'plain' -> response là link luôn
function get_builtin_providers(): array {
    return [
        'link4m'   => ['label' => 'link4m.co',    'url' => 'https://link4m.co/api-shorten/v2?api={api}&url={url}',         'type' => 'json',  'field' => 'shortenedUrl'],
        'yeumoney' => ['label' => 'yeumoney.com',  'url' => 'https://yeumoney.com/QL_api.php?token={api}&format=json&url={url}', 'type' => 'json', 'field' => 'shortenedUrl'],
        'layma'    => ['label' => 'layma.net',      'url' => 'https://api.layma.net/api/admin/shortlink/quicklink?tokenUser={api}&format=json&url={url}&link_du_phong={url}', 'type' => 'json', 'field' => 'html'],
        'traffictop' => ['label' => 'traffictop.net', 'url' => 'https://traffictop.net/api?api={api}&url={url}&sub_link={url}', 'type' => 'json', 'field' => 'shortenedUrl'],
        'ouo'      => ['label' => 'ouo.io',        'url' => 'https://ouo.io/api/{api}?s={url}',                             'type' => 'plain'],
        'bitly'    => ['label' => 'bit.ly',        'url' => 'https://api-ssl.bitly.com/v4/shorten',                        'type' => 'bitly'], // xử lý riêng (POST + Bearer token)
    ];
}

// Sinh chuỗi ngẫu nhiên an toàn (dùng cho token)
function random_string(int $len): string {
    return substr(bin2hex(random_bytes($len)), 0, $len);
}

// ------------------------------------------------------------
// Mã hoá config (danh sách server VPN thật) gửi kèm response thành
// công của api.php - key giải mã derive từ chính license key, nên
// app chỉ giải mã được nếu có đúng key hợp lệ đã qua server xác
// nhận. Chống bypass kiểu patch app bỏ qua check "valid": app vẫn
// cần "cfg" giải mã được thì mới hoạt động thật.
// ------------------------------------------------------------
function encrypt_config(string $plainJson, string $licenseKey): string {
    $derivedKey = hash('sha256', $licenseKey, true); // 32 byte raw, khớp SHA-256 phía Java
    $iv = random_bytes(12); // GCM chuẩn dùng IV 12 byte
    $tag = '';
    $ciphertext = openssl_encrypt($plainJson, 'aes-256-gcm', $derivedKey, OPENSSL_RAW_DATA, $iv, $tag);
    // Đóng gói IV + ciphertext + tag liền nhau để khớp cách Java Cipher.doFinal() xuất ra
    return base64_encode($iv . $ciphertext . $tag);
}

// Sinh mã key theo định dạng cố định: HQD-1234567-ABC
// (HQD = tiền tố thương hiệu, 7 số ngẫu nhiên, gạch ngang, 3 chữ in hoa)
// Tự kiểm tra trùng trong DB và thử lại tối đa vài lần (xác suất trùng
// gần như bằng 0 nhưng vẫn phòng hờ vì cột keycode là UNIQUE).
function generate_keycode(): string {
    $db = get_db();
    for ($i = 0; $i < 10; $i++) {
        $digits = str_pad((string)random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        $letters = '';
        for ($j = 0; $j < 3; $j++) $letters .= chr(random_int(65, 90));
        $code = 'HQD-' . $digits . '-' . $letters;

        $stmt = $db->prepare("SELECT COUNT(*) FROM keys WHERE keycode = ?");
        $stmt->execute([$code]);
        if ((int)$stmt->fetchColumn() === 0) return $code;
    }
    // Cực kỳ hiếm khi rơi vào đây - fallback thêm random_string cho chắc chắn unique
    return 'HQD-' . str_pad((string)random_int(0, 9999999), 7, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(random_string(3), 0, 3));
}

// ------------------------------------------------------------
// Chống bypass bước vượt link: tool tự động thường gọi thẳng URL
// callback (hop.php?token=..&advance=1) mà không thật sự vượt qua
// trang shortlink (nên không tốn thời gian countdown/quảng cáo).
// Hai lớp phòng thủ:
//   1. Thời gian tối thiểu giữa lúc redirect sang shortlink và lúc
//      nhận callback - vượt quá nhanh (dưới MIN_HOP_SECONDS) là dấu
//      hiệu bypass rõ ràng.
//   2. Sau nhiều lần vi phạm liên tiếp từ 1 IP -> tự động chặn IP đó
//      trong 24h, không cho tạo/xác nhận key nữa.
// ------------------------------------------------------------
define('MIN_HOP_SECONDS', 4); // thời gian tối thiểu hợp lý để vượt 1 bước link
define('MAX_VIOLATIONS_BEFORE_BLOCK', 3); // ngưỡng cho bypass vượt link (nghiêm ngặt)
define('IP_BLOCK_HOURS', 24);
// Ngưỡng riêng cho api.php - lỏng hơn vì user thật cũng có thể gõ sai key
// vài lần, không nên khoá IP quá sớm giống hệt trường hợp bypass link
define('API_MAX_FAILS_BEFORE_BLOCK', 15);
define('API_BLOCK_HOURS', 1);

function is_ip_blocked(string $ip): bool {
    if ($ip === '') return false;
    $stmt = get_db()->prepare("SELECT blocked_until FROM ip_blocklist WHERE ip = ?");
    $stmt->execute([$ip]);
    $until = $stmt->fetchColumn();
    return $until && time() < (int)$until;
}

// Ghi nhận 1 lần vi phạm từ IP này (bypass link HOẶC dò key sai liên tục ở
// api.php - dùng chung 1 bảng ip_blocklist, khác nhau ở $threshold/$blockHours
// truyền vào). Tự động chặn nếu vượt ngưỡng.
function record_bypass_violation(string $ip, string $reason, int $threshold = MAX_VIOLATIONS_BEFORE_BLOCK, int $blockHours = IP_BLOCK_HOURS): void {
    if ($ip === '') return;
    $db = get_db();
    $stmt = $db->prepare("SELECT violation_count FROM ip_blocklist WHERE ip = ?");
    $stmt->execute([$ip]);
    $count = $stmt->fetchColumn();

    if ($count === false) {
        db_execute($db->prepare("INSERT INTO ip_blocklist (ip, violation_count, last_reason, last_violation_at) VALUES (?, 1, ?, ?)"), [$ip, $reason, time()]);
        return;
    }

    $newCount = (int)$count + 1;
    $blockedUntil = null;
    if ($newCount >= $threshold) {
        $blockedUntil = time() + $blockHours * 3600;
    }
    db_execute(
        $db->prepare("UPDATE ip_blocklist SET violation_count = ?, last_reason = ?, last_violation_at = ?, blocked_until = ? WHERE ip = ?"),
        [$newCount, $reason, time(), $blockedUntil, $ip]
    );
}

// Reset về 0 khi có 1 lần thành công (chỉ dùng cho api.php: gõ đúng key
// sau vài lần sai thì không nên cộng dồn mãi vào cùng 1 lần thử sai trước đó)
function reset_violation_count(string $ip): void {
    if ($ip === '') return;
    db_execute(
        get_db()->prepare("UPDATE ip_blocklist SET violation_count = 0 WHERE ip = ? AND (blocked_until IS NULL OR blocked_until < ?)"),
        [$ip, time()]
    );
}

// Danh sách IP đang bị chặn (dùng cho trang admin)
function get_blocked_ips(): array {
    return get_db()->query("SELECT * FROM ip_blocklist WHERE blocked_until IS NOT NULL AND blocked_until > " . time() . " ORDER BY last_violation_at DESC")->fetchAll(PDO::FETCH_ASSOC);
}

// Admin gỡ chặn 1 IP thủ công
function unblock_ip(string $ip): void {
    db_execute(get_db()->prepare("UPDATE ip_blocklist SET blocked_until = NULL, violation_count = 0 WHERE ip = ?"), [$ip]);
}

// ------------------------------------------------------------
// Cấm thiết bị VĨNH VIỄN (admin bấm tay, khác ip_blocklist là tự động
// + có hạn). Thiết bị bị cấm sẽ không đăng nhập được BẤT KỲ key nào.
// ------------------------------------------------------------
function is_device_blocked(string $deviceId): bool {
    if ($deviceId === '') return false;
    $stmt = get_db()->prepare("SELECT 1 FROM device_blocklist WHERE device_id = ?");
    $stmt->execute([$deviceId]);
    return (bool)$stmt->fetchColumn();
}

function ban_device(string $deviceId, string $reason = ''): void {
    if ($deviceId === '') return;
    db_execute(
        get_db()->prepare("INSERT OR REPLACE INTO device_blocklist (device_id, reason, banned_at) VALUES (?, ?, ?)"),
        [$deviceId, $reason, time()]
    );
}

function unban_device(string $deviceId): void {
    db_execute(get_db()->prepare("DELETE FROM device_blocklist WHERE device_id = ?"), [$deviceId]);
}

function get_banned_devices(): array {
    return get_db()->query("SELECT * FROM device_blocklist ORDER BY banned_at DESC")->fetchAll(PDO::FETCH_ASSOC);
}

// Trang thông báo khi IP bị chặn do nghi ngờ bypass
function render_blocked_screen(): void {
    http_response_code(403);
    render_notice_screen('IP của bạn đang bị tạm chặn', 'Hệ thống phát hiện dấu hiệu bất thường khi vượt link (vượt quá nhanh, nghi ngờ dùng tool bypass). IP của bạn đã bị tạm khoá 24 giờ. Nếu đây là nhầm lẫn, vui lòng thử lại sau hoặc liên hệ admin.');
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
    db_execute($stmt, [$slug, $name, $icon ?: '🎮', time()]);
}

// Cập nhật cấu hình 1 khu vực (vn/intl) của 1 game: số bước vượt, hạn key (giờ), chuỗi provider
function update_game_region(int $gameId, string $region, int $hops, int $hours, array $chain): void {
    $col = $region === 'intl' ? 'intl' : 'vn';
    $stmt = get_db()->prepare("UPDATE games SET {$col}_hops=?, {$col}_key_hours=?, {$col}_chain=? WHERE id=?");
    db_execute($stmt, [max(1, $hops), max(1, $hours), implode(',', $chain), $gameId]);
}

function toggle_game(int $gameId): void {
    db_execute(get_db()->prepare("UPDATE games SET enabled = 1 - enabled WHERE id = ?"), [$gameId]);
}

function delete_game(int $gameId): void {
    db_execute(get_db()->prepare("DELETE FROM games WHERE id = ?"), [$gameId]);
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
    // Một số provider chặn request không có User-Agent giống trình duyệt (coi là bot)
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
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
    if (!$link) {
        $reason = $json['message'] ?? $resp;
        record_shorten_debug($provider, $api, $httpCode, $curlErr, $reason);
    }
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

// CSS token + font dùng chung cho toàn bộ trang thông báo (đồng bộ với
// giao diện "Keycard" của index.php / confirm.php)
function shared_notice_head(): string {
    return '<link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">';
}
function shared_notice_css(): string {
    return ':root{--bg:#0B0E14;--surface:#12151F;--cyan:#00E5C7;--violet:#8B7CFF;--text:#E8ECF3;--text-dim:#8891A3}
    *{box-sizing:border-box}
    body{font-family:"Inter",-apple-system,Arial,sans-serif;max-width:400px;margin:70px auto;background:radial-gradient(ellipse at top,#151a26 0%,var(--bg) 60%);color:var(--text);text-align:center;padding:0 16px;animation:fadeIn .4s ease}
    .box{background:var(--surface);padding:26px 22px;border-radius:16px;animation:popIn .45s cubic-bezier(.16,1,.3,1);box-shadow:0 16px 40px -18px rgba(0,0,0,.55),0 0 0 1px rgba(255,255,255,.04)}
    h3{font-family:"Space Grotesk",sans-serif;font-weight:700;font-size:17px;margin:8px 0 6px}
    button,a.btn{display:inline-block;margin-top:14px;padding:11px 20px;background:linear-gradient(135deg,var(--cyan),var(--violet));border-radius:10px;color:#0B0E14;text-decoration:none;border:none;font-family:"Space Grotesk",sans-serif;font-weight:700;font-size:13.5px;cursor:pointer;transition:transform .12s,filter .15s}
    a.btn:active,button:active{transform:scale(.95)}
    a.btn:hover,button:hover{filter:brightness(1.08)}
    @keyframes fadeIn{from{opacity:0}to{opacity:1}}
    @keyframes popIn{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}';
}

// Trang thông báo dùng chung (token không hợp lệ, v.v...) - có style,
// không hiện chữ trơn không style nhìn như màn hình trắng.
function render_notice_screen(string $title, string $message): void {
    ?>
    <!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <?= shared_notice_head() ?>
    <style><?= shared_notice_css() ?></style>
    </head><body><div class="box">
    <div style="font-size:34px">🔑</div>
    <h3><?= htmlspecialchars($title) ?></h3>
    <p style="font-size:13.5px;color:var(--text-dim)"><?= htmlspecialchars($message) ?></p>
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
    <?= shared_notice_head() ?>
    <style><?= shared_notice_css() ?>
    .box{max-width:460px}
    .debug{margin-top:18px;text-align:left;background:var(--bg);border-radius:10px;padding:12px;font-family:"JetBrains Mono",monospace;font-size:11.5px;color:#FBBF24;word-break:break-all;animation:fadeIn .3s ease .1s both}
    .debug b{color:#fff;font-family:"Inter",sans-serif}
    .shake{animation:shake .4s ease}
    @keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-4px)}75%{transform:translateX(4px)}}</style>
    </head><body><div class="box shake">
    <div style="font-size:34px">⚠️</div>
    <h3>Không tạo được link rút gọn</h3>
    <p style="font-size:13.5px;color:var(--text-dim)">Hệ thống rút gọn link đang gặp sự cố, vui lòng thử lại sau.</p>
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
    <title>Đang xử lý...</title>
    <?= shared_notice_head() ?>
    <style>
    :root{--bg:#0B0E14;--surface:#12151F;--cyan:#00E5C7;--violet:#8B7CFF;--text:#E8ECF3;--text-dim:#8891A3}
    *{box-sizing:border-box}
    body{font-family:"Inter",-apple-system,Arial,sans-serif;max-width:360px;margin:80px auto;background:radial-gradient(ellipse at top,#151a26 0%,var(--bg) 60%);color:var(--text);text-align:center;padding:0 16px;animation:fadeIn .4s ease}
    .box{background:var(--surface);padding:28px 22px;border-radius:16px;animation:popIn .45s cubic-bezier(.16,1,.3,1);box-shadow:0 16px 40px -18px rgba(0,0,0,.55),0 0 0 1px rgba(255,255,255,.04)}
    h3{font-family:"Space Grotesk",sans-serif;font-weight:700;font-size:17px;margin:8px 0 6px}
    .bar{background:#1c202c;border-radius:8px;height:8px;overflow:hidden;margin:16px 0}
    .fill{background:linear-gradient(90deg,var(--cyan),var(--violet));height:100%;width:0%;transition:width 1s ease}
    .step{font-size:13px;color:var(--text-dim);font-family:"JetBrains Mono",monospace}
    .spinner{display:inline-block;width:14px;height:14px;border:2px solid #262b38;border-top-color:var(--cyan);border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:6px}
    .checkmark{font-size:36px;animation:popIn .5s cubic-bezier(.34,1.56,.64,1)}
    a.btn{display:inline-block;margin-top:16px;padding:11px 20px;background:linear-gradient(135deg,var(--cyan),var(--violet));border-radius:10px;color:#0B0E14;text-decoration:none;font-family:"Space Grotesk",sans-serif;font-weight:700;font-size:13.5px;transition:transform .12s}
    a.btn:active{transform:scale(.95)}
    @keyframes fadeIn{from{opacity:0}to{opacity:1}}
    @keyframes popIn{from{opacity:0;transform:scale(.9)}to{opacity:1;transform:scale(1)}}
    @keyframes spin{to{transform:rotate(360deg)}}
    </style>
    </head><body><div class="box">
    <div class="checkmark"><?= $done ? '🎉' : '✅' ?></div>
    <h3><?= $done ? 'Đã vượt đủ link!' : 'Đã vượt bước ' . $current . '/' . $total ?></h3>
    <div class="bar"><div class="fill" id="fill"></div></div>
    <p class="step"><span class="spinner" id="spin"></span><span id="stepText"><?= $done ? 'Đang chuyển tới trang nhận key...' : 'Đang tạo link cho bước ' . ($current + 1) . '/' . $total . '...' ?></span></p>
    <a class="btn" href="<?= htmlspecialchars($nextUrl) ?>" id="nextBtn"><?= $done ? 'Nhận key ngay' : 'Tiếp tục' ?></a>
    </div>
    <script>
    requestAnimationFrame(() => { document.getElementById('fill').style.width = '<?= $percent ?>%'; });
    setTimeout(() => { window.location.href = <?= json_encode($nextUrl) ?>; }, 1800);
    </script>
    </body></html>
    <?php
}
