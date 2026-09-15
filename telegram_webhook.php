<?php
require_once __DIR__ . '/config.php';

// Telegram Webhook Handler
// Nhận các sự kiện từ Bot Telegram (đặc biệt là chat_member khi user rời nhóm / vào nhóm)

// Đảm bảo luôn phản hồi HTTP 200 OK ngay cả khi có lỗi nội bộ để Telegram không retry spam
header('Content-Type: application/json; charset=utf-8');

$rawInput = file_get_contents('php://input');
if (empty($rawInput)) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'message' => 'Empty payload']);
    exit;
}

$update = json_decode($rawInput, true);
if (!is_array($update)) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'message' => 'Invalid JSON']);
    exit;
}

// 1. XỬ LÝ SỰ KIỆN THÀNH VIÊN RỜI / THOÁT NHÓM (chat_member)
if (isset($update['chat_member'])) {
    $cm = $update['chat_member'];
    $chat = $cm['chat'] ?? [];
    $chatId = (string)($chat['id'] ?? '');
    $chatTitle = (string)($chat['title'] ?? '');
    $chatUsername = (string)($chat['username'] ?? '');

    $newMember = $cm['new_chat_member'] ?? [];
    $oldMember = $cm['old_chat_member'] ?? [];
    $newStatus = (string)($newMember['status'] ?? '');
    $oldStatus = (string)($oldMember['status'] ?? '');
    
    // User liên quan
    $targetUser = $newMember['user'] ?? $oldMember['user'] ?? null;
    $tgUserId = (int)($targetUser['id'] ?? 0);
    $tgUsername = (string)($targetUser['username'] ?? '');
    $tgFirstName = (string)($targetUser['first_name'] ?? '');
    $isPrem = !empty($targetUser['is_premium']);

    // Lưu / Cập nhật thông tin user vào database tg_users để luôn có dữ liệu tag tên
    if ($tgUserId > 0) {
        try {
            $db = get_db();
            $stmt = $db->prepare("INSERT INTO tg_users (username, tg_user_id, updated_at, is_premium, first_name) 
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT(username) DO UPDATE SET 
                    tg_user_id = excluded.tg_user_id, 
                    updated_at = excluded.updated_at, 
                    is_premium = excluded.is_premium,
                    first_name = excluded.first_name");
            $uKey = $tgUsername !== '' ? $tgUsername : ('id_' . $tgUserId);
            $stmt->execute([$uKey, $tgUserId, time(), $isPrem ? 1 : 0, $tgFirstName]);
        } catch (\Throwable $e) {}
    }

    // Kiểm tra trạng thái: Người dùng rời nhóm, bị kick hoặc banned
    $isLeave = in_array($newStatus, ['left', 'kicked', 'banned'], true) || 
               ($newStatus === 'restricted' && isset($newMember['is_member']) && !$newMember['is_member']);

    if ($isLeave && $tgUserId > 0) {
        // Kiểm tra xem chat này có phải là một trong các nhóm yêu cầu không
        if (is_required_telegram_chat($chat)) {
            $groupName = $chatTitle !== '' ? $chatTitle : ($chatUsername ? '@' . $chatUsername : $chatId);
            $reason = "Rời khỏi nhóm: " . $groupName;
            
            // Xoá toàn bộ key của user, gửi tin nhắn báo cho user, báo admin,
            // và tự động gửi thông báo lên nhóm chat @hoquoc30 tag tên user bị xoá key (KHÔNG gửi lên channel @AuraPingVN)
            revoke_user_keys_on_telegram_leave($tgUserId, $reason);
        }
    }
}

// 2. XỬ LÝ LỆNH /start hoặc tin nhắn trực tiếp nếu user chat với Bot
if (isset($update['message'])) {
    $msg = $update['message'];
    $text = trim((string)($msg['text'] ?? ''));
    $from = $msg['from'] ?? [];
    $chat = $msg['chat'] ?? [];
    $chatType = (string)($chat['type'] ?? '');
    $userId = (int)($from['id'] ?? 0);

    // Lưu thông tin user
    if ($userId > 0) {
        $uName = (string)($from['username'] ?? '');
        $fName = (string)($from['first_name'] ?? '');
        $isPrem = !empty($from['is_premium']);
        try {
            $db = get_db();
            $stmt = $db->prepare("INSERT INTO tg_users (username, tg_user_id, updated_at, is_premium, first_name) 
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT(username) DO UPDATE SET 
                    tg_user_id = excluded.tg_user_id, 
                    updated_at = excluded.updated_at, 
                    is_premium = excluded.is_premium,
                    first_name = excluded.first_name");
            $stmt->execute([$uName !== '' ? $uName : ('id_' . $userId), $userId, time(), $isPrem ? 1 : 0, $fName]);
        } catch (\Throwable $e) {}
    }

    // Nếu là chat riêng (private) với bot
    if ($chatType === 'private' && $userId > 0) {
        if (str_starts_with($text, '/start')) {
            $firstName = (string)($from['first_name'] ?? '');
            $reply = "Xin chào <b>" . htmlspecialchars($firstName) . "</b>!\n\n" .
                     "Tôi là Bot hỗ trợ lấy Key tự động.\n" .
                     "Vui lòng truy cập website bên dưới để tham gia nhóm chat và nhận Key:";
            
            telegram_api_call('sendMessage', [
                'chat_id'    => $chat['id'],
                'text'       => $reply,
                'parse_mode' => 'HTML',
                'reply_markup' => [
                    'inline_keyboard' => [
                        [['text' => '🌐 Vào Web Lấy Key 🚀', 'url' => rtrim(BASE_URL, '/') . '/']]
                    ]
                ]
            ]);
        }
    }
}

http_response_code(200);
echo json_encode(['ok' => true]);
