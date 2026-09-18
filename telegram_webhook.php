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
function render_tg_membership_content(int $userId, string $firstName, string $payload = ''): array {
    $groups = get_required_telegram_groups();
    $missingCount = 0;
    $statusLines = [];
    $groupButtons = [];

    $icHeader   = telegram_premium_icon('header', '⚡');
    $icSuccess  = telegram_premium_icon('success', '✅');
    $icWarning  = telegram_premium_icon('warning', '⚠️');
    $icDelivery = telegram_premium_icon('delivery', '📦');
    $icPointer  = telegram_premium_icon('delivery', '👉');
    $icGroup    = telegram_premium_icon('bell', '📢');

    foreach ($groups as $idx => $g) {
        $cId = $g['chat_id'];
        $isJoined = check_telegram_membership($cId, $userId);
        if (!$isJoined) {
            $missingCount++;
            $statusLines[] = $icWarning . " <b>" . htmlspecialchars($g['name']) . "</b>: CHƯA THAM GIA";
        } else {
            $statusLines[] = $icSuccess . " <b>" . htmlspecialchars($g['name']) . "</b>: ĐÃ THAM GIA";
        }
        $groupButtons[] = [
            ['text' => '📣 ' . ($idx + 1) . '. Tham gia ' . $g['name'], 'url' => $g['url']]
        ];
    }

    $allJoined = ($missingCount === 0);
    $escapedName = htmlspecialchars($firstName !== '' ? $firstName : 'bạn');
    $uInfo = $userId > 0 ? get_telegram_user_info($userId) : null;
    $isPrem = !empty($uInfo['is_premium']);
    $starPrem = $isPrem ? (' ' . telegram_premium_icon('header', '⭐')) : '';
    $webUrl = rtrim(BASE_URL, '/') . '/';

    // Xử lý token nếu có từ payload link web (vd: ?start=TOKEN hoặc TOKEN_CID)
    $token = '';
    $cid = 0;
    if ($payload !== '') {
        if (str_contains($payload, '_')) {
            [$tPart, $cPart] = explode('_', $payload, 2);
            $token = trim($tPart);
            $cid = (int)$cPart;
        } else {
            $token = trim($payload);
        }
    }

    if ($allJoined) {
        if ($token !== '') {
            mark_channel_verified($token, $cid, $userId);
            mark_channel_verified($token, 0, $userId);
        }

        $text = "{$icHeader} <b>XÁC THỰC THÀNH VIÊN THÀNH CÔNG!</b>\n\n" .
                "<blockquote>\n" .
                "👋 Chào <b>{$escapedName}</b>{$starPrem},\n" .
                "{$icSuccess} <b>Bạn đã tham gia đầy đủ tất cả các nhóm bắt buộc:</b>\n\n" .
                implode("\n", $statusLines) . "\n\n" .
                "{$icDelivery} <b>Giao hàng:</b> Tự động sau khi bot kiểm tra đủ\n" .
                "{$icWarning} <b>Quy định:</b> Thoát nhóm sau khi nhận key sẽ bị huỷ key tự động!\n" .
                "</blockquote>\n\n" .
                "{$icPointer} <i>Bạn đã hoàn tất xác thực! Bấm nút bên dưới để mở Web lấy Key:</i>";

        $keyboard = [
            [['text' => '🌐 Vào Web Lấy Key 🚀', 'url' => $webUrl]],
            [['text' => '🔄 Kiểm Tra Lại 🔄', 'callback_data' => 'check_sub:' . $payload]]
        ];
    } else {
        $totalGroups = count($groups);
        $text = "{$icHeader} <b>XÁC THỰC THÀNH VIÊN BOT TELEGRAM</b>\n\n" .
                "<blockquote>\n" .
                "👋 Chào <b>{$escapedName}</b>{$starPrem},\n" .
                "{$icWarning} <b>Bạn chưa tham gia đủ {$totalGroups} nhóm bắt buộc:</b>\n\n" .
                implode("\n", $statusLines) . "\n\n" .
                "{$icDelivery} <b>Giao hàng:</b> Tự động sau khi bot kiểm tra đủ\n" .
                "{$icWarning} <b>Quy định:</b> Thoát nhóm sau khi nhận key sẽ bị huỷ key tự động!\n" .
                "</blockquote>\n\n" .
                "{$icPointer} <i>Bấm vào từng nhóm bên dưới để tham gia, sau đó bấm nút 'Xác Nhận Kiểm Tra'. Khi bot kiểm tra bạn đã vào đủ, nút mở Web lấy Key sẽ xuất hiện:</i>";

        $keyboard = $groupButtons;
        $keyboard[] = [
            ['text' => '✅ Tôi Đã Tham Gia - Bấm Xác Nhận Kiểm Tra 🔄', 'callback_data' => 'check_sub:' . $payload]
        ];
    }

    return [
        'all_joined' => $allJoined,
        'missing'    => $missingCount,
        'text'       => $text,
        'keyboard'   => $keyboard,
    ];
}

// 2.1 Xử lý khi user bấm nút xác nhận (Callback Query)
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $cbId = (string)($cb['id'] ?? '');
    $cbData = (string)($cb['data'] ?? '');
    $cbFrom = $cb['from'] ?? [];
    $cbMsg = $cb['message'] ?? [];
    $cbChatId = $cbMsg['chat']['id'] ?? 0;
    $cbMsgId = (int)($cbMsg['message_id'] ?? 0);
    $userId = (int)($cbFrom['id'] ?? 0);
    $firstName = (string)($cbFrom['first_name'] ?? '');

    if ($userId > 0 && str_starts_with($cbData, 'check_sub')) {
        $payload = '';
        if (str_contains($cbData, ':')) {
            $payload = substr($cbData, strpos($cbData, ':') + 1);
        }

        $res = render_tg_membership_content($userId, $firstName, $payload);

        if ($res['all_joined']) {
            telegram_api_call('answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => '🎉 Xác nhận thành công! Bạn đã tham gia đầy đủ tất cả các nhóm.',
                'show_alert'        => false,
            ]);
        } else {
            telegram_api_call('answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => '⚠️ Bạn vẫn chưa tham gia đủ các nhóm! Vui lòng bấm vào từng nhóm bên dưới để tham gia rồi bấm kiểm tra lại.',
                'show_alert'        => true,
            ]);
        }

        if ($cbChatId && $cbMsgId) {
            telegram_api_call('editMessageText', [
                'chat_id'      => $cbChatId,
                'message_id'   => $cbMsgId,
                'text'         => $res['text'],
                'parse_mode'   => 'HTML',
                'reply_markup' => [
                    'inline_keyboard' => $res['keyboard']
                ]
            ]);
        }
    }
}

// 2.2 Xử lý tin nhắn trực tiếp (/start, tin nhắn chat riêng)
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
        $firstName = (string)($from['first_name'] ?? '');
        $payload = '';
        if (str_starts_with($text, '/start')) {
            $parts = preg_split('/\s+/', $text, 2);
            $payload = trim($parts[1] ?? '');
        }

        $res = render_tg_membership_content($userId, $firstName, $payload);

        telegram_api_call('sendMessage', [
            'chat_id'    => $chat['id'],
            'text'       => $res['text'],
            'parse_mode' => 'HTML',
            'reply_markup' => [
                'inline_keyboard' => $res['keyboard']
            ]
        ]);
    }
}

http_response_code(200);
echo json_encode(['ok' => true]);
