<?php
// ============================================================
// telegram_webhook.php - Tiếp nhận Webhook từ Telegram Bot API
// Tự động kiểm tra người dùng đã tham gia các nhóm bắt buộc
// theo cấu hình ID Channel trong Admin (mặc định hoquoc30 & AuraPingVN)
// Đồng bộ và mở khoá nhiệm vụ trên web (getkey.php)
// Giao diện nút bấm Inline Keyboard UI đẹp mắt, tương tác mượt mà.
// ============================================================

require_once __DIR__ . '/config.php';

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

// Tự động lưu ánh xạ username <-> tg_user_id và cờ is_premium
$fromUser = $update['message']['from'] ?? ($update['callback_query']['from'] ?? null);
if ($fromUser && !empty($fromUser['id'])) {
    $uName = !empty($fromUser['username']) ? strtolower(ltrim(trim($fromUser['username']), '@')) : '';
    $uId = (int)$fromUser['id'];
    $isPrem = !empty($fromUser['is_premium']);
    $fName = trim(($fromUser['first_name'] ?? '') . ' ' . ($fromUser['last_name'] ?? ''));
    save_telegram_user($uId, $uName, $isPrem, $fName);
}

// ------------------------------------------------------------
// Xử lý sự kiện Thay đổi thành viên nhóm (chat_member / my_chat_member)
// Khi user rời nhóm hoặc bị kick/ban khỏi nhóm Telegram bắt buộc -> XOÁ KEY NGAY LẬP TỨC
// ------------------------------------------------------------
$chatMemberUpdate = $update['chat_member'] ?? ($update['my_chat_member'] ?? null);
if ($chatMemberUpdate) {
    $chat = $chatMemberUpdate['chat'] ?? [];
    $newMember = $chatMemberUpdate['new_chat_member'] ?? [];
    $oldMember = $chatMemberUpdate['old_chat_member'] ?? [];
    $status = $newMember['status'] ?? '';
    $userObj = $newMember['user'] ?? ($oldMember['user'] ?? null);
    $userId = (int)($userObj['id'] ?? 0);
    $uName = $userObj['username'] ?? '';
    $isPrem = !empty($userObj['is_premium']);
    $fName = trim(($userObj['first_name'] ?? '') . ' ' . ($userObj['last_name'] ?? ''));
    $chatTitle = $chat['title'] ?? ($chat['username'] ?? 'Nhóm Telegram');

    if ($userId > 0) {
        save_telegram_user($userId, $uName, $isPrem, $fName);
    }

    // Các trạng thái thoát: 'left', 'kicked', 'banned'
    if ($userId > 0 && in_array($status, ['left', 'kicked', 'banned'], true)) {
        // Kiểm tra xem chat này có phải là nhóm yêu cầu hay không
        if (is_required_telegram_chat($chat)) {
            $delCount = revoke_user_keys_on_telegram_leave($userId, "Rời khỏi nhóm " . $chatTitle);
        }
    }

    http_response_code(200);
    exit('OK');
}

// Lấy danh sách các nhóm Telegram bắt buộc từ cấu hình Admin
function get_configured_telegram_groups(?int $channelId = null): array {
    $cfg = get_telegram_bot_config();
    $rawList = trim((string)($cfg['channel_ids'] ?? ''));
    if ($rawList === '') {
        $rawList = '@hoquoc30, @AuraPingVN';
    }

    $entries = preg_split('/[\s,]+/', $rawList, -1, PREG_SPLIT_NO_EMPTY);
    $groups = [];
    foreach ($entries as $idx => $item) {
        $item = trim($item);
        if ($item === '') continue;
        $clean = ltrim($item, '@');
        $isUsername = str_starts_with($item, '@');
        $url = $isUsername ? ("https://t.me/" . $clean) : (str_starts_with($item, 'https://') ? $item : ("https://t.me/" . $clean));
        $name = $isUsername ? ("Kênh @" . $clean) : ("Nhóm " . $item);
        if (strcasecmp($clean, 'hoquoc30') === 0) $name = 'Kênh HoQuoc';
        if (strcasecmp($clean, 'aurapingvn') === 0) $name = 'Kênh AuraPingVN';

        $groups[] = [
            'id'      => 'grp_' . $idx,
            'name'    => $name,
            'chat_id' => $item,
            'url'     => $url,
        ];
    }

    // Nếu có kênh cụ thể trong DB có tg_chat_id khác, gộp thêm vào
    if ($channelId && $channelId > 0) {
        $chan = get_channel_by_id($channelId);
        if ($chan && !empty($chan['tg_chat_id'])) {
            $cChatId = trim($chan['tg_chat_id']);
            $found = false;
            foreach ($groups as $g) {
                if (strcasecmp($g['chat_id'], $cChatId) === 0) { $found = true; break; }
            }
            if (!$found) {
                $groups[] = [
                    'id'      => 'chan_' . $chan['id'],
                    'name'    => $chan['label'] ?: 'Kênh nhiệm vụ',
                    'chat_id' => $cChatId,
                    'url'     => $chan['url'] ?: ('https://t.me/' . ltrim($cChatId, '@')),
                ];
            }
        }
    }

    return $groups;
}

// Hàm kiểm tra trạng thái các nhóm và tạo nội dung thông báo + nút bấm giao diện Telegram
function evaluate_groups_and_build_ui(int $tgUserId, string $token, int $channelId, string $firstName = 'bạn', bool $isPremium = false): array {
    $groups = get_configured_telegram_groups($channelId);
    $allJoined = true;
    $statusLines = [];
    $joinButtons = [];

    $star = telegram_premium_icon('header', '⭐');
    $chk  = telegram_premium_icon('success', '✔');
    $warn = telegram_premium_icon('warning', '⚠️');
    $bell = telegram_premium_icon('bell', '📢');
    $rock = telegram_premium_icon('delivery', '🚀');
    $auto = telegram_premium_icon('delivery', '⚡');
    $pin  = telegram_premium_icon('warning', '📌');

    foreach ($groups as $idx => $g) {
        $num = $idx + 1;
        $isJoined = check_telegram_membership($g['chat_id'], $tgUserId);
        if ($isJoined) {
            $statusLines[] = "{$chk} <b>{$g['name']}</b>: ĐÃ THAM GIA";
        } else {
            $allJoined = false;
            $statusLines[] = "{$warn} <b>{$g['name']}</b>: CHƯA THAM GIA";
            $joinButtons[] = [
                ['text' => "📢 {$num}. Tham gia {$g['name']}", 'url' => $g['url']]
            ];
        }
    }

    $webUrl = rtrim(BASE_URL, '/') . '/';

    if ($allJoined) {
        // Ghi nhận xác minh vào DB cho channelId này và TẤT CẢ kênh Telegram trên web
        if ($token !== '') {
            if ($channelId > 0) {
                mark_channel_verified($token, $channelId, $tgUserId);
            }
            // Đồng bộ mở khoá tất cả kênh Telegram đang bật cho token này trên web
            try {
                $allChans = get_channels(true);
                foreach ($allChans as $c) {
                    if ($c['type'] === 'telegram') {
                        mark_channel_verified($token, (int)$c['id'], $tgUserId);
                    }
                }
                get_db()->prepare("UPDATE keys SET tg_user_id = ?, is_premium = ? WHERE token = ?")->execute([$tgUserId, $isPremium ? 1 : 0, $token]);
            } catch (\Throwable $e) {}
        }

        $premLine = $isPremium ? ("{$star} <b>Tài khoản:</b> Telegram Premium\n") : "";

        $text = "<blockquote>" .
                "{$star} <b>XÁC MINH THÀNH CÔNG TẤT CẢ NHÓM!</b>\n\n" .
                $premLine .
                implode("\n", $statusLines) . "\n\n" .
                "{$auto} <b>Trạng thái:</b> Tự động duyệt hoàn tất\n" .
                "{$rock} <b>Hệ thống:</b> Web đã mở khoá nhiệm vụ!" .
                "</blockquote>\n" .
                "<i>👉 Bạn hãy bấm nút bên dưới để quay lại Web và nhận Key ngay!</i>";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🌐 Quay lại Web nhận Key Ngay 🚀', 'url' => $webUrl]
                ]
            ]
        ];

        return ['success' => true, 'text' => $text, 'keyboard' => $keyboard];
    } else {
        $totalGroups = count($groups);
        $text = "<blockquote>" .
                "{$bell} <b>THÔNG BÁO TỪ QUẢN TRỊ</b>\n\n" .
                "👋 Chào <b>" . htmlspecialchars($firstName) . "</b>,\n" .
                "⚠️ Bạn chưa tham gia đủ {$totalGroups} nhóm yêu cầu bên dưới:\n\n" .
                implode("\n", $statusLines) . "\n\n" .
                "{$auto} <b>Giao hàng:</b> Tự động sau khi tham gia đủ\n" .
                "{$pin} <b>Quy định:</b> Nếu thoát nhóm sau khi nhận key, key sẽ bị huỷ tự động!" .
                "</blockquote>\n" .
                "<i>👉 Bấm vào từng nhóm bên dưới để tham gia, sau đó bấm nút <b>Xác Minh Ngay</b>:</i>";

        // Thêm nút xác minh lại ở cuối bảng nút bấm
        $joinButtons[] = [
            ['text' => '🔄 Đã vào đủ - Bấm Xác Minh Ngay', 'callback_data' => "chk_{$token}_{$channelId}"]
        ];

        return ['success' => false, 'text' => $text, 'keyboard' => ['inline_keyboard' => $joinButtons]];
    }
}

// ------------------------------------------------------------
// 1. Xử lý Callback Query (khi người dùng bấm nút xác minh trực tiếp trên bot)
// ------------------------------------------------------------
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $cbId = $cb['id'] ?? '';
    $data = trim((string)($cb['data'] ?? ''));
    $tgUserId = (int)($cb['from']['id'] ?? 0);
    $firstName = trim((string)($cb['from']['first_name'] ?? 'bạn'));
    $isUserPremium = !empty($cb['from']['is_premium']);
    $chatId = $cb['message']['chat']['id'] ?? null;
    $messageId = $cb['message']['message_id'] ?? null;

    if (str_starts_with($data, 'chk_')) {
        $payload = substr($data, 4);
        $lastUnderscore = strrpos($payload, '_');
        $token = $lastUnderscore !== false ? trim(substr($payload, 0, $lastUnderscore)) : '';
        $channelId = $lastUnderscore !== false ? (int)substr($payload, $lastUnderscore + 1) : 0;

        $res = evaluate_groups_and_build_ui($tgUserId, $token, $channelId, $firstName, $isUserPremium);

        if ($res['success']) {
            telegram_api_call('answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => '✅ Chúc mừng! Bạn đã tham gia đủ các nhóm. Đã mở khoá trên web!',
                'show_alert'        => false,
            ]);
        } else {
            telegram_api_call('answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text'              => '❌ Bạn vẫn chưa tham gia đủ các nhóm! Vui lòng bấm vào nút nhóm bên dưới để tham gia.',
                'show_alert'        => true,
            ]);
        }

        // Cập nhật lại tin nhắn hiển thị với giao diện mới nhất
        if ($chatId && $messageId) {
            telegram_api_call('editMessageText', [
                'chat_id'                  => $chatId,
                'message_id'               => $messageId,
                'text'                     => $res['text'],
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => false,
                'reply_markup'             => $res['keyboard'],
            ]);
        }
    } else {
        telegram_api_call('answerCallbackQuery', ['callback_query_id' => $cbId]);
    }

    http_response_code(200);
    exit('OK');
}

// ------------------------------------------------------------
// 2. Xử lý Message thường (người dùng bấm link /start <token>_<channel_id>)
// ------------------------------------------------------------
$message = $update['message'] ?? ($update['edited_message'] ?? null);
if (!$message || empty($message['text'])) {
    http_response_code(200);
    exit('OK');
}

$chatId = $message['chat']['id'] ?? null;
$tgUserId = (int)($message['from']['id'] ?? 0);
$firstName = trim((string)($message['from']['first_name'] ?? 'bạn'));
$isUserPremium = !empty($message['from']['is_premium']);
$text = trim((string)$message['text']);

if (!$chatId || !$tgUserId) {
    http_response_code(200);
    exit('OK');
}

if (str_starts_with($text, '/start')) {
    $parts = explode(' ', $text, 2);
    $payload = trim($parts[1] ?? '');

    if ($payload === '') {
        // Người dùng bấm /start thông thường (không qua link nhiệm vụ)
        $groups = get_configured_telegram_groups();
        $groupLines = [];
        $keyButtons = [];
        foreach ($groups as $idx => $g) {
            $num = $idx + 1;
            $groupLines[] = "{$num}. <a href=\"{$g['url']}\"><b>{$g['name']}</b></a> ({$g['chat_id']})";
            $keyButtons[] = [['text' => "📢 {$num}. Tham gia {$g['name']}", 'url' => $g['url']]];
        }
        $keyButtons[] = [['text' => '🌐 Mở trang Web lấy Key 🚀', 'url' => rtrim(BASE_URL, '/') . '/']];

        $star = telegram_premium_icon('header', '⭐');
        $botIc = telegram_premium_icon('header', '🤖');
        $infoIc = telegram_premium_icon('bell', '📌');
        $premBadge = $isUserPremium ? ("{$star} <b>Tài khoản:</b> Telegram Premium\n") : "";

        $welcomeText = "<blockquote>" .
                       "{$botIc} <b>XÁC THỰC THÀNH VIÊN BOT TELEGRAM</b>\n\n" .
                       "👋 Xin chào <b>" . htmlspecialchars($firstName) . "</b>" . ($isUserPremium ? (' ' . telegram_premium_icon('header', '⭐')) : '') . "!\n" .
                       $premBadge .
                       "• <b>Mô tả:</b> Bot tự động xác minh thành viên nhóm & cấp key\n" .
                       "• <b>Giao hàng:</b> Tự động 100%\n\n" .
                       "{$infoIc} <b>Danh sách nhóm bắt buộc:</b>\n" .
                       implode("\n", $groupLines) .
                       "</blockquote>\n" .
                       "<i>👉 Để lấy key, bạn hãy quay lại trang web làm nhiệm vụ và bấm <b>/start</b> theo liên kết để bot xác nhận tự động!</i>";

        telegram_api_call('sendMessage', [
            'chat_id'                  => $chatId,
            'text'                     => $welcomeText,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => false,
            'reply_markup'             => ['inline_keyboard' => $keyButtons],
        ]);
        http_response_code(200);
        exit('OK');
    }

    // Payload có định dạng: <token>_<channel_id>
    $lastUnderscore = strrpos($payload, '_');
    if ($lastUnderscore === false) {
        $token = $payload;
        $channelId = 0;
    } else {
        $token = trim(substr($payload, 0, $lastUnderscore));
        $channelId = (int)substr($payload, $lastUnderscore + 1);
    }

    $res = evaluate_groups_and_build_ui($tgUserId, $token, $channelId, $firstName, $isUserPremium);

    telegram_api_call('sendMessage', [
        'chat_id'                  => $chatId,
        'text'                     => $res['text'],
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => false,
        'reply_markup'             => $res['keyboard'],
    ]);
    http_response_code(200);
    exit('OK');
}

http_response_code(200);
echo 'OK';
