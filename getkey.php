<?php
// ============================================================
// getkey.php - Người dùng vào đây để "Lấy Key"
// Luồng: tạo token pending -> gọi API rút gọn link (theo provider
// admin đang chọn active) -> redirect user qua shortlink -> user
// hoàn thành -> quay lại confirm.php
// ============================================================
require_once __DIR__ . '/config.php';

$db = get_db();
$cfg = get_shortener_config();
$active = $cfg['active'];
$apiKey = $cfg['keys'][$active] ?? '';

if ($active === '' || $apiKey === '') {
    http_response_code(500);
    die('Server chưa cấu hình nhà cung cấp rút gọn link nào. Vào /admin.php để nhập API.');
}

// Tạo token (dùng để xác nhận) và keycode (key thật trả cho user sau khi qua link)
$token = random_string(32);
$keycode = strtoupper(substr(random_string(16), 0, 12));

$stmt = $db->prepare("INSERT INTO keys (keycode, token, status, duration_seconds, max_devices, created_at) VALUES (?, ?, 'pending', ?, ?, ?)");
$stmt->execute([$keycode, $token, KEY_LIFETIME, DEFAULT_MAX_DEVICES, time()]);

// Link đích sau khi user vượt qua shortlink
$destUrl = BASE_URL . '/confirm.php?token=' . urlencode($token);

// Gọi API rút gọn link theo định nghĩa provider (built-in hoặc custom)
function shorten_link(string $provider, string $apiKey, string $destUrl, array $cfg): ?string {
    $builtins = get_builtin_providers();

    // Provider tuỳ chỉnh do admin tự khai báo (url template + kiểu response)
    if ($provider === 'custom' && !empty($cfg['custom'])) {
        $def = $cfg['custom'];
    } elseif (isset($builtins[$provider])) {
        $def = $builtins[$provider];
    } else {
        return null;
    }

    // bit.ly cần gọi POST + header Authorization Bearer, xử lý riêng
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
        curl_close($ch);
        if (!$resp) return null;
        $json = json_decode($resp, true);
        return $json['link'] ?? null;
    }

    $api = str_replace(['{api}', '{url}'], [urlencode($apiKey), urlencode($destUrl)], $def['url']);

    $ch = curl_init($api);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return null;

    if ($def['type'] === 'plain') {
        $resp = trim($resp);
        return filter_var($resp, FILTER_VALIDATE_URL) ? $resp : null;
    }

    // type === 'json'
    $json = json_decode($resp, true);
    return $json[$def['field']] ?? null;
}

$shortUrl = shorten_link($active, $apiKey, $destUrl, $cfg);

if ($shortUrl) {
    header('Location: ' . $shortUrl);
    exit;
}

// Fallback nếu API rút gọn lỗi: vẫn cho qua thẳng confirm (không rút gọn)
header('Location: ' . $destUrl);
exit;
