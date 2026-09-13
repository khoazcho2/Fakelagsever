<?php
// ============================================================
// telegram_webhook.php - Tiếp nhận Webhook từ Telegram Bot API
// Tự động kiểm tra và xác minh thành viên nhóm khi người dùng
// bấm link /start <token>_<channel_id> từ getkey.php
// ============================================================

require_once __DIR__ . '/config.php';

// Thiết lập header trả lời nhanh cho Telegram
header('Content-Type: text/plain; charset=utf-8');

$rawInput = file_get_contents('php://input');
if (!$rawInput) {
    http_response_code(200);
    exit('OK');
}

$update = json_decode($rawInput, true);
if (!is_array($update)) {
    http_response_code(200);
    exit('OK');
}

// Lấy message (có thể là message mới hoặc edited_message)
$message = $update['message'] ?? ($update['edited_message'] ?? null);
if (!$message || empty($message['text'])) {
    http_response_code(200);
    exit('OK');
}

$chatId = $message['chat']['id'] ?? null;
$tgUserId = (int)($message['from']['id'] ?? 0);
$firstName = htmlspecialchars(trim($message['from']['first_name'] ?? 'bạn'));
$text = trim((string)$message['text']);

if (!$chatId || !$tgUserId) {
    http_response_code(200);
    exit('OK');
}

// Xử lý các lệnh
if (str_starts_with($text, '/start')) {
    $parts = explode(' ', $text, 2);
    $payload = trim($parts[1] ?? '');

    if ($payload === '') {
        $welcomeMsg = "👋 Xin chào <b>{$firstName}</b>!\n\n" .
                      "🤖 Bot này dùng để tự động xác minh thành viên nhóm Telegram khi bạn vượt link lấy key trên hệ thống.\n\n" .
                      "👉 Để xác minh, bạn vui lòng quay lại trang web lấy key và nhấn vào nút <b>Tham Gia (Bot)</b>.";
        telegram_api_call('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => $welcomeMsg,
            'parse_mode' => 'HTML',
        ]);
        http_response_code(200);
        exit('OK');
    }

    // Payload có định dạng: <token>_<channel_id>
    $lastUnderscore = strrpos($payload, '_');
    if ($lastUnderscore === false) {
        telegram_api_call('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => "⚠️ <b>Mã xác minh không hợp lệ.</b>\nVui lòng quay lại trang web và nhấn vào nút <b>Tham Gia (Bot)</b> để thử lại.",
            'parse_mode' => 'HTML',
        ]);
        http_response_code(200);
        exit('OK');
    }

    $token = trim(substr($payload, 0, $lastUnderscore));
    $channelId = (int)substr($payload, $lastUnderscore + 1);

    if ($token === '' || $channelId <= 0) {
        telegram_api_call('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => "⚠️ Tham số xác minh không hợp lệ.",
            'parse_mode' => 'HTML',
        ]);
        http_response_code(200);
        exit('OK');
    }

    $channel = get_channel_by_id($channelId);
    if (!$channel || empty($channel['enabled'])) {
        telegram_api_call('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => "⚠️ Kênh hoặc nhiệm vụ này hiện không tồn tại hoặc đã tạm dừng.",
            'parse_mode' => 'HTML',
        ]);
        http_response_code(200);
        exit('OK');
    }

    $groupChatId = trim((string)($channel['tg_chat_id'] ?? ''));
    if ($groupChatId === '') {
        telegram_api_call('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => "ℹ️ Kênh này không cấu hình xác minh thật qua Telegram Bot.",
            'parse_mode' => 'HTML',
        ]);
        http_response_code(200);
        exit('OK');
    }

    // Gọi API Telegram kiểm tra quyền thành viên
    $isMember = check_telegram_membership($groupChatId, $tgUserId);
    $channelName = htmlspecialchars($channel['label'] ?: 'Nhóm Telegram');
    $channelUrl = !empty($channel['url']) ? htmlspecialchars($channel['url']) : '';

    if ($isMember) {
        // Ghi nhận xác minh thành công vào DB
        mark_channel_verified($token, $channelId, $tgUserId);

        $successMsg = "✅ <b>XÁC MINH THÀNH CÔNG!</b>\n\n" .
                      "Bạn đã là thành viên của: <b>{$channelName}</b>.\n\n" .
                      "🚀 Hệ thống trên web đã nhận diện xong. Bạn hãy <b>quay lại trình duyệt</b>, nhiệm vụ sẽ tự động tích xanh để bạn tiếp tục lấy key!";

        telegram_api_call('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => $successMsg,
            'parse_mode' => 'HTML',
        ]);
    } else {
        $failMsg = "❌ <b>BẠN CHƯA THAM GIA NHÓM!</b>\n\n" .
                   "Để hoàn thành nhiệm vụ, bạn cần tham gia nhóm trước:\n";

        if ($channelUrl !== '') {
            $failMsg .= "👉 Link nhóm: <a href=\"{$channelUrl}\"><b>{$channelName}</b></a>\n\n";
        } else {
            $failMsg .= "👉 Nhóm: <b>{$channelName}</b>\n\n";
        }

        $failMsg .= "Sau khi đã bấm <b>Join/Tham gia</b> vào nhóm, hãy bấm lại lệnh bên dưới để xác minh lại nhé:\n" .
                    "👉 <code>/start " . htmlspecialchars($payload) . "</code>";

        telegram_api_call('sendMessage', [
            'chat_id'                  => $chatId,
            'text'                     => $failMsg,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => false,
        ]);
    }
} elseif (str_starts_with($text, '/help')) {
    telegram_api_call('sendMessage', [
        'chat_id'    => $chatId,
        'text'       => "ℹ️ Bot này dùng để xác thực thành viên nhóm Telegram khi nhận key.\nVui lòng bấm vào nút 'Tham Gia (Bot)' trên trang web để kích hoạt.",
        'parse_mode' => 'HTML',
    ]);
}

// Luôn phản hồi HTTP 200 cho Telegram
http_response_code(200);
echo 'OK';
