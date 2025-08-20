<?php
// Bot configuration
$botToken = getenv('BOT_TOKEN') ?: 'Place_Your_Token_Here';
define('BOT_TOKEN', $botToken);
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('USERS_FILE', 'users.json');
define('ERROR_LOG', 'error.log');

// Error logging
function logError($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(ERROR_LOG, "[$timestamp] $message\n", FILE_APPEND);
    error_log($message);
}

// Data management
function loadUsers() {
    try {
        if (!file_exists(USERS_FILE)) {
            file_put_contents(USERS_FILE, json_encode([]));
        }
        return json_decode(file_get_contents(USERS_FILE), true) ?: [];
    } catch (Exception $e) {
        logError("Load users failed: " . $e->getMessage());
        return [];
    }
}

function saveUsers($users) {
    try {
        file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
        return true;
    } catch (Exception $e) {
        logError("Save users failed: " . $e->getMessage());
        return false;
    }
}

// Message sending with inline keyboard
function sendMessage($chat_id, $text, $keyboard = null) {
    try {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard
            ]);
        }
        
        $url = API_URL . 'sendMessage';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $result = curl_exec($ch);
        curl_close($ch);
        
        return $result !== false;
    } catch (Exception $e) {
        logError("Send message failed: " . $e->getMessage());
        return false;
    }
}

// Answer callback query to remove loading state
function answerCallbackQuery($callback_query_id, $text = null) {
    try {
        $params = [
            'callback_query_id' => $callback_query_id
        ];
        
        if ($text) {
            $params['text'] = $text;
        }
        
        $url = API_URL . 'answerCallbackQuery';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $result = curl_exec($ch);
        curl_close($ch);
        
        return $result !== false;
    } catch (Exception $e) {
        logError("Answer callback failed: " . $e->getMessage());
        return false;
    }
}

// Set webhook
function setWebhook($url) {
    try {
        $webhookUrl = API_URL . 'setWebhook?url=' . urlencode($url);
        $result = file_get_contents($webhookUrl);
        logError("Webhook set: " . $result);
        return $result;
    } catch (Exception $e) {
        logError("Webhook setup failed: " . $e->getMessage());
        return false;
    }
}

// Delete webhook
function deleteWebhook() {
    try {
        $webhookUrl = API_URL . 'deleteWebhook';
        $result = file_get_contents($webhookUrl);
        logError("Webhook deleted: " . $result);
        return $result;
    } catch (Exception $e) {
        logError("Webhook deletion failed: " . $e->getMessage());
        return false;
    }
}

// Get webhook info
function getWebhookInfo() {
    try {
        $webhookUrl = API_URL . 'getWebhookInfo';
        $result = file_get_contents($webhookUrl);
        return json_decode($result, true);
    } catch (Exception $e) {
        logError("Webhook info failed: " . $e->getMessage());
        return false;
    }
}

// Main keyboard
function getMainKeyboard() {
    return [
        [['text' => '💰 Earn', 'callback_data' => 'earn'], ['text' => '💳 Balance', 'callback_data' => 'balance']],
        [['text' => '🏆 Leaderboard', 'callback_data' => 'leaderboard'], ['text' => '👥 Referrals', 'callback_data' => 'referrals']],
        [['text' => '🏧 Withdraw', 'callback_data' => 'withdraw'], ['text' => '❓ Help', 'callback_data' => 'help']]
    ];
}

// Process update
function processUpdate($update) {
    $users = loadUsers();
    
    if (isset($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
        $text = trim($update['message']['text'] ?? '');
        
        // Create new user if doesn't exist
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        if (strpos($text, '/start') === 0) {
            $ref = explode(' ', $text)[1] ?? null;
            if ($ref && !$users[$chat_id]['referred_by']) {
                foreach ($users as $id => $user) {
                    if ($user['ref_code'] === $ref && $id != $chat_id) {
                        $users[$chat_id]['referred_by'] = $id;
                        $users[$id]['referrals']++;
                        $users[$id]['balance'] += 50; // Referral bonus
                        sendMessage($id, "🎉 New referral! +50 points bonus!");
                        break;
                    }
                }
            }
            
            $msg = "Welcome to Earning Bot!\nEarn points, invite friends, and withdraw your earnings!\nYour referral code: <b>{$users[$chat_id]['ref_code']}</b>";
            sendMessage($chat_id, $msg, getMainKeyboard());
        }
        
    } elseif (isset($update['callback_query'])) {
        $callback_query = $update['callback_query'];
        $chat_id = $callback_query['message']['chat']['id'];
        $message_id = $callback_query['message']['message_id'];
        $callback_query_id = $callback_query['id'];
        $data = $callback_query['data'];
        
        // Answer callback query immediately to remove loading state
        answerCallbackQuery($callback_query_id);
        
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        switch ($data) {
            case 'earn':
                $time_diff = time() - $users[$chat_id]['last_earn'];
                if ($time_diff < 60) {
                    $remaining = 60 - $time_diff;
                    $msg = "⏳ Please wait $remaining seconds before earning again!";
                } else {
                    $earn = 10;
                    $users[$chat_id]['balance'] += $earn;
                    $users[$chat_id]['last_earn'] = time();
                    $msg = "✅ You earned $earn points!\nNew balance: {$users[$chat_id]['balance']}";
                }
                break;
                
            case 'balance':
                $msg = "💳 Your Balance\nPoints: {$users[$chat_id]['balance']}\nReferrals: {$users[$chat_id]['referrals']}";
                break;
                
            case 'leaderboard':
                $sorted = [];
                foreach ($users as $id => $user) {
                    $sorted[$id] = $user['balance'];
                }
                arsort($sorted);
                $top = array_slice($sorted, 0, 5, true);
                $msg = "🏆 Top Earners\n";
                $i = 1;
                foreach ($top as $id => $bal) {
                    $msg .= "$i. User $id: $bal points\n";
                    $i++;
                }
                break;
                
            case 'referrals':
                $bot_username = explode(':', BOT_TOKEN)[0];
                $msg = "👥 Referral System\nYour code: <b>{$users[$chat_id]['ref_code']}</b>\nReferrals: {$users[$chat_id]['referrals']}\nInvite link: https://t.me/{$bot_username}?start={$users[$chat_id]['ref_code']}\n50 points per referral!";
                break;
                
            case 'withdraw':
                $min = 100;
                if ($users[$chat_id]['balance'] < $min) {
                    $msg = "🏧 Withdrawal\nMinimum: $min points\nYour balance: {$users[$chat_id]['balance']}\nNeed " . ($min - $users[$chat_id]['balance']) . " more points!";
                } else {
                    $amount = $users[$chat_id]['balance'];
                    $users[$chat_id]['balance'] = 0;
                    $msg = "🏧 Withdrawal of $amount points requested!\nOur team will process it soon.";
                }
                break;
                
            case 'help':
                $msg = "❓ Help\n💰 Earn: Get 10 points every minute\n👥 Refer: 50 points per referral\n🏧 Withdraw: Minimum 100 points\nUse buttons below to navigate!";
                break;
                
            default:
                $msg = "❓ Unknown command";
                break;
        }
        
        sendMessage($chat_id, $msg, getMainKeyboard());
    }
    
    saveUsers($users);
    return ['status' => 'ok', 'message' => 'Update processed successfully'];
}

// Handle webhook request
function handleWebhook() {
    try {
        $input = file_get_contents('php://input');
        $update = json_decode($input, true);
        
        if ($update) {
            $result = processUpdate($update);
            header('Content-Type: application/json');
            http_response_code(200);
            echo json_encode($result);
        } else {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid update data']);
        }
    } catch (Exception $e) {
        logError("Webhook error: " . $e->getMessage());
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

// Handle setup and info requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    
    if (isset($_GET['setup'])) {
        // Set webhook
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $webhookUrl = $protocol . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $result = setWebhook($webhookUrl);
        echo "<h1>Webhook Setup</h1>";
        echo "<pre>" . htmlspecialchars($result) . "</pre>";
        echo "<p>Webhook URL: " . htmlspecialchars($webhookUrl) . "</p>";
        
    } elseif (isset($_GET['delete'])) {
        // Delete webhook
        $result = deleteWebhook();
        echo "<h1>Webhook Deleted</h1>";
        echo "<pre>" . htmlspecialchars($result) . "</pre>";
        
    } elseif (isset($_GET['info'])) {
        // Show webhook info
        $info = getWebhookInfo();
        echo "<h1>Webhook Information</h1>";
        echo "<pre>" . htmlspecialchars(print_r($info, true)) . "</pre>";
        
    } elseif (isset($_GET['users'])) {
        // Show users data
        $users = loadUsers();
        echo "<h1>Users Data</h1>";
        echo "<pre>" . htmlspecialchars(print_r($users, true)) . "</pre>";
        
    } elseif (isset($_GET['health'])) {
        // Health check endpoint
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok',
            'timestamp' => time(),
            'bot_token_set' => !empty(BOT_TOKEN) && BOT_TOKEN !== 'Place_Your_Token_Here',
            'users_file_exists' => file_exists(USERS_FILE),
            'error_log_exists' => file_exists(ERROR_LOG)
        ]);
        
    } else {
        // Default page
        echo "<!DOCTYPE html>
        <html>
        <head>
            <title>Telegram Bot Webhook</title>
            <style>
                body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
                .button { display: inline-block; padding: 10px 20px; margin: 5px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; }
                .button:hover { background: #0056b3; }
            </style>
        </head>
        <body>
            <h1>Telegram Bot Webhook Handler</h1>
            <p>This is a webhook-based Telegram bot running on Render.com</p>
            
            <h2>Actions:</h2>
            <a href='?setup=1' class='button'>Setup Webhook</a>
            <a href='?delete=1' class='button'>Delete Webhook</a>
            <a href='?info=1' class='button'>Webhook Info</a>
            <a href='?users=1' class='button'>View Users</a>
            <a href='?health=1' class='button'>Health Check</a>
            
            <h2>Status:</h2>
            <ul>
                <li>Bot Token: " . (BOT_TOKEN && BOT_TOKEN !== 'Place_Your_Token_Here' ? '✅ Set' : '❌ Not set') . "</li>
                <li>Users File: " . (file_exists(USERS_FILE) ? '✅ Exists' : '❌ Missing') . "</li>
                <li>Error Log: " . (file_exists(ERROR_LOG) ? '✅ Exists' : '❌ Missing') . "</li>
                <li>Server Time: " . date('Y-m-d H:i:s') . "</li>
            </ul>
            
            <h2>Instructions:</h2>
            <ol>
                <li>Set your bot token as environment variable BOT_TOKEN</li>
                <li>Click 'Setup Webhook' to configure Telegram webhook</li>
                <li>Start chatting with your bot!</li>
            </ol>
        </body>
        </html>";
    }
} else {
    // Handle webhook POST requests from Telegram
    handleWebhook();
}
?>