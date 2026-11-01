<?php
// ====================================================================
// BOT.PHP — STRICT DELIVERY & INSTANT TELEGRAM ADMIN ASSIGNMENT
// ====================================================================
require_once __DIR__ . '/config.php';

// Optional webhook secret (config.php te TG_WEBHOOK_SECRET set korle fake request block hobe)
if (defined('TG_WEBHOOK_SECRET') && TG_WEBHOOK_SECRET !== '') {
    $hdr = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals((string)TG_WEBHOOK_SECRET, (string)$hdr)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    exit('No payload');
}

function isBotAdmin(PDO $pdo, $chatId): bool {
    if ((string)$chatId === (string)ADMIN_TELEGRAM_ID) return true;
    $stmt = $pdo->prepare("SELECT id FROM bot_admins WHERE telegram_id = ? LIMIT 1");
    $stmt->execute([$chatId]);
    return (bool)$stmt->fetch();
}

// 1. Callback Query Handler (Mini App & Manage Admins)
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $cbChatId = $cb['message']['chat']['id'];
    $cbMsgId = $cb['message']['message_id'];
    $data = $cb['data'];

    if (!isBotAdmin($pdo, $cbChatId)) {
        tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => '⛔ Access Denied!']);
        exit;
    }

    if (strpos($data, 'del_') !== 0) {
        tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
    }

    if ($data === 'manage_admins') {
        $admins = $pdo->query("SELECT * FROM bot_admins ORDER BY id DESC")->fetchAll();
        $adminList = "👑 <b>Admin Management Console:</b>\n━━━━━━━━━━━━━━━━━━━━\n";
        $inlineKeys = [];

        if (empty($admins)) {
            $adminList .= "<i>No sub-admins added yet.</i>\n";
        } else {
            foreach ($admins as $idx => $adm) {
                $adminList .= ($idx + 1) . ". <code>" . $adm['telegram_id'] . "</code>\n";
                $inlineKeys[] = [['text' => '❌ Remove ' . $adm['telegram_id'], 'callback_data' => 'del_' . $adm['telegram_id']]];
            }
        }

        $adminList .= "\n━━━━━━━━━━━━━━━━━━━━\n➕ <b>Quick Command to add admin:</b>\n<code>/admin Telegram_UID</code>";
        $inlineKeys[] = [['text' => '🔙 Back to Console', 'callback_data' => 'back_admin']];

        tgApiRequest('editMessageText', [
            'chat_id'      => $cbChatId,
            'message_id'   => $cbMsgId,
            'text'         => $adminList,
            'parse_mode'   => 'HTML',
            'reply_markup' => ['inline_keyboard' => $inlineKeys]
        ]);
        exit;
    }

    if (strpos($data, 'del_') === 0) {
        $targetId = str_replace('del_', '', $data);
        if ((string)$targetId === (string)ADMIN_TELEGRAM_ID) {
            tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => 'Master Admin cannot be removed!', 'show_alert' => true]);
            exit;
        }
        $pdo->prepare("DELETE FROM bot_admins WHERE telegram_id = ?")->execute([$targetId]);
        tgApiRequest('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => "Admin #$targetId removed!"]);
        
        $admins = $pdo->query("SELECT * FROM bot_admins ORDER BY id DESC")->fetchAll();
        $adminList = "👑 <b>Admin Management Console:</b>\n━━━━━━━━━━━━━━━━━━━━\n";
        $inlineKeys = [];
        foreach ($admins as $idx => $adm) {
            $adminList .= ($idx + 1) . ". <code>" . $adm['telegram_id'] . "</code>\n";
            $inlineKeys[] = [['text' => '❌ Remove ' . $adm['telegram_id'], 'callback_data' => 'del_' . $adm['telegram_id']]];
        }
        $adminList .= "\n━━━━━━━━━━━━━━━━━━━━\n➕ <code>/admin Telegram_UID</code>";
        $inlineKeys[] = [['text' => '🔙 Back', 'callback_data' => 'back_admin']];

        tgApiRequest('editMessageText', [
            'chat_id'      => $cbChatId,
            'message_id'   => $cbMsgId,
            'text'         => $adminList,
            'parse_mode'   => 'HTML',
            'reply_markup' => ['inline_keyboard' => $inlineKeys]
        ]);
        exit;
    }

    if ($data === 'back_admin') {
        $adminUrl = SITE_URL . 'admin.php';
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🚀 Open Admin Panel (Mini App)', 'web_app' => ['url' => $adminUrl]]],
                [['text' => '👥 Manage Admins', 'callback_data' => 'manage_admins']]
            ]
        ];
        tgApiRequest('editMessageText', [
            'chat_id'      => $cbChatId,
            'message_id'   => $cbMsgId,
            'text'         => "👑 <b>MASTER ADMIN COMMAND CENTER</b>\n━━━━━━━━━━━━━━━━━━━━\nSelect an action below:",
            'parse_mode'   => 'HTML',
            'reply_markup' => $keyboard
        ]);
        exit;
    }
}

// 2. Text Command Handler
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $tgUser = $message['from']['username'] ?? ($message['from']['first_name'] ?? 'User');

    // /admin <UID> or /addadmin <UID> command
    if (preg_match('/^\/(admin|addadmin)(@\w+)?(\s|$)/i', $text) && isBotAdmin($pdo, $chatId)) {
        $parts = explode(' ', $text);
        $newAdminId = trim($parts[1] ?? '');

        if (!empty($newAdminId) && is_numeric($newAdminId)) {
            if ((string)$newAdminId === (string)ADMIN_TELEGRAM_ID) {
                tgApiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "⚠️ This ID is already Master Admin!", 'parse_mode' => 'HTML']);
                exit;
            }
            try {
                $stmt = $pdo->prepare("INSERT INTO bot_admins (telegram_id, username, added_by) VALUES (?, 'Admin', ?)");
                $stmt->execute([$newAdminId, $chatId]);
                tgApiRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text'    => "✅ <b>Success!</b> Telegram UID <code>$newAdminId</code> is now an Admin.",
                    'parse_mode' => 'HTML'
                ]);
            } catch (Exception $e) {
                tgApiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "⚠️ This ID is already an admin!"]);
            }
        } else {
            tgApiRequest('sendMessage', ['chat_id' => $chatId, 'text' => "⚠️ <b>Usage:</b> <code>/admin 123456789</code>", 'parse_mode' => 'HTML']);
        }
        exit;
    }

    // /admins command to view all admins
    if (preg_match('/^\/admins(@\w+)?$/i', $text) && isBotAdmin($pdo, $chatId)) {
        $admins = $pdo->query("SELECT * FROM bot_admins ORDER BY id DESC")->fetchAll();
        $adminList = "👑 <b>Current Admins List:</b>\n━━━━━━━━━━━━━━━━━━━━\n";
        $adminList .= "• Master Admin: <code>" . ADMIN_TELEGRAM_ID . "</code>\n";
        foreach ($admins as $a) {
            $adminList .= "• Sub-Admin: <code>" . $a['telegram_id'] . "</code>\n";
        }
        tgApiRequest('sendMessage', ['chat_id' => $chatId, 'text' => $adminList, 'parse_mode' => 'HTML']);
        exit;
    }

    // /start command
    if (strpos($text, '/start') === 0) {
        $parts = explode(' ', $text);
        $sessionToken = trim($parts[1] ?? '');

        // ক. ওয়েবসাইট থেকে ওটিপি চাওয়ার রিকোয়েস্ট (/start TOKEN)
        if (!empty($sessionToken)) {
            $stmt = $pdo->prepare("SELECT id FROM tg_auth_sessions WHERE session_token = ? AND status = 'pending' LIMIT 1");
            $stmt->execute([$sessionToken]);
            $session = $stmt->fetch();

            if ($session) {
                $otp = (string)random_int(10000, 99999);
                $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

                $msg = "🛡️ <b>KEYMANAGER VIP ACCESS GATEWAY</b> 🛡️\n";
                $msg .= "━━━━━━━━━━━━━━━━━━━━\n";
                $msg .= "👤 <b>User:</b> @{$tgUser} (<code>{$chatId}</code>)\n";
                $msg .= "🔑 <b>One-Time Login Code:</b>\n\n";
                $msg .= "👉 <code>{$otp}</code> 👈 <i>(Tap to Copy)</i>\n\n";
                $msg .= "⏱️ <b>Expires in:</b> 5 Minutes\n";
                $msg .= "⚠️ <i>Do not share this OTP with anyone!</i>\n";
                $msg .= "━━━━━━━━━━━━━━━━━━━━";

                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => "📋 Copy Code: $otp", 'copy_text' => ['text' => $otp]]]
                    ]
                ];

                $res = tgApiRequest('sendMessage', [
                    'chat_id'      => $chatId,
                    'text'         => $msg,
                    'parse_mode'   => 'HTML',
                    'reply_markup' => $keyboard
                ]);

                // মেসেজ সফলভাবে ডেলিভারি হলেই কেবল ডাটাবেজে স্ট্যাটাস অন হবে
                if (!empty($res['ok']) && !empty($res['result']['message_id'])) {
                    $msgId = $res['result']['message_id'];
                    $pdo->prepare("UPDATE tg_auth_sessions 
                                   SET telegram_chat_id = ?, telegram_username = ?, otp_code = ?, otp_expires_at = ?, telegram_message_id = ?, is_tg_bound = 1 
                                   WHERE session_token = ?")
                        ->execute([$chatId, $tgUser, $otp, $expiresAt, $msgId, $sessionToken]);
                }
                exit;
            } else {
                tgApiRequest('sendMessage', [
                    'chat_id'    => $chatId,
                    'text'       => "❌ <b>Session Expired!</b>\nPlease return to https://keymanager.shop and click 'Start TG Bot for Code' again.",
                    'parse_mode' => 'HTML'
                ]);
                exit;
            }
        }

        // খ. যদি অ্যাডমিন সরাসরি বটে /start দেয়
        if (isBotAdmin($pdo, $chatId)) {
            $adminUrl = SITE_URL . 'admin.php';
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🚀 Open Admin Panel (Mini App)', 'web_app' => ['url' => $adminUrl]]],
                    [['text' => '👥 Manage Admins', 'callback_data' => 'manage_admins']]
                ]
            ];
            tgApiRequest('sendMessage', [
                'chat_id'      => $chatId,
                'text'         => "👑 <b>MASTER ADMIN COMMAND CENTER</b>\n━━━━━━━━━━━━━━━━━━━━\nSelect an action below:",
                'parse_mode'   => 'HTML',
                'reply_markup' => $keyboard
            ]);
            exit;
        }

        // গ. সাধারণ ইউজার ডিরেক্ট /start লিখলে স্পষ্ট গাইড মেসেজ
        tgApiRequest('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => "👋 <b>Welcome to KeyManager 2FA Bot!</b>\n\n⚠️ <b>To receive your 5-digit Login OTP:</b>\n1. Open our website: https://keymanager.shop\n2. Complete Human Verification Shield.\n3. Click <b>'Start TG Bot for Code'</b>.\n\n<i>Do not type /start manually. Always click the website button!</i>",
            'parse_mode' => 'HTML'
        ]);
        exit;
    }
}