<?php
require_once 'db.php'; // اتصال به دیتابیس اصلی

$token = "1671402681:2TQ91huAA81c6JV5AhBE3dX4pMmfXxRpK1w";
$apiUrl = "https://tapi.bale.ai/bot" . $token . "/";
$targetChannelId = "@barfin_bizz"; 
$channelUrl = "https://ble.ir/barfin_bizz";

$update = json_decode(file_get_contents("php://input"), TRUE);

function sendRequest($method, $data) {
    global $apiUrl;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . $method);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

function isUserMember($chatId, $userId) {
    $data = ["chat_id" => $chatId, "user_id" => $userId];
    $response = sendRequest("getChatMember", $data);
    $result = json_decode($response, true);
    if (isset($result['ok']) && $result['ok'] == true) {
        $status = $result['result']['status'];
        return in_array($status, ['member', 'administrator', 'creator']);
    }
    return false;
}

function registerBotUser($employeeId, $baleUserId, $mobile, $firstName) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO bot_users (employee_id, bale_chat_id, mobile, first_name) 
                            VALUES (?, ?, ?, ?) 
                            ON DUPLICATE KEY UPDATE bale_chat_id = VALUES(bale_chat_id), last_seen = NOW()");
    return $stmt->execute([$employeeId, $baleUserId, $mobile, $firstName]);
}

if (isset($update["message"])) {
    $chatId = $update["message"]["chat"]["id"];
    $fromId = $update["message"]["from"]["id"];
    $firstName = $update["message"]["from"]["first_name"] ?? "کاربر";
    
    if (isset($update["message"]["text"]) && $update["message"]["text"] == "/start") {
        if (!isUserMember($targetChannelId, $fromId)) {
            $inlineKeyboard = [
                "inline_keyboard" => [[["text" => "📢 عضویت در کانال", "url" => $channelUrl]]]
            ];
            sendRequest("sendMessage", [
                "chat_id" => $chatId,
                "text" => "❌ لطفاً ابتدا در کانال عضو شوید، سپس /start را مجدداً ارسال کنید.",
                "reply_markup" => json_encode($inlineKeyboard)
            ]);
            exit;
        }
        
        // بررسی اینکه آیا این کاربر قبلاً در جدول bot_users ثبت شده است؟
        $stmt = $pdo->prepare("SELECT employee_id FROM bot_users WHERE bale_chat_id = ?");
        $stmt->execute([$chatId]);
        $existing = $stmt->fetch();
        if ($existing) {
            sendRequest("sendMessage", [
                "chat_id" => $chatId,
                "text" => "✅ شما قبلاً در سیستم ثبت شده‌اید. با تشکر!"
            ]);
            exit;
        }
        
        // درخواست شماره تماس
        $keyboard = [
            "keyboard" => [[["text" => "ارسال شماره موبایل", "request_contact" => true]]],
            "resize_keyboard" => true,
            "one_time_keyboard" => true
        ];
        sendRequest("sendMessage", [
            "chat_id" => $chatId,
            "text" => "👋 به ربات پشتیبانی خوش آمدید.\nلطفاً شماره موبایل خود را ارسال کنید تا دسترسی به اطلاع‌رسانی‌ها فعال شود.",
            "reply_markup" => json_encode($keyboard)
        ]);
    }
    elseif (isset($update["message"]["contact"])) {
        if (!isUserMember($targetChannelId, $fromId)) {
            sendRequest("sendMessage", [
                "chat_id" => $chatId,
                "text" => "❌ شما از کانال خارج شده‌اید. لطفاً عضو شوید."
            ]);
            exit;
        }
        
        $contact = $update["message"]["contact"];
        $phoneNumber = $contact["phone_number"];
        // نرمال‌سازی شماره: ۰۹۱۲...
        $normalizedPhone = preg_replace('/[^0-9]/', '', $phoneNumber);
        if (substr($normalizedPhone, 0, 2) == '98') {
            $normalizedPhone = '0' . substr($normalizedPhone, 2);
        }
        
        // پیدا کردن کارمند در جدول employees بر اساس شماره موبایل
        $stmt = $pdo->prepare("SELECT id, fullname FROM employees WHERE mobile = ?");
        $stmt->execute([$normalizedPhone]);
        $employee = $stmt->fetch();
        if ($employee) {
            registerBotUser($employee['id'], $chatId, $normalizedPhone, $firstName);
            sendRequest("sendMessage", [
                "chat_id" => $chatId,
                "text" => "✅ ثبت نام شما با موفقیت انجام شد. در صورت ناقص بودن مدارک قراردادها، به شما اطلاع رسانی خواهد شد.",
                "reply_markup" => json_encode(["remove_keyboard" => true])
            ]);
        } else {
            sendRequest("sendMessage", [
                "chat_id" => $chatId,
                "text" => "❌ شماره موبایل وارد شده در سامانه مدیریت قراردادها ثبت نشده است. لطفاً با مدیر سیستم تماس بگیرید.",
                "reply_markup" => json_encode(["remove_keyboard" => true])
            ]);
        }
    }
}
