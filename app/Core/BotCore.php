<?php

namespace App\Core;

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/TelegramAPI.php';
require_once __DIR__ . '/ProfileFieldManager.php';
//require_once __DIR__ . '/PerformanceMonitor.php';
use Carbon\Carbon;
use Morilog\Jalali\Jalalian;
use App\Models\User;
use App\Models\Wallet;
use App\Models\ChargeCode;
use App\Models\Transaction;
use App\Models\ProfileField;
use App\Models\Administrator;
use App\Models\ContactRequestHistory;
use App\Models\UserFilter;
use App\Models\UserSuggestion;
use App\Models\SystemFilter;
use App\Models\Referral;
use App\Core\UpdateManager;
use App\Core\DatabaseManage;
use APP\Models\Like;
use APP\Models\UserSubscription;

use Exception;


class BotCore
{

    private $telegram;
    private $updateManager;


    private static $databaseOptimized = false; // 🔴 جلوگیری از اجرای تکراری

    public function __construct()
    {
        // 🔴 مطمئن شو updateManager مقداردهی شده است
        $this->updateManager = new UpdateManager();

        $token = $_ENV['TELEGRAM_BOT_TOKEN'] ?? null;
        $this->telegram = new TelegramAPI($token);

        if (!$token) {
            throw new \Exception('TELEGRAM_BOT_TOKEN is not set in .env file');
        }

        error_log("🤖 BotCore initialized successfully");
    }

    private function optimizeDatabase()
    {
        // جلوگیری از اجرای مکرر
        if (self::$databaseOptimized) {
            return;
        }

        try {
            $pdo = $this->getPDO();

            $indexes = [
                "ALTER TABLE users ADD INDEX idx_profile_gender_city (is_profile_completed, gender, city)",
                "ALTER TABLE users ADD INDEX idx_telegram_id (telegram_id)",
                "ALTER TABLE user_filters ADD INDEX idx_user_id (user_id)",
                "ALTER TABLE transactions ADD INDEX idx_user_created (user_id, created_at)",
                "ALTER TABLE profile_fields ADD INDEX idx_active_sort (is_active, sort_order)",
                "ALTER TABLE user_suggestions ADD INDEX idx_user_shown (user_id, shown_at)",
                "ALTER TABLE contact_request_history ADD INDEX idx_user_requested (user_id, requested_at)",
                "ALTER TABLE users ADD INDEX idx_invite_code (invite_code)",
                "ALTER TABLE users ADD INDEX idx_referred_by (referred_by)",
                "ALTER TABLE referrals ADD INDEX idx_referrer_id (referrer_id)",
                "ALTER TABLE referrals ADD INDEX idx_referred_id (referred_id)",
                "ALTER TABLE referrals ADD INDEX idx_has_purchased (has_purchased)"

            ];

            $successCount = 0;
            $errorCount = 0;

            foreach ($indexes as $sql) {
                try {
                    $pdo->exec($sql);
                    error_log("✅ ایندکس ایجاد شد: " . substr($sql, 0, 60) . "...");
                    $successCount++;
                } catch (\Exception $e) {
                    // اگر ایندکس از قبل وجود دارد، خطا نگیر
                    if (
                        strpos($e->getMessage(), 'Duplicate key') === false &&
                        strpos($e->getMessage(), 'already exists') === false
                    ) {
                        error_log("⚠️ خطا در ایجاد ایندکس: " . $e->getMessage());
                        $errorCount++;
                    } else {
                        error_log("🔵 ایندکس از قبل وجود داشت: " . substr($sql, 0, 40) . "...");
                    }
                }
            }

            error_log("🎯 بهینه‌سازی دیتابیس تکمیل شد. موفق: {$successCount}, خطا: {$errorCount}");
            self::$databaseOptimized = true;
        } catch (\Exception $e) {
            error_log("❌ خطا در بهینه‌سازی دیتابیس: " . $e->getMessage());
        }
    }

    public function handleWebhookUpdate()
    {
        $update = json_decode(file_get_contents('php://input'), true);

        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->processCallbackQuery($update['callback_query']);
        }
    }
    // در کلاس BotCore این متد را اضافه کنید:
protected function toJalali($date, $format = 'Y/m/d H:i')
{
    try {
        if (!$date) {
            return 'نامشخص';
        }
        
        // اگر رشته است به Carbon تبدیل کن
        if (is_string($date)) {
            $date = \Carbon\Carbon::parse($date);
        }
        
        // اگر Carbon instance است
        if ($date instanceof \Carbon\Carbon) {
            return \Morilog\Jalali\Jalalian::fromCarbon($date)->format($format);
        }
        
        // اگر timestamp است
        if (is_numeric($date)) {
            return \Morilog\Jalali\Jalalian::fromDateTime(\Carbon\Carbon::createFromTimestamp($date))->format($format);
        }
        
        return 'نامشخص';
    } catch (\Exception $e) {
        error_log("❌ خطا در تبدیل تاریخ: " . $e->getMessage());
        return $date; // در صورت خطا تاریخ اصلی را برگردان
    }
}

    public function handleUpdate()
    {
        $this->performHealthCheck();
        try {
            // 🔴 اضافه کردن چک برای updateManager
            if (!$this->updateManager) {
                error_log("⚠️ updateManager is null, initializing...");
                $this->updateManager = new UpdateManager();
            }

            $lastUpdateId = $this->updateManager->getLastUpdateId();
            error_log("📡 Getting updates from ID: " . ($lastUpdateId + 1));

            $updates = $this->telegram->getUpdates($lastUpdateId + 1);

            if ($updates && $updates['ok'] && !empty($updates['result'])) {
                foreach ($updates['result'] as $update) {
                    $this->processUpdate($update);
                    $this->updateManager->saveLastUpdateId($update['update_id']);
                }

                echo "✅ Processed " . count($updates['result']) . " update(s)\n";
            } else {
                if ($updates && !$updates['ok']) {
                    error_log("❌ Telegram API error: " . ($updates['description'] ?? 'Unknown error'));
                }
                echo "⏳ No new updates\n";
            }
        } catch (\Exception $e) {
            error_log("❌ Bot Error: " . $e->getMessage());
            echo "❌ Error: " . $e->getMessage() . "\n";

            // 🔴 اگر خطا از updateManager است، بدون آن ادامه بده
            if (strpos($e->getMessage(), 'updateManager') !== false) {
                error_log("🔄 Continuing without updateManager...");
                $this->handleUpdateWithoutManager();
            }
        }
    }
    private function handleUpdateWithoutManager()
    {
        try {
            error_log("🔄 Handling updates without updateManager");

            // استفاده از offset 0 برای گرفتن آخرین آپدیت
            $updates = $this->telegram->getUpdates();

            if ($updates && $updates['ok'] && !empty($updates['result'])) {
                foreach ($updates['result'] as $update) {
                    $this->processUpdate($update);
                }
                echo "✅ Processed " . count($updates['result']) . " update(s) without manager\n";
            } else {
                echo "⏳ No new updates (without manager)\n";
            }
        } catch (\Exception $e) {
            error_log("❌ Error in handleUpdateWithoutManager: " . $e->getMessage());
        }
    }
    private function processUpdate($update)
    {
        if (isset($update['message'])) {
            $message = $update['message'];
            $chatId = $message['chat']['id'];

            // پیدا کردن کاربر با مدیریت خطا
            $user = $this->findUserSafely($chatId);


            if (isset($message['photo'])) {
                $this->handlePhotoMessage($user, $message);
            } elseif (isset($message['text'])) {
                $this->handleMessage($message);
            }
        } elseif (isset($update['callback_query'])) {
            $callbackQuery = $update['callback_query'];
            $chatId = $callbackQuery['message']['chat']['id'];

            $user = $this->findUserSafely($chatId);

            if ($user) {
                $this->processCallbackQuery($callbackQuery);
            }
        }
    }

    private function findOrCreateUser($from, $chatId = null)
    {
        $telegramId = $from['id'];

        // اول سعی کن از Eloquent استفاده کنی
        if (class_exists('App\Models\User') && class_exists('Illuminate\Database\Eloquent\Model')) {
            try {
                $user = \App\Models\User::where('telegram_id', $telegramId)->first();

                if (!$user) {
                       // ایجاد کد دعوت برای کاربر جدید
                $inviteCode = $this->generateUniqueInviteCode();
                    // ایجاد کاربر جدید با Eloquent
                    $user = \App\Models\User::create([
                        'telegram_id' => $telegramId,
                        'first_name' => $from['first_name'] ?? '',
                        'username' => $from['username'] ?? '',
                        'state' => 'start',
                        'rules_accepted' => 0, // ← این خط را اضافه کنید
                          'invite_code' => $inviteCode, // این خط را اضافه کنید
                    ]);

                    echo "✅ Created new user with Eloquent: {$user->telegram_id}\n";
                } else {
                    echo "🔍 Found user with Eloquent: {$user->telegram_id}, State: {$user->state}\n";
                }

                return $user;
            } catch (\Exception $e) {
                echo "❌ Eloquent failed: " . $e->getMessage() . "\n";
                // ادامه با روش PDO
            }
        }


        // روش fallback با PDO
        $pdo = $this->getPDO();
        $sql = "SELECT * FROM users WHERE telegram_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$telegramId]);
        $userData = $stmt->fetch(\PDO::FETCH_ASSOC);

        // در قسمت INSERT کاربر جدید با PDO
        $inviteCode = strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
        $sql = "INSERT INTO users (telegram_id, first_name, username, state, invite_code, created_at) 
        VALUES (?, ?, ?, ?, ?, NOW())";

        $stmt->execute([$telegramId, $from['first_name'] ?? '', $from['username'] ?? '', 'start', $inviteCode]);

        // سپس ست کنید
        $user->invite_code = $inviteCode;

        if ($userData) {
            echo "🔍 Found user with PDO: {$telegramId}, State: {$userData['state']}\n";

            // اگر مدل User وجود دارد اما Eloquent مشکل داشت
            if (class_exists('App\Models\User')) {
                $user = new \App\Models\User();
                foreach ($userData as $key => $value) {
                    $user->$key = $value;
                }
            } else {
                $user = new \stdClass();
                foreach ($userData as $key => $value) {
                    $user->$key = $value;
                }
                // اضافه کردن متد getWallet به stdClass
                $user->getWallet = function () {
                    $wallet = new \stdClass();
                    $wallet->balance = 0;
                    $wallet->currency = 'تومان';
                    $wallet->formatBalance = function () use ($wallet) {
                        return number_format($wallet->balance) . ' ' . $wallet->currency;
                    };
                    return $wallet;
                };
                $user->getFormattedBalance = function () {
                    return number_format(0) . ' تومان';
                };
            }

            return $user;
        } else {
            // ایجاد کاربر جدید با PDO
            echo "🆕 Creating new user with PDO: {$telegramId}\n";

            // ← این خط را تغییر دهید
            $sql = "INSERT INTO users (telegram_id, first_name, username, state, rules_accepted, created_at) VALUES (?, ?, ?, ?, 0, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$telegramId, $from['first_name'] ?? '', $from['username'] ?? '', 'start']);

            if (class_exists('App\Models\User')) {
                $user = new \App\Models\User();
            } else {
                $user = new \stdClass();
            }

            $user->telegram_id = $telegramId;
            $user->first_name = $from['first_name'] ?? '';
            $user->username = $from['username'] ?? '';
            $user->state = 'start';
            $user->rules_accepted = 0; // ← این خط را اضافه کنید

            if ($user instanceof \stdClass) {
                $user->getWallet = function () {
                    $wallet = new \stdClass();
                    $wallet->balance = 0;
                    $wallet->currency = 'تومان';
                    $wallet->formatBalance = function () use ($wallet) {
                        return number_format($wallet->balance) . ' ' . $wallet->currency;
                    };
                    return $wallet;
                };
                $user->getFormattedBalance = function () {
                    return number_format(0) . ' تومان';
                };
            }

            return $user;
        }
    }
    private function generateUniqueInviteCode()
{
    $pdo = $this->getPDO();
    
    do {
        $code = strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE invite_code = ?");
        $stmt->execute([$code]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
    } while ($result['count'] > 0);
    
    return $code;
}
    // این متد را اضافه کنید و با دستور /resetstate فراخوانی کنید
    private function forceResetState($user, $chatId)
    {
        error_log("🚨 FORCE RESET STATE - Before: " . $user->state);
        $user->update(['state' => 'main_menu']);
        error_log("🚨 FORCE RESET STATE - After: " . $user->state);

        $this->telegram->sendMessage($chatId, "✅ وضعیت سیستم کاملاً بازنشانی شد. اکنون می‌توانید به طور عادی از ربات استفاده کنید.");
        $this->showMainMenu($user, $chatId);
    }

    private function sendPreStartWelcome($chatId, $userId)
    {
        $message = "👋 **سلام! خوش اومدی**\n\n";
        $message .= "به **ربات همسر یابی ** خوش آمدید! 🌸\n\n";
        $message .= "📋 **قبل از شروع، چند نکته:**\n";
        $message .= "• پروفایل شما کاملاً محرمانه است\n";
        $message .= "• می‌توانید محدوده سنی و موقعیت مکانی مشخص کنید\n";
        $message .= "• ارتباط از طریق چت امن انجام می‌شود\n\n";
        $message .= "💫 **آماده‌ای شروع کنی؟**";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'بله، شروع کنیم! 🚀', 'callback_data' => 'complete_registration']
                ],
                [
                    ['text' => 'راهنما 📖', 'callback_data' => 'help'],
                    ['text' => 'قوانین 📜', 'callback_data' => 'rules']
                ]
            ]
        ];

        // ذخیره کاربر موقت
        $tempUser = new User();
        $tempUser->id = $userId;
        $tempUser->chat_id = $chatId;
        $tempUser->status = 'pre_start';
        $tempUser->save();

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function showRules($chatId, $user)
    {
        $rulesText = "📜 **به ربات همسریابی خوش آمدید!**\n\n";
        $rulesText .= "🔸 قبل از شروع، لطفاً قوانین زیر را با دقت مطالعه کنید:\n\n";
        $rulesText .= "1. احترام متقابل به تمامی کاربران الزامی است.\n";
        $rulesText .= "2. ارسال محتوای نامناسب ممنوع است.\n";
        $rulesText .= "3. اطلاعات نادرست در پروفایل منجر به مسدودی می‌شود.\n";
        $rulesText .= "4. حفظ حریم خصوصی کاربران بسیار مهم است.\n";
        $rulesText .= "5. هرگونه سوءاستفاده از ربات پیگرد قانونی دارد.\n\n";
        $rulesText .= "با پذیرش قوانین، شما تمام موارد فوق را تأیید می‌کنید.";

        $keyboard = [
            'keyboard' => [
                [
                    ['text' => '✅ قوانین را می‌پذیرم']
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ];

        // آپدیت state کاربر
        $user->update(['state' => 'waiting_for_rules_acceptance']);

        $this->telegram->sendMessage($chatId, $rulesText, $keyboard);
    }

   private function acceptRules($chatId, $user)
{
    // آپدیت وضعیت کاربر
    $user->update([
        'rules_accepted' => 1,
        'state' => 'main_menu'
    ]);

    // 🔴 پردازش کد دعوت (اگر وجود دارد)
    if ($user->temp_invite_code) {
        error_log("🎯 پردازش کد دعوت در acceptRules: {$user->temp_invite_code}");
        $this->processInviteCodeAfterRules($user, $chatId);
    }

    // فراخوانی منوی اصلی
    $this->showMainMenu($user, $chatId);
}
    private function checkRules($user)
    {
        if (!$user->rules_accepted) {
            $this->showRules($user->telegram_id, $user);
            return false;
        }
        return true;
    }
  private function processInviteCode($user, $inviteCode)
{
    try {
        error_log("🔄 شروع پردازش کد دعوت: {$inviteCode} برای کاربر: " . ($user->id ?? 'null'));
        
        // 1. اطمینان از وجود کاربر در دیتابیس
        if (!$user->id) {
            error_log("❌ کاربر ID ندارد، ذخیره کاربر...");
            $user->save();
        }
        
        // 2. پیدا کردن دعوت‌کننده
        $pdo = $this->getPDO();
        $sql = "SELECT id, telegram_id, first_name FROM users WHERE invite_code = ? AND id != ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$inviteCode, $user->id]);
        $referrer = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$referrer) {
            error_log("❌ کد دعوت نامعتبر یا متعلق به خود کاربر است");
            return false;
        }
        
        error_log("✅ دعوت‌کننده پیدا شد: {$referrer['id']} - {$referrer['first_name']}");
        
        // 3. چک کردن که قبلاً دعوت نشده باشد
        $sql = "SELECT id FROM referrals WHERE referred_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user->id]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if ($existing) {
            error_log("⚠️ کاربر قبلاً دعوت شده است");
            return false;
        }
        
        // 4. ایجاد رکورد دعوت
        $sql = "INSERT INTO referrals (referrer_id, referred_id, invite_code, has_purchased, bonus_amount, created_at) 
                VALUES (?, ?, ?, 0, 0, NOW())";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$referrer['id'], $user->id, $inviteCode]);
        
        if (!$result) {
            error_log("❌ خطا در ایجاد رکورد دعوت");
            return false;
        }
        
        // 5. آپدیت فیلد referred_by در کاربر
        try {
            $sql = "UPDATE users SET referred_by = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$referrer['id'], $user->id]);
            error_log("✅ فیلد referred_by آپدیت شد");
        } catch (\Exception $e) {
            error_log("⚠️ خطا در آپدیت referred_by: " . $e->getMessage());
        }
        
        // 6. اطلاع‌رسانی به دعوت‌کننده
        $this->notifyReferrerAboutNewReferral($referrer, $user);
        
        error_log("🎉 پردازش کد دعوت با موفقیت انجام شد");
        return true;
        
    } catch (\Exception $e) {
        error_log("❌ خطا در processInviteCode: " . $e->getMessage());
        error_log("❌ Stack trace: " . $e->getTraceAsString());
        return false;
    }
}
private function notifyReferrerAboutNewReferral($referrer, $referredUser)
{
    try {
        $message = "🎉 **کاربر جدید با لینک دعوت شما ثبت‌نام کرد!**\n\n";
        $message .= "👤 کاربر: " . ($referredUser->first_name ?? 'ناشناس') . "\n";
        $message .= "📅 تاریخ: " . date('Y/m/d H:i') . "\n\n";
        $message .= "اگر این کاربر اولین شارژ را انجام دهد، 30٪ پاداش دریافت خواهید کرد.";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👥 مشاهده آمار دعوت', 'callback_data' => 'show_referral_stats']
                ]
            ]
        ];
        
        $this->telegram->sendMessage($referrer['telegram_id'], $message, $keyboard);
        
        error_log("✅ اطلاع‌رسانی به دعوت‌کننده ارسال شد: {$referrer['telegram_id']}");
        
    } catch (\Exception $e) {
        error_log("⚠️ خطا در ارسال اطلاع‌رسانی به دعوت‌کننده: " . $e->getMessage());
    }
}
private function processInviteCodeAfterRules($user, $chatId)
{
    try {
        $inviteCode = $user->temp_invite_code;
        
        if (!$inviteCode) {
            return;
        }
        
        error_log("🔄 پردازش کد دعوت بعد از پذیرش قوانین: {$inviteCode}");
        
        // بررسی که قبلاً پردازش نشده باشد
        if ($user->temp_invite_processed) {
            error_log("ℹ️ کد دعوت قبلاً پردازش شده است");
            $user->temp_invite_code = null;
            $user->save();
            return;
        }
        
        // پردازش کد دعوت
        $result = $this->processInviteCode($user, $inviteCode);
        
        if ($result) {
            // ارسال پیام تأیید به کاربر
            $this->telegram->sendMessage($chatId, "✅ با تشکر! شما با کد دعوت ثبت‌نام کردید.");
            
            // علامت‌گذاری به عنوان پردازش شده
            $user->temp_invite_processed = 1;
            $user->temp_invite_code = null;
            $user->save();
            
            error_log("✅ کد دعوت با موفقیت پردازش شد");
        } else {
            error_log("❌ پردازش کد دعوت ناموفق بود");
            $user->temp_invite_code = null;
            $user->save();
        }
        
    } catch (\Exception $e) {
        error_log("❌ خطا در پردازش کد دعوت بعد از قوانین: " . $e->getMessage());
        $user->temp_invite_code = null;
        $user->save();
    }
}
private function debugReferralInfo($user, $chatId)
{
    $message = "🔍 **اطلاعات دیباگ سیستم دعوت**\n\n";
    
    $message .= "👤 **اطلاعات کاربر شما:**\n";
    $message .= "• آیدی: {$user->id}\n";
    $message .= "• کد دعوت: " . ($user->invite_code ?? '❌ ندارد') . "\n";
    $message .= "• دعوت شده توسط: " . ($user->referred_by ?? '❌ هیچکس') . "\n\n";
    
    // بررسی رکوردهای referrals
    $pdo = $this->getPDO();
    
    // کسانی که شما دعوت کرده‌اید
    $sql = "SELECT COUNT(*) as count FROM referrals WHERE referrer_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user->id]);
    $result = $stmt->fetch(\PDO::FETCH_ASSOC);
    $message .= "📤 **شما دعوت کرده‌اید:** {$result['count']} نفر\n";
    
    // آیا شما دعوت شده‌اید؟
    $sql = "SELECT r.*, u.first_name, u.username 
            FROM referrals r 
            JOIN users u ON r.referrer_id = u.id 
            WHERE r.referred_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$user->id]);
    $referral = $stmt->fetch(\PDO::FETCH_ASSOC);
    
    if ($referral) {
        $message .= "📥 **شما دعوت شده‌اید توسط:**\n";
        $message .= "• نام: {$referral['first_name']}\n";
        $message .= "• کد دعوت: {$referral['invite_code']}\n";
        $message .= "• وضعیت: {$referral['status']}\n";
    } else {
        $message .= "📥 **شما دعوت نشده‌اید.**\n";
    }
    
    $this->telegram->sendMessage($chatId, $message);
}


    public function handleMessage($message)
    {
        $text = $message['text'] ?? '';
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $from = $message['from']; // اطلاعات کاربر

       // **استخراج کد دعوت از پیام /start**
    $inviteCode = null;
    if (strpos($text, '/start') === 0) {
        $parts = explode(' ', $text);
        if (count($parts) > 1) {
            $param = $parts[1];
            if (strpos($param, 'ref_') === 0) {
                $inviteCode = substr($param, 4); // حذف 'ref_'
            } else {
                $inviteCode = $param;
            }
            error_log("🔗 کد دعوت شناسایی شد: {$inviteCode}");
        }
    }

    // **اول کاربر را پیدا یا ایجاد کن**
    $user = User::where('telegram_id', $chatId)->first();
    if (!$user) {
        $user = $this->findOrCreateUser($from, $chatId);
    }

    if (!$user) {
        error_log("❌ خطا در ایجاد کاربر");
        return $this->sendMessage($chatId, "خطا در بارگذاری پروفایل. لطفاً دوباره تلاش کنید.");
    }

    // **ذخیره موقت کد دعوت در کاربر (اگر وجود دارد)**
    if ($inviteCode && !empty($inviteCode)) {
        // بررسی که کاربر خودش را دعوت نکرده باشد
        if (!$user->invite_code || $user->invite_code != $inviteCode) {
            // ذخیره موقت کد دعوت
            $user->temp_invite_code = $inviteCode;
            $user->save();
            error_log("💾 کد دعوت موقت ذخیره شد: {$inviteCode} برای کاربر {$user->id}");
        }
    }

    // **ادامه منطق قوانین**
    if (!$user->rules_accepted) {
        // اگر کاربر دستور /start داده یا در وضعیت شروع است
        if ($text === '/start' || $user->state === 'start') {
            return $this->showRules($chatId, $user);
        }

        // اگر کاربر در وضعیت انتظار پذیرش قوانین است و دکمه را زده
        if ($user->state === 'waiting_for_rules_acceptance' && $text === '✅ قوانین را می‌پذیرم') {
            return $this->acceptRules($chatId, $user);
        }

        // برای هر پیام دیگر، دوباره قوانین را نشان بده
        return $this->showRules($chatId, $user);
    }

    // **بعد از پذیرش قوانین، پردازش کد دعوت انجام شود**
    if ($user->temp_invite_code && $user->rules_accepted) {
        $this->processInviteCodeAfterRules($user, $chatId);
    }



        error_log("📝 handleMessage - User state: " . $user->state . " | Text: " . $text);

        //  برای شارژکیف  پول روش جدید 
        if ($user->state === 'selecting_charge_amount') {
            $this->handleChargeAmountSelection($user, $chatId, $text);
            return;
        }

        if (strpos($user->state, 'confirming_charge:') === 0) {
            $this->handleChargeConfirmation($user, $chatId, $text);
            return;
        }

        // 🔴 دستورات مربوط به اشتراک
        if ($text === '💎 اشتراک من') {
            $this->showMySubscription($user, $chatId);
            return;
        }

        if ($text === '💎 مشاهده پلن‌ها') {
            $this->handleSubscription($user, $chatId);
            return;
        }

        if ($text === '🔄 تمدید اشتراک') {
            $this->handleSubscription($user, $chatId);
            return;
        }

        if ($user->state === 'selecting_subscription_plan') {
             if ($text === '🔙 بازگشت' || $text === '🔙 بازگشت به منوی اصلی') {
            $user->update(['state' => 'main_menu']);
            $this->showMainMenu($user, $chatId);
            return;
        }
            $this->handleSubscriptionPlanSelection($user, $chatId, $text);
            return;
        }

        if (strpos($user->state, 'confirming_subscription:') === 0) {
            $this->handleSubscriptionConfirmation($user, $chatId, $text);
            return;
        }

        // 🔴 مدیریت stateهای ویرایش پروفایل
        if (str_starts_with($user->state, 'editing_')) {
            error_log("🔄 User is editing profile field: " . $user->state);
            $this->handleProfileFieldInput($text, $user, $chatId);
            return;
        }

        // 🔴 مدیریت state آپلود عکس
        if ($user->state === 'awaiting_photo') {
            error_log("🔄 User is awaiting photo");

            if (isset($message['photo'])) {
                error_log("📸 Photo received, processing...");
                $this->handleProfilePhotoUpload($user, $chatId, $message['photo']);
            } else {
                error_log("❌ User in awaiting_photo state but sent text: " . $text);

                // اگر کاربر روی "بازگشت" کلیک کرد
                if ($text === '🔙 بازگشت به منوی اصلی') {
                    $user->update(['state' => 'main_menu']);
                    $this->showMainMenu($user, $chatId);
                } else {
                    $this->telegram->sendMessage($chatId, "❌ لطفاً یک عکس ارسال کنید یا از دکمه بازگشت استفاده کنید.");
                    $this->requestProfilePhoto($user, $chatId);
                }
            }
            return;
        }

        // در handleMessage - بخش stateها
        // 🔴 اول stateهای خاص را بررسی کن - اما فقط برای دستورات مرتبط
        if ($user->state === 'awaiting_deactivation_reason') {
            // فقط اگر متن مربوط به غیرفعال سازی است پردازش کن
            if (str_starts_with($text, '⏸️ غیرفعال سازی موقت - ') || $text === '🔙 بازگشت به منوی اصلی') {
                if ($text === '🔙 بازگشت به منوی اصلی') {
                    $this->updateUserState($user->telegram_id, 'main_menu');
                    $this->showMainMenu($user, $chatId);
                    return;
                }

                $reason = str_replace('⏸️ غیرفعال سازی موقت - ', '', $text);
                $this->handleDeactivationConfirmation($user, $chatId, $reason);
                return;
            }
            // اگر متن غیرمرتبط است، state را ریست کن و به منوی اصلی برگرد
            else {
                $this->updateUserState($user->telegram_id, 'main_menu');
                $this->showMainMenu($user, $chatId);
                return;
            }
        }



        // اگر state دیگری دارید، آنها را اینجا بررسی کنید
        // if ($user->state === 'awaiting_something_else') { ... }
        switch ($text) {

            case '/debug_referral':
    $this->debugReferralInfo($user, $chatId);
    break;

            // ربوط به مدیریتد درخواست ها 
            case '📬 مدیریت درخواست‌ها':
            case str_contains($text, 'مدیریت درخواست‌ها'):
                $this->showMyContactRequests($user, $chatId);
                break;

            case '📥 درخواست‌های ورودی':
            case str_contains($text, 'درخواست‌های ورودی'):
                $this->showIncomingRequestsList($user, $chatId, 1);
                break;

            case '📤 درخواست‌های ارسالی':
            case str_contains($text, 'درخواست‌های ارسالی'):
                $this->showOutgoingRequestsList($user, $chatId, 1); // مشابه incoming پیاده‌سازی کنید
                break;

            // در handleMessage اضافه کنید:
            case '/resetstate':
                $this->forceResetState($user, $chatId);
                break;
            case '/start':
                $this->showMainMenu($user, $chatId);
                break;
            case '/admin':
                $this->showAdminPanelWithNotification($user, $chatId);
                break;

            case '**پنل مدیریت**':
                $this->showAdminPanelWithNotification($user, $chatId);
                break;

            case '📜 تاریخچه درخواست‌ها':
                $this->showContactHistory($user, $chatId);
                break;
            case '📜 بازگشت به تاریخچه':
                error_log("📜 Returning to contact history");
                $this->showContactHistory($user, $chatId);
                break;
            // در متد handleMessage
            case str_starts_with($text, '👤 '):
                $this->handleContactHistoryUserClick($user, $chatId, $text);
                break;

            case str_starts_with($text, '📦 '):
                $this->handlePlanSelection($user, $chatId, $text);
                break;

            case '⚙️ تنظیمات':
                $this->showSettingsMenu($user, $chatId);
                break;
            // در handleMessage در BotCore.php - بخش switch/case
            case '⏸️ غیرفعال سازی موقت':
                $this->handleDeactivateRequest($user, $chatId);
                break;

            case '▶️ فعال سازی حساب':
                $this->handleActivateRequest($user, $chatId);
                break;
            case '👥 سیستم دعوت و پاداش':
                $this->handleReferral($user, $chatId);
                break;
            case '📋 کپی لینک دعوت':
                $this->handleCopyInviteLink($user, $chatId);
                break;

            case '📤 اشتراک‌گذاری لینک':
                $this->handleShareInviteLink($user, $chatId);
                break;

            case '🔄 بروزرسانی آمار':
                $this->handleReferral($user, $chatId); // فراخوانی مجدد همان متد
                break;
            case 'ℹ️ راهنمای استفاده':
             case 'ℹ️ راهنما':    
                $this->handleHelp($chatId);
                break;
            case '📊 پروفایل من':
                $this->showProfile($user, $chatId);
                break;
            case '🔙 بازگشت به پروفایل':
                $this->showProfile($user, $chatId);
                break;
            case '📊مدیریت  پروفایل  ':
                $this->showProfileMenu($user, $chatId);
                break;
            case '✏️ ویرایش پروفایل':
                $this->startProfileEdit($user, $chatId);
                break;





            case '💼 کیف پول':
                error_log("💼 Calling handleWallet");
                $this->handleWallet($user, $chatId);
                break;
            case '🔙 بازگشت به کیف پول':
                error_log("💼 Calling handleWallet");
                $this->handleWallet($user, $chatId);
                break;
            case '💰 شارژ کیف پول':
                $this->handleCharge($user, $chatId);
                break;

            case '📋 تاریخچه تراکنش‌ها':
                $this->handleTransactions($user, $chatId);
                break;



            case '🔙 بازگشت':
                $this->showMainMenu($user, $chatId);
                break;
            case '🔙 بازگشت به منوی اصلی':
                error_log("🔙 Returning to main menu");
                $user->update(['state' => 'main_menu']);
                $this->showMainMenu($user, $chatId);
                break;
            case '🔙 منوی اصلی':
                error_log("🔙 Returning to main menu from contact info");
                $this->showMainMenu($user, $chatId);
                break;
            default:

            case '📸 آپلود عکس پروفایل':
                error_log("📸 Requesting profile photo upload");
                $this->requestProfilePhoto($user, $chatId);
                break;
            case '🔄 تغییر عکس پروفایل':
                error_log("📸 Requesting profile photo upload");
                $this->requestProfilePhoto($user, $chatId);
                break;

            case '🔙 انصراف':
                error_log("🔙 Cancelling photo upload");
                $this->showProfile($user, $chatId);
                break;

            case '💌 دریافت پیشنهاد':
                error_log("💡 Calling handleGetSuggestion");
                $this->handleGetSuggestion($user, $chatId);
                break;
            // در متد handleMessage - برای دکمه "📞 درخواست اطلاعات تماس"
            case '📞 درخواست اطلاعات تماس':
                error_log("📞 Contact request button clicked - looking for suggested user ID");

                // پیدا کردن آخرین suggested_user_id از state یا آخرین پیشنهاد
                $suggestedUserId = $this->findSuggestedUserId($user, $chatId);

                if ($suggestedUserId) {
                    error_log("🎯 Found suggested user ID: {$suggestedUserId}");
                    $this->handleContactRequest($user, $chatId, $suggestedUserId);
                } else {
                    error_log("❌ No suggested user ID found");
                    $this->telegram->sendMessage(
                        $chatId,
                        "❌ کاربری برای درخواست تماس یافت نشد.\nلطفاً ابتدا از قسمت '💌 دریافت پیشنهاد' یک کاربر را مشاهده کنید."
                    );
                    $this->showMainMenu($user, $chatId);
                }
                break;
            case '💌 پیشنهاد بعدی':
                error_log("🔄 Calling handleGetSuggestion for next suggestion");
                $this->handleGetSuggestion($user, $chatId);
                break;

            case '⚙️ تنظیم فیلترها':
                error_log("⚙️ Calling showFilterSettings");
                $this->handleEditFilters($user, $chatId);
                break;


            // در handleMessage - برای دکمه‌های معمولی تاریخچه
            case '💌 پیشنهاد جدید':
                error_log("💌 Calling handleGetSuggestion from history");
                $this->handleGetSuggestion($user, $chatId);
                break;

            case ($text == '👨‍💼 ارتباط با پشتیبانی');
                $this->connectToSupport($chatId);
                break;


            case ($text == '🏠 منوی اصلی');
                $this->showmainmenu($user, $chatId);
                break;
        }
    }
    public function processCallbackQuery($callbackQuery)
    {
        PerformanceMonitor::start('callback_' . $callbackQuery['data']);
        $chatId = $callbackQuery['message']['chat']['id'];
        $data = $callbackQuery['data'];
        $messageId = $callbackQuery['message']['message_id'];
        $from = $callbackQuery['from'];

        echo "🔄 Callback: $data from: {$from['first_name']}\n";

        $user = $this->findOrCreateUser($from, $chatId);

        // در قسمت پردازش callback_query:


        if (strpos($data, 'like:') === 0) {
            $targetUserId = str_replace('like:', '', $data);
            $this->handleLikeAction($user, $targetUserId, $callbackQuery);
        } elseif ($data === 'already_liked') {
            $this->telegram->answerCallbackQuery($callbackQuery['id'], [
                'text' => 'شما قبلاً این کاربر را لایک کرده‌اید!',
                'show_alert' => false
            ]);
        } elseif (strpos($data, 'view_liker:') === 0) {
            $likerId = str_replace('view_liker:', '', $data);
            $this->showLikerProfile($user, $chatId, $likerId);
        }

        //مربوط به درخواست اطلاعات با تایید 
        elseif (strpos($data, 'view_requester_profile:') === 0) {
            $parts = explode(':', $data); // استفاده از $data
            if (count($parts) >= 3) {
                $requesterId = $parts[1];
                $requestId = $parts[2];
                $this->showRequesterProfileForRequest($user, $chatId, $requesterId, $requestId);
            } else {
                error_log("Invalid view_requester_profile callback: " . $data);
            }
            return;
        } elseif (strpos($data, 'approve_contact:') === 0) {
            $requestId = str_replace('approve_contact:', '', $data); // استفاده از $data
            $this->approveContactRequest($user, $chatId, $requestId);
            return;
        } elseif (strpos($data, 'reject_contact:') === 0) {
            $requestId = str_replace('reject_contact:', '', $data); // استفاده از $data
            $this->rejectContactRequest($user, $chatId, $requestId);
            return;
        } elseif ($data === 'waiting_for_subscription') {
            $this->telegram->answerCallbackQuery($chatId, [
                'text' => "⏳ کاربر مورد نظر در حال حاضر اشتراک فعال ندارد. باید منتظر بمانید تا اشتراک تهیه کند.",
                'show_alert' => true
            ]);
            return;
        }


        // پردازش کلیه callback data ها
        switch ($data) {
            //  منوی اصلی
            case 'main_menu':
                $this->showMainMenu($user, $chatId);
                break;
            case 'profile':
                $this->handleProfileCommand($user, $chatId);
                break;

            case 'search':
                $this->handleSearch($user, $chatId);
                break;
            case 'help':
                $this->handleHelp($chatId);
                break;


            // منوی پروفایل - سیستم جدید
            case 'profile_edit_start':
                $this->startProfileEdit($user, $chatId);
                break;

            case 'back_to_profile_menu':
                $this->showProfile($user, $chatId);
                break;
            case 'profile_status':
                $this->showProfileStatus($user, $chatId);
                break;
            case 'profile_next_field':
                $this->handleNextField($user, $chatId);
                break;
            case 'profile_prev_field':
                $this->handlePrevField($user, $chatId);
                break;
            case 'profile_skip_field':
                $this->handleSkipField($user, $chatId);
                break;
            case 'profile_save_exit':
                $this->handleProfileSave($user, $chatId);
                break;
            case 'profile_cancel':
                $this->showMainMenu($user, $chatId);
                break;

            // دیباگ و مدیریت فیلدها
            case 'debug_sync_fields':
                $this->handleSyncFields($user, $chatId);
                break;
            case 'auto_fix_fields':
                $this->handleAutoFixFields($user, $chatId);
                break;


            case 'back_to_profile':
                $this->handleProfileCommand($user, $chatId);
                break;

            case 'debug_select':
                $this->debugSelectFields($user, $chatId);
                break;
            //  پنل مدیریتی 
            case 'admin_panel':
                $this->showAdminPanelWithNotification($user, $chatId);
                break;
            case 'admin_sync_fields':
                $this->adminSyncFields($user, $chatId);
                break;
            case 'admin_list_fields':
                $this->adminListFields($user, $chatId);
                break;
            case 'admin_manage_fields':
                $this->adminManageFields($user, $chatId);
                break;
            case 'field_panel':
                $this->showAdminfieldPanel($user, $chatId);
                break;
            case 'payment_management':
                $this->showPaymentManagement($user, $chatId);
                break;

            case 'view_pending_payments':
                $this->showPendingPayments($user, $chatId);
                break;
            case 'admin_optimize_db':
                $this->optimizeDatabaseManual($user, $chatId);
                break;



            case str_starts_with($data, 'select_plan:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2 && is_numeric($parts[1])) {
                    $planId = intval($parts[1]);
                    $this->handlePlanSelection($user, $chatId, $planId);
                }
                break;

            case str_starts_with($data, 'confirm_payment:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2 && is_numeric($parts[1])) {
                    $planId = intval($parts[1]);
                    $this->handlePaymentConfirmation($user, $chatId, $planId);
                }
                break;

            case str_starts_with($data, 'approve_payment:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2 && is_numeric($parts[1])) {
                    $paymentId = intval($parts[1]);
                    $this->approvePayment($user, $chatId, $paymentId);
                }
                break;

            case str_starts_with($data, 'reject_payment:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2 && is_numeric($parts[1])) {
                    $paymentId = intval($parts[1]);
                    $this->rejectPayment($user, $chatId, $paymentId);
                }
                break;

            // مدیریت پلن‌ها و سایر موارد را می‌توانید بعداً اضافه کنید
            case 'manage_subscription_plans':
                $this->telegram->sendMessage($chatId, "⚙️ این بخش به زودی اضافه خواهد شد...");
                break;

            case 'set_card_number':
                $this->telegram->sendMessage($chatId, "💳 این بخش به زودی اضافه خواهد شد...");
                break;

            case 'payment_reports':
                $this->telegram->sendMessage($chatId, "📈 این بخش به زودی اضافه خواهد شد...");
                break;


            //اضافه کردن  فیلد در بخش مدیریت 

            case str_starts_with($data, 'admin_toggle_field:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2 && is_numeric($parts[1])) {
                    $fieldId = intval($parts[1]);
                    $this->adminToggleField($user, $chatId, $fieldId);
                }
                break;

            case 'admin_add_field':
                $this->adminAddField($user, $chatId);
                break;

            case str_starts_with($data, 'admin_add_field_type:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2) {
                    $fieldType = $parts[1];
                    $this->adminAddFieldStep1($user, $chatId, $fieldType);
                }
                break;

            case 'admin_add_field_cancel':
                $this->adminAddFieldCancel($user, $chatId);
                break;

            case str_starts_with($data, 'admin_add_field_required:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2) {
                    $isRequired = $parts[1];
                    $this->adminAddFieldFinalize($user, $chatId, $isRequired);
                }
                break;

            case 'admin_manage_hidden_fields':
                $this->adminManageHiddenFields($user, $chatId);
                break;

            case str_starts_with($data, 'admin_toggle_hidden:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2 && is_numeric($parts[1])) {
                    $fieldId = intval($parts[1]);
                    $this->adminToggleHiddenField($user, $chatId, $fieldId);
                }
                break;

            // بخش  پیشنهاد ات 
            case 'get_suggestion':
                $this->handleGetSuggestion($user, $chatId);
                break;

            case 'debug_field_options':
                $this->debugFieldOptions($user, $chatId);
                break;

            case str_starts_with($data, 'request_contact:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2 && is_numeric($parts[1])) {
                    $suggestedUserId = intval($parts[1]);
                    $this->handleContactRequest($user, $chatId, $suggestedUserId);
                }
                break;

            case str_starts_with($data, 'contact_history_view:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2 && is_numeric($parts[1])) {
                    $requestedUserId = intval($parts[1]);
                    $this->showContactInfo($user, $chatId, $requestedUserId, 0);
                }




            case 'debug_users':
                $this->debugUsersStatus($user, $chatId);
                break;
            // case str_starts_with($data, 'confirm_contact_request:'):
            //     $parts = explode(':', $data);
            //     if (count($parts) >= 2 && is_numeric($parts[1])) {
            //         $suggestedUserId = intval($parts[1]);
            //         $this->processContactPayment($user, $chatId, $suggestedUserId);
            //     }
            //     break;

            case 'cancel_contact_request':
                $this->telegram->sendMessage($chatId, "❌ درخواست اطلاعات تماس لغو شد.");
                $this->showMainMenu($user, $chatId);
                break;

            //بخش فیلتر ها 
            case 'edit_filters':
                $this->handleEditFilters($user, $chatId);
                break;

            case str_starts_with($data, 'edit_filter:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2) {
                    $fieldName = $parts[1];
                    $this->editUserFilter($user, $chatId, $fieldName);
                }
                break;

            case 'reset_filters':
                $this->resetUserFilters($user, $chatId);
                break;

            case 'save_filters':
                $this->saveUserFilters($user, $chatId);
                break;
            case str_starts_with($data, 'set_filter_value:'):
                $parts = explode(':', $data);
                if (count($parts) >= 3) {
                    $fieldName = $parts[1];
                    $value = $parts[2];
                    $this->setFilterValue($user, $chatId, $fieldName, $value);
                }
                break;
            // 🔴 caseهای جدید برای مدیریت فیلترها
            case 'admin_filters_management':
                $this->showAdminFiltersManagement($user, $chatId);
                break;

            case 'admin_view_filters':
                $this->adminViewFilters($user, $chatId);
                break;

            case 'admin_configure_filters':
                $this->adminConfigureFilters($user, $chatId);
                break;

            case 'admin_auto_sync_filters':
                $this->adminAutoSyncFilters($user, $chatId);
                break;

            case 'admin_manage_cities':
                $this->adminManageCities($user, $chatId);
                break;

            case 'admin_add_city':
                $this->adminAddCity($user, $chatId);
                break;

            case 'admin_delete_city':
                $this->adminDeleteCity($user, $chatId);
                break;

            case 'admin_load_default_cities':
                $this->adminLoadDefaultCities($user, $chatId);
                break;
            case str_starts_with($data, 'add_city:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2) {
                    $cityName = $parts[1];
                    $this->addCityToFilter($user, $chatId, $cityName);
                }
                break;

            case str_starts_with($data, 'remove_city:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2) {
                    $cityName = $parts[1];
                    $this->removeCityFromFilter($user, $chatId, $cityName);
                }
                break;

            case 'save_cities_selection':
                $this->saveCitiesSelection($user, $chatId);
                break;

            case 'reset_cities':
                $this->resetCitiesFilter($user, $chatId);
                break;
            case 'settings':
                $this->showSettingsMenu($user, $chatId);
                break;


            case 'test_filters':
                $this->testFilterSystem($user, $chatId);
                break;
            case 'debug_filters':
                $this->debugFilterSystem($user, $chatId);
                break;


            case 'update_gender_filter':
                $this->updateGenderFilter($user, $chatId);
                break;
            case 'fix_filter_issues':
                $this->fixAllFilterIssues($user, $chatId);
                break;
            case 'debug_filter_logic':
                $this->debugFilterLogic($user, $chatId);
                break;
            //   متد مانیتورینگ  مدیریت 
            case 'performance_report':
                $this->showPerformanceReport($user, $chatId);
                break;

            case 'detailed_performance':
                $this->showDetailedPerformance($user, $chatId);
                break;
            //  مربوط به کد دعوت 
            case 'copy_invite_link':
                $this->handleCopyInviteLink($user, $chatId);
                break;
            case 'share_invite_link':
                $this->handleShareInviteLink($user, $chatId);
                break;
            case 'generate_all_invite_codes':
                $this->generateInviteCodesForAllUsers($user, $chatId);
                break;

            //موقت برای دیباگ فیلتر کاربر 
            case 'debug_current_filters':
                $this->debugCurrentFilterIssue($user, $chatId);
                break;
            case 'fix_gender_data':
                $this->fixGenderFilterLogic($user, $chatId);
                break;
            // case 'manage_photos':
            //     $this->showPhotoManagementMenu($user, $chatId);
            //     break;

            case 'managing_photos':
                // در message handler ها $text از پیام کاربر گرفته می‌شود
                $text = $update['message']['text'] ?? '';
                return $this->handlePhotoManagement($text, $user, $chatId);

            case 'selecting_main_photo':
            case 'upload_first_photo':
            case 'upload_new_photo':
                echo "🔧 Setting user state to uploading_additional_photo\n";
                $this->sendMessage($chatId, "لطفاً عکس مورد نظر را ارسال کنید:");
                $this->updateUserState($user->telegram_id, 'uploading_additional_photo');

                // دیباگ: بررسی state بعد از تنظیم
                $updatedUser = $this->findUserByTelegramId($user->telegram_id);
                echo "🔍 User state after update: " . ($updatedUser->state ?? 'NOT SET') . "\n";
                break;

            case 'upload_more_photos':
                $this->sendMessage($chatId, "لطفاً عکس بعدی را ارسال کنید:");
                $this->updateUserState($user->telegram_id, 'uploading_additional_photo');
                break;

            case 'select_main_photo_menu':
                $this->sendMessage($chatId, "🔧 این قابلیت به زودی اضافه خواهد شد...");
                // $this->showMainPhotoSelection($user, $chatId);
                break;

            case 'view_all_photos':
                $this->sendMessage($chatId, "🔧 این قابلیت به زودی اضافه خواهد شد...");
                // $this->showUserPhotos($user, $chatId);
                break;

            case 'back_to_main_from_photos':
                $this->showMainMenu($user, $chatId);
                break;
            // منوی کیف پول
            case 'wallet_charge':
                $this->handleCharge($user, $chatId);
                break;
            case str_starts_with($data, 'view_incoming:'):
                $requestId = explode(':', $data)[1];
                $this->showIncomingRequestDetail($user, $chatId, $requestId, $messageId);
                break;

            case str_starts_with($data, 'incoming_page:'):
                $page = explode(':', $data)[1];
                $this->showIncomingRequestsList($user, $chatId, $page);
                break;

            case str_starts_with($data, 'approve_request:'):
                $requestId = explode(':', $data)[1];
                $this->approveContactRequest($user, $chatId, $requestId, $messageId);
                break;

            case str_starts_with($data, 'reject_request:'):
                $requestId = explode(':', $data)[1];
                $this->rejectContactRequest($user, $chatId, $requestId, $messageId);
                break;

            // case str_starts_with($data, 'view_profile:'):
            //     $userId = explode(':', $data)[1];
            //     $this->showUserProfileForRequest($user, $chatId, $userId, $messageId);
            //     break;

            case str_starts_with($data, 'next_incoming:'):
                $requestId = explode(':', $data)[1];
                $this->showNextIncomingRequest($user, $chatId, $requestId, $messageId);
                break;

            case str_starts_with($data, 'prev_incoming:'):
                $requestId = explode(':', $data)[1];
                $this->showPrevIncomingRequest($user, $chatId, $requestId, $messageId);
                break;

            case $data === 'back_to_incoming_list':
                $this->showIncomingRequestsList($user, $chatId, 1);
                break;

            case $data === 'back_to_requests_menu':
                $this->showMyContactRequests($user, $chatId);
                break;

            case str_starts_with($data, 'view_contact_info:'):
                $parts = explode(':', $data);
                if (count($parts) >= 3) {
                    $requestedUserId = $parts[1];
                    $requestId = $parts[2];
                    $this->showContactInfo($user, $chatId, $requestedUserId, $requestId);
                }
                break;

            case 'subscription':
                $this->handleSubscription($user, $chatId);
                break;
        }

        $this->telegram->answerCallbackQuery($callbackQuery['id']);
        PerformanceMonitor::start('callback_' . $callbackQuery['data']);
    }

    private function optimizeDatabaseManual($user, $chatId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        $this->telegram->sendMessage($chatId, "🔄 در حال بهینه‌سازی دیتابیس...");

        // ریست flag برای اجرای مجدد
        self::$databaseOptimized = false;
        $this->optimizeDatabase();

        $this->telegram->sendMessage($chatId, "✅ بهینه‌سازی دیتابیس تکمیل شد!");
    }

    // ==================== متدهای جدید برای مدیریت فیلدها ====================
    private function handleSyncFields($user, $chatId)
    {
        $missingFields = $this->syncProfileFields();

        $message = "🔍 **بررسی هماهنگی فیلدها**\n\n";

        if (empty($missingFields)) {
            $message .= "✅ همه فیلدها در مدل User و دیتابیس وجود دارند";
        } else {
            $message .= "❌ فیلدهای missing:\n";
            foreach ($missingFields as $field) {
                $message .= "• `{$field}`\n";
            }
            $message .= "\nبرای رفع خودکار روی 'تعمیر خودکار' کلیک کنید";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🔧 تعمیر خودکار', 'callback_data' => 'auto_fix_fields']
                    ],
                    [
                        ['text' => '🔙 بازگشت', 'callback_data' => 'back_to_profile_menu']
                    ]
                ]
            ];

            $this->telegram->sendMessage($chatId, $message, $keyboard);
            return;
        }

        $this->telegram->sendMessage($chatId, $message);
    }

    private function handleAutoFixFields($user, $chatId)
    {
        $result = $this->autoAddMissingFields();
        $this->telegram->sendMessage($chatId, $result);

        // برگشت به منوی پروفایل بعد از 2 ثانیه
        sleep(2);
        $this->handleProfileCommand($user, $chatId);
    }

    private function addCityToFilter($user, $chatId, $cityName)
    {
        $userFilters = UserFilter::getFilters($user->id);
        $currentCities = isset($userFilters['city']) ? $userFilters['city'] : [];

        if (!is_array($currentCities)) {
            $currentCities = ($currentCities !== '') ? [$currentCities] : [];
        }

        // اضافه کردن شهر اگر وجود ندارد
        if (!in_array($cityName, $currentCities)) {
            $currentCities[] = $cityName;
        }

        $userFilters['city'] = $currentCities;
        UserFilter::saveFilters($user->id, $userFilters);

        // بازگشت به صفحه ویرایش فیلتر شهر
        $this->editUserFilter($user, $chatId, 'city');
    }

    private function removeCityFromFilter($user, $chatId, $cityName)
    {
        $userFilters = UserFilter::getFilters($user->id);
        $currentCities = isset($userFilters['city']) ? $userFilters['city'] : [];

        if (!is_array($currentCities)) {
            $currentCities = ($currentCities !== '') ? [$currentCities] : [];
        }

        // حذف شهر اگر وجود دارد
        $currentCities = array_filter($currentCities, function ($city) use ($cityName) {
            return $city !== $cityName;
        });

        $userFilters['city'] = array_values($currentCities); // بازسازی اندیس‌ها
        UserFilter::saveFilters($user->id, $userFilters);

        // بازگشت به صفحه ویرایش فیلتر شهر
        $this->editUserFilter($user, $chatId, 'city');
    }

    private function saveCitiesSelection($user, $chatId)
    {
        $userFilters = UserFilter::getFilters($user->id);
        $selectedCities = isset($userFilters['city']) ? $userFilters['city'] : [];

        if (!empty($selectedCities) && is_array($selectedCities)) {
            $message = "✅ **شهرهای انتخاب شده ذخیره شدند**\n\n";
            $message .= "🏙️ شهرهای انتخابی شما:\n";

            foreach ($selectedCities as $city) {
                $message .= "• {$city}\n";
            }

            $message .= "\nاکنون فقط افرادی از این شهرها به شما پیشنهاد داده می‌شوند.";
        } else {
            $message = "ℹ️ **هیچ شهری انتخاب نشده است**\n\n";
            $message .= "در حال حاضر افراد از تمام شهرها به شما پیشنهاد داده می‌شوند.";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⚙️ ادامه تنظیم فیلترها', 'callback_data' => 'edit_filters'],
                    ['text' => '💾 ذخیره همه تنظیمات', 'callback_data' => 'save_filters']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function resetCitiesFilter($user, $chatId)
    {
        $userFilters = UserFilter::getFilters($user->id);
        $userFilters['city'] = [];
        UserFilter::saveFilters($user->id, $userFilters);

        $message = "🔄 **فیلتر شهرها بازنشانی شد**\n\n";
        $message .= "همه شهرهای انتخابی حذف شدند. اکنون افراد از تمام شهرها به شما پیشنهاد داده می‌شوند.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🏙️ انتخاب شهرها', 'callback_data' => 'edit_filter:city'],
                    ['text' => '🔙 بازگشت', 'callback_data' => 'edit_filters']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function syncProfileFields()
    {
        try {
            $activeFields = ProfileField::getActiveFields();

            // خواندن فیلدهای users با روش مطمئن‌تر
            $pdo = $this->getPDO();
            $stmt = $pdo->query("SHOW COLUMNS FROM users");
            $columns = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $existingColumnNames = array_column($columns, 'Field');

            error_log("🔍 فیلدهای موجود در users: " . implode(', ', $existingColumnNames));

            // 🔴 اصلاح: استفاده از array_map به جای pluck
            $activeFieldNames = array_map(function ($field) {
                return $field->field_name;
            }, $activeFields);
            error_log("🔍 فیلدهای فعال در profile_fields: " . implode(', ', $activeFieldNames));

            $missingFields = [];

            foreach ($activeFields as $field) {
                error_log("🔍 بررسی فیلد: {$field->field_name}");

                if (!in_array($field->field_name, $existingColumnNames)) {
                    $missingFields[] = $field->field_name;
                    error_log("❌ فیلد missing: {$field->field_name}");
                } else {
                    error_log("✅ فیلد موجود: {$field->field_name}");
                }
            }

            error_log("📋 فیلدهای missing: " . implode(', ', $missingFields));

            return $missingFields;
        } catch (\Exception $e) {
            error_log("❌ خطا در syncProfileFields: " . $e->getMessage());
            return [];
        }
    }

    private function autoAddMissingFields()
    {
        $missingFields = $this->syncProfileFields();

        if (empty($missingFields)) {
            return "✅ همه فیلدها در جدول users وجود دارند";
        }

        try {
            $addedFields = [];

            foreach ($missingFields as $fieldName) {
                $field = ProfileField::whereFieldName($fieldName);
                if ($field) {
                    $result = $this->addFieldToUsersTable($field);
                    if ($result) {
                        $addedFields[] = $fieldName;
                        error_log("✅ فیلد {$fieldName} به users اضافه شد");
                    }
                }
            }

            if (empty($addedFields)) {
                return "⚠️ هیچ فیلدی اضافه نشد. ممکن است از قبل وجود داشته باشند.";
            }

            return "✅ فیلدهای زیر به جدول users اضافه شدند:\n" . implode(', ', $addedFields);
        } catch (\Exception $e) {
            error_log("❌ خطا در autoAddMissingFields: " . $e->getMessage());
            return "❌ خطا در اضافه کردن فیلدها: " . $e->getMessage();
        }
    }

    private function getPDO()
    {
        static $pdo = null;
        if ($pdo === null) {
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $dbname = $_ENV['DB_NAME'] ?? 'dating_system';
            $username = $_ENV['DB_USER'] ?? 'root';
            $password = $_ENV['DB_PASS'] ?? '';

            $pdo = new \PDO("mysql:host=$host;dbname=$dbname", $username, $password);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        return $pdo;
    }


    private function addFieldToUsersTable($field)
    {
        try {
            $fieldType = $this->getSQLType($field->field_type);

            // چک کردن وجود ستون قبل از اضافه کردن
            $existingColumns = \Illuminate\Support\Facades\DB::select("SHOW COLUMNS FROM users");
            $existingColumnNames = array_column($existingColumns, 'Field');

            if (in_array($field->field_name, $existingColumnNames)) {
                error_log("⚠️ فیلد {$field->field_name} از قبل در users وجود دارد");
                return true;
            }

            // اضافه کردن ستون به جدول users
            \Illuminate\Support\Facades\DB::statement(
                "ALTER TABLE users ADD COLUMN `{$field->field_name}` {$fieldType}"
            );

            error_log("✅ فیلد {$field->field_name} با نوع {$fieldType} به users اضافه شد");
            return true;
        } catch (\Exception $e) {
            error_log("❌ خطا در اضافه کردن فیلد {$field->field_name} به users: " . $e->getMessage());
            return false;
        }
    }

    private function getFieldType($fieldType)
    {
        $types = [
            'text' => 'VARCHAR(255) NULL',
            'number' => 'INT NULL',
            'select' => 'VARCHAR(255) NULL',
            'textarea' => 'TEXT NULL'

        ];

        return $types[$fieldType] ?? 'VARCHAR(255) NULL';
    }

    // ==================== منوی اصلی ====================
   private function showMainMenu($user, $chatId)
{
    $counts = $this->getContactRequestCounts($user->id);

    $contactRequestText = '📬 مدیریت درخواست‌ها';
    $notificationText = '';

    if ($counts['incoming_pending'] > 0) {
        $notificationText .= " ({$counts['incoming_pending']}📥)";
    }
    if ($counts['outgoing_pending'] > 0) {
        $notificationText .= " ({$counts['outgoing_pending']}✅)";
    }

    $contactRequestText .= $notificationText;

    $wallet = $user->getWallet();
    // $cost = $this->getContactRequestCost();

    // بررسی دقیق وضعیت پروفایل
    $actualCompletion = $this->checkProfileCompletion($user);
    $completionPercent = $this->calculateProfileCompletion($user);

    // اگر وضعیت در دیتابیس با واقعیت تطابق ندارد، آپدیت کن
    if ($user->is_profile_completed != $actualCompletion) {
        $user->update(['is_profile_completed' => $actualCompletion]);
    }

    $statusText = $user->is_active ? '🟢 فعال' : '🔴 غیرفعال';

    // 🔴 دریافت آمار لایک‌ها
    $receivedLikes = \App\Models\Like::getReceivedCount($user->id);
    $mutualLikes = \App\Models\Like::getMutualCount($user->id);

    $message = "🎯 **منوی اصلی ربات همسر یابی**\n\n";
    $message .= "👤 کاربر: " . $user->first_name . "\n";
    $message .= "💰 موجودی: " . number_format($wallet->balance) . " تومان\n";
    $message .= "📊 وضعیت پروفایل: " . ($actualCompletion ? "✅ تکمیل شده" : "❌ ناقص ({$completionPercent}%)") . "\n\n";
    $message .= "📱 وضعیت حساب: {$statusText}\n\n";

    // 🔴 اضافه کردن وضعیت اشتراک
    $subscription = $user->getActiveSubscription();
    if ($subscription) {
        $daysRemaining = $subscription->daysRemaining();
        $message .= "💎 اشتراک: {$subscription->plan->name}\n";
        $message .= "⏳ باقی‌مانده: {$daysRemaining} روز\n";
    } else {
        $message .= "🔴 اشتراک: فعال نیست\n";
    }

    // 🔴 اضافه کردن آمار لایک‌ها
    $message .= "❤️ لایک‌های دریافتی: " . $receivedLikes . "\n";
    $message .= "🤝 لایک‌های متقابل: " . $mutualLikes . "\n\n";

    // 🔴 اضافه کردن وضعیت پیشنهادات
    $suggestionCount = \App\Models\UserSuggestion::getUserSuggestionCount($user->id);
    $message .= "💌 پیشنهادات دریافت شده: " . $suggestionCount . "\n\n";

    // 🔴 🔴 🔴 اصلاح خط 1710: بررسی null بودن subscription
    if ($subscription) {
        // اگر اشتراک وجود دارد، آمار را بگیر
        $stats = $subscription->getUsageStats();

        $message .= "📊 **سهمیه‌های مصرفی:**\n\n";
        $message .= "📞 **درخواست تماس:**\n";
        $message .= "   امروز: {$stats['daily_contacts']['used']}/{$stats['daily_contacts']['total']}\n";
        $message .= "   کل: {$stats['total_contacts']['used']}/{$stats['total_contacts']['total']}\n\n";
        $message .= "👥 **پیشنهادات:**\n";
        $message .= "   امروز: {$stats['daily_suggestions']['used']}/{$stats['daily_suggestions']['total']}\n";
        $message .= "   کل: {$stats['total_suggestions']['used']}/{$stats['total_suggestions']['total']}\n";
     } else {
    // اگر اشتراک وجود ندارد، سهمیه رایگان را بررسی کن
    $remainingFreeQuota = $this->checkUserFreeQuota($user);
    
    $message .= "📊 **سهمیه‌های مصرفی:**\n\n";
    $message .= "📞 **درخواست تماس:**\n";
    $message .= "   امروز: 0/0 (نیاز به اشتراک)\n";
    $message .= "   کل: 0/0\n\n";
    
    if ($remainingFreeQuota > 0) {
        $message .= "👥 **پیشنهادات رایگان:**\n";
        $message .= "   باقی‌مانده: {$remainingFreeQuota} از 10 پیشنهاد\n";
    } else {
        $message .= "👥 **پیشنهادات:**\n";
        $message .= "   امروز: 0/0 (نیاز به اشتراک)\n";
        $message .= "   کل: 0/0\n";
    }
}

    if (!$actualCompletion) {
        $message .= "⚠️ **توجه:** برای استفاده از امکانات ربات، لطفاً پروفایل خود را کامل کنید.\n\n";
    }

    $message .= "لطفاً یکی از گزینه‌های زیر را انتخاب کنید:";

    if ($this->isSuperAdmin($user->telegram_id)) {
        $keyboard = [
            'keyboard' => [
                [
                    ['text' => '📊 پروفایل من'],
                    ['text' => '💌 دریافت پیشنهاد']
                ],
                [
                    ['text' => '💼 کیف پول'],
                    ['text' => '💎 اشتراک من'],
                ],
                [
                    ['text' => $contactRequestText],
                    ['text' => '👥 سیستم دعوت و پاداش']
                ],
                [
                    ['text' => 'ℹ️ راهنمای استفاده'],
                    ['text' => '⚙️ تنظیمات']
                ],
                [
                    ['text' => ' **پنل مدیریت**']
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];
    } else {
        // کیبورد معمولی (ReplyKeyboard) برای پایین صفحه
        $keyboard = [
            'keyboard' => [
                [
                    ['text' => '📊 پروفایل من'],
                    ['text' => '💌 دریافت پیشنهاد']
                ],
                [
                    ['text' => '💼 کیف پول'],
                    ['text' => '💎 اشتراک من'],
                ],
                [
                    ['text' => $contactRequestText],
                    ['text' => '👥 سیستم دعوت و پاداش']
                ],
                [
                    ['text' => 'ℹ️ راهنمای استفاده'],
                    ['text' => '⚙️ تنظیمات']
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];
    }

    $this->telegram->sendMessage($chatId, $message, $keyboard);
}
    private function showSettingsMenu($user, $chatId)
    {
        $wallet = $user->getWallet();
        $actualCompletion = $this->checkProfileCompletion($user);
        $completionPercent = $this->calculateProfileCompletion($user);

        // دریافت فیلترهای کاربر
        $userFilters = UserFilter::getFilters($user->id);
        $activeFiltersCount = 0;


        foreach ($userFilters as $value) {
            if (!empty($value) && $value !== '') {
                if (is_array($value)) {
                    if (!empty($value))
                        $activeFiltersCount++;
                } else {
                    $activeFiltersCount++;
                }
            }
        }
        $statusButton = $user->is_active ? '⏸️ غیرفعال سازی موقت' : '▶️ فعال سازی حساب';
        $statusText = $user->is_active ? '🟢 فعال' : '🔴 غیرفعال';

        $filterStatus = $activeFiltersCount > 0 ? "✅ فعال ({$activeFiltersCount} فیلتر)" : "❌ غیرفعال";

        $message = "⚙️ **منوی تنظیمات**\n\n";
        $message .= "👤 کاربر: " . $user->first_name . "\n";
        $message .= "💰 موجودی: " . number_format($wallet->balance) . " تومان\n";
        $message .= "📊 وضعیت پروفایل: " . ($actualCompletion ? "✅ تکمیل شده" : "❌ ناقص ({$completionPercent}%)") . "\n";
        $message .= "🎛️ وضعیت فیلترها: {$filterStatus}\n\n";
        $message .= "📱 وضعیت حساب: {$statusText}\n\n"; // 🔴 این خط اضافه شده
        $message .= "لطفاً یکی از گزینه‌های زیر را انتخاب کنید:";

        $keyboard = [
            'keyboard' => [
                [
                    ['text' => '💼 کیف پول'],
                    ['text' => '⚙️ تنظیم فیلترها']
                ],
                [
                    $statusButton,
                    ['text' => '🔙 بازگشت به منوی اصلی']
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];
        $statusText = $user->getStatusInfo();
        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    // ==================== منوی پروفایل - سیستم جدید ====================
    private function handleProfileCommand($user, $chatId)
    {
        $completionPercent = $this->calculateProfileCompletion($user);

        $message = "📝 **مدیریت پروفایل**\n\n";
        $message .= "📊 وضعیت تکمیل: {$completionPercent}%\n\n";
        $message .= "لطفاً یکی از گزینه‌های زیر را انتخاب کنید:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✏️ مدیریت پروفایل', 'callback_data' => 'back_to_profile_menu'],
                    ['text' => '👁️ مشاهده پروفایل', 'callback_data' => 'profile_view']
                ],
                [
                    ['text' => '📊 وضعیت تکمیل', 'callback_data' => 'profile_status'],
                    ['text' => '🔧 هماهنگ‌سازی فیلدها', 'callback_data' => 'debug_sync_fields']
                ],
                [
                    ['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function startProfileEdit($user, $chatId)
    {
        $user->update(['state' => 'profile_edit']);
        $this->handleProfileEdit($user, $chatId);
    }

    private function handleProfileEdit($user, $chatId)
    {
        $activeFields = ProfileField::getActiveFields();
        $currentState = $user->state;

        $currentField = null;
        $currentIndex = -1;

        // پیدا کردن فیلد فعلی
        foreach ($activeFields as $index => $field) {
            if ("editing_{$field->field_name}" === $currentState) {
                $currentField = $field;
                $currentIndex = $index;
                break;
            }
        }

        // اگر state عمومی است و فیلد خاصی انتخاب نشده
        if (!$currentField && $currentState === 'profile_edit') {
            if (!empty($activeFields)) {
                // اول فیلدهای اجباری خالی را پیدا کن
                foreach ($activeFields as $index => $field) {
                    $value = $user->{$field->field_name};
                    if ($field->is_required && (empty($value) || $value === 'تعیین نشده')) {
                        $currentField = $field;
                        $currentIndex = $index;
                        break;
                    }
                }

                // اگر همه فیلدهای اجباری پر هستند، اولین فیلد را انتخاب کن
                if (!$currentField) {
                    $currentField = $activeFields[0];
                    $currentIndex = 0;
                }

                $user->update(['state' => "editing_{$currentField->field_name}"]);
            }
        }

        if ($currentField) {
            $this->showFieldEdit($currentField, $user, $chatId, $currentIndex, count($activeFields));
        } else {
            $this->showMainMenu($user, $chatId);
        }
    }
    // متدهای کمکی
    private function getEmptyRequiredFields($user)
    {
        $activeFields = ProfileField::getActiveFields();

        // فیلتر کردن فیلدهای اجباری از بین فیلدهای فعال
        $requiredFields = array_filter($activeFields, function ($field) {
            return $field->is_required == 1 || $field->is_required === true;
        });

        $emptyFields = [];

        foreach ($requiredFields as $field) {
            $value = $user->{$field->field_name};
            if (empty($value) || $value === 'تعیین نشده' || $value === '') {
                $emptyFields[] = $field;
            }
        }

        return $emptyFields;
    }


    private function checkRequiredFieldsCompletion($user)
    {
        $emptyFields = $this->getEmptyRequiredFields($user);
        return empty($emptyFields);
    }
    private function showFieldEdit($field, $user, $chatId, $currentIndex, $totalFields)
    {

        // تنظیم state برای فیلد جاری - بدون پاک کردن state در ابتدا
        $user->update(['state' => "editing_{$field->field_name}"]);

        $message = "📝 **ویرایش پروفایل**\n\n";
        $message .= "🔄 پیشرفت: " . ($currentIndex + 1) . "/{$totalFields}\n";

        // نمایش وضعیت فیلدهای اجباری
        $emptyRequiredFields = $this->getEmptyRequiredFields($user);
        if (!empty($emptyRequiredFields) && $field->is_required) {
            $message .= "🔴 فیلدهای اجباری باقی‌مانده: " . count($emptyRequiredFields) . "\n\n";
        } else if (empty($emptyRequiredFields)) {
            $message .= "✅ تمام فیلدهای اجباری تکمیل شدند!\n\n";
        } else {
            $message .= "\n";
        }

        $message .= "**{$field->field_label}**";
        $message .= $field->is_required ? " 🔴" : " 🔵";
        $message .= "\n";

        // نمایش مقدار فعلی اگر وجود دارد
        $currentValue = $user->{$field->field_name};
        if ($currentValue) {
            if ($field->field_type === 'select' && is_numeric($currentValue)) {
                $displayValue = $this->convertSelectValueToText($field, $currentValue);
                $message .= "📋 مقدار فعلی: {$displayValue}\n\n";
            } else {
                $message .= "📋 مقدار فعلی: {$currentValue}\n\n";
            }
        } else {
            $message .= "\n";
        }

        // راهنمای ورودی بر اساس نوع فیلد
        if ($field->field_type === 'select') {
            $message .= "لطفاً یکی از گزینه‌های زیر را انتخاب کنید:\n\n";

            $options = $this->getFieldOptions($field);
            if (!empty($options)) {
                foreach ($options as $index => $option) {
                    $message .= ($index + 1) . "️⃣ {$option}\n";
                }
                $message .= "\n📝 می‌توانید عدد گزینه مورد نظر را وارد کنید.";
            } else {
                $message .= "⚠️ هیچ گزینه‌ای تعریف نشده است.";
            }
        } else {
            $message .= "لطفاً " . $field->field_label . "  خود را وارد کنید:\n";
            if ($field->field_type === 'number') {
                $message .= "🔢 (عدد - فارسی یا انگلیسی قابل قبول است)";
            } else {
                $message .= "📝 (متن)";
            }
        }

        // هشدار برای فیلدهای اجباری خالی
        if ($field->is_required && empty($currentValue)) {
            $message .= "\n\n⚠️ این فیلد اجباری است و باید پر شود.";
        }

        // ایجاد کیبورد دینامیک
        $keyboard = ['inline_keyboard' => []];

        // دکمه‌های ناوبری
        $navButtons = [];

        // دکمه قبلی (اگر اولین فیلد نیستیم)
        if ($currentIndex > 0) {
            $navButtons[] = ['text' => '⏪ قبلی', 'callback_data' => 'profile_prev_field'];
        }

        // دکمه رد شدن (فقط برای فیلدهای غیرالزامی)
        if (!$field->is_required) {
            $navButtons[] = ['text' => '⏭️ رد شدن', 'callback_data' => 'profile_skip_field'];
        }

        if (!empty($navButtons)) {
            $keyboard['inline_keyboard'][] = $navButtons;
        }

        // دکمه بعدی (اگر آخرین فیلد نیستیم)
        if ($currentIndex < $totalFields - 1) {
            $keyboard['inline_keyboard'][] = [
                ['text' => '⏩ بعدی', 'callback_data' => 'profile_next_field']
            ];
        }

        // دکمه‌های پایانی
        $keyboard['inline_keyboard'][] = [
            ['text' => '💾 ذخیره و پایان', 'callback_data' => 'profile_save_exit'],
            ['text' => '❌ انصراف', 'callback_data' => 'back_to_profile_menu']
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }
    private function handleNextField($user, $chatId)
    {
        $activeFields = ProfileField::getActiveFields();
        $currentState = $user->state;

        // پیدا کردن فیلد فعلی
        $currentIndex = -1;
        foreach ($activeFields as $index => $field) {
            if ("editing_{$field->field_name}" === $currentState) {
                $currentIndex = $index;
                break;
            }
        }

        // رفتن به فیلد بعدی
        if ($currentIndex >= 0 && $currentIndex < count($activeFields) - 1) {
            $nextField = $activeFields[$currentIndex + 1];
            $user->update(['state' => "editing_{$nextField->field_name}"]);
            $this->handleProfileEdit($user, $chatId);
        } else {
            // اگر آخرین فیلد بود، ذخیره کن
            $this->handleProfileSave($user, $chatId);
        }
    }

    private function handlePrevField($user, $chatId)
    {
        $activeFields = ProfileField::getActiveFields();
        $currentState = $user->state;

        $currentIndex = -1;
        foreach ($activeFields as $index => $field) {
            if ("editing_{$field->field_name}" === $currentState) {
                $currentIndex = $index;
                break;
            }
        }

        // رفتن به فیلد قبلی
        if ($currentIndex > 0) {
            $prevField = $activeFields[$currentIndex - 1];
            $user->update(['state' => "editing_{$prevField->field_name}"]);
            $this->handleProfileEdit($user, $chatId);
        }
    }

    private function handleSkipField($user, $chatId)
    {
        // فقط برو به فیلد بعدی، هیچ مقداری ذخیره نکن
        $this->handleNextField($user, $chatId);
    }

    private function handleProfileSave($user, $chatId)
    {
        // بررسی دقیق تکمیل بودن پروفایل
        $isComplete = $this->checkProfileCompletion($user);
        $completionPercent = $this->calculateProfileCompletion($user);

        $user->update([
            'is_profile_completed' => $isComplete,
            'state' => 'main_menu'
        ]);

        $message = "✅ **پروفایل ذخیره شد!**\n\n";
        $message .= "📊 میزان تکمیل: {$completionPercent}%\n";

        if ($isComplete) {
            $message .= "🎉 پروفایل شما کاملاً تکمیل شد!\n";
            $message .= "✅ اکنون می‌توانید از بخش 'دریافت پیشنهاد' استفاده کنید.";
        } else {
            $missingFields = $this->getMissingRequiredFields($user);
            $message .= "❌ **پروفایل شما ناقص است!**\n\n";
            $message .= "فیلدهای اجباری زیر تکمیل نشده‌اند:\n";
            foreach ($missingFields as $field) {
                $message .= "• {$field->field_label}\n";
            }
            $message .= "\n⚠️ برای استفاده از تمامی امکانات ربات، لطفاً این فیلدها را تکمیل کنید.";
        }

        $this->telegram->sendMessage($chatId, $message);

        // بعد از 2 ثانیه منوی اصلی را نشان بده
        sleep(2);
        $this->showprofile($user, $chatId);
    }

    // متد جدید برای پیدا کردن فیلدهای اجباری خالی
    private function getMissingRequiredFields($user)
    {
        $activeFields = ProfileField::getActiveFields();

        // فیلتر کردن فیلدهای اجباری از بین فیلدهای فعال
        $requiredFields = array_filter($activeFields, function ($field) {
            return $field->is_required == 1;
        });

        $missingFields = [];

        foreach ($requiredFields as $field) {
            $value = $user->{$field->field_name};
            if (empty($value) || $value === 'تعیین نشده' || $value === '') {
                $missingFields[] = $field;
            }
        }

        return $missingFields;
    }
    private function showProfileStatus($user, $chatId)
    {
        $completionPercent = $this->calculateProfileCompletion($user);
        $requiredComplete = $this->checkProfileCompletion($user);

        $message = "📊 **وضعیت تکمیل پروفایل**\n\n";
        $message .= "📈 میزان تکمیل: {$completionPercent}%\n";
        $message .= $requiredComplete ? "✅ تمام اطلاعات الزامی تکمیل شده‌اند" : "⚠️ برخی اطلاعات الزامی تکمیل نشده‌اند";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✏️ ادامه تکمیل', 'callback_data' => 'profile_edit_start'],
                    ['text' => '👁️ مشاهده پروفایل', 'callback_data' => 'profile_view']
                ],
                [
                    ['text' => '🔙 بازگشت', 'callback_data' => 'profile']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function checkProfileCompletion($user)
    {
        $activeFields = ProfileField::getActiveFields();

        // فیلتر کردن فیلدهای اجباری از بین فیلدهای فعال (با استفاده از array_filter)
        $requiredFields = array_filter($activeFields, function ($field) {
            return $field->is_required == 1 || $field->is_required === true;
        });

        foreach ($requiredFields as $field) {
            $value = $user->{$field->field_name};
            if (empty($value) || $value === 'تعیین نشده' || $value === '') {
                return false;
            }
        }

        return true;
    }

    private function calculateProfileCompletion($user)
    {
        $activeFields = ProfileField::getActiveFields();
        $totalFields = count($activeFields);
        $completedFields = 0;

        foreach ($activeFields as $field) {
            $value = $user->{$field->field_name};
            if (!empty($value) && $value !== 'تعیین نشده') {
                $completedFields++;
            }
        }

        return $totalFields > 0 ? round(($completedFields / $totalFields) * 100) : 0;
    }
    private function showProfile($user, $chatId)
    {
        $message = "👤 **پروفایل کاربری**\n\n";
        $message .= "🆔 شناسه: " . $user->telegram_id . "\n";
        $message .= "👤 نام: " . ($user->first_name ?? 'تعیین نشده') . "\n";
        $message .= "📧 یوزرنیم: @" . ($user->username ?? 'ندارد') . "\n";

        // نمایش فیلدهای پروفایل به صورت دینامیک
        $activeFields = ProfileField::getActiveFields();
        foreach ($activeFields as $field) {
            $value = $user->{$field->field_name} ?? 'تعیین نشده';

            // تبدیل جنسیت به فارسی برای نمایش
            if ($field->field_name === 'gender') {
                $value = $this->convertGenderForDisplay($value);
            }
            // اگر فیلد از نوع select هست و مقدار عددی داره، به متن تبدیل کن 
            elseif ($field->field_type === 'select' && is_numeric($value)) {
                $value = $this->convertSelectValueToText($field, $value);
            }

            $message .= "✅ {$field->field_label} : {$value}\n";
        }

        $message .= "\n📊 وضعیت: " . ($user->is_profile_completed ? "✅ تکمیل شده" : "⚠️ ناقص");

        if ($user->telegram_photo_id) {
            // کیبورد برای حالت دارای عکس
            $keyboardWithPhoto = [
                'keyboard' => [
                    [
                        ['text' => '✏️ ویرایش پروفایل'],
                        ['text' => '🔄 تغییر عکس پروفایل']

                    ],

                    [['text' => '🔙 بازگشت به منوی اصلی']]
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => false
            ];

            // 🔴 Escape کردن متن برای جلوگیری از خطای Markdown
            $escapedMessage = $this->escapeMarkdown($message);

            // نمایش عکس پروفایل با کیبورد
            $this->telegram->sendPhoto($chatId, $user->telegram_photo_id, $escapedMessage, $keyboardWithPhoto);
        } else {
            $message .= "\n📷 شما هنوز عکس پروفایل تنظیم نکرده‌اید.";

            $keyboard = [
                'keyboard' => [
                    [['text' => '✏️ ویرایش پروفایل']],
                    [['text' => '📸 آپلود عکس پروفایل']],
                    [['text' => '🔙 بازگشت به منوی اصلی']]
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => false
            ];

            $this->telegram->sendMessage($chatId, $message, $keyboard);
        }
    }
    /**
     * Escape کردن کاراکترهای خاص Markdown برای جلوگیری از خطای parsing
     */
    private function escapeMarkdown($text)
    {
        // کاراکترهای خاص Markdown که نیاز به escape دارند
        $specialChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];

        foreach ($specialChars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }

        return $text;
    }
    // متد کمکی برای گرفتن آدرس کامل عکس پروفایل
    private function getProfilePhotoUrl($photoFilename)
    {
        // آدرس دامنه خود را اینجا قرار دهید
        $baseUrl = "http://localhost/dating_bot/storage/profile_photos/";
        return $baseUrl . $photoFilename;
    }

    // ==================== پردازش state‌ها ====================
    private function handleProfileState($text, $user, $chatId, $message = null)
    {
        $text = $text ?? '';
        $text = trim($text);

        // دیباگ state
        echo "🔍 handleProfileState - User State: {$user->state}, Text: '$text'\n";

        switch ($user->state) {
            case 'managing_photos':
                return $this->handlePhotoManagement($text, $user, $chatId);

            case 'uploading_main_photo':
            case 'uploading_additional_photo':
                // اگر message داریم و عکس دارد
                if ($message && isset($message['photo'])) {
                    return $this->handlePhotoMessage($user, $message);
                }
                // اگر کاربر متن ارسال کرده (نه عکس)
                elseif (!empty($text)) {
                    if ($text === '❌ لغو آپلود عکس') {
                        $this->sendMessage($chatId, "آپلود عکس لغو شد.");
                        //   $this->showPhotoManagementMenu($user, $chatId);
                    } else {
                        $this->sendMessage($chatId, "لطفاً یک عکس ارسال کنید. برای لغو از گزینه '❌ لغو آپلود عکس' استفاده کنید.");

                        $keyboard = [
                            ['❌ لغو آپلود عکس']
                        ];
                        $this->sendMessage($chatId, "یا از منوی زیر برای لغو استفاده کنید:", $keyboard);
                    }
                }
                break;






            default:
                return $this->showMainMenu($user, $chatId);
        }

        return true;
    }
    // متد جدید برای مدیریت ورودی فیلترها
    private function handleFilterInput($text, $user, $chatId)
    {
        $currentState = $user->state;
        $fieldName = str_replace('editing_filter:', '', $currentState);

        // تبدیل اعداد فارسی به انگلیسی
        $processedText = $this->validateAndConvertNumbers($text);

        if (empty($processedText)) {
            $this->telegram->sendMessage($chatId, "❌ لطفاً یک عدد معتبر وارد کنید\nمثال: ۱۷۵ یا 175");
            return;
        }

        // ذخیره مقدار فیلتر
        $this->setFilterValue($user, $chatId, $fieldName, $processedText);

        // بازگشت به حالت عادی
        $user->update(['state' => 'main_menu']);
    }



    private function handleProfileFieldInput($text, $user, $chatId)
    {
        $currentState = $user->state;

        // اگر کاربر روی دکمه بازگشت کلیک کرد
        if ($text === '🔙 بازگشت به ویرایش پروفایل' || $text === '❌ انصراف') {
            $user->update(['state' => 'main_menu']);
            $this->showProfile($user, $chatId);
            return;
        }

        $fieldName = str_replace('editing_', '', $currentState);

        // پیدا کردن فیلد - اصلاح خطای تایپو
        $field = ProfileField::whereFieldName($fieldName);

        if (!$field) {
            $this->telegram->sendMessage($chatId, "❌ خطای سیستم. لطفاً مجدد تلاش کنید.");
            $user->update(['state' => 'main_menu']);
            return;
        }

        // لاگ برای دیباگ
        error_log("Processing field: {$fieldName}, Input: {$text}");

        // تبدیل اعداد فارسی به انگلیسی برای فیلدهای عددی
        $processedText = $text;
        if ($field->field_type === 'number' || $field->field_type === 'select') {
            $processedText = $this->validateAndConvertNumbers($text);

            if (empty($processedText)) {
                $this->telegram->sendMessage($chatId, "❌ لطفاً یک عدد معتبر وارد کنید\nمثال: ۱۷۵ یا 175");
                return;
            }
        }

        // اعتبارسنجی مقدار وارد شده
        $validationResult = $field->validate($processedText);
        if ($validationResult !== true) {
            $this->telegram->sendMessage($chatId, "❌ {$validationResult}");
            return;
        }

        // برای فیلدهای select، عدد رو به عنوان index ذخیره می‌کنیم
        $valueToSave = $processedText;

        // ذخیره مقدار در دیتابیس
        try {
            // بررسی وجود فیلد در مدل User
            $fillable = $user->getFillable();
            if (!in_array($fieldName, $fillable)) {
                error_log("❌ Field {$fieldName} not in fillable attributes - Migration needed!");
                $this->telegram->sendMessage($chatId, "⚠️ سیستم در حال بروزرسانی است. لطفاً چند دقیقه دیگر مجدد تلاش کنید.");
                return;
            }

            $updateData = [$fieldName => $valueToSave];
            error_log("Updating user with data: " . print_r($updateData, true));

            $result = $user->update($updateData);

            if ($result) {
                error_log("✅ Field {$fieldName} updated successfully to: {$valueToSave}");

                // نمایش تأیید برای کاربر
                if ($field->field_type === 'select') {
                    $selectedText = $this->convertSelectValueToText($field, $valueToSave);
                    $this->telegram->sendMessage($chatId, "✅ {$field->field_label} شما به: **{$selectedText}** تنظیم شد");
                } else {
                    $this->telegram->sendMessage($chatId, "✅ {$field->field_label} شما به: **{$valueToSave}** تنظیم شد");
                }
            } else {
                error_log("❌ Failed to update field {$fieldName}");
                $this->telegram->sendMessage($chatId, "❌ خطا در ذخیره اطلاعات. لطفاً مجدد تلاش کنید.");
                return;
            }
        } catch (\Exception $e) {
            error_log("Error updating profile field {$fieldName}: " . $e->getMessage());
            $this->telegram->sendMessage($chatId, "❌ خطا در ذخیره اطلاعات. لطفاً مجدد تلاش کنید.");
            return;
        }

        // رفتن به فیلد بعدی - استفاده از متد موجود
        $this->handleNextField($user, $chatId);
    }

    private function debugSelectFields($user, $chatId)
    {
        $activeFields = ProfileField::getActiveFields();
        $selectFields = array_filter($activeFields, function ($field) {
            return $field->field_type === 'select';
        });

        $message = "🔍 **دیباگ فیلدهای Select**\n\n";

        foreach ($activeFields as $field) {
            $value = $user->{$field->field_name};
            $textValue = $this->convertSelectValueToText($field, $value);

            $message .= "**{$field->field_label}**\n";
            $message .= "مقدار عددی: " . ($value ?: '❌ خالی') . "\n";
            $message .= "مقدار متن: " . ($textValue ?: '❌ خالی') . "\n";
            $message .= "────────────\n";
        }

        $this->telegram->sendMessage($chatId, $message);
    }

    // ==================== منوی کیف پول ====================
    private function handleWallet($user, $chatId)
    {
        $wallet = $user->getWallet();

        $message = "💼 **کیف پول شما**\n\n";
        $message .= "💰 موجودی فعلی: **" . number_format($wallet->balance) . " تومان**\n\n";
        $message .= "لطفاً یکی از گزینه‌های زیر را انتخاب کنید:";

        // کیبورد ثابت
        $keyboard = [
            'keyboard' => [
                [
                    ['text' => '💰 شارژ کیف پول'],
                    ['text' => '📋 تاریخچه تراکنش‌ها']
                ],
                [
                  //  ['text' => '📜 تاریخچه درخواست‌ها'],
                    ['text' => '🔙 بازگشت به منوی اصلی']
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function toPersianDateTime($date, $showTime = true)
    {
        if (!$date) {
            return 'نامشخص';
        }

        try {
            $jdate = \Morilog\Jalali\Jalalian::fromDateTime($date);

            if ($showTime) {
                return $jdate->format('Y/m/d H:i');
            } else {
                return $jdate->format('Y/m/d');
            }
        } catch (\Exception $e) {
            error_log("Persian date conversion error: " . $e->getMessage());

            // در صورت خطا، تاریخ میلادی برگردان
            if ($date instanceof \Carbon\Carbon) {
                return $date->format('Y/m/d H:i');
            }
            return date('Y/m/d H:i', strtotime($date));
        }
    }

    private function handleTransactions($user, $chatId)
    {
        $transactions = $user->transactions()->latest()->limit(15)->get();
        $wallet = $user->getWallet();

        $message = "📋 **آخرین تراکنش‌های شما**\n\n";

        // آمار سریع
        $totalDeposit = $transactions->where('amount', '>', 0)->sum('amount');
        $totalWithdraw = abs($transactions->where('amount', '<', 0)->sum('amount'));

        $message .= "💰 **آمار سریع:**\n";
        $message .= "• کل واریز‌ها: " . number_format($totalDeposit) . " تومان\n";
        $message .= "• کل برداشت‌ها: " . number_format($totalWithdraw) . " تومان\n";
        $message .= "• موجودی فعلی: **" . number_format($wallet->balance) . " تومان**\n\n";

        $message .= "📊 **جزئیات تراکنش‌ها:**\n";
        $message .= "────────────────\n";

        if ($transactions->count() > 0) {
            $counter = 1;
            foreach ($transactions as $transaction) {
                // انتخاب ایموجی مناسب
                $typeEmoji = $this->getTransactionEmoji($transaction->type);

                // تعیین نوع تراکنش (واریز/برداشت)
                $isDeposit = $transaction->amount > 0;
                $sign = $isDeposit ? '➕' : '➖';
                $amountColor = $isDeposit ? '🟢' : '🔴';

                // تاریخ شمسی
                $formattedDate = $this->toPersianDateTime($transaction->created_at);

                // متن تراکنش
                $message .= "{$counter}. {$typeEmoji} **" . $this->getTransactionTypeText($transaction->type) . "**\n";
                $message .= "   {$amountColor} مبلغ: **" . number_format(abs($transaction->amount)) . " تومان**\n";

                // توضیحات اگر وجود دارد
                if (!empty($transaction->description)) {
                    $message .= "   📌 " . $transaction->description . "\n";
                }

                $message .= "   ⏰ تاریخ: " . $formattedDate . "\n";

                // وضعیت تراکنش (اگر فیلد status وجود دارد)
                if (isset($transaction->status)) {
                    $statusText = $this->getTransactionStatusText($transaction->status);
                    $message .= "   📊 وضعیت: " . $statusText . "\n";
                }

                $message .= "   ────────────────\n";
                $counter++;
            }

            // پیام پایانی اگر تراکنش بیشتری وجود دارد
            $totalTransactions = $user->transactions()->count();
            if ($totalTransactions > 15) {
                $message .= "\n📌 *نکته:* " . ($totalTransactions - 15) . " تراکنش قدیمی‌تر وجود دارد.\n";
                $message .= "برای مشاهده تمام تراکنش‌ها از بخش گزارشات استفاده کنید.";
            }
        } else {
            $message .= "📭 هیچ تراکنشی یافت نشد.\n";
            $message .= "اولین تراکنش شما می‌تواند شارژ کیف پول یا خرید اشتراک باشد.";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📥 شارژ کیف پول', 'callback_data' => 'charge_wallet'],
                    ['text' => '💎 خرید اشتراک', 'callback_data' => 'buy_subscription']
                ],
                [
                    ['text' => '🔄 بروزرسانی', 'callback_data' => 'refresh_transactions'],
                    ['text' => '🔙 بازگشت', 'callback_data' => 'back_to_wallet']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    // متد کمکی برای وضعیت تراکنش‌ها (اختیاری)
    private function getTransactionStatusText($status)
    {
        $statuses = [
            'pending' => '⏳ در انتظار',
            'completed' => '✅ تکمیل شده',
            'failed' => '❌ ناموفق',
            'cancelled' => '🚫 لغو شده'
        ];

        return $statuses[$status] ?? $status;
    }
    // ✅ اضافه کردن این متد برای ایموجی تراکنش‌ها
    private function getTransactionEmoji($type)
    {
        $emojis = [
            'charge' => '💵',
            'purchase' => '📞',
            'referral_bonus' => '🎁',
            'withdraw' => '🏦',
            'subscription_purchase' => '💎' // برای خرید اشتراک
        ];

        return $emojis[$type] ?? '💰';
    }
   

    // ==================== سایر منوها ====================
    private function handleSearch($user, $chatId)
    {
        $message = "🔍 **جستجوی افراد**\n\n";
        $message .= "این بخش به زودی فعال خواهد شد...\n";
        $message .= "در این بخش می‌توانید افراد بر اساس فیلترهای مختلف جستجو کنید.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'back_to_main']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function handleReferral($user, $chatId)
{
    // اطمینان از وجود کد دعوت
    if (!$user->invite_code) {
        $inviteCode = $this->createInviteCodeDirectly($user);
        if ($inviteCode) {
            $user->invite_code = $inviteCode;
            $user->save();
        }
    }

    // محاسبه آمار واقعی
    $stats = $this->calculateRealReferralStats($user->id);
    
    $inviteLink = "https://t.me/" . $_ENV['TELEGRAM_BOT_USERNAME'] . "?start=ref_{$user->invite_code}";
    
    $message = "👥 **سیستم دعوت دوستان**\n\n";
    
    $message .= "🔗 **لینک دعوت شما:**\n";
    $message .= "`{$inviteLink}`\n\n";
    
    $message .= "📧 **کد دعوت شما:**\n";
    $message .= "`{$user->invite_code}`\n\n";
    
    $message .= "📊 **آمار دعوت‌های شما:**\n";
    $message .= "• 👥 کل دعوت‌ها: {$stats['total_referrals']} نفر\n";
    $message .= "• ✅ دعوت‌های موفق (شارژ کرده‌اند): {$stats['purchased_referrals']} نفر\n";
    $message .= "• ⏳ دعوت‌های در انتظار: {$stats['pending_referrals']} نفر\n";
    $message .= "• 💰 مجموع پاداش‌ها: " . number_format($stats['total_bonus']) . " تومان\n\n";
    
    $message .= "🎁 **شرایط پاداش:**\n";
    $message .= "• با هر دعوت موفق، 30٪ از مبلغ اولین شارژ دوستتان به عنوان پاداش دریافت می‌کنید\n";
    $message .= "• پاداش بلافاصله پس از اولین شارژ به کیف پول شما اضافه می‌شود\n";
    $message .= "• می‌توانید از پاداش برای خرید اشتراک استفاده نمایید\n\n";
    
    $message .= "💡 **نحوه استفاده:**\n";
    $message .= "• لینک فوق را برای دوستان خود ارسال کنید\n";
    $message .= "• یا کد دعوت خود را به آنها بدهید\n";
    $message .= "• وقتی دوستان شما اولین شارژ را انجام دهند، پاداش دریافت می‌کنید";

    $keyboard = [
        'keyboard' => [
            [
                ['text' => '📋 کپی لینک دعوت'],
                ['text' => '📤 اشتراک‌گذاری لینک']
            ],
            [
                ['text' => '🔄 بروزرسانی آمار'],
                ['text' => '📊 مشاهده جزئیات']
            ],
            [
                ['text' => '🔙 بازگشت به منوی اصلی']
            ]
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false
    ];

    $this->telegram->sendMessage($chatId, $message, $keyboard);
}
private function calculateRealReferralStats($userId)
{
    $pdo = $this->getPDO();
    
    // تعداد کل دعوت‌ها
    $sql = "SELECT COUNT(*) as count FROM referrals WHERE referrer_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $total = $stmt->fetch(\PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // تعداد دعوت‌های موفق (دارای خرید)
    $sql = "SELECT COUNT(*) as count FROM referrals WHERE referrer_id = ? AND has_purchased = 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $purchased = $stmt->fetch(\PDO::FETCH_ASSOC)['count'] ?? 0;
    
    // مجموع پاداش‌ها
    $sql = "SELECT SUM(bonus_amount) as total FROM referrals WHERE referrer_id = ? AND has_purchased = 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $totalBonus = $stmt->fetch(\PDO::FETCH_ASSOC)['total'] ?? 0;
    
    return [
        'total_referrals' => $total,
        'purchased_referrals' => $purchased,
        'pending_referrals' => $total - $purchased,
        'total_bonus' => $totalBonus
    ];
}
    private function createInviteCodeDirectly($user)
    {
        try {
            $pdo = $this->getPDO();

            do {
                $code = strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));

                // بررسی تکراری نبودن کد
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE invite_code = ?");
                $stmt->execute([$code]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            } while ($result['count'] > 0);

            // ذخیره مستقیم در دیتابیس
            $stmt = $pdo->prepare("UPDATE users SET invite_code = ? WHERE telegram_id = ? OR id = ?");

            // تلاش با telegram_id اول
            $stmt->execute([$code, $user->telegram_id, $user->id]);

            // بررسی آیا رکورد آپدیت شد
            if ($stmt->rowCount() > 0) {
                return $code;
            } else {
                // اگر با telegram_id پیدا نشد، با id امتحان کنید
                if (isset($user->id)) {
                    $stmt = $pdo->prepare("UPDATE users SET invite_code = ? WHERE id = ?");
                    $stmt->execute([$code, $user->id]);

                    if ($stmt->rowCount() > 0) {
                        return $code;
                    }
                }
            }

            return false;
        } catch (\Exception $e) {
            error_log("Error creating invite code: " . $e->getMessage());
            return false;
        }
    }
    private function handleHelp($chatId)
    {
        $message = "ℹ️ **راهنمای استفاده از ربات**\n\n";
        $message .= "🤝 **ربات همسر یابی**\n";
        $message .= "• ایجاد پروفایل کامل\n";
        $message .= "• جستجوی افراد هم‌شهر\n";
        $message .= "• سیستم کیف پول و شارژ\n";
        $message .= "• دعوت دوستان و دریافت پاداش\n\n";
        $message .= "📞 **پشتیبانی**: برای راهنمایی بیشتر با پشتیبانی تماس بگیرید.";

        // دکمه‌های reply keyboard (ثابت در پایین صفحه)
        $keyboard = [
            ['👨‍💼 ارتباط با پشتیبانی'],
            ['🏠 منوی اصلی']
        ];

        $replyMarkup = [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'one_time_keyboard' => false, // ثابت بمونه
            'selective' => false
        ];

        $this->telegram->sendMessage($chatId, $message, $replyMarkup);
    }

    private function connectToSupport($chatId)
    {
        $supportUsername = 'support_username'; // یوزرنیم پشتیبانی

        $message = "👨‍💼 **برای ارتباط با پشتیبانی**\n\n";
        $message .= "مستقیماً به آیدی زیر پیام دهید:\n\n";
        $message .= "🆔 @{$supportUsername}\n\n";
        $message .= "یا روی لینک زیر کلیک کنید:\n";
        $message .= "https://t.me/{$supportUsername}";

        // کیبورد اصلی برای بازگشت
        $keyboard = [
            ['🏠 منوی اصلی', 'ℹ️ راهنما']
        ];

        $replyMarkup = [
            'keyboard' => $keyboard,
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];

        $this->telegram->sendMessage($chatId, $message, $replyMarkup);
    }
    private function getTransactionTypeText($type)
    {
        $types = [
            'charge' => 'شارژ کیف پول',
            'purchase' => 'دریافت اطلاعات تماس',
            'referral_bonus' => '🎁 پاداش دعوت',
            'withdraw' => 'برداشت',
            'subscription_purchase' => 'خرید اشتراک' // ✅ جدید
        ];

        return $types[$type] ?? $type;
    }
    private function getCities()
    {
        try {
            // خواندن شهرها از دیتابیس
            $pdo = $this->getPDO();
            $sql = "SELECT name FROM cities ORDER BY name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $cities = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);

            if (!empty($cities)) {
                return $cities;
            }
        } catch (\Exception $e) {
            error_log("❌ Error in getCities: " . $e->getMessage());
        }

        // لیست پیشفرض در صورت خطا
        return [
            'تهران',
            'مشهد',
            'اصفهان',
            'شیراز',
            'تبریز',
            'کرج',
            'قم',
            'اهواز',
            'کرمانشاه',
            'ارومیه',
            'رشت',
            'زاهدان',
            'کرمان',
            'همدان',
            'اراک',
            'یزد',
            'اردبیل',
            'بندرعباس',
            'قدس',
            'خرم‌آباد',
            'ساری',
            'گرگان'
        ];
    }

    // ==================== توابع کمکی برای تبدیل اعداد ====================
    private function convertPersianNumbersToEnglish($string)
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $string = str_replace($persian, $english, $string);
        $string = str_replace($arabic, $english, $string);

        return $string;
    }

    private function validateAndConvertNumbers($input)
    {
        // تبدیل اعداد فارسی/عربی به انگلیسی
        $converted = $this->convertPersianNumbersToEnglish($input);

        // حذف کاراکترهای غیرعددی (به جز نقطه برای اعشار)
        $cleaned = preg_replace('/[^0-9.]/', '', $converted);

        return $cleaned;
    }

    // ==================== تابع دیباگ برای بررسی فیلدها ====================
    private function checkDatabaseFields($user, $chatId)
    {
        $activeFields = ProfileField::getActiveFields();
        $message = "🔍 **بررسی فیلدهای دیتابیس**\n\n";

        foreach ($activeFields as $field) {
            $fieldName = $field->field_name;
            $fillable = $user->getFillable();
            $existsInModel = in_array($fieldName, $fillable);
            $currentValue = $user->$fieldName;

            $message .= "**{$field->field_label}**\n";
            $message .= "فیلد: `{$fieldName}`\n";
            $message .= "در مدل: " . ($existsInModel ? "✅" : "❌") . "\n";
            $message .= "مقدار فعلی: " . ($currentValue ?: '❌ خالی') . "\n";
            $message .= "────────────\n";
        }

        $message .= "\n📝 **فیلدهای fillable مدل User:**\n";
        $message .= "`" . implode('`, `', $fillable) . "`";

        $this->telegram->sendMessage($chatId, $message);
    }
    private function convertSelectValueToText($field, $numericValue)
    {
        $options = $this->getFieldOptions($field);

        if (empty($options)) {
            return $numericValue; // اگر گزینه‌ای نیست، مقدار عددی رو برگردون
        }

        $index = intval($numericValue) - 1; // چون کاربر از ۱ شماره گذاری می‌کنه

        if (isset($options[$index])) {
            return $options[$index];
        }

        // اگر عدد معتبر نبود، مقدار اصلی رو برگردون
        return $numericValue;
    }
    private function isSuperAdmin($telegramId)
    {
        // آیدی‌های سوپر ادمین - اینجا می‌تونی آیدی خودت رو قرار بدی
        $superAdmins = [123456789]; // 👈 این رو عوض کن به آیدی تلگرام خودت

        return in_array($telegramId, $superAdmins) || Administrator::isAdmin($telegramId);
    }
    private function handleAdminCommand($user, $chatId, $text)
    {
        $parts = explode(' ', $text);

        if (count($parts) === 1) {
            // نمایش منوی مدیریت
            $this->showAdminPanelWithNotification($user, $chatId);
        } elseif (count($parts) === 3 && $parts[1] === 'add') {
            // دستور: /admin add 123456789
            $newAdminId = intval($parts[2]);
            $this->addNewAdmin($user, $chatId, $newAdminId);
        } else {
            $this->telegram->sendMessage($chatId, "❌ دستور نامعتبر\n\nاستفاده صحیح:\n/admin - نمایش پنل\n/admin add 123456789 - افزودن مدیر جدید");
        }
    }

    private function showAdminfieldPanel($user, $chatId)
    {

        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        // استفاده از متد getActiveFields به جای where
        $activeFields = ProfileField::getActiveFields();
        $activeFieldsCount = count($activeFields);

        // برای گرفتن تعداد کل فیلدها، از یک متد جدید استفاده می‌کنیم
        $allFields = ProfileField::getAllFields(); // این متد باید ایجاد شود
        $totalFieldsCount = count($allFields);

        $message = "👑 *بخش تنظیمات فیلد ها  **\n\n";
        $message .= "📊 آمار فیلدها:\n";
        $message .= "• ✅ فیلدهای فعال: {$activeFieldsCount}\n";
        $message .= "• 📋 کل فیلدها: {$totalFieldsCount}\n\n";
        $message .= "لطفاً یکی از گزینه‌ها را انتخاب کنید:";



        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 هماهنگ‌سازی فیلدها', 'callback_data' => 'admin_sync_fields'],
                    ['text' => '📋 لیست فیلدها', 'callback_data' => 'admin_list_fields'],
                ],
                [
                    ['text' => '⚙️ مدیریت فیلدها', 'callback_data' => 'admin_manage_fields'],
                    ['text' => '👁️ مدیریت نمایش فیلدها', 'callback_data' => 'admin_manage_hidden_fields']

                ],
                [

                    ['text' => '🔙 بازگشت به پنل مدیریت', 'callback_data' => 'admin_panel']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }
    private function showAdminFiltersManagement($user, $chatId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        $activeFilters = SystemFilter::getActiveFilters();
        $activeFields = ProfileField::getActiveFields();

        $message = "🎛️ **مدیریت فیلترهای سیستم**\n\n";
        $message .= "📊 آمار:\n";
        $message .= "• ✅ فیلترهای فعال: " . count($activeFilters) . "\n";
        $message .= "• 📋 فیلدهای قابل فیلتر: " . count($activeFields) . "\n\n";
        $message .= "لطفاً یکی از گزینه‌ها را انتخاب کنید:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👁️ مشاهده فیلترها', 'callback_data' => 'admin_view_filters'],
                    ['text' => '⚙️ تنظیم فیلترها', 'callback_data' => 'admin_configure_filters']
                ],
                [
                    ['text' => '🔄 هماهنگ‌سازی خودکار', 'callback_data' => 'admin_auto_sync_filters'],
                    ['text' => '🏙️ مدیریت شهرها', 'callback_data' => 'admin_manage_cities']
                ],
                [
                    ['text' => '🔙 بازگشت به پنل مدیریت', 'callback_data' => 'admin_panel']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }
    private function debugFilters($user, $chatId)
    {
        $availableFilters = $this->getAvailableFilters();
        $userFilters = UserFilter::getFilters($user->id);

        $message = "🔍 **دیباگ سیستم فیلترها**\n\n";

        $message .= "🎯 **فیلترهای موجود در سیستم:**\n";
        foreach ($availableFilters as $filter) {
            $message .= "• {$filter['field_label']} ({$filter['field_name']})\n";
            $message .= "  نوع: {$filter['type']}\n";
            if (isset($filter['options'])) {
                $message .= "  گزینه‌ها: " . implode(', ', $filter['options']) . "\n";
            }
            $message .= "\n";
        }

        $message .= "👤 **فیلترهای کاربر:**\n";
        $message .= "```json\n" . json_encode($userFilters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n```";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 تست فیلترها', 'callback_data' => 'test_filters'],
                    ['text' => '🔙 بازگشت', 'callback_data' => 'admin_filters_management']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }
    private function setFilterValue($user, $chatId, $fieldName, $value)
    {

        // 🔴 DECODE کردن مقدار قبل از استفاده
        $decodedValue = urldecode($value);

        error_log("🔵 setFilterValue called - Field: {$fieldName}, Value: {$value}, User: {$user->id}");

        // دریافت فیلترهای فعلی
        $userFilters = UserFilter::getFilters($user->id);
        error_log("🔵 Current filters before update: " . json_encode($userFilters));

        // آپدیت مقدار - حتی اگر خالی است
        $userFilters[$fieldName] = $value;

        // ذخیره در دیتابیس
        $saveResult = UserFilter::saveFilters($user->id, $userFilters);
        error_log("🔵 Save result: " . ($saveResult ? "true" : "false"));

        // تأیید ذخیره‌سازی با خواندن مجدد
        $updatedFilters = UserFilter::getFilters($user->id);
        error_log("🔵 Updated filters after save: " . json_encode($updatedFilters));

        $filterLabel = $this->getFilterLabel($fieldName);
        $message = "✅ **فیلتر {$filterLabel} تنظیم شد**\n\n";
        $message .= "مقدار جدید: **{$decodedValue}**\n\n";

        // نمایش وضعیت ذخیره‌سازی
        if (isset($updatedFilters[$fieldName]) && $updatedFilters[$fieldName] === $value) {
            $message .= "💾 مقدار با موفقیت در دیتابیس ذخیره شد.\n\n";
        } else {
            $message .= "⚠️ **هشدار:** ممکن است مقدار در دیتابیس ذخیره نشده باشد!\n\n";
            $message .= "لطفاً با پشتیبانی تماس بگیرید.\n\n";
        }

        $message .= "برای تنظیم فیلترهای دیگر، از دکمه زیر استفاده کنید:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⚙️ ادامه تنظیم فیلترها', 'callback_data' => 'edit_filters'],
                    ['text' => '💾 ذخیره و پایان', 'callback_data' => 'save_filters']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);

        error_log("🎯 setFilterValue completed - Field: {$fieldName}, Value: {$value}");
    }
    private function adminViewFilters($user, $chatId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        $availableFilters = $this->getAvailableFilters();

        $message = "👁️ **مشاهده فیلترهای سیستم**\n\n";
        $message .= "فیلترهای فعال در سیستم:\n\n";

        foreach ($availableFilters as $filter) {
            $message .= "• **{$filter['field_label']}** (`{$filter['field_name']}`)\n";
            $message .= "  نوع: {$filter['type']}\n";
            if (isset($filter['options'])) {
                $message .= "  گزینه‌ها: " . implode(', ', $filter['options']) . "\n";
            }
            $message .= "\n";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔙 بازگشت به مدیریت فیلترها', 'callback_data' => 'admin_filters_management']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    // متدهای دیگر مدیریت فیلترها (می‌توانید بعداً تکمیل کنید)
    private function adminConfigureFilters($user, $chatId)
    {
        $this->telegram->sendMessage($chatId, "⚙️ این بخش به زودی فعال خواهد شد...");
        $this->showAdminFiltersManagement($user, $chatId);
    }



    private function adminAddCity($user, $chatId)
    {
        $this->telegram->sendMessage($chatId, "➕ این بخش به زودی فعال خواهد شد...");
        $this->adminManageCities($user, $chatId);
    }

    private function adminDeleteCity($user, $chatId)
    {
        $this->telegram->sendMessage($chatId, "🗑️ این بخش به زودی فعال خواهد شد...");
        $this->adminManageCities($user, $chatId);
    }

    private function adminLoadDefaultCities($user, $chatId)
    {
        $this->telegram->sendMessage($chatId, "📥 این بخش به زودی فعال خواهد شد...");
        $this->adminManageCities($user, $chatId);
    }
    private function adminAutoSyncFilters($user, $chatId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        $activeFields = ProfileField::getActiveFields();
        $createdCount = 0;
        $updatedCount = 0;
        $errorCount = 0;

        foreach ($activeFields as $field) {
            $filterType = $this->determineFilterType($field);

            if ($filterType) {
                $existingFilter = SystemFilter::getFilterByFieldName($field->field_name);

                if (!$existingFilter) {
                    // ایجاد فیلتر جدید
                    $result = $this->createSystemFilter($field, $filterType);
                    if ($result) {
                        $createdCount++;
                    } else {
                        $errorCount++;
                    }
                } else {
                    // آپدیت فیلتر موجود
                    $result = $this->updateSystemFilter($existingFilter, $field, $filterType);
                    if ($result) {
                        $updatedCount++;
                    } else {
                        $errorCount++;
                    }
                }
            }
        }

        $message = "🔄 **هماهنگ‌سازی فیلترها تکمیل شد**\n\n";
        $message .= "• ✅ فیلترهای جدید: {$createdCount}\n";
        $message .= "• 🔄 فیلترهای آپدیت شده: {$updatedCount}\n";
        $message .= "• ❌ خطاها: {$errorCount}\n";
        $message .= "• 📋 کل فیلدهای بررسی شده: " . count($activeFields) . "\n\n";

        if ($errorCount === 0) {
            $message .= "✅ همه فیلترها با موفقیت هماهنگ شدند.\n";
            $message .= "حالا کاربران می‌توانند از این فیلترها استفاده کنند.";
        } else {
            $message .= "⚠️ برخی فیلترها با خطا مواجه شدند.\n";
            $message .= "لطفاً لاگ‌ها را بررسی کنید.";
        }

        $this->telegram->sendMessage($chatId, $message);
    }

    private function determineFilterType($field)
    {
        switch ($field->field_type) {
            case 'select':
                return 'select';
            case 'number':
                return 'range';
            case 'text':
                // برای فیلدهای متنی خاص مثل شهر
                if (in_array($field->field_name, ['city', 'location', 'shahr'])) {
                    return 'select'; // با لیست شهرهای از پیش تعریف شده
                }
                return null; // فیلدهای متنی عمومی فیلتر نمی‌شوند
            default:
                return null;
        }
    }
    private function editUserFilter($user, $chatId, $fieldName)
    {
        error_log("🔵 editUserFilter called - Field: {$fieldName}, User: {$user->id}");

        $availableFilters = $this->getAvailableFilters();
        $currentFilter = null;

        foreach ($availableFilters as $filter) {
            if ($filter['field_name'] === $fieldName) {
                $currentFilter = $filter;
                break;
            }
        }

        if (!$currentFilter) {
            $this->telegram->sendMessage($chatId, "❌ فیلتر پیدا نشد");
            return;
        }

        $userFilters = UserFilter::getFilters($user->id);
        $currentValue = $userFilters[$fieldName] ?? '';

        error_log("🔵 Current filter value: " . (is_array($currentValue) ? json_encode($currentValue) : $currentValue));

        $message = "⚙️ **تنظیم فیلتر: {$currentFilter['field_label']}**\n\n";

        if ($currentFilter['type'] === 'select') {
            if ($fieldName === 'city') {
                // حالت چند انتخابی برای شهر
                $message .= "🏙️ **انتخاب چند شهر**\n\n";
                $message .= "می‌توانید چند شهر را انتخاب کنید. شهرهای انتخاب شده با ✅ مشخص می‌شوند.\n\n";

                $currentCities = is_array($currentValue) ? $currentValue : (($currentValue !== '') ? [$currentValue] : []);

                // نمایش شهرهای انتخاب شده
                if (!empty($currentCities)) {
                    $message .= "✅ **شهرهای انتخاب شده:**\n";
                    foreach ($currentCities as $city) {
                        $message .= "• {$city}\n";
                    }
                    $message .= "\n";
                }

                $message .= "📋 **لیست شهرها:**\n";
                $message .= "برای انتخاب/عدم انتخاب هر شهر روی آن کلیک کنید.\n\n";

                $keyboard = ['inline_keyboard' => []];

                // 🔴 تغییر: استفاده از گروه‌بندی هوشمند
                $cities = $currentFilter['options'];
                $cityChunks = $this->chunkCitiesByWidth($cities, 25); // حداکثر عرض 25 واحد

                foreach ($cityChunks as $chunk) {
                    $row = [];
                    foreach ($chunk as $city) {
                        $isSelected = in_array($city, $currentCities);
                        $buttonText = $isSelected ? "✅{$city}" : $city;

                        // کوتاه کردن متن اگر خیلی طولانی است
                        if (mb_strlen($buttonText, 'UTF-8') > 12) {
                            $buttonText = mb_substr($buttonText, 0, 10, 'UTF-8') . '..';
                        }

                        $callbackData = $isSelected ?
                            "remove_city:{$city}" :
                            "add_city:{$city}";

                        $row[] = ['text' => $buttonText, 'callback_data' => $callbackData];
                    }
                    $keyboard['inline_keyboard'][] = $row;
                }

                // دکمه‌های مدیریت
                $keyboard['inline_keyboard'][] = [
                    ['text' => '💾 ذخیره انتخاب', 'callback_data' => 'save_cities_selection'],
                    ['text' => '🔄 بازنشانی', 'callback_data' => 'reset_cities']
                ];

                $keyboard['inline_keyboard'][] = [
                    ['text' => '🔍 جستجوی شهر', 'callback_data' => 'search_city'],
                    ['text' => '📋 همه شهرها', 'callback_data' => 'show_all_cities']
                ];

                $keyboard['inline_keyboard'][] = [
                    ['text' => '🔙 بازگشت', 'callback_data' => 'edit_filters']
                ];
            } else {
                // حالت عادی برای سایر فیلترهای select (مثل جنسیت)
                $message .= "لطفاً یکی از گزینه‌های زیر را انتخاب کنید:\n\n";
                foreach ($currentFilter['options'] as $option) {
                    $isSelected = ($currentValue === $option) ? ' ✅' : '';
                    $message .= "• {$option}{$isSelected}\n";
                }

                $keyboard = ['inline_keyboard' => []];

                // گروه‌بندی گزینه‌ها
                $optionChunks = array_chunk($currentFilter['options'], 2);
                foreach ($optionChunks as $chunk) {
                    $row = [];
                    foreach ($chunk as $option) {
                        // 🔴 تغییر مهم: اطمینان از encoding صحیح داده‌های فارسی
                        $encodedOption = urlencode($option); // encode کردن مقدار برای callback_data
                        $row[] = [
                            'text' => $option,
                            'callback_data' => "set_filter_value:{$fieldName}:{$encodedOption}"
                        ];
                    }
                    $keyboard['inline_keyboard'][] = $row;
                }

                $keyboard['inline_keyboard'][] = [
                    ['text' => '🔙 بازگشت', 'callback_data' => 'edit_filters']
                ];
            }
        } else {
            // برای فیلترهای عددی (سن)
            $message .= "لطفاً مقدار جدید را وارد کنید:\n";
            $message .= "مثال: 25\n\n";
            $message .= "⚠️ لطفاً فقط عدد وارد کنید (فارسی یا انگلیسی)";

            if (!empty($currentValue)) {
                $message .= "\n\n📋 مقدار فعلی: **{$currentValue}**";
            }

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🔙 بازگشت', 'callback_data' => 'edit_filters']
                    ]
                ]
            ];

            // تنظیم state برای دریافت ورودی کاربر
            $user->update(['state' => "editing_filter:{$fieldName}"]);
            error_log("🔵 Set user state to: editing_filter:{$fieldName}");
        }

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }
    private function resetUserFilters($user, $chatId)
    {
        // 🔴 تغییر: بازنشانی به فیلترهای کاملاً خالی
        $defaultFilters = [
            'gender' => '',
            'min_age' => '',
            'max_age' => '',
            'city' => []
        ];

        UserFilter::saveFilters($user->id, $defaultFilters);

        $message = "🔄 **فیلترها بازنشانی شدند**\n\n";
        $message .= "تمام فیلترهای شما به حالت پیش‌فرض بازگشتند.\n";
        $message .= "✅ اکنون سیستم به طور خودکار از منطق جنسیت مخالف استفاده می‌کند.\n\n";
        $message .= "جنسیت شما: **{$user->gender}**\n";
        $message .= "جنسیت مخالف: **{$this->getOppositeGender($user->gender)}**";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💌 دریافت پیشنهاد', 'callback_data' => 'get_suggestion'],
                    ['text' => '⚙️ تنظیم فیلترها', 'callback_data' => 'edit_filters']
                ],
                [
                    ['text' => '🔙 منوی اصلی', 'callback_data' => 'main_menu']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }
    private function saveUserFilters($user, $chatId)
    {
        $userFilters = UserFilter::getFilters($user->id);

        $message = "💾 **تنظیمات فیلترها ذخیره شد**\n\n";
        $message .= "فیلترهای فعلی شما:\n";

        foreach ($userFilters as $fieldName => $value) {
            if (!empty($value)) {
                $filterLabel = $this->getFilterLabel($fieldName);


                if ($fieldName === 'city' && is_array($value)) {
                    // 🔴 نمایش ویژه برای شهرهای چندگانه
                    $cityCount = count($value);
                    $message .= "• **{$filterLabel}**: {$cityCount} شهر انتخاب شده\n";
                    if ($cityCount <= 5) { // اگر تعداد شهرها کم است، نمایش بده
                        $message .= "  (" . implode(', ', $value) . ")\n";
                    }
                } else {
                    $message .= "• **{$filterLabel}**: {$value}\n";
                }
            }
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💌 دریافت پیشنهاد', 'callback_data' => 'get_suggestion'],
                    ['text' => '🔙 منوی اصلی', 'callback_data' => 'main_menu']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function getFilterLabel($fieldName)
    {
        $labels = [
            'gender' => 'جنسیت',
            'min_age' => 'حداقل سن',
            'max_age' => 'حداکثر سن',
            'city' => 'شهر'
        ];

        return $labels[$fieldName] ?? $fieldName;
    }
    private function adminManageCities($user, $chatId)
    {
        $pdo = $this->getPDO();
        $sql = "SELECT * FROM cities ORDER BY name";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $cities = $stmt->fetchAll(\PDO::FETCH_OBJ);

        $message = "🏙️ **مدیریت شهرها**\n\n";
        $message .= "📋 تعداد شهرها: " . count($cities) . "\n\n";

        if (!empty($cities)) {
            $message .= "لیست شهرهای موجود:\n";
            foreach ($cities as $index => $city) {
                $message .= ($index + 1) . ". {$city->name}\n";
            }
        } else {
            $message .= "📭 هیچ شهری تعریف نشده است.\n";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '➕ افزودن شهر', 'callback_data' => 'admin_add_city'],
                    ['text' => '🗑️ حذف شهر', 'callback_data' => 'admin_delete_city']
                ],
                [
                    ['text' => '📥 وارد کردن شهرهای پیش‌فرض', 'callback_data' => 'admin_load_default_cities']
                ],
                [
                    ['text' => '🔙 بازگشت به مدیریت فیلترها', 'callback_data' => 'admin_filters_management']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }
    private function handleEditFilters($user, $chatId)
    {

        $userFilters = UserFilter::getFilters($user->id);

        // اگر کاربر فیلتری ندارد، فیلترهای پیش‌فرض ایجاد کنید
        if (empty($userFilters)) {
            $userFilters = [
                'gender' => '',
                'min_age' => '',
                'max_age' => '',
                'city' => [] // 🔴 تغییر به آرایه خالی
            ];
            UserFilter::saveFilters($user->id, $userFilters);
        }

        $availableFilters = $this->getAvailableFilters();

        $message = "🎛️ **تنظیمات فیلترهای جستجو**\n\n";
        $message .= "با تنظیم فیلترها، فقط افرادی را می‌بینید که با معیارهای شما هماهنگ هستند.\n\n";

        foreach ($availableFilters as $filter) {
            $currentValue = $userFilters[$filter['field_name']] ?? '';

            if ($filter['field_name'] === 'city') {
                // 🔴 نمایش ویژه برای شهرهای چندگانه
                if (is_array($currentValue) && !empty($currentValue)) {
                    $cityCount = count($currentValue);
                    $message .= "• **{$filter['field_label']}**: {$cityCount} شهر انتخاب شده\n";
                } else {
                    $message .= "• **{$filter['field_label']}**: همه شهرها\n";
                }
            } else {
                $message .= "• **{$filter['field_label']}**: " . ($currentValue ?: 'تعیین نشده') . "\n";
            }
        }

        $keyboard = ['inline_keyboard' => []];

        foreach ($availableFilters as $filter) {
            $keyboard['inline_keyboard'][] = [
                ['text' => "⚙️ {$filter['field_label']}", 'callback_data' => "edit_filter:{$filter['field_name']}"]
            ];
        }

        $keyboard['inline_keyboard'][] = [
            ['text' => '🔄 بازنشانی فیلترها', 'callback_data' => 'reset_filters'],
            ['text' => '💾 ذخیره تنظیمات', 'callback_data' => 'save_filters']
        ];

        $keyboard['inline_keyboard'][] = [
            ['text' => '🔙 بازگشت', 'callback_data' => 'main_menu']
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }
    private function adminSyncFields($user, $chatId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        $result = $this->autoAddMissingFields();
        $this->telegram->sendMessage($chatId, $result);

        // برگشت به پنل مدیریت بعد از 2 ثانیه
        sleep(2);
        $this->showAdminPanelWithNotification($user, $chatId);
    }

    private function adminListFields($user, $chatId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        // استفاده از متد getActiveFields
        $activeFields = ProfileField::getActiveFields();

        $message = "📋 **فیلدهای فعال**\n\n";

        foreach ($activeFields as $field) {
            $status = $field->is_required ? "🔴 الزامی" : "🔵 اختیاری";
            $message .= "• {$field->field_label} ({$field->field_name})\n";
            $message .= "  📝 نوع: {$field->field_type} | {$status} | ترتیب: {$field->sort_order}\n\n";
        }

        $message .= "🔄 تعداد: " . count($activeFields) . " فیلد فعال";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔙 بازگشت به پنل فیلد ها', 'callback_data' => 'field_panel']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function addNewAdmin($user, $chatId, $newAdminId)
    {
        try {
            $existingAdmin = Administrator::where('telegram_id', $newAdminId)->first();

            if ($existingAdmin) {
                $this->telegram->sendMessage($chatId, "✅ این کاربر از قبل مدیر است");
                return;
            }

            Administrator::create([
                'telegram_id' => $newAdminId,
                'username' => 'unknown',
                'first_name' => 'New Admin'
            ]);

            $this->telegram->sendMessage($chatId, "✅ کاربر با آیدی {$newAdminId} به عنوان مدیر اضافه شد");
        } catch (Exception $e) {
            $this->telegram->sendMessage($chatId, "❌ خطا در افزودن مدیر: " . $e->getMessage());
        }
    }
    private function adminManageFields($user, $chatId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        // استفاده از متد getAllFields
        $fields = ProfileField::getAllFields();

        $message = "⚙️ **مدیریت فیلدها**\n\n";
        $message .= "تعداد فیلدها: " . count($fields) . "\n\n";

        foreach ($fields as $field) {
            $status = $field->is_active ? "✅ فعال" : "❌ غیرفعال";
            $required = $field->is_required ? "🔴 الزامی" : "🔵 اختیاری";
            $message .= "• **{$field->field_label}**\n";
            $message .= "  نام: `{$field->field_name}`\n";
            $message .= "  نوع: {$field->field_type} | {$status} | {$required}\n";
            $message .= "  ترتیب: {$field->sort_order}\n\n";
        }

        $keyboard = [];

        // دکمه‌های تغییر وضعیت برای هر فیلد
        foreach ($fields as $field) {
            $toggleText = $field->is_active ? "❌ غیرفعال" : "✅ فعال";
            $keyboard[] = [
                [
                    'text' => "{$toggleText} {$field->field_label}",
                    'callback_data' => "admin_toggle_field:{$field->id}"
                ]
            ];
        }

        // دکمه‌های اصلی
        $keyboard[] = [
            ['text' => '➕ افزودن فیلد جدید', 'callback_data' => 'admin_add_field'],
            ['text' => '🔄 هماهنگ‌سازی', 'callback_data' => 'admin_sync_fields']
        ];
        $keyboard[] = [
            ['text' => '🔙 بازگشت به پنل مدیریت فیلد ها', 'callback_data' => 'field_panel']
        ];

        $this->telegram->sendMessage($chatId, $message, [
            'inline_keyboard' => $keyboard,
            'parse_mode' => 'Markdown'
        ]);
    }

    private function adminToggleField($user, $chatId, $fieldId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        try {
            // استفاده از getAllFields و پیدا کردن فیلد مورد نظر
            $fields = ProfileField::getAllFields();
            $field = null;

            foreach ($fields as $f) {
                if ($f->id == $fieldId) {
                    $field = $f;
                    break;
                }
            }

            if (!$field) {
                $this->telegram->sendMessage($chatId, "❌ فیلد پیدا نشد");
                return;
            }

            // تغییر وضعیت فیلد
            $newStatus = !$field->is_active;

            // آپدیت در دیتابیس
            $pdo = $this->getPDO();
            $sql = "UPDATE profile_fields SET is_active = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([$newStatus ? 1 : 0, $fieldId]);

            if ($result) {
                $statusText = $newStatus ? "فعال" : "غیرفعال";
                $this->telegram->sendMessage($chatId, "✅ فیلد **{$field->field_label}** {$statusText} شد");

                // برگشت به صفحه مدیریت بعد از 1 ثانیه
                sleep(1);
                $this->adminManageFields($user, $chatId);
            } else {
                $this->telegram->sendMessage($chatId, "❌ خطا در تغییر وضعیت فیلد");
            }
        } catch (\Exception $e) {
            error_log("❌ Error in adminToggleField: " . $e->getMessage());
            $this->telegram->sendMessage($chatId, "❌ خطا در تغییر وضعیت فیلد: " . $e->getMessage());
        }
    }
    private function adminAddField($user, $chatId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        $message = "➕ **افزودن فیلد جدید**\n\n";
        $message .= "لطفاً نوع فیلد جدید را انتخاب کنید:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📝 متن ساده', 'callback_data' => 'admin_add_field_type:text'],
                    ['text' => '🔢 عدد', 'callback_data' => 'admin_add_field_type:number']
                ],
                [
                    ['text' => '📋 لیست انتخابی', 'callback_data' => 'admin_add_field_type:select'],
                    ['text' => '📄 متن طولانی', 'callback_data' => 'admin_add_field_type:textarea']
                ],
                [

                    ['text' => '🔙 بازگشت به مدیریت فیلدها', 'callback_data' => 'admin_manage_fields']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function adminAddFieldStep1($user, $chatId, $fieldType)
    {
        // ایجاد داده‌های جدید
        $tempData = [
            'field_type' => $fieldType,
            'step' => 1
        ];

        // ذخیره مستقیم با مدل
        $user->temp_data = json_encode($tempData);
        $user->state = 'admin_adding_field';
        $user->save();

        $typeLabels = [
            'text' => 'متن ساده',
            'number' => 'عدد',
            'select' => 'لیست انتخابی',
            'textarea' => 'متون طولانی'
        ];

        $message = "➕ **افزودن فیلد جدید - مرحله ۱**\n\n";
        $message .= "📝 نوع فیلد: **{$typeLabels[$fieldType]}**\n\n";
        $message .= "لطفاً **نام فیلد** را وارد کنید (انگلیسی و بدون فاصله):\n";
        $message .= "مثال: `hobby`, `favorite_color`, `phone_number`\n\n";
        $message .= "⚠️ فقط از حروف انگلیسی، اعداد و underline استفاده کنید.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '❌ انصراف', 'callback_data' => 'admin_add_field_cancel']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function adminAddFieldStep2($user, $chatId, $fieldName, $tempData)
    {
        // اعتبارسنجی نام فیلد
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $fieldName)) {
            $this->telegram->sendMessage($chatId, "❌ نام فیلد نامعتبر!\n\nلطفاً فقط از حروف کوچک انگلیسی، اعداد و underline استفاده کنید.\nمثال: `hobby`, `phone_number`");
            return;
        }

        // 🔴 تغییر: فقط چک کنید وجود دارد، اما ایجاد نکنید
        $existingField = ProfileField::whereFieldName($fieldName);
        if ($existingField) {
            $this->telegram->sendMessage($chatId, "❌ فیلد با این نام از قبل وجود دارد!\n\nلطفاً نام دیگری انتخاب کنید.");
            return;
        }

        // آپدیت temp_data (فقط ذخیره اطلاعات، ایجاد نکنید)
        $tempData['field_name'] = $fieldName;
        $tempData['step'] = 2;

        $user->temp_data = json_encode($tempData);
        $user->state = 'admin_adding_field_step2';
        $user->save();

        $message = "➕ **افزودن فیلد جدید - مرحله ２**\n\n";
        $message .= "📝 نوع فیلد: **{$this->getFieldTypeLabel($tempData['field_type'])}**\n";
        $message .= "🔤 نام فیلد: **{$fieldName}**\n\n";
        $message .= "لطفاً **عنوان فارسی** فیلد را وارد کنید:\n";
        $message .= "مثال: `سرگرمی`, `شماره تلفن`, `رنگ مورد علاقه`";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '❌ انصراف', 'callback_data' => 'admin_add_field_cancel']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function adminAddFieldStep3($user, $chatId, $fieldLabel, $tempData)
    {
        // آپدیت temp_data (فقط ذخیره اطلاعات، ایجاد نکنید)
        $tempData['field_label'] = $fieldLabel;
        $tempData['step'] = 3;

        $user->temp_data = json_encode($tempData);
        $user->state = 'admin_adding_field_step3';
        $user->save();

        $message = "➕ **افزودن فیلد جدید - مرحله ３**\n\n";
        $message .= "📝 نوع فیلد: **{$this->getFieldTypeLabel($tempData['field_type'])}**\n";
        $message .= "🔤 نام فیلد: **{$tempData['field_name']}**\n";
        $message .= "📋 عنوان فارسی: **{$fieldLabel}**\n\n";
        $message .= "آیا این فیلد **الزامی** باشد؟";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ بله - الزامی', 'callback_data' => 'admin_add_field_required:1'],
                    ['text' => '🔵 خیر - اختیاری', 'callback_data' => 'admin_add_field_required:0']
                ],
                [
                    ['text' => '❌ انصراف', 'callback_data' => 'admin_add_field_cancel']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function adminAddFieldFinalize($user, $chatId, $isRequired)
    {
        // ابتدا کاربر رو refresh کنیم تا آخرین داده‌ها رو بگیریم
        $user->refresh();
        $tempData = json_decode($user->temp_data, true) ?? [];

        error_log("🔍 Finalize - temp_data: " . print_r($tempData, true));

        // بررسی وجود داده‌های ضروری
        if (empty($tempData) || !isset($tempData['field_name']) || !isset($tempData['field_label']) || !isset($tempData['field_type'])) {
            $this->telegram->sendMessage($chatId, "❌ داده‌های فیلد گم شده! لطفاً فرآیند را از ابتدا شروع کنید.");

            // بازنشانی state
            $user->update([
                'state' => 'main_menu',
                'temp_data' => null
            ]);

            $this->adminManageFields($user, $chatId);
            return;
        }

        // 🔴 تغییر: چک کنید آیا فیلد از قبل وجود دارد (برای اطمینان)
        $existingField = ProfileField::whereFieldName($tempData['field_name']);

        if ($existingField) {
            $this->telegram->sendMessage($chatId, "❌ فیلد با نام '{$tempData['field_name']}' از قبل وجود دارد! لطفاً فرآیند را از ابتدا شروع کنید و نام دیگری انتخاب کنید.");

            // بازنشانی state
            $user->update([
                'state' => 'main_menu',
                'temp_data' => null
            ]);

            return;
        }

        try {
            // محاسبه sort_order
            $maxSortOrder = ProfileField::max('sort_order');
            $sortOrder = $maxSortOrder ? $maxSortOrder + 1 : 1;

            // 🔴 ایجاد فیلد جدید فقط در این مرحله
            $newField = ProfileField::create([
                'field_name' => $tempData['field_name'],
                'field_label' => $tempData['field_label'],
                'field_type' => $tempData['field_type'],
                'is_required' => $isRequired,
                'is_active' => true,
                'sort_order' => $sortOrder,
                'options' => $tempData['field_type'] === 'select' ? '[]' : null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            error_log("✅ فیلد ایجاد شد: {$tempData['field_name']}");

            // اضافه کردن فیلد به جدول users
            $fieldType = $this->getSQLType($tempData['field_type']);
            try {
                \Illuminate\Support\Facades\DB::statement(
                    "ALTER TABLE users ADD COLUMN {$tempData['field_name']} {$fieldType}"
                );
                error_log("✅ فیلد به جدول users اضافه شد: {$tempData['field_name']}");
            } catch (\Exception $e) {
                error_log("⚠️ خطا در اضافه کردن فیلد به users: " . $e->getMessage());
                // ادامه می‌دهیم حتی اگر اضافه کردن به users با مشکل مواجه شود
            }

            // بازنشانی state کاربر
            $user->update([
                'state' => 'main_menu',
                'temp_data' => null
            ]);

            $requiredText = $isRequired ? "الزامی" : "اختیاری";

            $message = "🎉 **فیلد جدید با موفقیت ایجاد شد!**\n\n";
            $message .= "📝 نوع: **{$this->getFieldTypeLabel($tempData['field_type'])}**\n";
            $message .= "🔤 نام: **{$tempData['field_name']}**\n";
            $message .= "📋 عنوان: **{$tempData['field_label']}**\n";
            $message .= "⚙️ وضعیت: **{$requiredText}**\n";
            $message .= "🔢 ترتیب: **{$sortOrder}**\n\n";
            $message .= "✅ فیلد در profile_fields ایجاد شد\n";
            $message .= "✅ فیلد به جدول users اضافه شد\n\n";
            $message .= "حالا کاربران می‌توانند این فیلد را در پروفایل خود پر کنند.";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '⚙️ مدیریت فیلدها', 'callback_data' => 'admin_manage_fields'],
                        ['text' => '👑 پنل فیلد ها', 'callback_data' => 'field_panel']
                    ]
                ]
            ];

            $this->telegram->sendMessage($chatId, $message, $keyboard);
        } catch (\Exception $e) {
            error_log("❌ خطا در ایجاد فیلد: " . $e->getMessage());

            $user->update([
                'state' => 'main_menu',
                'temp_data' => null
            ]);

            $errorMessage = "❌ خطا در ایجاد فیلد: " . $e->getMessage();

            // اگر خطای تکراری بود، پیام مناسب‌تری بده
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $errorMessage = "❌ فیلد با این نام از قبل وجود دارد! لطفاً نام دیگری انتخاب کنید.";
            }

            $this->telegram->sendMessage($chatId, $errorMessage);
        }
    }
    private function getFieldTypeLabel($type)
    {
        $labels = [
            'text' => 'متن ساده',
            'number' => 'عدد',
            'select' => 'لیست انتخابی',
            'textarea' => 'متون طولانی'

        ];

        return $labels[$type] ?? $type;
    }

    private function getSQLType($fieldType)
    {
        $types = [
            'text' => 'VARCHAR(255) NULL',
            'number' => 'INT NULL',
            'select' => 'VARCHAR(255) NULL',
            'textarea' => 'TEXT NULL'

        ];

        return $types[$fieldType] ?? 'VARCHAR(255) NULL';
    }
    private function adminAddFieldCancel($user, $chatId)
    {
        $user->update([
            'state' => 'main_menu',
            'temp_data' => null
        ]);

        $this->telegram->sendMessage($chatId, "❌ افزودن فیلد جدید لغو شد.");
        $this->adminManageFields($user, $chatId);
    }

   private function handleGetSuggestion($user, $chatId)
{
    error_log("🎯 handleGetSuggestion START - User: {$user->id}, Profile Completed: " . ($user->is_profile_completed ? 'YES' : 'NO'));

    // 1. چک کردن فعال بودن حساب کاربر درخواست‌کننده
    if (!$user->is_active) {
        $message = "⏸️ **حساب شما غیرفعال است!**\n\n";
        $message .= "در حال حاضر نمی‌توانید پیشنهاد دریافت کنید.\n\n";
        $message .= "📝 برای فعال‌سازی حساب، از منوی اصلی   '▶️ فعال‌سازی حساب' را انتخاب کنید.";

        $keyboard = [
            ['▶️ فعال سازی حساب'],
            ['🔙 بازگشت به منوی اصلی']
        ];

        $this->sendMessage($chatId, $message, $keyboard);
        return;
    }

    // 2. چک کردن تکمیل بودن پروفایل کاربر درخواست‌کننده
    if (!$user->is_profile_completed) {
        $message = "❌ **برای دریافت پیشنهاد باید پروفایل شما تکمیل باشد!**\n\n";

        $missingFields = $this->getMissingRequiredFields($user);
        if (!empty($missingFields)) {
            $message .= "🔴 فیلدهای اجباری زیر تکمیل نشده‌اند:\n";
            foreach ($missingFields as $field) {
                $message .= "• {$field->field_label}\n";
            }
            $message .= "\n";
        }

        $completionPercent = $this->calculateProfileCompletion($user);
        $message .= "📊 میزان تکمیل پروفایل: {$completionPercent}%\n\n";
        $message .= "لطفاً ابتدا پروفایل خود را از منوی زیر تکمیل کنید:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📝 تکمیل پروفایل', 'callback_data' => 'profile_edit_start'],
                    ['text' => '📊 وضعیت پروفایل', 'callback_data' => 'profile_status']
                ],
                [
                    ['text' => '🔙 بازگشت', 'callback_data' => 'main_menu']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
        return;
    }

    // 🔴 **3. بررسی سهمیه رایگان کاربر (10 پیشنهاد رایگان برای کاربران جدید)**
    $remainingFreeQuota = $this->checkUserFreeQuota($user);
    
    if ($remainingFreeQuota > 0) {
        // کاربر هنوز سهمیه رایگان دارد
        error_log("🎯 کاربر {$user->id} سهمیه رایگان دارد: {$remainingFreeQuota} پیشنهاد باقی مانده");
        
        // استفاده از یک سهمیه رایگان
        $this->useFreeQuota($user);
        
        // دریافت فیلترهای کاربر و پیدا کردن پیشنهاد
        $userFilters = UserFilter::getFilters($user->id);
        $suggestedUser = $this->findSuggestionWithFilters($user, $userFilters);
        
        // نمایش پیام استفاده از سهمیه رایگان
        $remainingAfterUse = $remainingFreeQuota - 1;
        $message = "🎁 **شما از سهمیه رایگان خود استفاده کردید!**\n\n";
        $message .= "📊 سهمیه رایگان باقی‌مانده: {$remainingAfterUse} از 10 پیشنهاد\n\n";
        
        if ($remainingAfterUse == 0) {
            $message .= "⚠️ **توجه:** سهمیه رایگان شما به پایان رسید.\n";
            $message .= "برای ادامه استفاده، لطفاً اشتراک تهیه کنید.\n\n";
        }
        
        $this->telegram->sendMessage($chatId, $message);
        
    } else {
        // 🔴 **4. بررسی اشتراک فعال کاربر درخواست‌کننده (اگر سهمیه رایگان تمام شد)**
        $subscription = $user->getActiveSubscription();

        if (!$subscription) {
            $message = "❌ **سهمیه رایگان شما به پایان رسیده است!**\n\n";
            $message .= "🎁 شما 10 پیشنهاد رایگان خود را استفاده کرده‌اید.\n\n";
            $message .= "💎 **برای ادامه نیاز به اشتراک دارید!**\n";
            $message .= "با خرید اشتراک می‌توانید:\n";
            $message .= "• پیشنهادات نامحدود دریافت کنید\n";
            $message .= "• اطلاعات تماس کاربران را مشاهده کنید\n";
            $message .= "• از امکانات ویژه ربات استفاده کنید\n\n";
            $message .= "📋 برای مشاهده پلن‌های اشتراک، از منوی اصلی گزینه '💎 اشتراک من' را انتخاب کنید.";

            $this->telegram->sendMessage($chatId, $message);
            return;
        }

        // 🔴 **5. بررسی فعال بودن اشتراک**
        if (!$subscription->isActive()) {
            $message = "❌ **اشتراک شما فعال نیست!**\n\n";
            $message .= "⏰ تاریخ انقضا: " . $subscription->expiry_date . "\n";
            $message .= "📋 برای تمدید اشتراک، از منوی اصلی گزینه '💎 اشتراک من' را انتخاب کنید.";

            $this->telegram->sendMessage($chatId, $message);
            return;
        }

        // 🔴 **6. بررسی سهمیه پیشنهادات کاربر درخواست‌کننده**
        if (!$subscription->canViewSuggestion()) {
            $stats = $subscription->getUsageStats();

            $message = "❌ **سهمیه پیشنهادات شما تمام شده!**\n\n";
            $message .= "📊 **سهمیه روزانه:**\n";
            $message .= "• استفاده شده: {$stats['daily_suggestions']['used']} از {$stats['daily_suggestions']['total']}\n";
            $message .= "• باقی‌مانده: {$stats['daily_suggestions']['remaining']}\n\n";
            $message .= "📊 **سهمیه کلی:**\n";
            $message .= "• استفاده شده: {$stats['total_suggestions']['used']} از {$stats['total_suggestions']['total']}\n";
            $message .= "• باقی‌مانده: {$stats['total_suggestions']['remaining']}\n\n";
            $message .= "⏰ سهمیه روزانه هر 24 ساعت شارژ می‌شود.\n";
            $message .= "🔄 برای افزایش سهمیه، می‌توانید اشتراک خود را ارتقا دهید.";

            $this->telegram->sendMessage($chatId, $message);
            return;
        }

        // 🔴 **استفاده از سهمیه اشتراک**
        if (!$subscription->useSuggestionView()) {
            $this->telegram->sendMessage($chatId, "❌ خطا در استفاده از سهمیه پیشنهادات!");
            return;
        }

        error_log("🎯 درخواست پیشنهاد برای کاربر: {$user->id} - {$user->first_name}");

        // دریافت فیلترهای کاربر
        $userFilters = UserFilter::getFilters($user->id);
        error_log("📋 فیلترهای کاربر: " . json_encode($userFilters));
        error_log("🔍 Calling findSuggestionWithFilters...");

        // پیدا کردن پیشنهاد
        $suggestedUser = $this->findSuggestionWithFilters($user, $userFilters);
    }

    // 🔴 **دیباگ: بررسی نوع suggestedUser**
    if ($suggestedUser) {
        error_log("📊 Suggested user type: " . gettype($suggestedUser));
        error_log("📊 Suggested user class: " . get_class($suggestedUser));
        error_log("📊 Suggested user ID: " . ($suggestedUser->id ?? 'null'));
        error_log("📊 Is User instance? " . ($suggestedUser instanceof \App\Models\User ? 'YES' : 'NO'));
    } else {
        error_log("📊 Suggested user is NULL");
    }

    if (!$suggestedUser) {
        $message = "😔 **در حال حاضر کاربر مناسبی برای نمایش پیدا نشد!**\n\n";

        // نمایش فیلترهای فعال
        $activeFilters = [];
        foreach ($userFilters as $field => $value) {
            if (!empty($value)) {
                $fieldLabel = $this->getFilterLabel($field);

                if ($field === 'city' && is_array($value) && !empty($value)) {
                    $activeFilters[] = "**{$fieldLabel}**: " . implode(', ', $value);
                } else if ($value !== '') {
                    $activeFilters[] = "**{$fieldLabel}**: {$value}";
                }
            }
        }

        if (!empty($activeFilters)) {
            $message .= "🔍 **فیلترهای فعال شما:**\n";
            $message .= implode("\n", $activeFilters) . "\n\n";
        }

        $message .= "⚠️ **دلایل ممکن:**\n";
        $message .= "• کاربران با مشخصات مورد نظر شما در سیستم موجود نیستند\n";
        $message .= "• همه کاربران مناسب قبلاً به شما نمایش داده شده‌اند\n";
        $message .= "• ممکن است نیاز باشد فیلترهای خود را گسترده‌تر کنید\n\n";

        $message .= "💡 **راه‌حل‌ها:**\n";
        $message .= "• فیلترهای خود را بازبینی کنید\n";
        $message .= "• محدوده فیلترها را گسترده‌تر کنید\n";
        $message .= "• برخی فیلترها را غیرفعال کنید\n";
        $message .= "• چند ساعت دیگر مجدد تلاش کنید\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⚙️ تغییر فیلترها', 'callback_data' => 'edit_filters'],
                    ['text' => '🔄 بازنشانی فیلترها', 'callback_data' => 'reset_filters']
                ],
                [
                    ['text' => '🔍 دیباگ داده‌ها', 'callback_data' => 'debug_users'],
                    ['text' => '🔧 دیباگ فیلترها', 'callback_data' => 'debug_filter_logic']
                ],
                [
                    ['text' => '🔙 منوی اصلی', 'callback_data' => 'main_menu']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
        return;
    }

    // 🔴 **تبدیل suggestedUser به مدل User اگر stdClass است**
    if ($suggestedUser instanceof \stdClass) {
        error_log("⚠️ Suggested user is stdClass, converting to User model...");

        $userId = $suggestedUser->id ?? $suggestedUser->ID ?? null;

        if ($userId) {
            // بارگذاری از دیتابیس
            $suggestedUser = \App\Models\User::find($userId);
            if (!$suggestedUser) {
                $this->telegram->sendMessage($chatId, "❌ کاربر مورد نظر در سیستم یافت نشد.");
                return;
            }
        } else {
            $this->telegram->sendMessage($chatId, "❌ اطلاعات کاربر ناقص است.");
            return;
        }
    }

    // 🔴 **بررسی نهایی که suggestedUser یک مدل User است**
    if (!($suggestedUser instanceof \App\Models\User)) {
        error_log("❌ Suggested user is not a User model: " . gettype($suggestedUser));
        $this->telegram->sendMessage($chatId, "❌ خطا در بارگذاری اطلاعات کاربر.");
        return;
    }

    // 🔴 **6. نمایش پیشنهاد به کاربر**
    $this->showSuggestion($user, $chatId, $suggestedUser);
}

// تابع جدید: بررسی سهمیه رایگان کاربر
private function checkUserFreeQuota($user)
{
    // حداکثر سهمیه رایگان
    $maxFreeQuota = 10;
    
    // اگر کاربر قبلاً اشتراک خریده باشد، سهمیه رایگان را بررسی نمی‌کنیم
    if ($user->subscription_id || $user->getActiveSubscription()) {
        return 0;
    }
    
    // اگر کاربر برای اولین بار است که از سهمیه استفاده می‌کند، سهمیه کامل را بده
    if (!isset($user->free_quota_used) || $user->free_quota_used === null) {
        return $maxFreeQuota;
    }
    
    // محاسبه سهمیه باقی‌مانده
    $remaining = $maxFreeQuota - $user->free_quota_used;
    return max(0, $remaining);
}

private function useFreeQuota($user)
{
    if (!isset($user->free_quota_used) || $user->free_quota_used === null) {
        $user->free_quota_used = 1;
    } else {
        $user->free_quota_used += 1;
    }
    
    $user->save();
    
    error_log("📊 کاربر {$user->id} از سهمیه رایگان استفاده کرد. تعداد استفاده: {$user->free_quota_used}");
    
    return true;
}
    private function findSuggestionWithFilters($user, $userFilters)
    {
        PerformanceMonitor::start('total_request');
        error_log("🎯 **شروع findSuggestionWithFilters** - کاربر: {$user->id}");

        // ابتدا فایلترها رو بررسی کن
        error_log("📋 فیلترهای کاربر: " . json_encode($userFilters));

        $hasActiveFilters = $this->hasActiveFilters($userFilters);
        error_log("🔍 فیلتر فعال وجود دارد: " . ($hasActiveFilters ? "بله" : "خیر"));

        // کاربرانی که قبلاً نمایش داده شده‌اند
        $excludedUsers = \App\Models\UserSuggestion::getAlreadyShownUsers($user->id);
        $excludedUsers[] = $user->id;

        $suitableUsers = [];

        if ($hasActiveFilters) {
            error_log("🔍 استفاده از منطق فیلترهای کاربر");
            $suitableUsers = $this->findSuitableUsersWithFilters($user, $userFilters, $excludedUsers);
            error_log("🔍 کاربران یافت شده با فیلتر: " . count($suitableUsers));

            // 🔴 تغییر مهم: اگر با فیلترها کاربری پیدا نشد، null برگردان - به منطق پیشفرض نرو!
            if (empty($suitableUsers)) {
                error_log("❌ هیچ کاربری با فیلترها یافت نشد - بازگشت null");
                PerformanceMonitor::start('total_request');
                return null;
            }

            // 🔴 **اگر findSuitableUsersWithFilters از PDO استفاده می‌کند، باید نتایج را به User تبدیل کنیم**
            $suitableUsers = $this->convertResultsToUserModels($suitableUsers);
        } else {
            error_log("🔍 استفاده از منطق پیشفرض (بدون فیلتر فعال)");
            $suitableUsers = $this->findSuggestionWithDefaultLogic($user, true);
            // این متد حالا آرایه‌ای از اشیاء User برمی‌گرداند
        }

        // 🔴 **مطمئن شویم که همه عناصر آرایه User هستند**
        $suitableUsers = array_filter($suitableUsers, function ($item) {
            return $item instanceof \App\Models\User;
        });

        // 🔴 فیلتر کردن کاربران غیرفعال
        $suitableUsers = array_filter($suitableUsers, function ($suggestedUser) {
            return $suggestedUser->is_active == 1;
        });

        error_log("🔍 مجموع کاربران مناسب (پس از فیلتر): " . count($suitableUsers));

        if (empty($suitableUsers)) {
            error_log("❌ هیچ کاربر مناسبی در سیستم وجود ندارد");
            return null;
        }

        // انتخاب تصادفی یک کاربر
        $randomIndex = array_rand($suitableUsers);
        $suggestedUser = $suitableUsers[$randomIndex];

        // ثبت در تاریخچه
        \App\Models\UserSuggestion::create($user->id, $suggestedUser->id);

        error_log("✅ کاربر انتخاب شده: {$suggestedUser->id} - {$suggestedUser->first_name}");
        error_log("✅ جنسیت کاربر انتخاب شده: {$suggestedUser->gender}");
        error_log("✅ شهر کاربر انتخاب شده: {$suggestedUser->city}");

        PerformanceMonitor::start('total_request');
        return $suggestedUser;
    }


    private function findSuitableUsersWithFilters($user, $filters, $excludedUsers)
    {
        PerformanceMonitor::start('filtered_search');
        error_log("🎯 **شروع findSuitableUsersWithFilters (Eloquent)** - کاربر: {$user->id}");
        error_log("📋 فیلترهای ورودی: " . json_encode($filters, JSON_UNESCAPED_UNICODE));

        try {
            // ساخت کوئری اصلی
            $query = \App\Models\User::whereNotIn('id', $excludedUsers)
                ->where('is_profile_completed', true)
                ->where('is_active', true);

            error_log("🎯 **اجرای منطق AND بین فیلترها**");

            // 🔴 **decode کردن کاراکترهای فارسی در فیلترها**
            array_walk_recursive($filters, function (&$value) {
                if (is_string($value)) {
                    $decoded = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
                    $value = trim($decoded);
                }
            });

            error_log("🔤 فیلترهای decode شده: " . json_encode($filters, JSON_UNESCAPED_UNICODE));

            // 🔴 **فیلتر جنسیت**
            $genderFilterApplied = false;
            $dbGender = '';

            if (isset($filters['gender']) && !empty($filters['gender']) && $filters['gender'] !== '') {
                $genderFilter = trim($filters['gender']);
                error_log("🔵 پردازش فیلتر جنسیت: '{$genderFilter}'");

                // تبدیل فیلتر فارسی به فرمت دیتابیس
                if (in_array(strtolower($genderFilter), ['زن', 'خانم', 'ز', 'female', 'f', '2'])) {
                    $dbGender = 'female';
                    error_log("🔄 تبدیل فیلتر '{$genderFilter}' به '{$dbGender}' برای دیتابیس");
                } elseif (in_array(strtolower($genderFilter), ['مرد', 'آقا', 'م', 'male', 'm', '1'])) {
                    $dbGender = 'male';
                    error_log("🔄 تبدیل فیلتر '{$genderFilter}' به '{$dbGender}' برای دیتابیس");
                }

                if (!empty($dbGender)) {
                    $query->where('gender', $dbGender);
                    error_log("✅ فیلتر جنسیت اعمال شد: gender = '{$dbGender}'");
                    $genderFilterApplied = true;
                } else {
                    error_log("⚠️ جنسیت نامعتبر یا ناشناخته: '{$genderFilter}'");
                }
            }

            // اگر فیلتر جنسیت اعمال نشد، از منطق پیشفرض استفاده کن
            if (!$genderFilterApplied) {
                error_log("⚪ فیلتر جنسیت: خالی یا تنظیم نشده - استفاده از منطق پیشفرض");

                // جنسیت کاربر فعلی را از دیتابیس بگیر (male/female)
                $userGender = strtolower(trim($user->gender ?? ''));
                error_log("🔍 جنسیت کاربر فعلی ({$user->id}) در دیتابیس: '{$userGender}'");

                if ($userGender === 'male') {
                    // کاربر مرد است، پس زن‌ها (female) را نشان بده
                    $dbGender = 'female';
                    $query->where('gender', 'female');
                    error_log("✅ منطق پیشفرض: کاربر male است، فقط female را نشان بده");
                } elseif ($userGender === 'female') {
                    // کاربر زن است، پس مردها (male) را نشان بده
                    $dbGender = 'male';
                    $query->where('gender', 'male');
                    error_log("✅ منطق پیشفرض: کاربر female است، فقط male را نشان بده");
                } else {
                    // اگر جنسیت کاربر نامشخص است، از همه جنسیت‌ها استفاده کن
                    error_log("⚠️ جنسیت کاربر نامشخص است، فیلتر جنسیت اعمال نمی‌شود");
                }
            }

            // 🔴 **فیلتر شهر**
            if (isset($filters['city']) && !empty($filters['city'])) {
                if (is_array($filters['city']) && !empty($filters['city'])) {
                    $cityList = array_filter($filters['city']);
                    if (!empty($cityList)) {
                        $query->whereIn('city', $cityList);
                        error_log("✅ فیلتر شهر اعمال شد (چند شهری): " . json_encode($cityList, JSON_UNESCAPED_UNICODE));
                    }
                } elseif (!is_array($filters['city']) && trim($filters['city']) !== '') {
                    $query->where('city', trim($filters['city']));
                    error_log("✅ فیلتر شهر اعمال شد (تک شهری): {$filters['city']}");
                }
            } else {
                error_log("⚪ فیلتر شهر: خالی یا تنظیم نشده");
            }

            // 🔴 **فیلتر سن**
            $hasAgeFilter = false;

            if (isset($filters['min_age']) && !empty($filters['min_age']) && is_numeric($filters['min_age'])) {
                $minAge = intval($filters['min_age']);
                if ($minAge > 0) {
                    $query->where('age', '>=', $minAge);
                    $hasAgeFilter = true;
                    error_log("✅ فیلتر حداقل سن اعمال شد: {$minAge} سال (age >= {$minAge})");
                }
            }

            if (isset($filters['max_age']) && !empty($filters['max_age']) && is_numeric($filters['max_age'])) {
                $maxAge = intval($filters['max_age']);
                if ($maxAge > 0) {
                    $query->where('age', '<=', $maxAge);
                    $hasAgeFilter = true;
                    error_log("✅ فیلتر حداکثر سن اعمال شد: {$maxAge} سال (age <= {$maxAge})");
                }
            }

            // اگر فیلتر سن وجود ندارد، محدوده منطقی سنی اعمال کنید
            if (!$hasAgeFilter) {
                $query->whereBetween('age', [18, 100]);
                error_log("🔵 محدوده سنی پیشفرض: 18 تا 100 سال (age BETWEEN 18 AND 100)");
            }

            // 🔴 **حذف خود کاربر از نتایج**
            $query->where('id', '!=', $user->id);

            // 🔴 **دیباگ: نمایش کوئری نهایی**
            $sql = $query->toSql();
            $bindings = $query->getBindings();
            error_log("📝 کوئری نهایی: {$sql}");
            error_log("🔢 پارامترهای کوئری: " . json_encode($bindings));

            // اجرای کوئری
            $results = $query->inRandomOrder()->limit(50)->get();

            error_log("✅ تعداد کاربران یافت شده با Eloquent: " . count($results));

            // 🔴 دیباگ دقیق نتایج
            if (!empty($results)) {
                error_log("👥 **نتایج فیلتر شده (Eloquent):**");
                foreach ($results as $index => $resultUser) {
                    error_log("   {$index}. ID:{$resultUser->id} - {$resultUser->first_name} - جنسیت:{$resultUser->gender} - شهر:{$resultUser->city} - سن:{$resultUser->age}");
                }
            } else {
                error_log("⚠️ هیچ کاربری با فیلترهای اعمال شده یافت نشد");

                // 🔴 **دیباگ کامل: چک کن که آیا کاربرانی با جنسیت مورد نظر در سیستم وجود دارند**
                if ($genderFilterApplied && !empty($dbGender)) {
                    // استفاده از مدل User به جای DB facade
                    $countByGender = \App\Models\User::where('gender', $dbGender)
                        ->where('is_profile_completed', true)
                        ->where('is_active', true)
                        ->where('id', '!=', $user->id)
                        ->count();

                    error_log("🔍 تعداد کاربران فعال با جنسیت '{$dbGender}': {$countByGender}");

                    if ($countByGender > 0) {
                        // اگر کاربر وجود دارد، ولی کوئری برنگشته، ممکن است مشکل فیلترهای دیگر باشد
                        $sampleUsers = \App\Models\User::where('gender', $dbGender)
                            ->where('is_profile_completed', true)
                            ->where('is_active', true)
                            ->where('id', '!=', $user->id)
                            ->limit(5)
                            ->get(['id', 'first_name', 'gender', 'age', 'city']);

                        error_log("👥 نمونه کاربران با جنسیت '{$dbGender}':");
                        foreach ($sampleUsers as $sample) {
                            error_log("   - ID:{$sample->id} - {$sample->first_name} - سن:{$sample->age} - شهر:{$sample->city}");
                        }

                        // 🔴 **دیباگ اضافه: چک کردن فیلترهای سن و شهر**
                        $countAgeCity = \App\Models\User::where('gender', $dbGender)
                            ->where('is_profile_completed', true)
                            ->where('is_active', true)
                            ->where('id', '!=', $user->id)
                            ->whereBetween('age', [18, 100])
                            ->count();

                        error_log("🔍 تعداد با محدوده سنی 18-100: {$countAgeCity}");
                    }
                }
            }

            PerformanceMonitor::end('filtered_search');
            return $results->all();
        } catch (\Exception $e) {
            error_log("❌ خطا در findSuitableUsersWithFilters (Eloquent): " . $e->getMessage());
            error_log("❌ Stack trace: " . $e->getTraceAsString());
            return [];
        }
    }
    private function findSuggestion($user)
    {
        // کاربرانی که قبلاً بیش از 2 بار نمایش داده شده‌اند
        $excludedUsers = \App\Models\UserSuggestion::getAlreadyShownUsers($user->id);
        $excludedUsers[] = $user->id;

        // پیدا کردن کاربران مناسب - فقط کاربران با پروفایل کامل
        $suitableUsers = $this->findSuitableUsers($user, $excludedUsers);

        // 🔴 اگر کاربری پیدا نشد، محدودیت نمایش رو بردار اما فقط کاربران کامل
        if (empty($suitableUsers)) {
            error_log("⚠️ هیچ کاربر مناسبی پیدا نشد. حذف محدودیت نمایش...");
            $suitableUsers = $this->findSuitableUsers($user, [$user->id]);
        }

        // 🔴 اگر بازهم کاربری پیدا نشد، همه کاربران کامل رو در نظر بگیر
        if (empty($suitableUsers)) {
            error_log("⚠️ هنوز هیچ کاربری پیدا نشد. جستجوی گسترده...");
            $suitableUsers = $this->findAllUsers($user, [$user->id]);
        }

        if (empty($suitableUsers)) {
            error_log("❌ واقعاً هیچ کاربر کاملی در سیستم وجود ندارد!");
            return null;
        }

        // انتخاب تصادفی یک کاربر
        $randomIndex = array_rand($suitableUsers);
        $suggestedUser = $suitableUsers[$randomIndex];

        // ثبت در تاریخچه
        \App\Models\UserSuggestion::create($user->id, $suggestedUser->id);

        return $suggestedUser;
    }
    private function findSuggestionWithDefaultLogic($user, $returnArray = false)
    {
        PerformanceMonitor::start('find_suggestion_default');
        error_log("🔵 **استفاده از منطق پیشفرض** برای کاربر: {$user->id}");

        // // 🔴 **دیباگ منطق جنسیت مخالف**
        // $this->debugOppositeGenderLogic($user->gender ?? '');

        // کاربرانی که قبلاً نمایش داده شده‌اند
        $excludedUsers = \App\Models\UserSuggestion::getAlreadyShownUsers($user->id);
        $excludedUsers[] = $user->id;

        // اگر کاربر جنسیت خودش را تنظیم نکرده، همه کاربران کامل را نمایش بده
        if (empty($user->gender)) {
            error_log("🔵 کاربر جنسیت خود را تنظیم نکرده - نمایش همه کاربران کامل");

            $query = \App\Models\User::whereNotIn('id', $excludedUsers)
                ->where('is_profile_completed', true)
                ->where('is_active', true)
                ->inRandomOrder()
                ->limit(50);

            try {
                $results = $query->get();
                error_log("🔵 تعداد کاربران یافت شده (بدون فیلتر جنسیت): " . count($results));

                if ($returnArray) {
                    return $results->all();
                }

                if ($results->isEmpty()) {
                    return null;
                }

                $suggestedUser = $results->random();
                \App\Models\UserSuggestion::create($user->id, $suggestedUser->id);

                return $suggestedUser;
            } catch (\Exception $e) {
                error_log("❌ خطا در منطق پیشفرض بدون جنسیت: " . $e->getMessage());
                return $returnArray ? [] : null;
            }
        }

        // پیدا کردن کاربران با جنسیت مخالف و پروفایل کامل
        // 🔴 **استفاده از منطق ساده‌شده**
        $userGender = strtolower(trim($user->gender));
        $oppositeGender = '';

        if (in_array($userGender, ['male', 'مرد', 'm', 'آقا', '1'])) {
            $oppositeGender = 'female';
        } elseif (in_array($userGender, ['female', 'زن', 'f', 'خانم', '2'])) {
            $oppositeGender = 'male';
        }

        error_log("🔵 جنسیت کاربر: '{$user->gender}' -> جنسیت مخالف: '{$oppositeGender}'");

        try {
            $query = \App\Models\User::whereNotIn('id', $excludedUsers)
                ->where('is_profile_completed', true)
                ->where('is_active', true);

            // 🔴 **فقط جستجوی جنسیت مخالف به صورت دقیق**
            if (!empty($oppositeGender)) {
                $query->where('gender', $oppositeGender);
                error_log("✅ فیلتر جنسیت مخالف اعمال شد: gender = '{$oppositeGender}'");
            } else {
                error_log("⚠️ نتوانستیم جنسیت مخالف را تشخیص دهیم - بدون فیلتر جنسیت");
            }

            $results = $query->inRandomOrder()->limit(50)->get();

            error_log("🔵 تعداد کاربران یافت شده با منطق پیشفرض: " . count($results));

            // 🔴 **دیباگ: نمایش نمونه نتایج**
            if ($results->count() > 0) {
                error_log("👥 **نمونه نتایج منطق پیشفرض:**");
                foreach ($results->take(3) as $index => $result) {
                    error_log("   {$index}. ID:{$result->id} - {$result->first_name} - جنسیت:{$result->gender}");
                }
            }

            if ($returnArray) {
                PerformanceMonitor::start('find_suggestion_default');
                return $results->all();
            }

            if ($results->isEmpty()) {
                error_log("❌ هیچ کاربری با منطق پیشفرض یافت نشد");

                // 🔴 **دیباگ: چک کردن چرا کاربری یافت نشد**
                $debugQuery = \App\Models\User::where('is_profile_completed', true)
                    ->where('is_active', true)
                    ->where('id', '!=', $user->id);

                if (!empty($oppositeGender)) {
                    $debugQuery->where('gender', $oppositeGender);
                }

                $debugCount = $debugQuery->count();
                error_log("🔍 دیباگ: {$debugCount} کاربر با شرایط کلی وجود دارد");

                return null;
            }

            // انتخاب تصادفی یک کاربر
            $suggestedUser = $results->random();

            // ثبت در تاریخچه
            \App\Models\UserSuggestion::create($user->id, $suggestedUser->id);

            error_log("✅ کاربر انتخاب شده با منطق پیشفرض: {$suggestedUser->id} - {$suggestedUser->first_name}");
            error_log("✅ جنسیت انتخاب شده: {$suggestedUser->gender}");

            return $suggestedUser;
        } catch (\Exception $e) {
            error_log("❌ خطا در منطق پیشفرض: " . $e->getMessage());
            return $returnArray ? [] : null;
        }
    }


    // 🔴 **اضافه کردن این متد برای تبدیل نتایج PDO به مدل User**
    private function convertResultsToUserModels($results)
    {
        $userModels = [];

        foreach ($results as $result) {
            if ($result instanceof \App\Models\User) {
                $userModels[] = $result;
            } elseif ($result instanceof \stdClass || is_array($result)) {
                // تبدیل به آرایه
                $data = (array)$result;

                // پیدا کردن ID
                $userId = $data['id'] ?? $data['ID'] ?? null;

                if ($userId) {
                    // بارگذاری از دیتابیس
                    $user = \App\Models\User::find($userId);
                    if ($user) {
                        $userModels[] = $user;
                    } else {
                        // اگر پیدا نشد، از داده‌های موجود ایجاد کنید
                        $user = new \App\Models\User();
                        foreach ($data as $key => $value) {
                            $user->$key = $value;
                        }
                        $userModels[] = $user;
                    }
                }
            }
        }

        return $userModels;
    }
    private function hasActiveFilters($filters)
    {
        if (empty($filters)) {
            return false;
        }

        // چک کردن فیلترهای اصلی
        $activeFilters = [
            'gender' => isset($filters['gender']) && !empty($filters['gender']) && $filters['gender'] !== '',
            'min_age' => isset($filters['min_age']) && !empty($filters['min_age']) && $filters['min_age'] !== '',
            'max_age' => isset($filters['max_age']) && !empty($filters['max_age']) && $filters['max_age'] !== '',
            'city' => isset($filters['city']) && !empty($filters['city']) && $filters['city'] !== '' && $filters['city'] !== [] && $filters['city'] !== [''],
        ];

        // 🔴 **دیباگ: بررسی دقیق فیلترها**
        error_log("🔍 **بررسی hasActiveFilters:**");
        error_log("📋 فیلترهای ورودی: " . json_encode($filters, JSON_UNESCAPED_UNICODE));
        error_log("✅ وضعیت فعال بودن:");
        foreach ($activeFilters as $key => $value) {
            error_log("   - {$key}: " . ($value ? 'فعال' : 'غیرفعال'));
        }

        $hasActive = in_array(true, $activeFilters);
        error_log("🔍 نتیجه hasActiveFilters: " . ($hasActive ? 'بله' : 'خیر'));

        return $hasActive;
    }

    private function findWithDefaultLogic($user, $excludedUsers)
    {
        $pdo = $this->getPDO();

        // استفاده از فیلد جنسیت واقعی
        $userGender = $user->gender;

        if (empty($userGender)) {
            // اگر کاربر جنسیت خودش رو تنظیم نکرده، همه کاربران کامل رو نمایش بده
            return $this->findAllUsers($user, $excludedUsers);
        }

        $oppositeGender = $this->getOppositeGender($userGender);

        if (empty($excludedUsers)) {
            $excludedUsers = [0];
        }

        $excludedStr = implode(',', $excludedUsers);

        // فقط کاربران با پروفایل کامل
        $sql = "SELECT * FROM users 
            WHERE id NOT IN ($excludedStr) 
            AND is_profile_completed = 1 
            AND gender = ? 
            LIMIT 50";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$oppositeGender]);

        return $stmt->fetchAll(\PDO::FETCH_CLASS, 'App\Models\User');
    }
    private function findSuitableUsers($user, $excludedUsers)
    {
        $pdo = $this->getPDO();

        // اگر کاربر فیلتر شخصی دارد
        $filters = \App\Models\UserFilter::getFilters($user->id);

        // 🔴 اگر فیلترها خالی هستند، از منطق پیشفرض استفاده کن
        if (empty($filters)) {
            return $this->findWithDefaultLogic($user, $excludedUsers);
        }

        // در غیر این صورت از فیلترهای کاربر استفاده کن
        return $this->findWithCustomFilters($user, $filters, $excludedUsers);
    }



    private function findAllUsers($user, $excludedUsers)
    {
        $pdo = $this->getPDO();

        if (empty($excludedUsers)) {
            $excludedUsers = [0];
        }

        $excludedStr = implode(',', $excludedUsers);

        $sql = "SELECT * FROM users 
            WHERE id NOT IN ($excludedStr) 
            AND is_profile_completed = 1 
            LIMIT 50";

        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_CLASS, 'App\Models\User');
    }

    private function getOppositeGender($gender)
    {
        $opposites = [
            'مرد' => 'زن',
            'زن' => 'مرد',
            'male' => 'female',
            'female' => 'male',
            '1' => '2',
            '2' => '1'
        ];

        return $opposites[$gender] ?? 'زن'; // مقدار پیشفرض
    }
    private function showSuggestion($user, $chatId, $suggestedUser)
    {
        // 🔴 **این متد فقط اطلاعات را نمایش می‌دهد - هیچ بررسی اشتراکی برای suggestedUser ندارد**

        $message = "📋 **مشخصات:**\n\n";

        // نمایش فیلدهای عمومی پروفایل
        $activeFields = ProfileField::getActiveFields();
        $displayedFieldsCount = 0;

        foreach ($activeFields as $field) {
            if ($this->shouldDisplayField($user, $field)) {
                $value = $suggestedUser->{$field->field_name} ?? 'تعیین نشده';

                if ($field->field_name === 'gender') {
                    $value = $this->convertGenderForDisplay($value);
                } elseif ($field->field_type === 'select' && is_numeric($value)) {
                    $value = $this->convertSelectValueToText($field, $value);
                }

                $message .= "✅ {$field->field_label} : {$value}\n";
                $displayedFieldsCount++;
            }
        }

        if ($displayedFieldsCount === 0) {
            $message .= "👀 اطلاعات بیشتری برای نمایش موجود نیست.\n";
        }

        $shownCount = \App\Models\UserSuggestion::getShownCount($user->id, $suggestedUser->id);
        $message .= "\n⭐ این فرد {$shownCount} بار برای شما نمایش داده شده است.";

        // 🔴 **حذف وضعیت اشتراک کاربر پیشنهادی**
        // ❌ خط زیر را حذف کنید
        // $subscriptionStatus = $suggestedUser->hasActiveSubscription() ? "✅ دارای اشتراک فعال" : "❌ بدون اشتراک فعال";
        // $message .= "\n📊 وضعیت اشتراک: {$subscriptionStatus}";

        // 🔴 **حذف اطلاع‌رسانی درباره اشتراک کاربر پیشنهادی**
        // ❌ این بلاک را حذف کنید
        // if (!$suggestedUser->hasActiveSubscription()) {
        //     $message .= "\n⚠️ **توجه:** این کاربر در حال حاضر اشتراک فعال ندارد.";
        //     $message .= "\nبرای درخواست تماس باید منتظر بمانید تا اشتراک تهیه کند.";
        // }

        // 🔴 **اضافه کردن دکمه لایک**
        $hasLiked = \App\Models\Like::hasLiked($user->id, $suggestedUser->id);

        $likeButtonText = $hasLiked ? '✅ لایک شده' : '❤️ لایک';
        $likeCallbackData = $hasLiked ? 'already_liked' : "like:{$suggestedUser->id}";

        // 🔴 **دکمه درخواست تماس - همیشه فعال است**
        // ✅ کاربر پیشنهادی نیازی به اشتراک ندارد
        $contactButtonText = "📞 درخواست اطلاعات تماس";
        $contactCallbackData = "request_contact:{$suggestedUser->id}";

        // 🔴 دکمه‌های اینلاین
      $inlineKeyboard = [
    'inline_keyboard' => [
        // سطر اول: دکمه درخواست اطلاعات تماس
        [
            ['text' => $contactButtonText, 'callback_data' => $contactCallbackData]
        ],
        // سطر دوم: دکمه لایک
        [
            ['text' => $likeButtonText, 'callback_data' => $likeCallbackData]
        ]
    ]
];

        // 🔵 دکمه‌های دیگر به صورت ReplyKeyboard معمولی
        $replyKeyboard = [
            'keyboard' => [
                [
                    ['text' => '💌 پیشنهاد بعدی']
                ],
                [
                    ['text' => '⚙️ تنظیم فیلترها'],
                    ['text' => '🔙 منوی اصلی']
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];

        if ($suggestedUser->telegram_photo_id) {
            try {
                // 🔴 ارسال عکس و اطلاعات در یک پیام با caption
                $this->telegram->sendPhoto($chatId, $suggestedUser->telegram_photo_id, $message, $inlineKeyboard);
            } catch (\Exception $e) {
                error_log("❌ Error showing suggestion with photo: " . $e->getMessage());
                // اگر ارسال عکس با caption شکست خورد، فقط متن را نمایش بده
                $this->telegram->sendMessage($chatId, $message, $inlineKeyboard);
            }
        } else {
            // اگر کاربر عکس ندارد، فقط متن را نمایش بده
            $this->telegram->sendMessage($chatId, $message, $inlineKeyboard);
        }

        // ارسال کیبورد معمولی
        $this->telegram->sendMessage($chatId, "لطفاً یکی از گزینه‌های زیر را انتخاب کنید:", $replyKeyboard);

        $newState = 'viewing_suggestion:' . $suggestedUser->id;
        $user->update(['state' => $newState]);
        error_log("💾 STATE UPDATED: {$newState}");
    }
    // 🔴 متد جدید برای چک کردن نمایش فیلد
    private function shouldDisplayField($user, $field)
    {
        // اگر کاربر اشتراک دارد، همه فیلدها رو نمایش بده
        if ($this->userHasSubscription($user)) {
            return true;
        }

        // اگر کاربر اشتراک ندارد و فیلد مخفی هست، نمایش نده
        if ($field->is_hidden_for_non_subscribers) {
            return false;
        }

        return true;
    }

    // 🔴 متد جدید برای چک کردن اشتراک کاربر
    private function userHasSubscription($user)
    {
        // اینجا منطق چک کردن اشتراک کاربر رو پیاده‌سازی کنید
        // فعلاً از مدل Subscription استفاده می‌کنیم
        return \App\Models\UserSubscription::hasActiveSubscription($user->id);
    }

    private function getFieldOptions($field)
    {
        // اگر فیلد select نیست، آرایه خالی برگردان
        if ($field->field_type !== 'select') {
            return [];
        }

        // اگر options رشته JSON هست، decode کن
        if (is_string($field->options)) {
            $decoded = json_decode($field->options, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        // اگر options آرایه هست، مستقیماً برگردون
        if (is_array($field->options)) {
            return $field->options;
        }

        // اگر options خالی یا null هست
        return [];
    }

    private function debugFieldOptions($user, $chatId)
    {
        $allFields = ProfileField::getActiveFields();
        $selectFields = array_filter($allFields, function ($field) {
            return $field->field_type === 'select';
        });

        $message = "🔍 **دیباگ فیلدهای Select**\n\n";

        foreach ($selectFields as $field) {
            $options = $this->getFieldOptions($field);
            $message .= "**{$field->field_label}** (`{$field->field_name}`)\n";
            $message .= "options نوع: " . gettype($field->options) . "\n";
            $message .= "options مقدار: " . (is_string($field->options) ? $field->options : json_encode($field->options)) . "\n";
            $message .= "گزینه‌ها: " . (empty($options) ? "❌ خالی" : implode(', ', $options)) . "\n";
            $message .= "────────────\n";
        }

        $this->telegram->sendMessage($chatId, $message);
    }

    private function findWithCustomFilters($user, $filters, $excludedUsers)
    {
        $pdo = $this->getPDO();

        $conditions = [];
        $params = [];

        // فیلتر جنسیت
        if (isset($filters['gender']) && !empty($filters['gender'])) {
            $conditions[] = "gender = ?";
            $params[] = $filters['gender'];
        }

        // 🔴 فیلتر شهر (چند شهری)
        if (isset($filters['city']) && !empty($filters['city']) && is_array($filters['city'])) {
            $placeholders = implode(',', array_fill(0, count($filters['city']), '?'));
            $conditions[] = "city IN ($placeholders)";
            $params = array_merge($params, $filters['city']);
        }

        // فیلتر سن
        if (isset($filters['min_age']) && !empty($filters['min_age'])) {
            $conditions[] = "age >= ?";
            $params[] = $filters['min_age'];
        }

        if (isset($filters['max_age']) && !empty($filters['max_age'])) {
            $conditions[] = "age <= ?";
            $params[] = $filters['max_age'];
        }

        // ساخت شرط WHERE
        $whereClause = "";
        if (!empty($conditions)) {
            $whereClause = "AND " . implode(" AND ", $conditions);
        }

        if (empty($excludedUsers)) {
            $excludedUsers = [0];
        }

        $excludedStr = implode(',', $excludedUsers);

        $sql = "SELECT * FROM users 
            WHERE id NOT IN ($excludedStr) 
            AND is_profile_completed = 1 
            {$whereClause}
            LIMIT 50";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_CLASS, 'App\Models\User');
    }
    private function getAvailableFilters()
    {
        try {
            // خواندن فیلترهای فعال از SystemFilter
            $systemFilters = SystemFilter::getActiveFilters();

            if (!empty($systemFilters)) {
                $filters = [];
                foreach ($systemFilters as $filter) {
                    $filterData = [
                        'field_name' => $filter->field_name,
                        'field_label' => $filter->field_label,
                        'type' => $filter->filter_type,
                    ];

                    // اگر فیلتر از نوع select است، options را اضافه کن
                    if ($filter->filter_type === 'select' && !empty($filter->options)) {
                        $options = json_decode($filter->options, true) ?? [];
                        $filterData['options'] = $options;
                    }

                    $filters[] = $filterData;
                }
                return $filters;
            }
        } catch (\Exception $e) {
            error_log("❌ Error in getAvailableFilters: " . $e->getMessage());
        }

        // 🔴 اگر system_filters خالی است، از دیتابیس پر کن
        $this->autoCreateSystemFilters();

        // دوباره تلاش کن
        return $this->getAvailableFilters();
    }

    private function createSystemFilter($field, $filterType)
    {
        try {
            $pdo = $this->getPDO();

            $sql = "INSERT INTO system_filters (field_name, field_label, filter_type, options, is_active, sort_order, created_at, updated_at) 
                VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())";

            $stmt = $pdo->prepare($sql);

            // تعیین options بر اساس نوع فیلتر
            $options = null;
            if ($filterType === 'select') {
                if ($field->field_name === 'gender') {
                    $options = json_encode(['مرد', 'زن']);
                } elseif ($field->field_name === 'city') {
                    $options = json_encode($this->getCities());
                } else {
                    $fieldOptions = $this->getFieldOptions($field);
                    $options = json_encode($fieldOptions);
                }
            }

            // محاسبه sort_order
            $maxOrder = $this->getMaxSystemFilterOrder();
            $sortOrder = $maxOrder + 1;

            $result = $stmt->execute([
                $field->field_name,
                $field->field_label,
                $filterType,
                $options,
                $sortOrder
            ]);

            if ($result) {
                error_log("✅ فیلتر سیستم ایجاد شد: {$field->field_name} - {$filterType}");
                return true;
            }

            return false;
        } catch (\Exception $e) {
            error_log("❌ خطا در ایجاد فیلتر سیستم {$field->field_name}: " . $e->getMessage());
            return false;
        }
    }

    private function getMaxSystemFilterOrder()
    {
        try {
            $pdo = $this->getPDO();
            $sql = "SELECT MAX(sort_order) as max_order FROM system_filters";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch(\PDO::FETCH_OBJ);
            return $result->max_order ?? 0;
        } catch (\Exception $e) {
            error_log("❌ خطا در دریافت max order: " . $e->getMessage());
            return 0;
        }
    }

    private function updateSystemFilter($existingFilter, $field, $filterType)
    {
        try {
            $pdo = $this->getPDO();

            $sql = "UPDATE system_filters SET field_label = ?, filter_type = ?, options = ?, updated_at = NOW() WHERE id = ?";

            $stmt = $pdo->prepare($sql);

            // تعیین options بر اساس نوع فیلتر
            $options = null;
            if ($filterType === 'select') {
                if ($field->field_name === 'gender') {
                    $options = json_encode(['مرد', 'زن']);
                } elseif ($field->field_name === 'city') {
                    $options = json_encode($this->getCities());
                } else {
                    $fieldOptions = $this->getFieldOptions($field);
                    $options = json_encode($fieldOptions);
                }
            }

            $result = $stmt->execute([
                $field->field_label,
                $filterType,
                $options,
                $existingFilter->id
            ]);

            if ($result) {
                error_log("✅ فیلتر سیستم آپدیت شد: {$field->field_name}");
                return true;
            }

            return false;
        } catch (\Exception $e) {
            error_log("❌ خطا در آپدیت فیلتر سیستم {$field->field_name}: " . $e->getMessage());
            return false;
        }
    }

    // 🔴 متد جدید: ایجاد خودکار فیلترهای سیستم
    private function autoCreateSystemFilters()
    {
        try {
            $activeFields = ProfileField::getActiveFields();

            foreach ($activeFields as $field) {
                $filterType = $this->determineFilterType($field);

                if ($filterType && !SystemFilter::getFilterByFieldName($field->field_name)) {
                    SystemFilter::createSystemFilter($field, $filterType);
                    error_log("✅ فیلتر سیستم ایجاد شد: {$field->field_name}");
                }
            }
        } catch (\Exception $e) {
            error_log("❌ Error in autoCreateSystemFilters: " . $e->getMessage());
        }
    }
    private function createDefaultFilter($user)
    {
        if (!empty($user->gender)) {
            $defaultFilters = [
                'gender' => $this->getOppositeGender($user->gender)
            ];

            \App\Models\UserFilter::saveFilters($user->id, $defaultFilters);
            error_log("✅ فیلتر پیشفرض برای کاربر {$user->id} ایجاد شد");
        }
    }

    private function debugUsersStatus($user, $chatId)
    {
        $pdo = $this->getPDO();

        // تعداد کل کاربران
        $sql = "SELECT COUNT(*) as total FROM users";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $total = $stmt->fetch()['total'];

        // تعداد کاربران با پروفایل تکمیل شده
        $sql = "SELECT COUNT(*) as completed FROM users WHERE is_profile_completed = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $completed = $stmt->fetch()['completed'];

        // تعداد کاربران با جنسیت مخالف
        $userGender = $user->gender;
        $oppositeGender = $this->getOppositeGender($userGender);
        $sql = "SELECT COUNT(*) as opposite FROM users WHERE is_profile_completed = 1 AND gender = ? AND id != ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$oppositeGender, $user->id]);
        $opposite = $stmt->fetch()['opposite'];

        $message = "🔍 **وضعیت کاربران در سیستم**\n\n";
        $message .= "👥 کل کاربران: {$total}\n";
        $message .= "✅ پروفایل تکمیل شده: {$completed}\n";
        $message .= "⚧ جنسیت مخالف ({$oppositeGender}): {$opposite}\n";
        $message .= "👤 جنسیت شما: {$userGender}\n\n";

        // کاربران قابل پیشنهاد
        $excludedUsers = \App\Models\UserSuggestion::getAlreadyShownUsers($user->id);
        $excludedUsers[] = $user->id;
        $excludedStr = implode(',', $excludedUsers);

        $sql = "SELECT COUNT(*) as available FROM users 
            WHERE id NOT IN ($excludedStr) 
            AND is_profile_completed = 1 
            AND gender = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$oppositeGender]);
        $available = $stmt->fetch()['available'];

        $message .= "💌 کاربران قابل پیشنهاد: {$available}";

        $this->telegram->sendMessage($chatId, $message);
    }

    private function createTestUser($user, $chatId)
    {
        try {
            $pdo = $this->getPDO();

            // ایجاد یک کاربر تستی با جنسیت مخالف
            $oppositeGender = $this->getOppositeGender($user->gender);
            $testUsername = "test_user_" . time();

            $sql = "INSERT INTO users (telegram_id, username, first_name, last_name, gender, is_profile_completed, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())";

            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                rand(100000, 999999), // آیدی تصادفی
                $testUsername,
                'کاربر تستی',
                'Test',
                $oppositeGender
            ]);

            if ($result) {
                $userId = $pdo->lastInsertId();

                // پر کردن فیلدهای پروفایل تستی
                $updateSql = "UPDATE users SET ";
                $fields = [];
                $params = [];

                $activeFields = ProfileField::getActiveFields();
                foreach ($activeFields as $field) {
                    if ($field->field_name !== 'gender') { // جنسیت رو قبلاً ست کردیم
                        $fields[] = "{$field->field_name} = ?";

                        if ($field->field_type === 'select') {
                            $options = $this->getFieldOptions($field);
                            $params[] = !empty($options) ? '1' : 'مقدار تستی';
                        } elseif ($field->field_type === 'number') {
                            $params[] = '25';
                        } else {
                            $params[] = 'مقدار تستی برای ' . $field->field_label;
                        }
                    }
                }

                if (!empty($fields)) {
                    $updateSql .= implode(', ', $fields) . " WHERE id = ?";
                    $params[] = $userId;

                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->execute($params);
                }

                $this->telegram->sendMessage($chatId, "✅ کاربر تستی ایجاد شد! حالا دکمه 'دریافت پیشنهاد' رو بزنید.");
            } else {
                $this->telegram->sendMessage($chatId, "❌ خطا در ایجاد کاربر تستی");
            }
        } catch (\Exception $e) {
            error_log("Error creating test user: " . $e->getMessage());
            $this->telegram->sendMessage($chatId, "❌ خطا: " . $e->getMessage());
        }
    }
    private function adminManageHiddenFields($user, $chatId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        // استفاده از متد getActiveFields به جای where
        $fields = ProfileField::getActiveFields();

        $message = "👁️ **مدیریت نمایش فیلدها برای کاربران بدون اشتراک**\n\n";
        $message .= "فیلدهایی که در اینجا مخفی شوند، برای کاربران بدون اشتراک در پیشنهادات نمایش داده نمی‌شوند.\n\n";

        foreach ($fields as $field) {
            $hiddenStatus = $field->is_hidden_for_non_subscribers ? "👁️‍🗨️ مخفی" : "👀 قابل مشاهده";
            $message .= "• ✅ {$field->field_label} : (`{$field->field_name}`)\n";
            $message .= "  وضعیت: {$hiddenStatus}\n\n";
        }

        $keyboard = [];

        // دکمه‌های تغییر وضعیت برای هر فیلد
        foreach ($fields as $field) {
            $toggleText = $field->is_hidden_for_non_subscribers ? "👀 قابل مشاهده" : "👁️‍🗨️ مخفی";
            $keyboard[] = [
                [
                    'text' => "{$toggleText} {$field->field_label}",
                    'callback_data' => "admin_toggle_hidden:{$field->id}"
                ]
            ];
        }

        // دکمه‌های اصلی
        $keyboard[] = [
            ['text' => '🔙 بازگشت به پنل مدیریت', 'callback_data' => 'admin_plan']
        ];

        $this->telegram->sendMessage($chatId, $message, [
            'inline_keyboard' => $keyboard,
            'parse_mode' => 'Markdown'
        ]);
    }
    private function adminToggleHiddenField($user, $chatId, $fieldId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        try {
            // استفاده از getAllFields و پیدا کردن فیلد مورد نظر
            $fields = ProfileField::getAllFields();
            $field = null;

            foreach ($fields as $f) {
                if ($f->id == $fieldId) {
                    $field = $f;
                    break;
                }
            }

            if (!$field) {
                $this->telegram->sendMessage($chatId, "❌ فیلد پیدا نشد");
                return;
            }

            // تغییر وضعیت مخفی بودن
            $newHiddenStatus = !$field->is_hidden_for_non_subscribers;

            // آپدیت در دیتابیس
            $pdo = $this->getPDO();
            $sql = "UPDATE profile_fields SET is_hidden_for_non_subscribers = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([$newHiddenStatus ? 1 : 0, $fieldId]);

            if ($result) {
                $statusText = $newHiddenStatus ? "مخفی" : "قابل مشاهده";
                $this->telegram->sendMessage($chatId, "✅ فیلد **{$field->field_label}** برای کاربران بدون اشتراک {$statusText} شد");

                // برگشت به صفحه مدیریت بعد از 1 ثانیه
                sleep(1);
                $this->adminManageHiddenFields($user, $chatId);
            } else {
                $this->telegram->sendMessage($chatId, "❌ خطا در تغییر وضعیت فیلد");
            }
        } catch (\Exception $e) {
            error_log("❌ Error in adminToggleHiddenField: " . $e->getMessage());
            $this->telegram->sendMessage($chatId, "❌ خطا در تغییر وضعیت فیلد: " . $e->getMessage());
        }
    }
    private function handleContactRequest($user, $chatId, $suggestedUserId)
    {
        $suggestedUser = User::find($suggestedUserId);

        if (!$suggestedUser) {
            $this->telegram->sendMessage($chatId, "❌ کاربر پیدا نشد");
            return;
        }

        // 🔴 **بررسی اشتراک فعال کاربر درخواست‌کننده**
        if (!$user->hasActiveSubscription()) {
            $message = "❌ **برای درخواست اطلاعات تماس نیاز به اشتراک فعال دارید!**\n\n";
            $message .= "💎 با خرید اشتراک می‌توانید:\n";
            $message .= "• اطلاعات تماس کاربران را مشاهده کنید\n";
            $message .= "• پیشنهادات نامحدود دریافت کنید\n";
            $message .= "• از امکانات ویژه ربات استفاده کنید\n\n";
            $message .= "📋 برای مشاهده پلن‌های اشتراک، از منوی اصلی گزینه '💎 اشتراک من' را انتخاب کنید.";

            $this->telegram->sendMessage($chatId, $message);
            return;
        }

        $subscription = $user->getActiveSubscription();

        // 🔴 **بررسی سهمیه درخواست تماس کاربر درخواست‌کننده**
        if (!$subscription->canRequestContact()) {
            $stats = $subscription->getUsageStats();

            $message = "❌ **سهمیه درخواست تماس شما تمام شده!**\n\n";
            $message .= "📊 **سهمیه روزانه:**\n";
            $message .= "• استفاده شده: {$stats['daily_contacts']['used']} از {$stats['daily_contacts']['total']}\n";
            $message .= "• باقی‌مانده: {$stats['daily_contacts']['remaining']}\n\n";
            $message .= "📊 **سهمیه کلی:**\n";
            $message .= "• استفاده شده: {$stats['total_contacts']['used']} از {$stats['total_contacts']['total']}\n";
            $message .= "• باقی‌مانده: {$stats['total_contacts']['remaining']}\n\n";
            $message .= "⏰ سهمیه روزانه هر 24 ساعت شارژ می‌شود.\n";
            $message .= "🔄 برای افزایش سهمیه، می‌توانید اشتراک خود را ارتقا دهید.";

            $this->telegram->sendMessage($chatId, $message);
            return;
        }

        // 🔴 چک کردن آیا قبلاً درخواست داده شده و تأیید شده
        if ($this->hasApprovedContactRequest($user->id, $suggestedUserId)) {
            // اگر قبلاً تأیید شده، اطلاعات رو نمایش بده
            $this->showContactInfo($user, $chatId, $suggestedUserId, 0);
            return;
        }

        // 🔴 چک کردن آیا درخواست pending داریم
        $existingRequest = \App\Models\ContactRequest::where('requester_id', $user->id)
            ->where('requested_id', $suggestedUserId)
            ->first();

        if ($existingRequest) {
            if ($existingRequest->isPending()) {
                $this->telegram->sendMessage($chatId, "⏳ درخواست شما در انتظار تایید کاربر است.");
            } elseif ($existingRequest->isApproved()) {
                $this->showContactInfo($user, $chatId, $suggestedUserId, 0);
            } elseif ($existingRequest->isRejected()) {
                $this->telegram->sendMessage($chatId, "❌ درخواست قبلی شما توسط کاربر رد شده است.");
            } elseif ($existingRequest->isWaitingForSubscription()) {
                $this->telegram->sendMessage($chatId, "⏳ کاربر اشتراک فعال ندارد. منتظر باشید تا اشتراک تهیه کند.");
            }
            return;
        }

        // 🔴 **استفاده از سهمیه اشتراک کاربر درخواست‌کننده برای ارسال درخواست**
        if (!$subscription->useContactRequest()) {
            $this->telegram->sendMessage($chatId, "❌ خطا در استفاده از سهمیه درخواست تماس!");
            return;
        }

        // 🔴 ایجاد درخواست تماس جدید در سیستم دوطرفه
        $this->createContactRequest($user, $suggestedUser, $chatId);
    }
   private function sendContactRequestToRequestedUser($requestedUser, $requester, $requestId)
{
    $chatId = $requestedUser->telegram_id;

    // اطلاعات درخواست‌کننده
    $age = $requester->age ? "، {$requester->age} سال" : "";
    $city = $requester->city ? "، {$requester->city}" : "";

    $caption = "🔔 **درخواست جدید برای مشاهده اطلاعات تماس شما**\n\n";
    $caption .= "👤 **{$requester->first_name}{$age}{$city}**\n";
    $caption .= "📞 می‌خواهد اطلاعات تماس شما را دریافت کند.\n\n";
    $caption .= "💰 **تأثیر بر سهمیه شما:**\n";
    $caption .= "• در صورت تأیید، یک درخواست تماس از سهمیه شما کسر می‌شود.\n";
    $caption .= "• در صورت رد، هیچ سهمیه‌ای مصرف نمی‌شود.\n\n";
    $caption .= "🔍 قبل از تصمیم‌گیری می‌توانید پروفایل کاربر را مشاهده کنید.";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '👀 مشاهده پروفایل کاربر', 'callback_data' => 'view_requester_profile:' . $requester->id . ':' . $requestId]
            ],
            [
                ['text' => '✅ تأیید درخواست', 'callback_data' => 'approve_contact:' . $requestId],
                ['text' => '❌ رد درخواست', 'callback_data' => 'reject_contact:' . $requestId]
            ]
        ]
    ];

    try {
        // بررسی وجود telegram_photo_id در دیتابیس
        if (!empty($requester->telegram_photo_id) && $requester->telegram_photo_id !== 'null') {
            // ارسال عکس با استفاده از file_id ذخیره شده در دیتابیس
            $this->telegram->sendPhoto($chatId, $requester->telegram_photo_id, $caption, $keyboard);
        } else {
            // اگر عکس وجود ندارد، فقط پیام متنی ارسال شود
            $this->telegram->sendMessage($chatId, $caption, $keyboard);
        }
    } catch (\Exception $e) {
        // در صورت خطا، پیام متنی را ارسال کن
        error_log("Error sending photo: " . $e->getMessage());
        $this->telegram->sendMessage($chatId, $caption, $keyboard);
    }
}
    private function sendSubscriptionRequiredNotification($requestedUser, $requester)
    {
        $chatId = $requestedUser->telegram_id;

        $age = $requester->age ? "، {$requester->age} سال" : "";
        $city = $requester->city ? "، {$requester->city}" : "";

        $message = "🔔 **درخواست جدید برای مشاهده اطلاعات تماس شما**\n\n";
        $message .= "👤 **{$requester->first_name}{$age}{$city}**\n";
        $message .= "📞 می‌خواهد اطلاعات تماس شما را دریافت کند.\n\n";
        $message .= "⚠️ **برای ادامه نیاز به اشتراک دارید**\n";
        $message .= "برای مشاهده جزئیات درخواست و تأیید یا رد آن، باید اشتراک فعال داشته باشید.\n\n";
        $message .= "💎 از طریق منوی اصلی → 'اشتراک من' می‌توانید اشتراک تهیه کنید.\n\n";
        $message .= "📝 درخواست ذخیره شده و پس از فعال‌سازی اشتراک می‌توانید آن را بررسی کنید.";

        $this->telegram->sendMessage($chatId, $message);
    }
    private function getContactInfo($user)
    {
        $info = "👤 **{$user->first_name}";

        if ($user->last_name) {
            $info .= " {$user->last_name}";
        }

        $info .= "**\n\n";

        // نمایش اطلاعات تماس (مثلاً موبایل)
        if ($user->mobile) {
            $info .= "📱 موبایل: {$user->mobile}\n";
        }

        // نمایش آیدی تلگرام
        if ($user->username) {
            $info .= "🔗 آیدی تلگرام: @{$user->username}\n";
        }

        // اطلاعات اضافی
        if ($user->age) {
            $info .= "📅 سن: {$user->age}\n";
        }

        if ($user->city) {
            $info .= "🏙️ شهر: {$user->city}\n";
        }

        $info .= "\n💡 می‌توانید از طریق اطلاعات بالا با کاربر در ارتباط باشید.";

        return $info;
    }


    // // 🔴 متد جدید برای پردازش پرداخت پس از تأیید
    // private function processContactPayment($user, $chatId, $suggestedUserId)
    // {
    //     $cost = $this->getContactRequestCost();
    //     $wallet = $user->getWallet();
    //     $suggestedUser = User::find($suggestedUserId);

    //     if (!$suggestedUser) {
    //         $this->telegram->sendMessage($chatId, "❌ کاربر پیدا نشد");
    //         return;
    //     }

    //     // کسر هزینه از کیف پول با نوع تراکنش "purchase"
    //     $deductionResult = $wallet->deduct($cost, "خرید اطلاعات تماس - کاربر: {$suggestedUser->first_name}", "purchase"); // 🔴 تغییر نوع به purchase

    //     if (!$deductionResult) {
    //         $this->telegram->sendMessage($chatId, "❌ خطا در کسر مبلغ از کیف پول. لطفاً دوباره تلاش کنید.");
    //         return;
    //     }

    //     // اضافه کردن به تاریخچه
    //     ContactRequestHistory::addToHistory($user->id, $suggestedUserId, $cost);

    //     // نمایش اطلاعات تماس
    //     $this->showContactInfo($user, $chatId, $suggestedUserId, $cost);

    //     // علامت‌گذاری درخواست تماس در تاریخچه
    //     \App\Models\UserSuggestion::markContactRequested($user->id, $suggestedUserId);
    // }

    // متد نمایش اطلاعات تماس (برای وقتی که قبلاً تأیید شده)
    private function showContactInfo($user, $chatId, $requestedUserId, $cost)
    {
        $requestedUser = User::find($requestedUserId);
        if (!$requestedUser) {
            $this->telegram->sendMessage($chatId, "❌ کاربر پیدا نشد");
            return;
        }

        $contactInfo = $this->getContactInfo($requestedUser);

        $message = "✅ **اطلاعات تماس کاربر**\n\n";
        $message .= "👤 کاربر: " . $requestedUser->first_name_display . "\n\n";
        $message .= $contactInfo . "\n\n";
        $message .= "💾 این اطلاعات قبلاً تأیید شده و در تاریخچه شما موجود است.";

        // ایجاد کیبورد
        $keyboardButtons = [];

        if (!empty($requestedUser->username)) {
            $keyboardButtons[] = [
                ['text' => '📝 ارسال پیام به کاربر', 'url' => 'https://t.me/' . $requestedUser->username]
            ];
        }

        $keyboardButtons[] = [
            ['text' => '📋 تاریخچه تماس‌ها', 'callback_data' => 'contact_history']
        ];

        $keyboard = [
            'inline_keyboard' => $keyboardButtons
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }



    private function showContactHistory($user, $chatId, $page = 1)
    {
        $pdo = $this->getPDO();

        // محاسبه صفحه‌بندی
        $perPage = 6; // تغییر به 6 تا در هر صفحه 3 ردیف دو تایی داشته باشیم
        $offset = ($page - 1) * $perPage;

        // تعداد کل رکوردها
        $countSql = "SELECT COUNT(*) as total FROM contact_request_history WHERE user_id = ?";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute([$user->id]);
        $totalCount = $countStmt->fetch(\PDO::FETCH_OBJ)->total;
        $totalPages = ceil($totalCount / $perPage);

        // دریافت رکوردهای صفحه جاری
        $sql = "SELECT crh.*, u.first_name, u.username, u.telegram_id 
        FROM contact_request_history crh 
        JOIN users u ON crh.requested_user_id = u.id 
        WHERE crh.user_id = ? 
        ORDER BY crh.requested_at DESC 
        LIMIT ? OFFSET ?";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $user->id, \PDO::PARAM_INT);
        $stmt->bindValue(2, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $history = $stmt->fetchAll(\PDO::FETCH_OBJ);

        if (empty($history)) {
            $message = "📜 **تاریخچه درخواست‌های تماس**\n\n";
            $message .= "📭 شما تاکنون هیچ درخواست تماسی نداشته‌اید.\n\n";
            $message .= "💡 پس از درخواست اطلاعات تماس برای هر کاربر، اطلاعات آنها در اینجا ذخیره می‌شود و می‌توانید بدون پرداخت مجدد آنها را مشاهده کنید.";

            // کیبورد ثابت برای حالت خالی
            $replyKeyboard = [
                'keyboard' => [
                    [
                        ['text' => '💌 دریافت پیشنهاد'],
                        ['text' => '🔙 منوی اصلی']
                    ]
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => false
            ];

            $this->telegram->sendMessage($chatId, $message, $replyKeyboard);
            return;
        }

        $message = "📜 **تاریخچه درخواست‌های تماس شما**\n\n";
        $message .= "👥 تعداد کل: " . $totalCount . " نفر\n";
        $message .= "📄 صفحه: " . $page . " از " . $totalPages . "\n\n";

        foreach ($history as $index => $record) {
            $globalIndex = $offset + $index + 1;

            // تبدیل تاریخ به شمسی
            $requestDate = $this->toPersianDate($record->requested_at, 'Y/m/d');

            $message .= "**" . $globalIndex . ". {$record->first_name}**\n";
            $message .= "📅 {$requestDate} | 💰 " . number_format($record->amount_paid) . " تومان\n";
            $message .= "────────────\n";
        }

        // ایجاد کیبورد ثابت با دو دکمه در هر ردیف
        $keyboardRows = [];
        $tempRow = [];

        foreach ($history as $record) {
            $buttonText = "👤 {$record->first_name}";
            $tempRow[] = ['text' => $buttonText];

            // ذخیره با نوع 'contact'
            $this->saveButtonInfo($user->id, $buttonText, $record->requested_user_id, null, 'contact');

            if (count($tempRow) === 2) {
                $keyboardRows[] = $tempRow;
                $tempRow = [];
            }
        }

        // اگر دکمه‌های باقیمانده وجود داشت، آنها را اضافه کن
        if (!empty($tempRow)) {
            $keyboardRows[] = $tempRow;
        }

        // اضافه کردن دکمه‌های ناوبری
        $navButtons = [];
        if ($page > 1) {
            $navButtons[] = ['text' => '⏪ صفحه قبلی'];
        }
        if ($page < $totalPages) {
            $navButtons[] = ['text' => 'صفحه بعدی ⏩'];
        }

        if (!empty($navButtons)) {
            $keyboardRows[] = $navButtons;
        }

        // دکمه‌های اصلی
        $keyboardRows[] = [
            ['text' => '💌 پیشنهاد جدید'],
            ['text' => '🔙 منوی اصلی']
        ];

        // کیبورد معمولی ثابت
        $replyKeyboard = [
            'keyboard' => $keyboardRows,
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];

        // ارسال پیام با کیبورد ثابت
        $this->telegram->sendMessage($chatId, $message, $replyKeyboard);
    }

    // اگر تابع toPersianDate را ندارید، این را اضافه کنید:
    private function toPersianDate($date, $format = 'Y/m/d')
    {
        if (!$date) {
            return 'نامشخص';
        }

        try {
            $jdate = \Morilog\Jalali\Jalalian::fromDateTime($date);
            return $jdate->format($format);
        } catch (\Exception $e) {
            error_log("Persian date conversion error: " . $e->getMessage());

            // در صورت خطا، تاریخ میلادی برگردان
            if ($date instanceof \Carbon\Carbon) {
                return $date->format($format);
            }

            // اگر format شمسی را داریم، به میلادی تغییر دهیم
            $format = str_replace(
                ['Y', 'm', 'd', 'H', 'i', 's'],
                ['Y', 'm', 'd', 'H', 'i', 's'],
                $format
            );

            return date($format, strtotime($date));
        }
    }


    /**
     * ذخیره اطلاعات دکمه در جدول user_button_sessions
     */
    private function saveButtonInfo($userId, $buttonText, $requestedUserId = null, $planId = null, $sessionType = 'contact')
    {
        $pdo = $this->getPDO();

        // حذف رکوردهای قدیمی برای همین کاربر و نوع session
        $deleteSql = "DELETE FROM user_button_sessions WHERE user_id = ? AND session_type = ? AND button_text = ?";
        $deleteStmt = $pdo->prepare($deleteSql);
        $deleteStmt->execute([$userId, $sessionType, $buttonText]);

        // اضافه کردن رکورد جدید
        $sql = "INSERT INTO user_button_sessions (user_id, button_text, session_type, requested_user_id, plan_id, created_at, expires_at) 
            VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $buttonText, $sessionType, $requestedUserId, $planId]);
    }
    /**
     * بازیابی اطلاعات دکمه از جدول user_button_sessions
     */
    private function getButtonInfo($userId, $buttonText, $sessionType = null)
    {
        $pdo = $this->getPDO();

        if ($sessionType) {
            $sql = "SELECT requested_user_id, plan_id, session_type 
                FROM user_button_sessions 
                WHERE user_id = ? AND button_text = ? AND session_type = ?
                ORDER BY created_at DESC LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId, $buttonText, $sessionType]);
        } else {
            $sql = "SELECT requested_user_id, plan_id, session_type 
                FROM user_button_sessions 
                WHERE user_id = ? AND button_text = ? 
                ORDER BY created_at DESC LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId, $buttonText]);
        }

        return $stmt->fetch(\PDO::FETCH_OBJ);
    }
    private function clearUserButtonSessions($userId, $sessionType = null)
    {
        $pdo = $this->getPDO();

        if ($sessionType) {
            $sql = "DELETE FROM user_button_sessions WHERE user_id = ? AND session_type = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId, $sessionType]);
        } else {
            $sql = "DELETE FROM user_button_sessions WHERE user_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
        }
    }
    // private function cleanupExpiredSessions()
    // {
    //     $pdo = $this->getPDO();
    //     $sql = "DELETE FROM user_button_sessions WHERE expires_at < NOW()";
    //     $stmt = $pdo->prepare($sql);
    //     $stmt->execute();
    // }

    private function getRequestedUserIdFromButton($userId, $buttonText)
    {
        $pdo = $this->getPDO();
        $sql = "SELECT requested_user_id FROM user_button_sessions 
            WHERE user_id = ? AND button_text = ? 
            ORDER BY created_at DESC LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $buttonText]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);

        return $result ? $result->requested_user_id : null;
    }

    private function handleContactHistoryUserClick($user, $chatId, $buttonText)
    {
        $buttonInfo = $this->getButtonInfo($user->id, $buttonText, 'contact');

        if ($buttonInfo && $buttonInfo->requested_user_id) {
            $this->showContactDetails($user, $chatId, $buttonInfo->requested_user_id);
        } else {
            $this->telegram->sendMessage($chatId, "❌ اطلاعات کاربر یافت نشد.");
        }
    }
    private function showContactDetails($user, $chatId, $requestedUserId)
    {
        $pdo = $this->getPDO();

        // دریافت اطلاعات کاربر مورد نظر
        $userSql = "SELECT * FROM users WHERE id = ?";
        $userStmt = $pdo->prepare($userSql);
        $userStmt->execute([$requestedUserId]);
        $requestedUser = $userStmt->fetch(\PDO::FETCH_OBJ);

        if (!$requestedUser) {
            $this->telegram->sendMessage($chatId, "❌ کاربر پیدا نشد");
            return;
        }

        // دریافت اطلاعات تاریخچه
        $historySql = "SELECT * FROM contact_request_history WHERE user_id = ? AND requested_user_id = ?";
        $historyStmt = $pdo->prepare($historySql);
        $historyStmt->execute([$user->id, $requestedUserId]);
        $historyRecord = $historyStmt->fetch(\PDO::FETCH_OBJ);

        // استفاده از escape برای تمام مقادیر متغیر
        $firstName = $this->escapeMarkdown($requestedUser->first_name);
        $username = $this->escapeMarkdown($requestedUser->username);

        $message = "👤 **مشخصات کامل کاربر**\n\n";
        $message .= "**{$firstName}**\n";

        if (!empty($requestedUser->username)) {
            $message .= "📧 آیدی: @{$username}\n";
        }

        $message .= "🆔 شناسه: {$requestedUser->telegram_id}\n";

        if ($historyRecord) {
            $requestDate = date('Y-m-d H:i', strtotime($historyRecord->requested_at));
            $message .= "💰 مبلغ پرداختی: " . number_format($historyRecord->amount_paid) . " تومان\n";
            $message .= "📅 تاریخ درخواست: {$requestDate}\n";
        }

        $message .= "\n**مشخصات پروفایل:**\n";

        // نمایش فیلدهای پروفایل
        $activeFields = ProfileField::getActiveFields();
        $displayedCount = 0;

        foreach ($activeFields as $field) {
            $value = $requestedUser->{$field->field_name} ?? null;

            if (!empty($value)) {
                // تبدیل جنسیت به فارسی برای نمایش
                if ($field->field_name === 'gender') {
                    $value = $this->convertGenderForDisplay($value);
                }
                // اگر فیلد از نوع select هست و مقدار عددی داره، به متن تبدیل کن 
                elseif ($field->field_type === 'select' && is_numeric($value)) {
                    $value = $this->convertSelectValueToText($field, $value);
                }

                // escape کردن مقدار برای جلوگیری از مشکل Markdown
                $escapedValue = $this->escapeMarkdown($value);
                $escapedLabel = $this->escapeMarkdown($field->field_label);

                $message .= "• ✅ {$escapedLabel} : {$escapedValue}\n";
                $displayedCount++;
            }
        }

        if ($displayedCount === 0) {
            $message .= "📝 اطلاعات پروفایل تکمیل نشده است.\n";
        }

        $message .= "\n💡 این اطلاعات قبلاً توسط شما خریداری شده و اکنون رایگان در دسترس شماست.";

        // کیبورد معمولی (ثابت)
        $keyboard = [
            'keyboard' => [
                [
                    ['text' => '📜 بازگشت به تاریخچه'],
                    ['text' => '🔙 بازگشت به منوی اصلی']
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];

        // نمایش عکس و اطلاعات در یک پیام
        if ($requestedUser->telegram_photo_id) {
            try {
                // ارسال عکس با اطلاعات به عنوان caption - اضافه کردن parse_mode
                $this->telegram->sendPhoto($chatId, $requestedUser->telegram_photo_id, $message, $keyboard, 'Markdown');
            } catch (\Exception $e) {
                error_log("❌ Error sending photo with contact details: " . $e->getMessage());
                // اگر ارسال عکس شکست خورد، فقط متن را نمایش بده
                $this->telegram->sendMessage($chatId, $message, $keyboard, 'Markdown');
            }
        } else {
            // اگر کاربر عکس ندارد، فقط متن را نمایش بده
            $this->telegram->sendMessage($chatId, $message, $keyboard, 'Markdown');
        }
    }

    private function testFilterSystem($user, $chatId)
    {
        $userFilters = UserFilter::getFilters($user->id);
        $suitableUsers = $this->findSuitableUsersWithFilters($user, $userFilters, [$user->id]);

        $message = "🧪 **تست سیستم فیلترها**\n\n";
        $message .= "🔍 کاربران مناسب یافت شده: " . count($suitableUsers) . " نفر\n\n";

        if (!empty($suitableUsers)) {
            $message .= "📋 لیست کاربران:\n";
            foreach ($suitableUsers as $index => $sUser) {
                $message .= ($index + 1) . ". {$sUser->first_name}";
                $message .= " - جنسیت: " . ($sUser->gender ?? 'نامشخص');
                $message .= " - شهر: " . ($sUser->city ?? 'نامشخص');
                $message .= " - سن: " . ($sUser->age ?? 'نامشخص') . "\n";
            }
        } else {
            $message .= "❌ هیچ کاربر مناسبی پیدا نشد.\n";
            $message .= "⚠️ ممکن است:\n";
            $message .= "• فیلترها خیلی محدود باشند\n";
            $message .= "• کاربران کافی در سیستم نباشند\n";
            $message .= "• فیلدهای پروفایل کاربران پر نشده باشد";
        }

        $this->telegram->sendMessage($chatId, $message);
    }
    private function debugFilterSystem($user, $chatId)
    {
        $userFilters = UserFilter::getFilters($user->id);
        $availableFilters = $this->getAvailableFilters();

        $message = "🔍 **دیباگ سیستم فیلترها (منطق AND)**\n\n";

        $message .= "👤 **فیلترهای کاربر شما:**\n";
        foreach ($userFilters as $field => $value) {
            if (is_array($value)) {
                $message .= "• **{$field}**: [" . implode(', ', $value) . "]\n";
            } else {
                $message .= "• **{$field}**: {$value}\n";
            }
        }

        // تست کوئری
        $suitableUsers = $this->findSuitableUsersWithFilters($user, $userFilters, [$user->id]);

        $message .= "\n🔍 **تست کوئری با منطق AND:**\n";
        $message .= "• کاربران مناسب یافت شده: " . count($suitableUsers) . " نفر\n";

        if (!empty($suitableUsers)) {
            $message .= "• نمونه کاربران:\n";
            foreach (array_slice($suitableUsers, 0, 3) as $index => $sUser) {
                $message .= "  " . ($index + 1) . ". {$sUser->first_name}";
                $message .= " - جنسیت: " . ($sUser->gender ?? '❌');
                $message .= " - سن: " . ($sUser->age ?? '❌');
                $message .= " - شهر: " . ($sUser->city ?? '❌') . "\n";
            }
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 تست مجدد', 'callback_data' => 'debug_filters'],
                    ['text' => '🔙 مدیریت فیلترها', 'callback_data' => 'admin_filters_management']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function updateGenderFilter($user, $chatId)
    {
        try {
            $pdo = $this->getPDO();

            // بروزرسانی فیلتر جنسیت برای پشتیبانی از مقادیر مختلف
            $options = json_encode(['مرد', 'زن']);

            $sql = "UPDATE system_filters SET options = ?, updated_at = NOW() WHERE field_name = 'gender'";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([$options]);

            if ($result) {
                $message = "✅ **فیلتر جنسیت بروزرسانی شد**\n\n";
                $message .= "🎯 اکنون فیلتر جنسیت از مقادیر فارسی و انگلیسی پشتیبانی می‌کند.\n";
                $message .= "• مرد (مرد, male, 1, M)\n";
                $message .= "• زن (زن, female, 2, F)";
            } else {
                $message = "❌ **خطا در بروزرسانی فیلتر جنسیت**";
            }
        } catch (\Exception $e) {
            $message = "❌ **خطا در بروزرسانی فیلتر جنسیت:**\n" . $e->getMessage();
        }

        $this->telegram->sendMessage($chatId, $message);
    }
    private function convertGenderForDisplay($gender)
    {
        $mapping = [
            'male' => 'مرد',
            'female' => 'زن',
            '1' => 'مرد',
            '2' => 'زن',
            'M' => 'مرد',
            'F' => 'زن'
        ];

        return $mapping[$gender] ?? $gender;
    }

    private function fixAllFilterIssues($user, $chatId)
    {
        $message = "🔧 **رفع مشکلات فیلترها**\n\n";

        // 1. بروزرسانی فیلتر جنسیت
        try {
            $pdo = $this->getPDO();
            $options = json_encode(['مرد', 'زن']);
            $sql = "UPDATE system_filters SET options = ? WHERE field_name = 'gender'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$options]);
            $message .= "✅ فیلتر جنسیت بروزرسانی شد\n";
        } catch (\Exception $e) {
            $message .= "❌ خطا در بروزرسانی فیلتر جنسیت: " . $e->getMessage() . "\n";
        }

        // 2. بروزرسانی فیلتر شهر
        try {
            $cities = $this->getCities();
            $citiesJson = json_encode($cities);
            $sql = "UPDATE system_filters SET options = ? WHERE field_name = 'city'";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$citiesJson]);
            $message .= "✅ فیلتر شهر بروزرسانی شد (" . count($cities) . " شهر)\n";
        } catch (\Exception $e) {
            $message .= "❌ خطا در بروزرسانی فیلتر شهر: " . $e->getMessage() . "\n";
        }

        // 3. بررسی کاربران نمونه
        try {
            $sampleSql = "SELECT gender, COUNT(*) as count FROM users WHERE gender IS NOT NULL GROUP BY gender LIMIT 10";
            $stmt = $pdo->prepare($sampleSql);
            $stmt->execute();
            $genderStats = $stmt->fetchAll(\PDO::FETCH_OBJ);

            $message .= "\n📊 **نمونه مقادیر جنسیت در دیتابیس:**\n";
            foreach ($genderStats as $stat) {
                $message .= "• `{$stat->gender}`: {$stat->count} کاربر\n";
            }
        } catch (\Exception $e) {
            $message .= "❌ خطا در بررسی آمار جنسیت: " . $e->getMessage() . "\n";
        }

        $message .= "\n🎯 **سیستم فیلترها آماده استفاده است**";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧪 تست فیلترها', 'callback_data' => 'debug_filters'],
                    ['text' => '🔙 مدیریت فیلترها', 'callback_data' => 'admin_filters_management']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }
    private function chunkCitiesByWidth($cities, $maxWidth = 30)
    {
        $chunks = [];
        $currentChunk = [];
        $currentWidth = 0;

        foreach ($cities as $city) {
            $cityWidth = $this->calculateTextWidth($city);

            // اگر اضافه کردن این شهر از حداکثر عرض بیشتر شود، chunk جدید شروع کن
            if ($currentWidth + $cityWidth > $maxWidth && !empty($currentChunk)) {
                $chunks[] = $currentChunk;
                $currentChunk = [];
                $currentWidth = 0;
            }

            $currentChunk[] = $city;
            $currentWidth += $cityWidth + 2; // 2 برای padding بین دکمه‌ها
        }

        // اضافه کردن chunk آخر
        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }

    private function calculateTextWidth($text)
    {
        // محاسبه عرض تقریبی متن بر اساس تعداد کاراکتر
        // فرض: هر کاراکتر فارسی حدود 1.5 واحد عرض دارد
        $persianChars = preg_match_all('/[\x{0600}-\x{06FF}]/u', $text);
        $otherChars = mb_strlen($text, 'UTF-8') - $persianChars;

        return ($persianChars * 1.5) + $otherChars;
    }
    private function debugFilterLogic($user, $chatId)
    {
        $userFilters = UserFilter::getFilters($user->id);

        $message = "🔍 **دیباگ منطق فیلترها (AND)**\n\n";

        $message .= "👤 **فیلترهای کاربر شما:**\n";
        foreach ($userFilters as $field => $value) {
            if (is_array($value)) {
                $message .= "• **{$field}**: [" . implode(', ', $value) . "]\n";
            } else {
                $message .= "• **{$field}**: `{$value}`\n";
            }
        }

        // بررسی دقیق کوئری
        $excludedUsers = \App\Models\UserSuggestion::getAlreadyShownUsers($user->id);
        $excludedUsers[] = $user->id;

        $pdo = $this->getPDO();
        $conditions = [];
        $params = [];

        // شبیه‌سازی دقیق منطق AND
        $message .= "\n🔍 **تحلیل منطق AND:**\n";

        // فیلتر جنسیت
        if (isset($userFilters['gender']) && !empty($userFilters['gender'])) {
            $genderFilter = $userFilters['gender'];
            $genderMapping = [
                'مرد' => ['مرد', 'male', '1', 'M'],
                'زن' => ['زن', 'female', '2', 'F']
            ];

            if (isset($genderMapping[$genderFilter])) {
                $genderValues = $genderMapping[$genderFilter];
                $placeholders = implode(',', array_fill(0, count($genderValues), '?'));
                $conditions[] = "gender IN ($placeholders)";
                $params = array_merge($params, $genderValues);
                $message .= "✅ **جنسیت**: IN (" . implode(', ', $genderValues) . ")\n";
            }
        } else {
            $message .= "⚪ **جنسیت**: بدون فیلتر\n";
        }

        // فیلتر شهر
        if (isset($userFilters['city']) && !empty($userFilters['city'])) {
            if (is_array($userFilters['city']) && !empty($userFilters['city'])) {
                $placeholders = implode(',', array_fill(0, count($userFilters['city']), '?'));
                $conditions[] = "city IN ($placeholders)";
                $params = array_merge($params, $userFilters['city']);
                $message .= "✅ **شهر**: IN (" . implode(', ', $userFilters['city']) . ")\n";

                // بررسی وجود شهرها در دیتابیس
                $message .= "\n🔎 **بررسی شهرها در دیتابیس:**\n";
                foreach ($userFilters['city'] as $city) {
                    $sql = "SELECT COUNT(*) as count FROM users WHERE city = ? AND is_profile_completed = 1";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$city]);
                    $count = $stmt->fetch(\PDO::FETCH_OBJ)->count;
                    $message .= "• `{$city}`: {$count} کاربر\n";
                }
            }
        } else {
            $message .= "⚪ **شهر**: بدون فیلتر\n";
        }

        // فیلتر سن
        if (isset($userFilters['min_age']) && !empty($userFilters['min_age'])) {
            $conditions[] = "age >= ?";
            $params[] = intval($userFilters['min_age']);
            $message .= "✅ **حداقل سن**: >= {$userFilters['min_age']}\n";
        } else {
            $message .= "⚪ **حداقل سن**: بدون فیلتر\n";
        }

        if (isset($userFilters['max_age']) && !empty($userFilters['max_age'])) {
            $conditions[] = "age <= ?";
            $params[] = intval($userFilters['max_age']);
            $message .= "✅ **حداکثر سن**: <= {$userFilters['max_age']}\n";
        } else {
            $message .= "⚪ **حداکثر سن**: بدون فیلتر\n";
        }

        // ساخت کوئری نهایی
        $whereClause = "";
        if (!empty($conditions)) {
            $whereClause = "AND " . implode(" AND ", $conditions);
        }

        $excludedStr = implode(',', $excludedUsers);

        $sql = "SELECT * FROM users 
            WHERE id NOT IN ($excludedStr) 
            AND is_profile_completed = 1 
            {$whereClause}
            ORDER BY RAND()
            LIMIT 50";

        $message .= "\n📋 **کوئری نهایی:**\n";
        $message .= "```sql\n" . $sql . "\n```\n";
        $message .= "🔢 **پارامترها:** " . implode(', ', $params) . "\n";

        // اجرای کوئری
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(\PDO::FETCH_OBJ);

            $message .= "\n🎯 **نتایج:**\n";
            $message .= "• تعداد کاربران یافت شده: **" . count($results) . "**\n";

            if (!empty($results)) {
                $message .= "\n👥 **نمونه کاربران:**\n";
                foreach (array_slice($results, 0, 3) as $index => $result) {
                    $message .= ($index + 1) . ". **{$result->first_name}**";
                    $message .= " - جنسیت: `{$result->gender}`";
                    $message .= " - سن: `{$result->age}`";
                    $message .= " - شهر: `{$result->city}`\n";
                }
            } else {
                $message .= "\n❌ **هیچ کاربری با این فیلترها یافت نشد!**\n";
            }
        } catch (\Exception $e) {
            $message .= "\n❌ **خطا در اجرای کوئری:**\n" . $e->getMessage();
        }

        $this->telegram->sendMessage($chatId, $message);
    }
    private function debugUserData($user, $chatId)
    {
        $pdo = $this->getPDO();

        $message = "🔍 **دیباگ داده‌های کاربران**\n\n";

        // بررسی کاربران با پروفایل کامل
        $sql = "SELECT COUNT(*) as total FROM users WHERE is_profile_completed = 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $totalCompleted = $stmt->fetch(\PDO::FETCH_OBJ)->total;

        $message .= "👥 کاربران با پروفایل کامل: {$totalCompleted}\n\n";

        // بررسی توزیع جنسیت
        $sql = "SELECT gender, COUNT(*) as count FROM users WHERE is_profile_completed = 1 GROUP BY gender";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $genderStats = $stmt->fetchAll(\PDO::FETCH_OBJ);

        $message .= "⚧ **توزیع جنسیت:**\n";
        foreach ($genderStats as $stat) {
            $message .= "• `{$stat->gender}`: {$stat->count} کاربر\n";
        }

        $message .= "\n🏙️ **شهرهای موجود:**\n";
        $sql = "SELECT city, COUNT(*) as count FROM users WHERE is_profile_completed = 1 AND city IS NOT NULL GROUP BY city LIMIT 10";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $cityStats = $stmt->fetchAll(\PDO::FETCH_OBJ);

        foreach ($cityStats as $stat) {
            $message .= "• `{$stat->city}`: {$stat->count} کاربر\n";
        }

        $this->telegram->sendMessage($chatId, $message);
    }
    private function getOppositeGenderEnglish($gender)
    {
        $opposites = [
            'مرد' => 'female',
            'زن' => 'male',
            'male' => 'female',
            'female' => 'male',
            '1' => '2',
            '2' => '1',
            'M' => 'F',
            'F' => 'M',
            'آقا' => 'خانم',
            'خانم' => 'آقا'
        ];

        return $opposites[$gender] ?? 'female';
    }

    private function getOppositeGenderNumeric($gender)
    {
        $opposites = [
            'مرد' => '2',
            'زن' => '1',
            'male' => '2',
            'female' => '1',
            '1' => '2',
            '2' => '1',
            'M' => 'F',
            'F' => 'M',
            'آقا' => '2',
            'خانم' => '1'
        ];

        return $opposites[$gender] ?? '2';
    }

    private function getOppositeGenderLetter($gender)
    {
        $opposites = [
            'مرد' => 'F',
            'زن' => 'M',
            'male' => 'F',
            'female' => 'M',
            '1' => 'F',
            '2' => 'M',
            'M' => 'F',
            'F' => 'M',
            'آقا' => 'F',
            'خانم' => 'M'
        ];

        return $opposites[$gender] ?? 'F';
    }

    // ==================== سیستم جدید شارژ کیف پول ====================

    private function handleCharge($user, $chatId)
    {
        // مقادیر پیشنهادی برای شارژ
        $chargeAmounts = [
            100000 => '💵 100,000 تومان',
            400000 => '💵 400,000 تومان',
            700000 => '💵 700,000 تومان',
            1000000 => '💵 1,000,000 تومان'
        ];

        $message = "💰 **شارژ کیف پول**\n\n";
        $message .= "لطفاً یکی از مبالغ زیر را برای شارژ انتخاب کنید:\n\n";

        foreach ($chargeAmounts as $amount => $label) {
            $message .= "{$label}\n";
        }

        $message .= "\n⚠️ توجه: پس از انتخاب مبلغ، درخواست شما برای تأیید به مدیران ارسال می‌شود.\n";
        $message .= "✅ پس از تأیید مدیر، مبلغ به کیف پول شما اضافه خواهد شد.";

        // ایجاد کیبورد ثابت
        $keyboardRows = [];

        foreach ($chargeAmounts as $amount => $label) {
            $keyboardRows[] = [['text' => $label]];
        }

        // دکمه بازگشت
        $keyboardRows[] = [['text' => '🔙 بازگشت به کیف پول']];

        $keyboard = [
            'keyboard' => $keyboardRows,
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);

        // تغییر state کاربر
        $user->update(['state' => 'selecting_charge_amount']);
    }
    private function handleChargeAmountSelection($user, $chatId, $buttonText)
    {
        error_log("💰 پردازش انتخاب مبلغ شارژ - متن ورودی: {$buttonText}");

        // 🔴 **1. ابتدا بررسی بازگشت/لغو**
        if (
            $buttonText === '🔙 بازگشت به کیف پول' ||
            $buttonText === '❌ خیر، انصراف' ||
            in_array(trim($buttonText), ['بازگشت', 'لغو', 'انصراف', 'cancel', 'back'])
        ) {

            // ریست state و بازگشت به کیف پول
            $user->update(['state' => 'wallet_menu']);
            error_log("🔄 کاربر درخواست بازگشت کرد. ریست state به wallet_menu");

            $this->handleWallet($user, $chatId);
            return;
        }

        // 🔴 **2. اگر کاربر دکمه تأیید نهایی زد (این نباید اینجا باشد)**
        if ($buttonText === '✅ بله، تأیید می‌کنم') {
            // این باید در متد handleChargeConfirmation پردازش شود
            $this->telegram->sendMessage($chatId, "⚠️ لطفاً ابتدا مبلغ را انتخاب کنید.");
            $this->handleCharge($user, $chatId);
            return;
        }

        // 🔴 **3. استخراج مبلغ از دکمه**
        $amountsMapping = [
            '💵 100,000 تومان' => 100000,
            '💵 400,000 تومان' => 400000,
            '💵 700,000 تومان' => 700000,
            '💵 1,000,000 تومان' => 1000000
        ];

        $amount = null;

        // روش 1: دکمه از پیش تعریف شده
        if (isset($amountsMapping[$buttonText])) {
            $amount = $amountsMapping[$buttonText];
            error_log("💰 دکمه انتخاب شد: {$buttonText} -> {$amount}");
        }
        // روش 2: ورودی عددی دستی
        elseif (is_numeric(str_replace(',', '', $buttonText))) {
            $amount = (int) str_replace(',', '', $buttonText);
            error_log("💰 عدد وارد شد: {$buttonText} -> {$amount}");

            // اعتبارسنجی
            if ($amount < 10000) {
                $this->telegram->sendMessage(
                    $chatId,
                    "❌ **حداقل مبلغ ۱۰,۰۰۰ تومان است!**\n\n" .
                        "لطفاً مبلغ بزرگ‌تری وارد کنید."
                );
                return;
            }

            if ($amount > 10000000) { // 10 میلیون
                $this->telegram->sendMessage(
                    $chatId,
                    "❌ **حداکثر مبلغ ۱۰,۰۰۰,۰۰۰ تومان است!**\n\n" .
                        "لطفاً مبلغ کمتری وارد کنید."
                );
                return;
            }
        }
        // روش 3: ورودی نامعتبر
        else {
            $message = "❌ **ورودی نامعتبر!**\n\n";
            $message .= "لطفاً:\n";
            $message .= "• روی یکی از دکمه‌های بالا کلیک کنید\n";
            $message .= "• یا عدد مبلغ را وارد کنید (مثال: 150000)\n";
            $message .= "• یا برای بازگشت، 'بازگشت' تایپ کنید\n\n";
            $message .= "⚠️ مبلغ باید بین ۱۰,۰۰۰ تا ۱۰,۰۰۰,۰۰۰ تومان باشد.";

            $this->telegram->sendMessage($chatId, $message);
            return;
        }

        // 🔴 **4. نمایش تأیید نهایی**
        $formattedAmount = number_format($amount);
        $message = "💰 **تأیید درخواست شارژ**\n\n";
        $message .= "💵 مبلغ: **{$formattedAmount} تومان**\n\n";
        $message .= "📋 **مراحل:**\n";
        $message .= "1️⃣ درخواست برای مدیران ارسال می‌شود\n";
        $message .= "2️⃣ پس از تأیید، کیف پول شما شارژ می‌شود\n";
        $message .= "3️⃣ زمان تأیید: ۱-۲ ساعت\n\n";
        $message .= "⚠️ **توجه:** پس از تأیید امکان بازگشت نیست.\n\n";
        $message .= "آیا از شارژ **{$formattedAmount} تومان** اطمینان دارید؟";

        $keyboard = [
            'keyboard' => [
                [['text' => '✅ بله، تأیید می‌کنم']],
                [['text' => '❌ خیر، انصراف']]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);

        // 🔴 **5. ذخیره state برای مرحله تأیید**
        $user->update(['state' => 'confirming_charge:' . $amount]);
        error_log("💾 state ذخیره شد: confirming_charge:{$amount}");
    }

    private function handleChargeConfirmation($user, $chatId, $buttonText)
    {
        // بررسی state کاربر
        if (strpos($user->state, 'confirming_charge:') !== 0) {
            $this->telegram->sendMessage($chatId, "❌ درخواست نامعتبر است.");
            $this->handleWallet($user, $chatId);
            return;
        }
 
        // استخراج مبلغ از state
        $amount = (int) str_replace('confirming_charge:', '', $user->state);

        if ($buttonText === '✅ بله، تأیید می‌کنم') {
            // ایجاد درخواست پرداخت (شارژ)
            // توجه: متد createRequest باید با type 'charge' فراخوانی شود
            $paymentRequest = \App\Models\PaymentRequest::createRequest($user->id, null, $amount, 'charge');

            if ($paymentRequest) {
                // ارسال پیام به مدیران با استفاده از متد موجود شما
                $this->notifyAdminsAboutPayment($user, $paymentRequest);

                $message = "✅ **درخواست شارژ کیف پول شما ثبت شد**\n\n";
                $message .= "💵 مبلغ: " . number_format($amount) . " تومان\n";
                $message .= "⏰ وضعیت: در انتظار تأیید مدیر\n\n";
                $message .= "📞 پیام به مدیران ارسال شد. پس از تأیید، کیف پول شما شارژ خواهد شد.\n\n";
                $message .= "🕐 زمان معمول تأیید: 1-2 ساعت";
            } else {
                $message = "❌ خطا در ثبت درخواست شارژ. لطفاً مجدد تلاش کنید.";
            }
        } else {
            $message = "درخواست شارژ لغو شد.";
        }

        // بازگشت به منوی کیف پول
        $user->update(['state' => 'wallet_menu']);
        $this->handleWallet($user, $chatId);
        $this->telegram->sendMessage($chatId, $message);
    }

    private function handlePlanSelection($user, $chatId, $buttonText)
    {
        // دریافت اطلاعات با نوع 'plan'
        $buttonInfo = $this->getButtonInfo($user->id, $buttonText, 'plan');


        if (!$buttonInfo || !$buttonInfo->plan_id) {
            $this->telegram->sendMessage($chatId, "❌ پلن انتخاب شده یافت نشد. لطفاً مجدداً از منوی خرید اقدام کنید.");
            return;
        }

        $planId = $buttonInfo->plan_id;
        $plan = \App\Models\SubscriptionPlan::find($planId);

        if (!$plan) {
            $this->telegram->sendMessage($chatId, "❌ پلن انتخاب شده معتبر نیست.");
            return;
        }

        // بقیه کد بدون تغییر...
        $message = "📦 **پلن انتخاب شده:** {$plan->name}\n\n";
        $message .= "⏰ مدت: {$plan->duration_days} روز\n";
        $message .= "💵 مبلغ: " . number_format($plan->amount) . " تومان\n";
        $message .= "📝 {$plan->description}\n\n";

        // بررسی موجودی کاربر
        $wallet = $user->getWallet();
        $message .= "💰 **موجودی فعلی شما:** " . number_format($wallet->balance) . " تومان\n\n";




        // کیبورد اینلاین (شیشه‌ای) برای تأیید پرداخت
        $keyboard = ['inline_keyboard' => []];


        $keyboard['inline_keyboard'][] = [
            ['text' => '✅ تأیید و پرداخت', 'callback_data' => "confirm_payment:{$plan->id}"],
            ['text' => '❌ انصراف', 'callback_data' => 'cancel_payment']
        ];




        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function handlePaymentConfirmation($user, $chatId, $planId)
    {
        $plan = \App\Models\SubscriptionPlan::getPlan($planId);

        if (!$plan) {
            $this->telegram->sendMessage($chatId, "❌ پلن انتخابی یافت نشد.");
            return;
        }

        // ایجاد درخواست پرداخت
        $paymentRequest = \App\Models\PaymentRequest::createRequest($user->id, $plan->id, $plan->amount);

        if ($paymentRequest) {
            // ارسال پیام به مدیران
            $this->notifyAdminsAboutPayment($user, $paymentRequest);

            $message = "✅ **درخواست پرداخت شما ثبت شد**\n\n";
            $message .= "📦 پلن: {$plan->name}\n";
            $message .= "💵 مبلغ: " . number_format($plan->amount) . " تومان\n";
            $message .= "⏰ وضعیت: در انتظار تأیید مدیر\n\n";
            $message .= "📞 پیام به مدیران ارسال شد. پس از تأیید، کیف پول شما شارژ خواهد شد.\n\n";
            $message .= "🕐 زمان معمول تأیید: 1-2 ساعت";

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu'],
                        ['text' => '💼 کیف پول', 'callback_data' => 'wallet']
                    ]
                ]
            ];

            $this->telegram->sendMessage($chatId, $message, $keyboard);
        } else {
            $this->telegram->sendMessage($chatId, "❌ خطا در ثبت درخواست پرداخت. لطفاً مجدد تلاش کنید.");
        }
    }




    // ==================== مدیریت درخواست‌های پرداخت ====================

    private function showPaymentManagement($user, $chatId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        $pendingCount = \App\Models\PaymentRequest::where('status', 'pending')->count();
        $approvedCount = \App\Models\PaymentRequest::where('status', 'approved')->count();
        $rejectedCount = \App\Models\PaymentRequest::where('status', 'rejected')->count();

        $message = "💰 **مدیریت درخواست‌های پرداخت**\n\n";
        $message .= "📊 آمار:\n";
        $message .= "• ⏳ در انتظار: {$pendingCount} درخواست\n";
        $message .= "• ✅ تأیید شده: {$approvedCount} درخواست\n";
        $message .= "• ❌ رد شده: {$rejectedCount} درخواست\n\n";
        $message .= "لطفاً یکی از گزینه‌ها را انتخاب کنید:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📋 درخواست‌های pending', 'callback_data' => 'view_pending_payments'],
                    ['text' => '⚙️ مدیریت پلن‌ها', 'callback_data' => 'manage_subscription_plans']
                ],
                [
                    ['text' => '💳 تنظیم شماره کارت', 'callback_data' => 'set_card_number'],
                    ['text' => '📈 گزارش پرداخت‌ها', 'callback_data' => 'payment_reports']
                ],
                [
                    ['text' => '🔙 بازگشت به پنل مدیریت', 'callback_data' => 'admin_panel']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function showPendingPayments($user, $chatId, $page = 1)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        $perPage = 5;
        $pendingRequests = \App\Models\PaymentRequest::getPendingRequests();
        $totalPages = ceil(count($pendingRequests) / $perPage);
        $currentPage = min(max($page, 1), $totalPages);
        $offset = ($currentPage - 1) * $perPage;
        $currentRequests = array_slice($pendingRequests->toArray(), $offset, $perPage);

        $message = "⏳ **درخواست‌های پرداخت در انتظار تأیید**\n\n";
        $message .= "📄 صفحه: {$currentPage} از {$totalPages}\n\n";

        if (empty($currentRequests)) {
            $message .= "✅ هیچ درخواست pendingی وجود ندارد.";
        } else {
            foreach ($currentRequests as $request) {
                $message .= "🆔 کد: #{$request['id']}\n";
                $message .= "👤 کاربر: {$request['user']['first_name']}";
                $message .= $request['user']['username'] ? " (@{$request['user']['username']})" : "";
                $message .= "\n📦 پلن: {$request['plan']['name']}\n";
                $message .= "💵 مبلغ: " . number_format($request['amount']) . " تومان\n";
                $message .= "⏰ زمان: " . date('Y-m-d H:i', strtotime($request['created_at'])) . "\n";
                $message .= "────────────\n";
            }
        }

        $keyboard = ['inline_keyboard' => []];

        // دکمه‌های تأیید/رد برای هر درخواست
        foreach ($currentRequests as $request) {
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => "✅ تأیید #{$request['id']}",
                    'callback_data' => "approve_payment:{$request['id']}"
                ],
                [
                    'text' => "❌ رد #{$request['id']}",
                    'callback_data' => "reject_payment:{$request['id']}"
                ]
            ];
        }

        // دکمه‌های صفحه‌بندی
        $paginationButtons = [];
        if ($currentPage > 1) {
            $paginationButtons[] = ['text' => '⏪ قبلی', 'callback_data' => "pending_payments_page:" . ($currentPage - 1)];
        }
        if ($currentPage < $totalPages) {
            $paginationButtons[] = ['text' => 'بعدی ⏩', 'callback_data' => "pending_payments_page:" . ($currentPage + 1)];
        }

        if (!empty($paginationButtons)) {
            $keyboard['inline_keyboard'][] = $paginationButtons;
        }

        $keyboard['inline_keyboard'][] = [
            ['text' => '🔙 بازگشت', 'callback_data' => 'payment_management']
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function approvePayment($user, $chatId, $paymentId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        $paymentRequest = \App\Models\PaymentRequest::find($paymentId);

        if (!$paymentRequest) {
            $this->telegram->sendMessage($chatId, "❌ درخواست پرداخت یافت نشد.");
            return;
        }

        if ($paymentRequest->status !== 'pending') {
            $this->telegram->sendMessage($chatId, "❌ این درخواست قبلاً پردازش شده است.");
            return;
        }

        // 🔴 ابتدا وضعیت را به approved تغییر دهید تا از double charging جلوگیری شود
        $paymentRequest->update(['status' => 'approved', 'approved_by' => $user->id]);

        // شارژ کیف پول کاربر - فقط یک بار
        $userWallet = $paymentRequest->user->getWallet();
        $chargeResult = $userWallet->charge($paymentRequest->amount, "شارژ از طریق پرداخت - پلن: {$paymentRequest->plan->name}", "charge");

        if (!$chargeResult) {
            // اگر شارژ失敗 شد، وضعیت را بازنشانی کنید
            $paymentRequest->update(['status' => 'pending', 'approved_by' => null]);
            $this->telegram->sendMessage($chatId, "❌ خطا در شارژ کیف پول کاربر.");
            return;
        }

        // 🔴 پرداخت پاداش به دعوت‌کننده
        $this->payReferralBonus($paymentRequest->user, $paymentRequest->amount);

        // اطلاع‌رسانی به کاربر
        $userMessage = "✅ **پرداخت شما تأیید شد!**\n\n";
        $userMessage .= "📦 پلن: {$paymentRequest->plan->name}\n";
        $userMessage .= "💵 مبلغ: " . number_format($paymentRequest->amount) . " تومان\n";
        $userMessage .= "💰 کیف پول شما با موفقیت شارژ شد.\n";
        $userMessage .= "⏰ زمان تأیید: " . $this->toJalali(now()) . "\n\n";
        $userMessage .= "با تشکر از پرداخت شما! 💝";
        $userMessage .= "شما می توانید اکنون از قسمت اشتراک من اشتراک تهیه فرمایید ";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu'],
                    ['text' => '💎 خرید اشتراک', 'callback_data' => 'subscription']
                ]
            ]
        ];

        $this->telegram->sendMessage($paymentRequest->user->telegram_id, $userMessage, $keyboard);

        $this->telegram->sendMessage($chatId, "✅ پرداخت کاربر تأیید و کیف پول شارژ شد.");

        // بازگشت به لیست درخواست‌ها
        sleep(2);
        $this->showPendingPayments($user, $chatId);
    }
    private function rejectPayment($user, $chatId, $paymentId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        $paymentRequest = \App\Models\PaymentRequest::find($paymentId);

        if (!$paymentRequest) {
            $this->telegram->sendMessage($chatId, "❌ درخواست پرداخت یافت نشد.");
            return;
        }

        if ($paymentRequest->status !== 'pending') {
            $this->telegram->sendMessage($chatId, "❌ این درخواست قبلاً پردازش شده است.");
            return;
        }

        // رد پرداخت
        $paymentRequest->reject($user->id);

        // اطلاع‌رسانی به کاربر
        $userMessage = "❌ **پرداخت شما رد شد**\n\n";
        $userMessage .= "📦 پلن: {$paymentRequest->plan->name}\n";
        $userMessage .= "💵 مبلغ: " . number_format($paymentRequest->amount) . " تومان\n";
        $userMessage .= "⏰ زمان: " . date('Y-m-d H:i') . "\n\n";
        $userMessage .= "⚠️ درصورت واریز وجه، با پشتیبانی تماس بگیرید.\n";
        $userMessage .= "📞 برای اطلاعات بیشتر با پشتیبانی ارتباط برقرار کنید.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu'],
                    ['text' => '💼 کیف پول', 'callback_data' => 'wallet']
                ]
            ]
        ];

        $this->telegram->sendMessage($paymentRequest->user->telegram_id, $userMessage, $keyboard);

        $this->telegram->sendMessage($chatId, "❌ پرداخت کاربر رد شد.");

        // 🔴 بروزرسانی منوی ادمین‌ها پس از رد
        $this->updateAllAdminMenus();

        // بازگشت به لیست درخواست‌ها
        sleep(2);
        $this->showPendingPayments($user, $chatId);
    }
    // ==================== متدهای مدیریت ادمین‌ها ====================

    private function getAllAdmins()
    {
        try {
            $pdo = $this->getPDO();
            $stmt = $pdo->prepare("SELECT * FROM administrators");
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_OBJ); // 🔴 حتماً با بک‌اسلش
        } catch (\Exception $e) { // 🔴 حتماً با بک‌اسلش
            error_log("❌ خطا در دریافت ادمین‌ها: " . $e->getMessage());
            return [];
        }
    }


    private function notifyAdminsAboutPayment($user, $paymentRequest)
    {
        $admins = $this->getAllAdmins();

        if (empty($admins)) {
            error_log("⚠️ هیچ ادمینی برای اطلاع‌رسانی پیدا نشد");
            $superAdminId = 123456789; // آیدی تلگرام خودتان
            $this->sendPaymentNotificationToAdmin($superAdminId, $user, $paymentRequest);
            return;
        }

        foreach ($admins as $admin) {
            $this->sendPaymentNotificationToAdmin($admin->telegram_id, $user, $paymentRequest);
        }

        // 🔴 بروزرسانی منوی همه ادمین‌ها برای نمایش نوتیفیکیشن
        $this->updateAllAdminMenus();
    }

    // متد کمکی برای ارسال پیام به ادمین
    private function sendPaymentNotificationToAdmin($adminTelegramId, $user, $paymentRequest)
    {
        $pendingCount = \App\Models\PaymentRequest::where('status', 'pending')->count();

        $message = "🔄 **درخواست پرداخت جدید**\n\n";
        $message .= "👤 کاربر: {$user->first_name}";
        $message .= $user->username ? " (@{$user->username})" : "";
        $message .= "\n📦 پلن: {$paymentRequest->plan->name}\n";
        $message .= "💵 مبلغ: " . number_format($paymentRequest->amount) . " تومان\n";
        $message .= "🆔 کد درخواست: #{$paymentRequest->id}\n";
        $message .= "⏰ زمان: " . date('Y-m-d H:i', strtotime($paymentRequest->created_at)) . "\n\n";

        // 🔴 اضافه کردن اطلاعات نوتیفیکیشن
        $message .= "📊 **وضعیت فعلی:** {$pendingCount} درخواست پرداخت pending\n\n";
        $message .= "برای مدیریت درخواست‌ها، از دکمه زیر استفاده کنید:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 مدیریت پرداخت‌ها 🔔', 'callback_data' => 'payment_management']
                ]
            ]
        ];

        try {
            $this->telegram->sendMessage($adminTelegramId, $message, $keyboard);
            error_log("✅ پیام به ادمین {$adminTelegramId} ارسال شد");
        } catch (\Exception $e) {
            error_log("❌ خطا در ارسال پیام به ادمین {$adminTelegramId}: " . $e->getMessage());
        }
    }
    private function updateAllAdminMenus()
    {
        try {
            $admins = $this->getAllAdmins();
            $pendingCount = \App\Models\PaymentRequest::where('status', 'pending')->count();

            foreach ($admins as $admin) {
                try {
                    // پیدا کردن کاربر ادمین
                    $adminUser = User::where('telegram_id', $admin->telegram_id)->first();
                    if ($adminUser) {
                        // ارسال منوی admin با نوتیفیکیشن
                        $this->showAdminPanelWithNotification($adminUser, $admin->telegram_id, $pendingCount);
                    }
                } catch (\Exception $e) {
                    error_log("❌ خطا در بروزرسانی منوی ادمین {$admin->telegram_id}: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            error_log("❌ خطا در بروزرسانی منوی ادمین‌ها: " . $e->getMessage());
        }
    }
    private function showAdminPanelWithNotification($user, $chatId, $pendingCount = null)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        if ($pendingCount === null) {
            $pendingCount = \App\Models\PaymentRequest::where('status', 'pending')->count();
        }

        // استفاده از متد getActiveFields به جای where
        $activeFields = ProfileField::getActiveFields();
        $activeFieldsCount = count($activeFields);

        // برای گرفتن تعداد کل فیلدها
        $allFields = ProfileField::getAllFields();
        $totalFieldsCount = count($allFields);

        $message = "👑 **    پنل مدیریت **\n\n";

        // 🔴 نمایش نوتیفیکیشن پرداخت‌های pending
        if ($pendingCount > 0) {
            $message .= "🔔 **نوتیفیکیشن:**\n";
            $message .= "💰 {$pendingCount} درخواست پرداخت pending دارید!\n\n";
        }

        $message .= "📊 آمار فیلدها:\n";
        $message .= "• ✅ فیلدهای فعال: {$activeFieldsCount}\n";
        $message .= "• 📋 کل فیلدها: {$totalFieldsCount}\n\n";
        $message .= "لطفاً یکی از گزینه‌ها را انتخاب کنید:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '⚙️  بخش فیلدها', 'callback_data' => 'field_panel'],
                    ['text' => '🎛️ مدیریت فیلترها', 'callback_data' => 'admin_filters_management']

                ],
                [
                    ['text' => '💰 مدیریت پرداخت‌ها' . ($pendingCount > 0 ? " 🔔($pendingCount)" : ""), 'callback_data' => 'payment_management'],
                    //['text' => '👤 ایجاد کاربر تستی', 'callback_data' => 'create_test_user']
                ],
                [
                    ['text' => '📊 گزارش عملکرد', 'callback_data' => 'performance_report'],
                    ['text' => '🚀 بهینه‌سازی دیتابیس', 'callback_data' => 'admin_optimize_db'],
                    ['text' => '🔧 تولید کدهای دعوت', 'callback_data' => 'generate_all_invite_codes'],

                ],
                [
                    ['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu'],
                    ['text' => '🔧 دیباگ فیلتر ها', 'callback_data' => 'debug_current_filters']


                ]
            ]
        ];


        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }
    private function getDatabaseConnection()
    {
        static $pdo = null;

        if ($pdo === null) {
            $host = 'localhost';
            $dbname = 'dating_system';
            $username = 'root';
            $password = '';

            $pdo = new \PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 30);
        }

        // تست اتصال
        try {
            $pdo->query('SELECT 1')->fetch(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            // اگر اتصال قطع شده، جدید ایجاد کن
            $pdo = null;
            return $this->getDatabaseConnection();
        }

        return $pdo;
    }
    private function showPerformanceReport($user, $chatId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        $report = PerformanceMonitor::getSummary();

        // آمار از دیتابیس
        $pdo = $this->getPDO();
        $stats = $pdo->query("
        SELECT 
            COUNT(*) as total_users,
            SUM(is_profile_completed) as completed_profiles,
            (SELECT COUNT(*) FROM user_suggestions WHERE DATE(shown_at) = CURDATE()) as today_suggestions,
            (SELECT COUNT(*) FROM contact_request_history WHERE DATE(requested_at) = CURDATE()) as today_contacts
        FROM users
    ")->fetch(\PDO::FETCH_OBJ);

        $report .= "\n\n👥 **آمار امروز:**\n";
        $report .= "• کاربران: " . number_format($stats->total_users) . "\n";
        $report .= "• پروفایل کامل: " . number_format($stats->completed_profiles) . "\n";
        $report .= "• پیشنهادات امروز: " . number_format($stats->today_suggestions) . "\n";
        $report .= "• درخواست‌های تماس: " . number_format($stats->today_contacts);

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 بروزرسانی گزارش', 'callback_data' => 'performance_report'],
                    ['text' => '📈 گزارش کامل', 'callback_data' => 'detailed_performance']
                ],
                [
                    ['text' => '🔙 بازگشت به مدیریت', 'callback_data' => 'admin_panel']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $report, $keyboard);
    }

    private function showDetailedPerformance($user, $chatId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            return;
        }

        $metrics = PerformanceMonitor::getMetrics();
        $report = "📈 **گزارش دقیق عملکرد**\n\n";

        foreach ($metrics as $operation => $metric) {
            if ($metric['duration'] !== null) {
                $memoryUsed = round(($metric['memory_end'] - $metric['memory_start']) / 1024 / 1024, 2);
                $status = $metric['duration'] > 1000 ? '🚨' : ($metric['duration'] > 500 ? '⚠️' : '✅');
                $report .= "{$status} {$operation}: {$metric['duration']}ms (حافظه: {$memoryUsed}MB)\n";
            }
        }

        // آمار ایندکس‌ها
        $pdo = $this->getPDO();
        $indexStats = $pdo->query("
        SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX 
        FROM information_schema.STATISTICS 
        WHERE TABLE_SCHEMA = 'dating_system'
        ORDER BY TABLE_NAME, INDEX_NAME
    ")->fetchAll(\PDO::FETCH_OBJ);

        $report .= "\n🔍 **ایندکس‌های فعال:**\n";
        $currentTable = '';
        foreach ($indexStats as $index) {
            if ($currentTable != $index->TABLE_NAME) {
                $report .= "• {$index->TABLE_NAME}:\n";
                $currentTable = $index->TABLE_NAME;
            }
            $report .= "  └─ {$index->INDEX_NAME}\n";
        }

        $this->telegram->sendMessage($chatId, $report);
    }
    private function handleStartWithReferral($text, $user, $chatId)
    {
        // بررسی وجود کد دعوت در متن
        if (strpos($text, 'ref_') !== false) {
            $parts = explode(' ', $text);
            if (count($parts) > 1) {
                $refCode = str_replace('ref_', '', $parts[1]);
                $this->processReferralCode($user, $refCode);
            }
        }

        // نمایش منوی اصلی
        $this->showMainMenu($user, $chatId);
    }

    private function processReferralCode($user, $refCode)
    {
        error_log("🔍 Processing referral code: {$refCode} for user: {$user->id}");

        // اگر کاربر قبلاً توسط کسی دعوت نشده باشد
        if (!$user->referred_by) {
            $referrer = User::findByInviteCode($refCode);

            if ($referrer && $referrer->id != $user->id) {
                // بررسی نکردن قبلی این کاربر
                $existingReferral = Referral::where('referred_id', $user->id)->first();

                if (!$existingReferral) {
                    // ثبت دعوت
                    $user->update(['referred_by' => $referrer->id]);
                    Referral::createReferral($referrer->id, $user->id, $refCode);

                    // اطلاع‌رسانی به دعوت‌کننده
                    $this->notifyReferrer($referrer, $user);

                    error_log("✅ کاربر {$user->id} توسط {$referrer->id} دعوت شد - کد: {$refCode}");
                } else {
                    error_log("⚠️ کاربر {$user->id} قبلاً دعوت شده است");
                }
            } else {
                error_log("❌ دعوت‌کننده پیدا نشد یا کاربر خودش را دعوت کرده - کد: {$refCode}");
            }
        } else {
            error_log("⚠️ کاربر {$user->id} قبلاً توسط {$user->referred_by} دعوت شده است");
        }
    }

    private function notifyReferrer($referrer, $referredUser)
    {
        $message = "🎉 **کاربر جدید دعوت کردید!**\n\n";
        $message .= "👤 {$referredUser->first_name} با موفقیت از طریق لینک دعوت شما ثبت نام کرد.\n\n";
        $message .= "💰 اگر این کاربر خرید کند، ۱۰٪ از مبلغ خرید به عنوان پاداش دریافت خواهید کرد!";

        try {
            $this->telegram->sendMessage($referrer->telegram_id, $message);
        } catch (\Exception $e) {
            error_log("❌ خطا در ارسال نوتیفیکیشن به دعوت‌کننده: " . $e->getMessage());
        }
    }
    private function processReferralBonus($userId, $chargeAmount)
{
    try {
        error_log("🎁 Processing referral bonus for user: {$userId}, amount: {$chargeAmount}");
        
        // پیدا کردن رکورد دعوت این کاربر
        $referral = Referral::where('referred_id', $userId)
            ->where('has_purchased', 0)
            ->first();
        
        if (!$referral) {
            error_log("ℹ️ No referral found or already processed");
            return false;
        }
        
        // محاسبه پاداش (30٪)
        $bonusAmount = $chargeAmount * 0.3;
        
        // پیدا کردن کاربر دعوت‌کننده
        $referrer = User::find($referral->referrer_id);
        if (!$referrer) {
            error_log("❌ Referrer not found: {$referral->referrer_id}");
            return false;
        }
        
        // اعطای پاداش به کیف پول دعوت‌کننده
        $referrerWallet = $referrer->getWallet();
        if (!$referrerWallet) {
            // اگر کیف پول وجود ندارد، ایجاد کنید
            $referrerWallet = Wallet::create([
                'user_id' => $referrer->id,
                'balance' => 0
            ]);
        }
        
        // اضافه کردن پاداش
        $referrerWallet->balance += $bonusAmount;
        $referrerWallet->save();
        
        // ثبت تراکنش پاداش برای دعوت‌کننده
        Transaction::create([
            'user_id' => $referrer->id,
            'amount' => $bonusAmount,
            'type' => 'referral_bonus',
            'description' => "پاداش دعوت برای کاربر {$userId}",
            'status' => 'completed'
        ]);
        
        // ثبت تراکنش برای کاربر دعوت شده (اختیاری)
        Transaction::create([
            'user_id' => $userId,
            'amount' => $chargeAmount,
            'type' => 'charge',
            'description' => "شارژ کیف پول - منبع: کد دعوت",
            'status' => 'completed'
        ]);
        
        // آپدیت وضعیت referral
        $referral->has_purchased = 1;
        $referral->bonus_amount = $bonusAmount;
        $referral->status = 'bonus_paid';
        $referral->purchased_at = date('Y-m-d H:i:s');
        $referral->save();
        
        // اطلاع‌رسانی به دعوت‌کننده
        $this->notifyReferrerAboutBonus($referrer, $userId, $bonusAmount, $chargeAmount);
        
        error_log("✅ Bonus processed successfully: {$bonusAmount} to user {$referrer->id}");
        return true;
        
    } catch (\Exception $e) {
        error_log("❌ Error in processReferralBonus: " . $e->getMessage());
        error_log("❌ Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

private function notifyReferrerAboutBonus($referrer, $referredUserId, $bonusAmount, $originalAmount)
{
    try {
        // پیدا کردن کاربر دعوت شده برای نمایش نام
        $referredUser = User::find($referredUserId);
        $referredName = $referredUser ? $referredUser->first_name : 'کاربر';
        
        $message = "🎉 **دریافت پاداش دعوت!**\n\n";
        $message .= "کاربری که با لینک دعوت شما ثبت‌نام کرده بود، اولین شارژ خود را انجام داد.\n\n";
        $message .= "👤 کاربر دعوت‌شده: {$referredName}\n";
        $message .= "💰 مبلغ شارژ: " . number_format($originalAmount) . " تومان\n";
        $message .= "🎁 پاداش شما (30٪): " . number_format($bonusAmount) . " تومان\n";
        $message .= "📅 تاریخ: " . date('Y/m/d H:i') . "\n\n";
        $message .= "✅ پاداش به کیف پول شما اضافه شد.\n";
        $message .= "💼 موجودی فعلی: " . number_format($referrer->getWallet()->balance) . " تومان";
        
        $this->telegram->sendMessage($referrer->telegram_id, $message);
        
        error_log("✅ Bonus notification sent to referrer: {$referrer->telegram_id}");
        
    } catch (\Exception $e) {
        error_log("⚠️ Failed to send bonus notification: " . $e->getMessage());
    }
}
    private function payReferralBonus($user, $purchaseAmount)
    {
        error_log("🔍 Checking referral bonus for user: {$user->id}, amount: {$purchaseAmount}");

        // اگر کاربر توسط کسی دعوت شده باشد
        if ($user->referred_by) {
            $referrer = User::find($user->referred_by);

            if ($referrer) {
                // بررسی اینکه آیا قبلاً پاداش پرداخت شده
                $referral = Referral::where('referred_id', $user->id)->first();

                if ($referral && !$referral->has_purchased) {
                    // محاسبه پاداش (30% از مبلغ خرید)
                    $bonusAmount = $purchaseAmount * 0.3;

                    error_log("💰 Calculating bonus: {$purchaseAmount} * 0.1 = {$bonusAmount}");

                    // شارژ کیف پول دعوت‌کننده
                    $referrerWallet = $referrer->getWallet();
                    $bonusResult = $referrerWallet->charge($bonusAmount, "پاداش دعوت کاربر: {$user->first_name}", "referral_bonus");

                    if ($bonusResult) {
                        // به‌روزرسانی رکورد دعوت
                        $referral->update([
                            'has_purchased' => true,
                            'bonus_amount' => $bonusAmount,
                            'bonus_paid_at' => now()
                        ]);

                        // اطلاع‌رسانی به دعوت‌کننده
                        $this->notifyBonusPayment($referrer, $user, $bonusAmount);

                        error_log("✅ پاداش دعوت پرداخت شد: {$bonusAmount} تومان به کاربر {$referrer->id}");
                    } else {
                        error_log("❌ خطا در شارژ کیف پول معرفی کننده");
                    }
                } else {
                    error_log("⚠️ رکورد referral پیدا نشد یا قبلاً پاداش پرداخت شده");
                }
            } else {
                error_log("❌ معرفی کننده پیدا نشد با ID: {$user->referred_by}");
            }
        } else {
            error_log("⚠️ کاربر {$user->id} توسط کسی دعوت نشده است");
        }
    }

    private function notifyBonusPayment($referrer, $referredUser, $bonusAmount)
    {
        $message = "🎊 **پاداش دعوت دریافت کردید!**\n\n";
        $message .= "👤 کاربر {$referredUser->first_name} که توسط شما دعوت شده بود، اولین خرید خود را انجام داد.\n\n";
        $message .= "💰 **مبلغ پاداش:** " . number_format($bonusAmount) . " تومان\n";
        $message .= "💳 این مبلغ به کیف پول شما اضافه شد.\n\n";
        $message .= "🙏 از اینکه ما را معرفی کردید متشکریم!";

        try {
            $this->telegram->sendMessage($referrer->telegram_id, $message);
        } catch (\Exception $e) {
            error_log("❌ خطا در ارسال نوتیفیکیشن پاداش: " . $e->getMessage());
        }
    }
    private function handleCopyInviteLink($user, $chatId)
    {
        $inviteLink = $user->getInviteLink();

        $message = "📋 **لینک دعوت شما آماده کپی است:**\n\n";
        $message .= "`{$inviteLink}`\n\n";
        $message .= "🔗 می‌توانید این لینک را کپی کرده و برای دوستان خود ارسال کنید.";

        $this->telegram->sendMessage($chatId, $message);
    }

    private function handleShareInviteLink($user, $chatId)
    {
        $inviteLink = $user->getInviteLink();

        $shareText = "👋 دوست عزیز!\n\n";
        $shareText .= "من از این ربات همسر یابی عالی استفاده می‌کنم و پیشنهاد می‌کنم تو هم عضو بشی! 🤝\n\n";
        $shareText .= "از طریق لینک زیر می‌تونی ثبت نام کنی:\n";
        $shareText .= $inviteLink . "\n\n";
        $shareText .= "پس از عضویت، می‌تونی با تکمیل پروفایل، افراد جدید رو ببینی و ارتباط برقرار کنی! 💫";

        $message = "📤 **متن آماده برای اشتراک‌گذاری:**\n\n";
        $message .= $shareText . "\n\n";
        $message .= "📝 می‌توانید این متن را کپی کرده و در چت‌های خود ارسال کنید.";

        $this->telegram->sendMessage($chatId, $message);
    }
    private function generateInviteCodesForAllUsers($user, $chatId)
    {
        if (!$this->isSuperAdmin($user->telegram_id)) {
            $this->telegram->sendMessage($chatId, "❌ دسترسی denied");
            return;
        }

        try {
            $pdo = $this->getPDO();

            // دریافت همه کاربران بدون کد دعوت
            $sql = "SELECT id, first_name FROM users WHERE invite_code IS NULL OR invite_code = ''";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $usersWithoutCode = $stmt->fetchAll(\PDO::FETCH_OBJ);

            $updatedCount = 0;
            $errorCount = 0;

            foreach ($usersWithoutCode as $userRecord) {
                do {
                    $code = strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
                    $checkSql = "SELECT COUNT(*) as count FROM users WHERE invite_code = ?";
                    $checkStmt = $pdo->prepare($checkSql);
                    $checkStmt->execute([$code]);
                    $exists = $checkStmt->fetch(\PDO::FETCH_OBJ)->count;
                } while ($exists > 0);

                $updateSql = "UPDATE users SET invite_code = ? WHERE id = ?";
                $updateStmt = $pdo->prepare($updateSql);
                $result = $updateStmt->execute([$code, $userRecord->id]);

                if ($result) {
                    $updatedCount++;
                    error_log("✅ کد دعوت برای کاربر {$userRecord->id} ایجاد شد: {$code}");
                } else {
                    $errorCount++;
                    error_log("❌ خطا در ایجاد کد برای کاربر {$userRecord->id}");
                }
            }

            $message = "🔧 **تولید کدهای دعوت برای کاربران قدیمی**\n\n";
            $message .= "• ✅ کاربران به‌روزرسانی شده: {$updatedCount}\n";
            $message .= "• ❌ خطاها: {$errorCount}\n";
            $message .= "• 📋 کل کاربران بررسی شده: " . count($usersWithoutCode) . "\n\n";

            if ($errorCount === 0) {
                $message .= "🎉 همه کاربران اکنون کد دعوت دارند!";
            } else {
                $message .= "⚠️ برخی کاربران با خطا مواجه شدند.";
            }

            $this->telegram->sendMessage($chatId, $message);
        } catch (\Exception $e) {
            error_log("❌ خطا در تولید کدهای دعوت: " . $e->getMessage());
            $this->telegram->sendMessage($chatId, "❌ خطا در تولید کدهای دعوت: " . $e->getMessage());
        }
    }

    // کد موقت برای دیباگ فیلتر کاربر 
    private function debugCurrentFilterIssue($user, $chatId)
    {
        $userFilters = UserFilter::getFilters($user->id);

        $message = "🔍 **دیباگ فیلتر فعلی**\n\n";
        $message .= "📋 فیلترهای کاربر:\n";
        $message .= "```json\n" . json_encode($userFilters, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n```\n\n";

        // بررسی کاربران موجود در سیستم
        $pdo = $this->getPDO();

        // بررسی توزیع جنسیت
        $sql = "SELECT gender, COUNT(*) as count FROM users WHERE is_profile_completed = 1 GROUP BY gender";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $genderStats = $stmt->fetchAll(\PDO::FETCH_OBJ);

        $message .= "⚧ **توزیع جنسیت در دیتابیس:**\n";
        foreach ($genderStats as $stat) {
            $message .= "• `{$stat->gender}`: {$stat->count} کاربر\n";
        }

        $message .= "\n🏙️ **کاربران در شهرهای انتخابی:**\n";

        $cities = $userFilters['city'] ?? [];
        if (is_array($cities) && !empty($cities)) {
            $placeholders = implode(',', array_fill(0, count($cities), '?'));
            $sql = "SELECT gender, city, COUNT(*) as count FROM users 
                WHERE is_profile_completed = 1 
                AND city IN ($placeholders)
                GROUP BY gender, city";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($cities);
            $cityStats = $stmt->fetchAll(\PDO::FETCH_OBJ);

            foreach ($cityStats as $stat) {
                $message .= "• `{$stat->gender}` در `{$stat->city}`: {$stat->count} کاربر\n";
            }
        }

        $this->telegram->sendMessage($chatId, $message);
    }

    private function fixGenderFilterLogic($user, $chatId)
    {
        $pdo = $this->getPDO();

        // نرمال‌سازی جنسیت‌ها در دیتابیس
        $updateSql = "UPDATE users SET gender = CASE 
                WHEN gender IN ('male', '1', 'M', 'آقا') THEN 'مرد'
                WHEN gender IN ('female', '2', 'F', 'خانم') THEN 'زن'
                ELSE gender
            END
            WHERE gender IS NOT NULL";

        try {
            $stmt = $pdo->prepare($updateSql);
            $stmt->execute();
            $affectedRows = $stmt->rowCount();

            $message = "✅ **نرمال‌سازی جنسیت‌ها انجام شد**\n\n";
            $message .= "🔧 {$affectedRows} رکورد به‌روزرسانی شد\n";
            $message .= "🎯 اکنون همه جنسیت‌ها به فرمت فارسی (مرد/زن) هستند";
        } catch (\Exception $e) {
            $message = "❌ خطا در نرمال‌سازی جنسیت‌ها: " . $e->getMessage();
        }

        $this->telegram->sendMessage($chatId, $message);

        // برگشت به دیباگ بعد از 2 ثانیه
        sleep(2);
        $this->debugCurrentFilterIssue($user, $chatId);
    }
    // انتهای کد موقت 



    public function handlePhotoMessage($user, $message)
    {
        $chatId = $user->telegram_id;

        error_log("🖼️ Processing photo upload for user: {$user->id}");

        if (!isset($message['photo'])) {
            $this->telegram->sendMessage($chatId, "❌ هیچ عکسی در پیام یافت نشد.");
            return false;
        }

        // گرفتن بزرگترین سایز عکس
        $photo = end($message['photo']);
        $fileId = $photo['file_id'];

        error_log("📸 File ID received: {$fileId}");

        try {
            // ذخیره ساده file_id در دیتابیس
            $user->update([
                'telegram_photo_id' => $fileId,
                'state' => 'main_menu' // بازگشت به حالت عادی
            ]);

            error_log("✅ Photo file_id saved successfully");
            $this->telegram->sendMessage($chatId, "✅ عکس پروفایل شما با موفقیت آپلود شد!");

            // بازگشت به منوی پروفایل
            $this->showProfile($user, $chatId);

            return true;
        } catch (\Exception $e) {
            error_log("❌ Error saving photo: " . $e->getMessage());
            $this->telegram->sendMessage($chatId, "❌ خطا در ذخیره عکس. لطفاً مجدداً تلاش کنید.");
            return false;
        }
    }

    private function requestProfilePhoto($user, $chatId)
    {
        error_log("🔄 requestProfilePhoto - Current state: " . $user->state);

        // ابتدا state را حتماً تغییر دهید
        $user->update(['state' => 'awaiting_photo']);
        error_log("✅ requestProfilePhoto - New state: " . $user->state);

        $message = "📸 **آپلود عکس پروفایل**\n\n";
        $message .= "لطفاً عکس مورد نظر خود را برای پروفایل ارسال کنید.\n";
        $message .= "⚠️ توجه: این عکس به عنوان عکس اصلی پروفایل شما ذخیره خواهد شد.";

        $keyboard = [
            'keyboard' => [
                [['text' => '🔙 بازگشت به منوی اصلی']]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }
    private function handleProfilePhotoUpload($user, $chatId, $photo)
    {
        error_log("🔄 handleProfilePhotoUpload - Starting with state: " . $user->state);

        try {
            // دریافت بزرگترین سایز عکس
            $largestPhoto = end($photo);
            $fileId = $largestPhoto['file_id'];

            error_log("📸 Photo file_id: " . $fileId);

            // 🔴 مهم: ابتدا state را بازنشانی کنید، سپس بقیه عملیات
            $user->update(['state' => 'main_menu']);
            error_log("✅ State reset to main_menu BEFORE processing");

            // سپس عکس را ذخیره کنید
            $user->update([
                'telegram_photo_id' => $fileId
            ]);

            error_log("✅ Photo saved to database");

            $this->telegram->sendMessage($chatId, "✅ عکس پروفایل شما با موفقیت آپلود شد!");

            // بازگشت به منوی اصلی
            $this->showMainMenu($user, $chatId);
        } catch (\Exception $e) {
            error_log("❌ Error in handleProfilePhotoUpload: " . $e->getMessage());

            // حتی در صورت خطا هم state را بازنشانی کنید
            $user->update(['state' => 'main_menu']);
            error_log("✅ State reset to main_menu after error");

            $this->telegram->sendMessage($chatId, "❌ خطا در آپلود عکس. لطفاً مجدداً تلاش کنید.");
            $this->showMainMenu($user, $chatId);
        }
    }
    private function getBotToken()
    {
        return $_ENV['TELEGRAM_BOT_TOKEN'] ?? '8309595970:AAGaX8wstn-Fby_IzF5cU_a1CxGCPfCEQNk';
    }





    private function showProfileMenu($user, $chatId = null)
    {
        // اگر chatId داده نشده، از telegram_id کاربر استفاده کن
        $targetChatId = $chatId ?? $user->telegram_id;

        $message = "🔧 **منوی ویرایش پروفایل**\n\n";
        $message .= "لطفاً گزینه مورد نظر را انتخاب کنید:";

        // ایجاد دکمه‌های شیشه‌ای (Inline Keyboard)
        $inlineKeyboard = [
            [
                ['text' => '👤 ویرایش نام', 'callback_data' => 'edit_name'],
                ['text' => '📝 ویرایش بیو', 'callback_data' => 'edit_bio']
            ],
            [
                ['text' => '🏙️ ویرایش شهر', 'callback_data' => 'edit_city'],
                ['text' => '💰 ویرایش درآمد', 'callback_data' => 'edit_income']
            ],
            [
                ['text' => '📅 ویرایش سن', 'callback_data' => 'edit_age']
            ],
            [
                ['text' => '📷 مدیریت عکس‌های پروفایل', 'callback_data' => 'manage_photos']
            ],
            [
                ['text' => '✏️ ویرایش پروفایل', 'callback_data' => 'profile_edit_start'],
                ['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => 'back_to_main']
            ]
        ];

        $replyMarkup = [
            'inline_keyboard' => $inlineKeyboard
        ];

        $this->sendMessage($targetChatId, $message, null, $replyMarkup);
    }







    /**
     * ارسال پیام به کاربر
     */
    private function sendMessage($chatId, $text, $keyboard = null, $inlineKeyboard = null)
    {
        $token = $this->getBotToken();

        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        // اگر کیبورد معمولی وجود دارد
        if ($keyboard && !$inlineKeyboard) {
            $data['reply_markup'] = json_encode([
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
                'one_time_keyboard' => false
            ]);
        }

        // اگر اینلاین کیبورد وجود دارد
        if ($inlineKeyboard) {
            $data['reply_markup'] = json_encode($inlineKeyboard);
        }

        $url = "https://api.telegram.org/bot{$token}/sendMessage";

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                error_log("Telegram API error: " . $response);
                return false;
            }

            return $response;
        } catch (Exception $e) {
            error_log("sendMessage error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * بروزرسانی state کاربر در دیتابیس
     */
    private function updateUserState($telegramId, $state)
    {
        try {
            $pdo = $this->getPDO();
            $sql = "UPDATE users SET state = ? WHERE telegram_id = ?";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([$state, $telegramId]);

            echo "✅ User state updated to: $state - Result: " . ($result ? "SUCCESS" : "FAILED") . "\n";
            return $result;
        } catch (\Exception $e) {
            echo "❌ Error updating user state: " . $e->getMessage() . "\n";
            return false;
        }
    }
    /**
     * پیدا کردن کاربر بر اساس telegram_id
     */
    private function findUserByTelegramId($telegramId)
    {
        try {
            $pdo = $this->getPDO();
            $sql = "SELECT * FROM users WHERE telegram_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$telegramId]);
            $userData = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($userData) {
                $user = new \stdClass();
                foreach ($userData as $key => $value) {
                    $user->$key = $value;
                }
                return $user;
            }
            return null;
        } catch (\Exception $e) {
            error_log("Error finding user: " . $e->getMessage());
            return null;
        }
    }
    /**
     * ایجاد کاربر جدید
     */
    private function createUser($telegramId, $firstName = null, $username = null, $state = 'start')
    {
        try {
            $pdo = $this->getPDO();
            $sql = "INSERT INTO users (telegram_id, first_name, username, state, created_at) VALUES (?, ?, ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([$telegramId, $firstName, $username, $state]);

            if ($result) {
                echo "✅ New user created: $telegramId\n";
                return $this->findUserByTelegramId($telegramId);
            }

            return null;
        } catch (\Exception $e) {
            error_log("Error creating user: " . $e->getMessage());
            return null;
        }
    }

    private function handlePhotoManagement($text, $user, $chatId)
    {

        switch ($text) {
            case '📤 آپلود اولین عکس':
            case '📤 آپلود عکس جدید':
                $this->sendMessage($chatId, "لطفاً عکس مورد نظر را ارسال کنید:");
                $this->updateUserState($user->telegram_id, 'uploading_additional_photo');
                break;

            case '↩️ بازگشت به منوی پروفایل':
                $this->showProfileMenu($user, $chatId);
                break;

            default:
                $this->sendMessage($chatId, "لطفاً یکی از گزینه‌های منو را انتخاب کنید.");
                // $this->showPhotoManagementMenu($user, $chatId);
                break;
        }

        return true;
    }

    private function getLastUpdateId()
    {
        $filePath = __DIR__ . '/../../storage/last_update_id.txt';

        if (file_exists($filePath)) {
            $lastUpdateId = (int) file_get_contents($filePath);
            echo "📄 Last Update ID from file: $lastUpdateId\n";
            return $lastUpdateId;
        }

        echo "📄 Last Update ID file not found, returning 0\n";
        return 0;
    }
    private function saveLastUpdateId($updateId)
    {
        $filePath = __DIR__ . '/../../storage/last_update_id.txt';
        $dir = dirname($filePath);

        // ایجاد پوشه اگر وجود ندارد
        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filePath, $updateId);
        echo "💾 Saved Last Update ID: $updateId\n";
    }
    private function getUpdates($offset = 0, $limit = 100, $timeout = 0)
    {
        $token = $this->getBotToken();
        $url = "https://api.telegram.org/bot{$token}/getUpdates?offset={$offset}&limit={$limit}&timeout={$timeout}";

        echo "🌐 Calling Telegram API: $url\n";

        $response = file_get_contents($url);
        if ($response === false) {
            echo "❌ Failed to get updates from Telegram\n";
            return [];
        }

        $data = json_decode($response, true);

        if (!$data || !$data['ok']) {
            echo "❌ Telegram API error: " . ($data['description'] ?? 'Unknown error') . "\n";
            return [];
        }

        $updates = $data['result'] ?? [];
        echo "📥 Got " . count($updates) . " update(s) from Telegram\n";

        return $updates;
    }
    private function findSuggestedUserId($user, $chatId)
    {
        error_log("🔍 Finding suggested user ID for user: {$user->id}, state: {$user->state}");
        error_log("🎯 findSuggestedUserId called");
        error_log("📊 User ID: {$user->id}");
        error_log("📋 User State: '{$user->state}'");
        error_log("🔍 Checking if state starts with 'viewing_suggestion:'");

        // فقط از state استفاده کن - راه حل موقت
        if (str_starts_with($user->state, 'viewing_suggestion:')) {
            $parts = explode(':', $user->state);
            if (count($parts) >= 2 && is_numeric($parts[1])) {
                $suggestedUserId = intval($parts[1]);
                error_log("✅ Found suggested user ID from state: {$suggestedUserId}");
                return $suggestedUserId;
            }
        }

        error_log("❌ No suggested user ID found in state");
        return null;
    }

    private function safeDatabaseOperation(callable $operation)
    {
        return DatabaseManager::executeWithRetry($operation);
    }

    private function findUserSafely($telegramId)
    {
        return $this->safeDatabaseOperation(function () use ($telegramId) {
            return User::where('telegram_id', $telegramId)->first();
        });
    }
    private function updateUserSafely($user, $data)
    {
        return $this->safeDatabaseOperation(function () use ($user, $data) {
            return $user->update($data);
        });
    }
    private $lastHealthCheck = 0;
    private $healthCheckInterval = 1800; // هر 30 دقیقه

    private function performHealthCheck()
    {
        if (time() - $this->lastHealthCheck < $this->healthCheckInterval) {
            return;
        }

        try {
            // تست اتصال دیتابیس
            if (!DatabaseManager::ensureConnection()) {
                error_log("🚨 HEALTH CHECK FAILED: Database connection");
                return;
            }

            // تست اتصال تلگرام
            $updates = $this->telegram->getUpdates(0, 1);
            if (!$updates || !$updates['ok']) {
                error_log("🚨 HEALTH CHECK FAILED: Telegram API");
                return;
            }

            error_log("✅ Health check passed");
            $this->lastHealthCheck = time();
        } catch (Exception $e) {
            error_log("🚨 HEALTH CHECK ERROR: " . $e->getMessage());
        }
    }
    private function setUserState($userId, $state)
    {
        $pdo = $this->getPDO();

        try {
            $sql = "INSERT INTO user_states (user_id, state, updated_at) 
                VALUES (?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE 
                state = VALUES(state), 
                updated_at = VALUES(updated_at)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId, $state]);
            return true;
        } catch (\Exception $e) {
            error_log("❌ Error in setUserState: " . $e->getMessage());
            return false;
        }
    }

    /**
     * دریافت وضعیت کاربر
     */
    private function getUserState($userId)
    {
        $pdo = $this->getPDO();

        try {
            $sql = "SELECT state FROM user_states WHERE user_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            $result = $stmt->fetch(\PDO::FETCH_OBJ);

            return $result ? $result->state : null;
        } catch (\Exception $e) {
            error_log("❌ Error in getUserState: " . $e->getMessage());
            return null;
        }
    }

    /**
     * پاک کردن وضعیت کاربر
     */
    private function clearUserState($userId)
    {
        $pdo = $this->getPDO();

        try {
            $sql = "DELETE FROM user_states WHERE user_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId]);
            return true;
        } catch (\Exception $e) {
            error_log("❌ Error in clearUserState: " . $e->getMessage());
            return false;
        }
    }
    // در کلاس BotCore.php
    private function handleDeactivateRequest($user, $chatId)
    {
        // نمایش گزینه‌های دلیل غیرفعال سازی
        $keyboard = [
            ['⏸️ غیرفعال سازی موقت - مسافرت'],
            ['⏸️ غیرفعال سازی موقت - مشغله کاری'],
            ['⏸️ غیرفعال سازی موقت - استراحت'],
            ['🔙 بازگشت به منوی اصلی']
        ];

        $message = "⏸️ غیرفعال سازی موقت حساب\n\n";
        $message .= "در این حالت:\n";
        $message .= "• شما به دیگران پیشنهاد داده نمی‌شوید\n";
        $message .= "• شما پیشنهادی دریافت نمی‌کنید\n";
        $message .= "• تمام اطلاعات و سوابق شما حفظ می‌شود\n";
        $message .= "• هر زمان می‌توانید حساب را فعال کنید\n\n";
        $message .= "لطفاً دلیل غیرفعال سازی را انتخاب کنید:";

        $this->sendMessage($chatId, $message, $keyboard);
        $this->updateUserState($user->telegram_id, 'awaiting_deactivation_reason');
    }

    private function handleActivateRequest($user, $chatId)
    {
        if ($user->is_active) {
            $this->sendMessage($chatId, "✅ حساب شما در حال حاضر فعال است.");
            return;
        }

        if ($this->activateUser($user->telegram_id)) {
            $message = "✅ حساب شما با موفقیت فعال شد!\n\n";
            $message .= "از این به بعد:\n";
            $message .= "• شما در جستجوی دیگران ظاهر می‌شوید\n";
            $message .= "• شما پیشنهادات جدید دریافت خواهید کرد\n";
            $message .= "• می‌توانید به جستجوی افراد بپردازید";

            $this->sendMessage($chatId, $message);
        } else {
            $this->sendMessage($chatId, "❌ خطا در فعال سازی حساب. لطفاً دوباره تلاش کنید.");
        }

        $this->showMainMenu($user, $chatId);
    }


    private function handleDeactivationConfirmation($user, $chatId, $reason)
    {
        if ($this->deactivateUser($user->telegram_id, $reason)) {
            $message = "✅ حساب شما با موفقیت غیرفعال شد\n\n";
            $message .= "📝 دلیل: $reason\n\n";
            $message .= "در این حالت:\n";
            $message .= "• شما به دیگران پیشنهاد داده نمی‌شوید\n";
            $message .= "• شما پیشنهادی دریافت نمی‌کنید\n";
            $message .= "• تمام اطلاعات شما حفظ شده است\n\n";
            $message .= "هر زمان که مایل بودید می‌توانید از منوی اصلی حساب را فعال کنید.";

            $this->sendMessage($chatId, $message);
        } else {
            $this->sendMessage($chatId, "❌ خطا در غیرفعال سازی حساب. لطفاً دوباره تلاش کنید.");
        }

        $this->showMainMenu($user, $chatId);
    }
    // متدهای کمکی برای فعال/غیرفعال کردن کاربر
    private function activateUser($telegramId)
    {
        try {
            $pdo = $this->getPDO();
            $sql = "UPDATE users SET is_active = 1, deactivation_reason = NULL, deactivated_at = NULL WHERE telegram_id = ?";
            $stmt = $pdo->prepare($sql);
            return $stmt->execute([$telegramId]);
        } catch (\Exception $e) {
            error_log("Error activating user: " . $e->getMessage());
            return false;
        }
    }

    private function deactivateUser($telegramId, $reason = 'موقت')
    {
        try {
            $pdo = $this->getPDO();
            $sql = "UPDATE users SET is_active = 0, deactivation_reason = ?, deactivated_at = NOW() WHERE telegram_id = ?";
            $stmt = $pdo->prepare($sql);
            return $stmt->execute([$reason, $telegramId]);
        } catch (\Exception $e) {
            error_log("Error deactivating user: " . $e->getMessage());
            return false;
        }
    }

    // و متد getStatusInfo را در BotCore پیاده‌سازی کنید:
    private function getUserStatusInfo($user)
    {
        if ($user->is_active) {
            return "🟢 حساب شما فعال است";
        } else {
            $reason = $user->deactivation_reason ?? 'موقت';
            $date = $user->deactivated_at ? date('Y-m-d H:i', strtotime($user->deactivated_at)) : 'نامشخص';
            return "🔴 حساب شما غیرفعال است\n📅 از تاریخ: $date\n📝 دلیل: $reason";
        }
    }

    private function handleLikeAction($user, $likedUserId, $callbackQuery)
    {
        $callbackQueryId = $callbackQuery['id'] ?? null;

        if (!$callbackQueryId) {
            error_log("❌ callback_query_id not found in callbackQuery array");
            return;
        }

        // 🔴 نکته مهم: $likedUserId که از callback_data دریافت می‌شود، باید id کاربر باشد (نه telegram_id)
        // در showSuggestion داریم: "like:{$suggestedUser->id}" که id است

        // پیدا کردن کاربر لایک‌شونده با استفاده از id
        $likedUser = \App\Models\User::find($likedUserId);
        if (!$likedUser) {
            $this->telegram->answerCallbackQuery($callbackQueryId, [
                'text' => "کاربر مورد نظر یافت نشد!",
                'show_alert' => false
            ]);
            return;
        }

        // 🔴 استفاده از id کاربران (نه telegram_id)
        $likerId = $user->id;  // id کاربر لایک‌کننده
        $likedId = $likedUser->id;  // id کاربر لایک‌شونده

        // بررسی آیا قبلاً لایک کرده
        if (\App\Models\Like::hasLiked($likerId, $likedId)) {
            $this->telegram->answerCallbackQuery($callbackQueryId, [
                'text' => "شما قبلاً این کاربر را لایک کرده‌اید!",
                'show_alert' => false
            ]);
            return;
        }

        // ذخیره لایک با استفاده از id کاربران
        $like = \App\Models\Like::create([
            'liker_id' => $likerId,  // id کاربر لایک‌کننده
            'liked_id' => $likedId,  // id کاربر لایک‌شونده
            'viewed' => 0,
            'mutual' => 0
        ]);

        // بررسی لایک متقابل
        $isMutual = \App\Models\Like::checkAndMarkMutual($likerId, $likedId);

        if ($isMutual) {
            $responseText = "🎉 لایک متقابل! این کاربر هم شما را لایک کرده بود!";
            $showAlert = true;

            $this->notifyMutualLike($likerId, $likedId);
        } else {
            $responseText = "❤️ لایک شما ثبت شد!";
            $showAlert = false;

            $this->sendLikeNotification($likerId, $likedId);
        }

        // پاسخ به callback
        $this->telegram->answerCallbackQuery($callbackQueryId, [
            'text' => $responseText,
            'show_alert' => $showAlert
        ]);
    }

    private function sendLikeNotification($likerId, $likedUserId)
    {
        $liker = \App\Models\User::find($likerId);
        $likedUser = \App\Models\User::find($likedUserId);

        if (!$liker || !$likedUser) {
            return;
        }

        $message = "🎉 **شما یک لایک جدید دارید!**\n\n";
        $message .= "👤 کاربر جدیدی شما را پسندید:\n\n";
        $message .= "📛 نام: {$liker->first_name_display}\n";

        if ($liker->age) {
            $message .= "📅 سن: {$liker->age}\n";
        }

        if ($liker->city) {
            $message .= "🏙️ شهر: {$liker->city}\n";
        }

        $message .= "\nبرای مشاهده کامل پروفایل، روی دکمه زیر کلیک کنید:";

        // 🔴 استفاده از view_liker: برای هماهنگی با handler موجود
        $inlineKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👀 مشاهده پروفایل', 'callback_data' => "view_liker:{$likerId}"]
                ]
            ]
        ];

        try {
            $this->telegram->sendMessage($likedUser->telegram_id, $message, $inlineKeyboard);

            // علامت زدن لایک به عنوان ارسال شده
            \App\Models\Like::markAsViewed($likerId, $likedUserId);
        } catch (\Exception $e) {
            error_log("❌ ارسال نوتیفیکیشن لایک: " . $e->getMessage());
        }
    }

    private function notifyMutualLike($userAId, $userBId)
{
    $userA = \App\Models\User::find($userAId);
    $userB = \App\Models\User::find($userBId);

    if (!$userA || !$userB) {
        return;
    }

    // پیام برای کاربر A (اطلاعات کاربر B)
    $messageForUserA = "🎉 **لایک متقابل!**\n\n";
    $messageForUserA .= "✅ کاربر زیر نیز شما را لایک کرده است:\n\n";
    $messageForUserA .= "👤 نام: {$userB->first_name_display}\n";
    
    if ($userB->age) {
        $messageForUserA .= "📅 سن: {$userB->age}\n";
    }
    
    if ($userB->city) {
        $messageForUserA .= "🏙️ شهر: {$userB->city}\n";
    }
    
    $messageForUserA .= "\n🤝 اکنون می‌توانید از طریق ربات با هم در ارتباط باشید.";

    // پیام برای کاربر B (اطلاعات کاربر A)
    $messageForUserB = "🎉 **لایک متقابل!**\n\n";
    $messageForUserB .= "✅ کاربر زیر نیز شما را لایک کرده است:\n\n";
    $messageForUserB .= "👤 نام: {$userA->first_name_display}\n";
    
    if ($userA->age) {
        $messageForUserB .= "📅 سن: {$userA->age}\n";
    }
    
    if ($userA->city) {
        $messageForUserB .= "🏙️ شهر: {$userA->city}\n";
    }
    
    $messageForUserB .= "\n🤝 اکنون می‌توانید از طریق ربات با هم در ارتباط باشید.";

    // کیبورد برای هر دو کاربر (مشاهده پروفایل کاربر مقابل)
    $keyboardForUserA = [
        'inline_keyboard' => [
            [
                ['text' => '👀 مشاهده پروفایل کاربر', 'callback_data' => "view_liker:{$userBId}"]
            ]
        ]
    ];

    $keyboardForUserB = [
        'inline_keyboard' => [
            [
                ['text' => '👀 مشاهده پروفایل کاربر', 'callback_data' => "view_liker:{$userAId}"]
            ]
        ]
    ];

    try {
        // ارسال به کاربر A با تصویر کاربر B
        if (!empty($userB->telegram_photo_id) && $userB->telegram_photo_id !== 'null') {
            try {
                $this->telegram->sendPhoto($userA->telegram_id, $userB->telegram_photo_id, $messageForUserA, $keyboardForUserA);
            } catch (\Exception $e) {
                error_log("Error sending mutual like photo to user A: " . $e->getMessage());
                $this->telegram->sendMessage($userA->telegram_id, $messageForUserA, $keyboardForUserA);
            }
        } else {
            $this->telegram->sendMessage($userA->telegram_id, $messageForUserA, $keyboardForUserA);
        }

        // ارسال به کاربر B با تصویر کاربر A
        if (!empty($userA->telegram_photo_id) && $userA->telegram_photo_id !== 'null') {
            try {
                $this->telegram->sendPhoto($userB->telegram_id, $userA->telegram_photo_id, $messageForUserB, $keyboardForUserB);
            } catch (\Exception $e) {
                error_log("Error sending mutual like photo to user B: " . $e->getMessage());
                $this->telegram->sendMessage($userB->telegram_id, $messageForUserB, $keyboardForUserB);
            }
        } else {
            $this->telegram->sendMessage($userB->telegram_id, $messageForUserB, $keyboardForUserB);
        }

    } catch (\Exception $e) {
        error_log("❌ ارسال نوتیفیکیشن لایک متقابل: " . $e->getMessage());
    }
}
// 🔹 نمایش لیست لایک‌کنندگان
private function showLikerProfile($user, $chatId, $likerId)
{
    // پیدا کردن کاربر لایک‌کننده
    $liker = \App\Models\User::find($likerId);

    if (!$liker) {
        $this->telegram->sendMessage($chatId, "❌ کاربر مورد نظر یافت نشد.");
        return;
    }

    // نمایش پیام تأیید
    $this->telegram->sendMessage($chatId, "🔍 در حال بارگذاری پروفایل کاربر...");

    // استفاده از متد showSuggestion موجود برای نمایش پروفایل
    // این متد هم دکمه لایک و هم دکمه درخواست اطلاعات تماس را نمایش می‌دهد
    $this->showSuggestion($user, $chatId, $liker);

    // آپدیت وضعیت کاربر
    $user->update(['state' => 'viewing_liker:' . $likerId]);
}

    private function handleSubscription($user, $chatId)
    {
        $plans = \App\Models\SubscriptionPlan::getActivePlans();

        if ($plans->isEmpty()) {
            $this->telegram->sendMessage($chatId, "⚠️ در حال حاضر پلن اشتراکی فعالی وجود ندارد.");
            return;
        }

        $message = "📋 **لیست پلن‌های اشتراک:**\n\n";

        foreach ($plans as $plan) {
            $message .= "🔸 **{$plan->name}**\n";
            $message .= "💰 قیمت: " . number_format($plan->price) . " تومان\n";
            $message .= "⏳ مدت: {$plan->duration_days} روز\n";
            $message .= "📞 درخواست تماس روزانه: {$plan->max_daily_contacts}\n";
            $message .= "📞 درخواست تماس کل: {$plan->total_contacts}\n";
            $message .= "👥 پیشنهادات روزانه: {$plan->max_daily_suggestions}\n";
            $message .= "👥 پیشنهادات کل: {$plan->total_suggestions}\n";
            $message .= "────────────\n";
        }

        $message .= "\nبرای خرید هر پلن، روی دکمه مربوطه کلیک کنید:";

        // ایجاد کیبورد ثابت
        $keyboardRows = [];

        foreach ($plans as $plan) {
            $label = "💎 {$plan->name}";
            $keyboardRows[] = [['text' => $label]];
        }

        $keyboardRows[] = [['text' => '🔙 بازگشت']];

        $keyboard = [
            'keyboard' => $keyboardRows,
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);

        // تنظیم state
        $user->update(['state' => 'selecting_subscription_plan']);
    }

    private function handleSubscriptionPlanSelection($user, $chatId, $buttonText)
    {
        // استخراج نام پلن از دکمه
        $planName = str_replace('💎 ', '', $buttonText);

        $plan = \App\Models\SubscriptionPlan::where('name', $planName)->first();

        if (!$plan) {
            $this->telegram->sendMessage($chatId, "❌ پلن انتخابی یافت نشد.");
            $this->handleSubscription($user, $chatId);
            return;
        }

        // بررسی موجودی کیف پول
        $wallet = $user->getWallet();

        $message = "💎 **پلن انتخابی:** {$plan->name}\n\n";
        $message .= "💰 قیمت: " . number_format($plan->price) . " تومان\n";
        $message .= "⏳ مدت: {$plan->duration_days} روز\n";
        $message .= "📞 درخواست تماس روزانه: {$plan->max_daily_contacts}\n";
        $message .= "📞 درخواست تماس کل: {$plan->total_contacts}\n";
        $message .= "👥 پیشنهادات روزانه: {$plan->max_daily_suggestions}\n";
        $message .= "👥 پیشنهادات کل: {$plan->total_suggestions}\n";
        $message .= "📝 {$plan->description}\n\n";

        $message .= "💰 **موجودی فعلی شما:** " . number_format($wallet->balance) . " تومان\n\n";

        if ($wallet->balance < $plan->price) {
            // 🔴 **موجودی کافی نیست: state را روی subscription_menu قرار می‌دهیم**
            $message .= "❌ موجودی کیف پول شما کافی نیست!\n";
            $message .= "💵 برای شارژ کیف پول، از منوی کیف پول استفاده کنید.";

            $keyboard = [
                'keyboard' => [
                    [['text' => '💼 کیف پول']],
                    [['text' => '🔙 بازگشت']]
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => false
            ];

            // 🔴 **state را روی subscription_menu قرار می‌دهیم (نه confirming)**
            $user->update(['state' => 'subscription_menu']);
            error_log("💳 موجودی کم - state به subscription_menu تغییر یافت");
        } else {
            // 🔴 **موجودی کافی است: state را برای تأیید خرید قرار می‌دهیم**
            $message .= "آیا مایل به خرید این اشتراک هستید?";

            $keyboard = [
                'keyboard' => [
                    [['text' => '✅ بله، خرید می‌کنم']],
                    [['text' => '❌ خیر، انصراف']]

                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => false
            ];

            // 🔴 **ذخیره plan_id در state برای مرحله تأیید**
            $user->update(['state' => 'confirming_subscription:' . $plan->id]);
            error_log("💾 state ذخیره شد: confirming_subscription:{$plan->id}");
        }

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }
    private function handleSubscriptionConfirmation($user, $chatId, $buttonText)
    {
        // بررسی state کاربر
        if (strpos($user->state, 'confirming_subscription:') !== 0) {
            $this->telegram->sendMessage($chatId, "❌ درخواست نامعتبر است.");
            $this->showMainMenu($user, $chatId);
            return;
        }

        // استخراج plan_id از state
        $planId = (int) str_replace('confirming_subscription:', '', $user->state);
        $plan = \App\Models\SubscriptionPlan::find($planId);

        if (!$plan) {
            $this->telegram->sendMessage($chatId, "❌ پلن انتخابی یافت نشد.");
            $this->showMainMenu($user, $chatId);
            return;
        }

        if ($buttonText === '✅ بله، خرید می‌کنم') {
            $wallet = $user->getWallet();

            // بررسی مجدد موجودی
            if ($wallet->balance < $plan->price) {
                $this->telegram->sendMessage($chatId, "❌ موجودی کیف پول شما کافی نیست!");
                $this->handleWallet($user, $chatId);
                return;
            }

            // کسر از کیف پول
            $wallet->decrement('balance', $plan->price);

            // غیرفعال کردن اشتراک قبلی (اگر وجود دارد)
            \App\Models\UserSubscription::where('user_id', $user->id)
                ->where('status', 'active')
                ->update(['status' => 'expired']);

            // ایجاد اشتراک جدید
            $expiryDate = \Carbon\Carbon::now()->addDays($plan->duration_days);

            $subscription = \App\Models\UserSubscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'start_date' => \Carbon\Carbon::now(),
                'expiry_date' => $expiryDate,
                'status' => 'active',
                'remaining_daily_contacts' => $plan->max_daily_contacts,
                'remaining_total_contacts' => $plan->total_contacts,
                'remaining_daily_suggestions' => $plan->max_daily_suggestions,
                'remaining_total_suggestions' => $plan->total_suggestions,
                'last_reset_date' => \Carbon\Carbon::now()
            ]);

            // ✅ **ثبت تراکنش خرید اشتراک**
            \App\Models\Transaction::create([
                'user_id' => $user->id,
                'type' => 'subscription_purchase', // نوع جدید
                'amount' => -$plan->price, // منفی چون کسر می‌شود
                'description' => "خرید اشتراک {$plan->name} ({$plan->duration_days} روزه)",
                'status' => 'completed',
                'related_id' => $subscription->id // ذخیره آیدی اشتراک
            ]);
            //    // 🔴 **به‌روزرسانی وضعیت درخواست‌های منتظر اشتراک**
            //     $this->updateContactRequestsAfterSubscription($user->id);

            //     // 🔴 **اطلاع‌رسانی به کاربران منتظر**
            //     $this->notifyWaitingUsers($user->id);


            // نمایش تاریخ شمسی
            $jdate = \Morilog\Jalali\Jalalian::fromDateTime($expiryDate);

            $message = "🎉 **خرید اشتراک با موفقیت انجام شد!**\n\n";
            $message .= "💎 اشتراک: {$plan->name}\n";
            $message .= "💰 مبلغ کسر شده: " . number_format($plan->price) . " تومان\n";
            $message .= "📊 موجودی جدید کیف پول: " . number_format($wallet->balance) . " تومان\n";
            $message .= "⏳ اعتبار تا: " . $jdate->format('Y/m/d') . "\n\n";
            $message .= "✅ اشتراک شما فعال شد و می‌توانید از امکانات ویژه استفاده کنید.";
        } elseif ($buttonText === '❌ خیر، انصراف') {
            $message = "خرید اشتراک لغو شد.";
        } else {
            $message = "❌ دستور نامعتبر است.";
        }


        // بازگشت به منوی اصلی
        $user->update(['state' => 'main_menu']);
        $this->showMainMenu($user, $chatId);
        $this->telegram->sendMessage($chatId, $message);
    }

    /**
     * به‌روزرسانی وضعیت درخواست‌های منتظر اشتراک
     */
    private function updateContactRequestsAfterSubscription($userId)
    {
        // پیدا کردن همه درخواست‌هایی که برای این کاربر هستند و منتظر اشتراک هستند
        $updatedCount = \App\Models\ContactRequest::where('requested_id', $userId)
            ->where('status', 'waiting_for_subscription')
            ->update([
                'status' => 'pending',
                'updated_at' => now()
            ]);

        if ($updatedCount > 0) {
            error_log("✅ Updated {$updatedCount} contact requests from 'waiting_for_subscription' to 'pending' for user {$userId}");
        }

        return $updatedCount;
    }

    /**
     * دریافت تعداد درخواست‌های به‌روزرسانی شده
     */
    private function getUpdatedRequestsCount($userId)
    {
        return \App\Models\ContactRequest::where('requested_id', $userId)
            ->where('status', 'pending')
            ->where('updated_at', '>=', Carbon::now()->subMinutes(5)) // درخواست‌هایی که اخیراً به‌روز شده‌اند
            ->count();
    }

    /**
     * اطلاع‌رسانی به کاربران منتظر
     */
    private function notifyWaitingUsers($userId)
    {
        // پیدا کردن کاربرانی که برای این کاربر درخواست داده‌اند و اکنون وضعیتشان pending است
        $waitingRequests = \App\Models\ContactRequest::with('requester')
            ->where('requested_id', $userId)
            ->where('status', 'pending')
            ->where('notification_sent', false)
            ->get();

        foreach ($waitingRequests as $request) {
            try {
                // ارسال پیام به کاربر درخواست‌دهنده
                $message = "🎉 **خبر خوب!**\n\n";
                $message .= "کاربر **{$request->requested->first_name}** اشتراک فعال کرده است.\n";
                $message .= "درخواست تماس شما اکنون در انتظار تأیید است.\n\n";
                $message .= "⏳ به محض تأیید، اطلاعات تماس برای شما ارسال خواهد شد.";

                $this->telegram->sendMessage($request->requester->telegram_id, $message);

                // علامت‌گذاری که اطلاع ارسال شده
                $request->update(['notification_sent' => true]);

                error_log("📨 Sent subscription activation notification to user {$request->requester->id}");
            } catch (\Exception $e) {
                error_log("❌ Failed to send notification to user {$request->requester->id}: " . $e->getMessage());
            }
        }
    }
    private function showMySubscription($user, $chatId)
    {
        $subscription = $user->getActiveSubscription();

        if (!$subscription) {
            $message = "📋 **وضعیت اشتراک شما:**\n\n";
            $message .= "🔴 شما هیچ اشتراک فعالی ندارید.\n\n";
            $message .= "💡 با خرید اشتراک می‌توانید از امکانات زیر استفاده کنید:\n";
            $message .= "• مشاهده اطلاعات تماس کاربران\n";
            $message .= "• دریافت پیشنهادات بیشتر\n";
            $message .= "• استفاده نامحدود از ربات\n\n";
            $message .= "برای مشاهده پلن‌های اشتراک روی دکمه زیر کلیک کنید:";

            $keyboard = [
                'keyboard' => [
                    [['text' => '💎 مشاهده پلن‌ها']],
                    [['text' => '💼 کیف پول']],
                    [['text' => '🔙 منوی اصلی']]
                ],
                'resize_keyboard' => true
            ];
        } else {
            $plan = $subscription->plan;
            $stats = $subscription->getUsageStats();

            // تبدیل تاریخ به شمسی با Morilog/Jalali
            $expiryDateFormatted = 'نامشخص';
            if ($subscription->expiry_date) {
                try {
                    // استفاده از Jalalian
                    $jdate = Jalalian::fromDateTime($subscription->expiry_date);
                    $expiryDateFormatted = $jdate->format('Y/m/d');
                } catch (\Exception $e) {
                    // در صورت خطا، تاریخ میلادی نمایش داده شود
                    error_log("Jalalian conversion error: " . $e->getMessage());
                    if ($subscription->expiry_date instanceof \Carbon\Carbon) {
                        $expiryDateFormatted = $subscription->expiry_date->format('Y/m/d');
                    } else {
                        $expiryDateFormatted = date('Y/m/d', strtotime($subscription->expiry_date));
                    }
                }
            }

            $message = "📋 **وضعیت اشتراک شما:**\n\n";
            $message .= "✅ اشتراک فعال: **" . ($plan->name ?? 'نامشخص') . "**\n";
            $message .= "⏳ روزهای باقی‌مانده: " . $subscription->daysRemaining() . " روز\n";
            $message .= "📅 اعتبار تا: " . $expiryDateFormatted . "\n\n";

            $message .= "📊 **سهمیه‌های مصرفی:**\n\n";

            $message .= "📞 **درخواست تماس:**\n";
            $message .= "   امروز: {$stats['daily_contacts']['used']}/{$stats['daily_contacts']['total']}\n";
            $message .= "   کل: {$stats['total_contacts']['used']}/{$stats['total_contacts']['total']}\n\n";

            $message .= "👥 **پیشنهادات:**\n";
            $message .= "   امروز: {$stats['daily_suggestions']['used']}/{$stats['daily_suggestions']['total']}\n";
            $message .= "   کل: {$stats['total_suggestions']['used']}/{$stats['total_suggestions']['total']}\n";

            $keyboard = [
                'keyboard' => [
                    [['text' => '🔄 تمدید اشتراک']],
                    [['text' => '🔙 منوی اصلی']]
                ],
                'resize_keyboard' => true
            ];
        }

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }
    // مشاهده پروفایل درخواست‌کننده
    private function showRequesterProfileForRequest($user, $chatId, $requesterId, $requestId)
{
    $requester = \App\Models\User::find($requesterId);
    if (!$requester) {
        $this->telegram->sendMessage($chatId, "❌ کاربر یافت نشد.");
        return;
    }

    $contactRequest = \App\Models\ContactRequest::find($requestId);
    if (!$contactRequest || $contactRequest->requested_id != $user->id) {
        $this->telegram->sendMessage($chatId, "❌ درخواست نامعتبر است.");
        return;
    }

    // علامت‌گذاری که کاربر پروفایل را دیده
    $contactRequest->update(['requested_viewed_requester_profile' => true]);

    // ساخت متن برای کپشن (caption)
    $caption = "👤 **پروفایل کاربر درخواست‌کننده**\n\n";
    $caption .= "📝 نام: " . $requester->first_name . "\n";

    if ($requester->age) {
        $caption .= "🎂 سن: " . $requester->age . " سال\n";
    }

    if ($requester->city) {
        $caption .= "📍 شهر: " . $requester->city . "\n";
    }

    if ($requester->gender) {
        $genderText = $requester->gender == 'male' ? 'آقا' : 'خانم';
        $caption .= "👫 جنسیت: " . $genderText . "\n";
    }

    if ($requester->bio) {
        $caption .= "\n📖 درباره من:\n" . $requester->bio . "\n";
    }

    $caption .= "\n────────────────\n";
    $caption .= "🔍 آیا مایلید اطلاعات تماس خود را با این کاربر به اشتراک بگذارید؟";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '✅ بله، تأیید می‌کنم', 'callback_data' => 'approve_contact:' . $requestId],
                ['text' => '❌ خیر، رد می‌کنم', 'callback_data' => 'reject_contact:' . $requestId]
            ],
            [
                ['text' => '🔙 بازگشت', 'callback_data' => 'back_to_request:' . $requestId]
            ]
        ]
    ];

    // ارسال عکس همراه با کپشن یا پیام متنی
    if (!empty($requester->telegram_photo_id) && $requester->telegram_photo_id !== 'null') {
        try {
            $this->telegram->sendPhoto($chatId, $requester->telegram_photo_id, $caption, $keyboard);
        } catch (\Exception $e) {
            // در صورت خطا در ارسال عکس، پیام متنی ارسال شود
            error_log("Error sending profile photo: " . $e->getMessage());
            $this->telegram->sendMessage($chatId, $caption, $keyboard);
        }
    } else {
        // اگر عکس وجود ندارد، پیام متنی ارسال شود
        $this->telegram->sendMessage($chatId, $caption, $keyboard);
    }
}

    // تأیید درخواست
    private function approveContactRequest($user, $chatId, $requestId, $messageId = null)
    {
        $request = \App\Models\ContactRequest::find($requestId);

        if (!$request || $request->requested_id != $user->id) {
            $this->telegram->sendMessage($chatId, "❌ درخواست مورد نظر یافت نشد.");
            return;
        }

        if ($request->status != 'pending') {
            $this->telegram->sendMessage($chatId, "❌ این درخواست قبلاً پردازش شده است.");
            return;
        }

        // به‌روزرسانی وضعیت
        $request->update(['status' => 'approved', 'responded_at' => now()]);

        // ارسال اطلاعات تماس به کاربر درخواست‌دهنده
        $requester = \App\Models\User::find($request->requester_id);
        if ($requester) {
            $contactMessage = "✅ درخواست تماس شما توسط {$user->first_name_display} تأیید شد!\n\n";
            $contactMessage .= "📞 اطلاعات تماس:\n";
            $contactMessage .= "• نام: {$user->first_name_display}\n";
            if ($user->phone) {
                $contactMessage .= "• تلفن: {$user->phone}\n";
            }
            if ($user->telegram_username) {
                $contactMessage .= "• تلگرام: @{$user->telegram_username}\n";
            }

            // 🔴 **اضافه کردن دکمه مشاهده اطلاعات کامل**
            $inlineKeyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '👁️ مشاهده اطلاعات کامل', 'callback_data' => "view_contact_info:{$user->id}:{$request->id}"]
                    ],
                    [
                        ['text' => '📝 ارسال پیام', 'url' => "https://t.me/{$user->telegram_username}"]
                    ]
                ]
            ];

            // اگر کاربر تلگرام یوزرنیم ندارد، دکمه ارسال پیام را حذف کنیم
            if (!$user->telegram_username) {
                $inlineKeyboard['inline_keyboard'][1] = [
                    ['text' => '📋 تاریخچه تماس‌ها', 'callback_data' => 'contact_history']
                ];
            }

            $this->telegram->sendMessage($requester->telegram_id, $contactMessage, $inlineKeyboard);
        }

        $this->telegram->sendMessage($chatId, "✅ درخواست تماس با موفقیت تأیید شد.");

        // نمایش درخواست بعدی اگر وجود دارد
        $nextRequest = \App\Models\ContactRequest::where('requested_id', $user->id)
            ->whereIn('status', ['pending', 'waiting_for_subscription'])
            ->where('id', '>', $requestId)
            ->orderBy('id', 'ASC')
            ->first();

        if ($nextRequest) {
            sleep(1); // تاخیر کوچک
            $this->showIncomingRequestDetail($user, $chatId, $nextRequest->id);
        } else {
            sleep(1);
            $this->showIncomingRequestsList($user, $chatId, 1);
        }
    }

    // رد درخواست
    private function rejectContactRequest($user, $chatId, $requestId)
    {
        $contactRequest = \App\Models\ContactRequest::find($requestId);

        if (!$contactRequest || $contactRequest->requested_id != $user->id) {
            $this->telegram->sendMessage($chatId, "❌ درخواست نامعتبر است.");
            return;
        }

        if (!$contactRequest->isPending()) {
            $this->telegram->sendMessage($chatId, "❌ این درخواست قبلاً پردازش شده است.");
            return;
        }

        $contactRequest->update(['status' => 'rejected']);

        // اطلاع به درخواست‌کننده
        $requester = $contactRequest->requester;
        $message = "❌ درخواست شما رد شد\n\n";
        $message .= "👤 کاربر: " . $user->first_name . "\n";
        $message .= "📝 کاربر درخواست شما برای مشاهده اطلاعات تماس را رد کرد.";

        $this->telegram->sendMessage($requester->telegram_id, $message);

        // پیام به کاربر ردکننده
        $this->telegram->sendMessage($chatId, "✅ درخواست رد شد. هیچ سهمیه‌ای از شما کسر نشد.");
    }

    // ارسال اطلاعات تماس
    private function sendContactInfoToRequester($requester, $requestedUser)
    {
        $message = "🎉 درخواست شما تأیید شد!\n\n";
        $message .= "👤 **اطلاعات تماس کاربر:**\n";
        $message .= "📝 نام: " . $requestedUser->first_name . "\n";

        if ($requestedUser->mobile) {
            $message .= "📱 شماره موبایل: " . $requestedUser->mobile . "\n";
        }

        if ($requestedUser->telegram_id) {
            $telegramUsername = $requestedUser->username ?? 'ندارد';
            $message .= "🔗 آیدی تلگرام: @" . $telegramUsername . "\n";
            if ($telegramUsername !== 'ندارد') {
                $message .= "📲 لینک مستقیم: https://t.me/" . $telegramUsername . "\n";
            }
        }

        if ($requestedUser->city) {
            $message .= "📍 شهر: " . $requestedUser->city . "\n";
        }

        $message .= "\n💡 می‌توانید از طریق دکمه زیر مستقیماً پیام دهید:";

        // ایجاد کیبورد
        $keyboardButtons = [];

        // فقط اگر username دارد دکمه ارسال پیام نمایش داده شود
        if (!empty($requestedUser->username)) {
            $keyboardButtons[] = [
                ['text' => '📝 ارسال پیام به کاربر', 'url' => 'https://t.me/' . $requestedUser->username]
            ];
        }

        $keyboardButtons[] = [
            ['text' => '💾 ذخیره اطلاعات', 'callback_data' => 'save_contact:' . $requestedUser->id],
            ['text' => '📋 تاریخچه تماس‌ها', 'callback_data' => 'contact_history']
        ];

        $keyboard = [
            'inline_keyboard' => $keyboardButtons
        ];

        $this->telegram->sendMessage($requester->telegram_id, $message, $keyboard);

        // ✅ ذخیره در تاریخچه با استفاده از متد استاتیک addToHistory
        // این همان روشی است که قبلاً استفاده می‌کردید
        ContactRequestHistory::addToHistory($requester->id, $requestedUser->id, 0);
    }    private function showMyContactRequests($user, $chatId)
    {
        $counts = $this->getContactRequestCounts($user->id);

        $message = "📋 **مدیریت درخواست‌های تماس**\n\n";

        // آمار کلی
        $message .= "📊 **آمار:**\n";
        $message .= "• درخواست‌های ورودی در انتظار: {$counts['incoming_pending']}\n";
         $message .= "• درخواست‌های ورودی تایید شده: {$counts['incoming_approved']}\n";
        $message .= "• درخواست‌های ارسالی تأیید شده: {$counts['outgoing_approved']}\n\n";

        $message .= "لطفاً یکی از گزینه‌های زیر را انتخاب کنید:";

        $incomingText = '📥 درخواست‌های ورودی';
        if ($counts['incoming_pending'] > 0) {
            $incomingText .= " ({$counts['incoming_pending']})";
        }

        $outgoingText = '📤 درخواست‌های ارسالی';
        if ($counts['outgoing_pending'] > 0) {
            $outgoingText .= " ({$counts['outgoing_pending']})";
        }

        $keyboard = [
            'keyboard' => [
                [['text' => $incomingText]],
                [['text' => $outgoingText]],
                [['text' => '🔙 منوی اصلی']]
            ],
            'resize_keyboard' => true
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
        $user->update(['state' => 'contact_requests_menu']);
    }
    private function hasApprovedContactRequest($requesterId, $requestedId)
    {
        // بررسی در جدول contact_requests
        $approvedRequest = \App\Models\ContactRequest::where('requester_id', $requesterId)
            ->where('requested_id', $requestedId)
            ->where('status', 'approved')
            ->first();

        if ($approvedRequest) {
            return true;
        }

        // همچنین بررسی در تاریخچه قدیمی (اگر می‌خواهید با سیستم قدیمی هم سازگار باشد)
        if (method_exists('ContactRequestHistory', 'hasRequestedBefore')) {
            return ContactRequestHistory::hasRequestedBefore($requesterId, $requestedId);
        }

        return false;
    }
    private function createContactRequest($requester, $requestedUser, $chatId)
    {
        // بررسی اشتراک کاربر درخواست‌شونده
        $requestedUserHasSubscription = $requestedUser->hasActiveSubscription();

        if ($requestedUserHasSubscription) {
            // ایجاد درخواست
            $contactRequest = \App\Models\ContactRequest::create([
                'requester_id' => $requester->id,
                'requested_id' => $requestedUser->id,
                'status' => 'pending'
            ]);

            // ارسال پیام به کاربر درخواست‌شونده
            $this->sendContactRequestToRequestedUser($requestedUser, $requester, $contactRequest->id);

            // پیام به درخواست‌کننده
            $message = "✅ **درخواست شما ارسال شد!**\n\n";
            $message .= "👤 کاربر: " . $requestedUser->first_name . "\n";
            $message .= "💰 یک درخواست از سهمیه شما کسر شد.\n";
            $message .= "⏳ منتظر تأیید کاربر باشید.\n\n";

            // نمایش سهمیه باقی‌مانده
            $subscription = $requester->getActiveSubscription();
            $subscription->refresh(); // تازه‌سازی مقادیر

            $message .= "📊 **سهمیه باقی‌مانده:**\n";
            $message .= "• درخواست تماس روزانه: {$subscription->remaining_daily_contacts}/{$subscription->plan->max_daily_contacts}\n";
            $message .= "• درخواست تماس کلی: {$subscription->remaining_total_contacts}/{$subscription->plan->total_contacts}";

            $this->telegram->sendMessage($chatId, $message);
        } else {
            // کاربر اشتراک ندارد
            $contactRequest = \App\Models\ContactRequest::create([
                'requester_id' => $requester->id,
                'requested_id' => $requestedUser->id,
                'status' => 'waiting_for_subscription'
            ]);

            // پیام به درخواست‌کننده
            $message = "⏳ **کاربر اشتراک فعال ندارد**\n\n";
            $message .= "👤 کاربر: " . $requestedUser->first_name . "\n";
            $message .= "💰 یک درخواست از سهمیه شما کسر شد.\n";
            $message .= "❌ این کاربر در حال حاضر اشتراک فعال ندارد.\n\n";
            $message .= "📩 به کاربر یک پیام ارسال شده و از او خواسته شده اشتراک تهیه کند.\n";
            $message .= "⏰ درخواست شما ذخیره شده و منتظر می‌ماند تا کاربر اشتراک تهیه کند.\n\n";

            // نمایش سهمیه باقی‌مانده
            $subscription = $requester->getActiveSubscription();
            $subscription->refresh();

            $message .= "📊 **سهمیه باقی‌مانده:**\n";
            $message .= "• درخواست تماس روزانه: {$subscription->remaining_daily_contacts}/{$subscription->plan->max_daily_contacts}\n";
            $message .= "• درخواست تماس کلی: {$subscription->remaining_total_contacts}/{$subscription->plan->total_contacts}";

            $this->telegram->sendMessage($chatId, $message);

            // ارسال پیام به کاربر درخواست‌شونده (بدون اشتراک)
            $this->sendSubscriptionRequiredNotification($requestedUser, $requester);
        }
    }

    private function getContactRequestCounts($userId)
    {
        return [
            'incoming_pending' => \App\Models\ContactRequest::where('requested_id', $userId)
                ->where('status', 'pending')
                ->count(),
            'incoming_approved' => \App\Models\ContactRequest::where('requested_id', $userId)
                ->where('status', 'approved')
                ->count(),    
            'outgoing_approved' => \App\Models\ContactRequest::where('requester_id', $userId)
                ->where('status', 'approved')
                ->count(),
            'outgoing_pending' => \App\Models\ContactRequest::where('requester_id', $userId)
                ->where('status', 'pending')
                ->count()    
        ];
    }
  private function showIncomingRequestsList($user, $chatId, $page = 1)
{
    $perPage = 5;
    $offset = ($page - 1) * $perPage;

    // گرفتن همه درخواست‌ها به جز رد شده‌ها
    $requests = \App\Models\ContactRequest::with('requester')
        ->where('requested_id', $user->id)
        ->whereIn('status', ['pending', 'waiting_for_subscription', 'approved'])
        ->orderBy('created_at', 'DESC')
        ->offset($offset)
        ->limit($perPage)
        ->get();

    $total = \App\Models\ContactRequest::where('requested_id', $user->id)
        ->whereIn('status', ['pending', 'waiting_for_subscription', 'approved'])
        ->count();

    $totalPages = ceil($total / $perPage);

    if ($requests->isEmpty()) {
        $message = "📭 هیچ درخواست ورودی ندارید.";
        $this->telegram->sendMessage($chatId, $message);
        $this->showMyContactRequests($user, $chatId);
        return;
    }

    $message = "📥 **درخواست‌های ورودی شما**\n\n";
    $message .= "📄 صفحه {$page} از {$totalPages}\n\n";

    foreach ($requests as $index => $request) {
        $position = $offset + $index + 1;
        
        // تعیین ایموجی بر اساس وضعیت - کنار اسم کاربر
        $statusEmoji = match($request->status) {
            'pending', 'waiting_for_subscription' => '⏳',
            'approved' => '✅',
            default => '🔘'
        };
        
        $statusText = match($request->status) {
            'pending' => 'در انتظار تأیید',
            'waiting_for_subscription' => 'منتظر اشتراک',
            'approved' => 'تایید شده',
            default => $request->status
        };

        // ایموجی وضعیت کنار اسم کاربر نمایش داده می‌شود
        $message .= "{$position}. {$statusEmoji} **{$request->requester->first_name_display}**\n";
        $message .= "   📝 وضعیت: {$statusText}\n";
        $message .= "   🕒 زمان: " . $this->toJalali($request->created_at) . "\n";
        
        // فقط برای درخواست‌های تایید شده، زمان تایید را نمایش بده
        if ($request->status == 'approved' && $request->approved_at) {
            $message .= "   ✅ تأیید در: " . $this->toJalali($request->approved_at) . "\n";
        }
        
        $message .= "   🔘 کد: `{$request->id}`\n\n";
    }

    // دکمه‌های صفحه‌بندی و انتخاب
    $inlineKeyboard = ['inline_keyboard' => []];

    // ردیف دکمه‌های انتخاب
    foreach ($requests as $index => $request) {
        $position = $offset + $index + 1;
        
        // تعیین ایموجی برای دکمه بر اساس وضعیت
        $buttonEmoji = match($request->status) {
            'pending', 'waiting_for_subscription' => '⏳',
            'approved' => '✅',
            default => '👤'
        };
        
        $inlineKeyboard['inline_keyboard'][] = [
            [
                'text' => "{$buttonEmoji} {$request->requester->first_name_display}",
                'callback_data' => "view_incoming:{$request->id}"
            ]
        ];
    }

    // ردیف دکمه‌های صفحه‌بندی (بدون دکمه فیلتر)
    $paginationButtons = [];
    if ($page > 1) {
        $paginationButtons[] = ['text' => '⏪ قبلی', 'callback_data' => "incoming_page:" . ($page - 1)];
    }
    $paginationButtons[] = ['text' => '🔙 بازگشت', 'callback_data' => 'back_to_requests_menu'];
    if ($page < $totalPages) {
        $paginationButtons[] = ['text' => 'بعدی ⏩', 'callback_data' => "incoming_page:" . ($page + 1)];
    }

    $inlineKeyboard['inline_keyboard'][] = $paginationButtons;

    $this->telegram->sendMessage($chatId, $message, $inlineKeyboard);
    $user->update(['state' => 'viewing_incoming_list:' . $page]);
}
 private function showIncomingRequestDetail($user, $chatId, $requestId, $messageId = null)
{
    $request = \App\Models\ContactRequest::with('requester')
        ->where('id', $requestId)
        ->where('requested_id', $user->id)
        ->first();

    if (!$request) {
        $this->telegram->sendMessage($chatId, "❌ درخواست مورد نظر یافت نشد.");
        return;
    }

    $requester = $request->requester;

    // محاسبه سن از birth_date اگر age موجود نیست
    $age = $requester->age;
    if (!$age && $requester->birth_date) {
        $age = Carbon::now()->diffInYears($requester->birth_date);
    }

    $message = "📋 **جزئیات درخواست تماس**\n\n";
    $message .= "👤 **کاربر درخواست‌دهنده:**\n";
    $message .= "• نام: {$requester->first_name_display}\n";
    if ($age) {
        $message .= "• سن: {$age} سال\n";
    }
    if ($requester->city) {
        $message .= "• شهر: {$requester->city}\n";
    }

    $message .= "\n📝 **وضعیت درخواست:**\n";
    $statusText = match ($request->status) {
        'pending' => '⏳ در انتظار تأیید',
        'waiting_for_subscription' => '💤 منتظر اشتراک کاربر',
        'approved' => '✅ تایید شده',
        'rejected' => '❌ رد شده',
        default => $request->status
    };
    $message .= "• {$statusText}\n";
    $message .= "• زمان ارسال: " . $this->toJalali($request->created_at) . "\n";
    
    // اگر تایید شده است، زمان تایید را نیز نمایش بده
    if ($request->status == 'approved' && $request->approved_at) {
        $message .= "• زمان تأیید: " . $this->toJalali($request->approved_at) . "\n";
    }

    // دکمه‌های عملیاتی
    $inlineKeyboard = ['inline_keyboard' => []];

    // ردیف اول: دکمه‌های تأیید/رد/پروفایل
    $actionRow = [];

    if ($request->status === 'pending') {
        $actionRow[] = ['text' => '✅ تأیید', 'callback_data' => "approve_request:{$request->id}"];
        $actionRow[] = ['text' => '❌ رد', 'callback_data' => "reject_request:{$request->id}"];
    }
    
    // اضافه کردن دکمه مشاهده پروفایل
    $actionRow[] = ['text' => '👀 مشاهده پروفایل', 'callback_data' => "view_profile:{$requester->id}:{$requestId}"];
    
    if (!empty($actionRow)) {
        $inlineKeyboard['inline_keyboard'][] = $actionRow;
    }

    // 🔴 اضافه کردن دکمه ارسال پیام اگر درخواست تایید شده باشد
    if ($request->status === 'approved') {
        $messageRow = [];
        
        // اگر کاربر درخواست دهنده username داشته باشد، از لینک مستقیم استفاده می‌کنیم
        if (!empty($requester->username)) {
            $messageRow[] = ['text' => '📝 ارسال پیام به کاربر', 'url' => 'https://t.me/' . $requester->username];
        } else {
            // اگر username ندارد، از callback_data استفاده می‌کنیم تا ربات بتواند واسط ارسال پیام شود
            $messageRow[] = ['text' => '📝 ارسال پیام به کاربر', 'callback_data' => "send_message:{$requester->id}"];
        }
        
        $inlineKeyboard['inline_keyboard'][] = $messageRow;
    }

    // ردیف دوم: دکمه‌های ناوبری
    $navRow = [];

    // پیدا کردن درخواست قبلی و بعدی
    // فقط برای درخواست‌هایی با وضعیت مشابه (pending/waiting یا approved)
    $statusFilter = $request->status === 'approved' 
        ? ['approved'] 
        : ['pending', 'waiting_for_subscription'];

    $prevRequest = \App\Models\ContactRequest::where('requested_id', $user->id)
        ->whereIn('status', $statusFilter)
        ->where('id', '<', $request->id)
        ->orderBy('id', 'DESC')
        ->first();

    $nextRequest = \App\Models\ContactRequest::where('requested_id', $user->id)
        ->whereIn('status', $statusFilter)
        ->where('id', '>', $request->id)
        ->orderBy('id', 'ASC')
        ->first();

    if ($prevRequest) {
        $navRow[] = ['text' => '◀️ قبلی', 'callback_data' => "prev_incoming:{$request->id}"];
    }

    $navRow[] = ['text' => '📋 لیست', 'callback_data' => 'back_to_incoming_list'];

    if ($nextRequest) {
        $navRow[] = ['text' => 'بعدی ▶️', 'callback_data' => "next_incoming:{$request->id}"];
    }

    $inlineKeyboard['inline_keyboard'][] = $navRow;

    // ردیف سوم: بازگشت
    $inlineKeyboard['inline_keyboard'][] = [
        ['text' => '🔙 منوی درخواست‌ها', 'callback_data' => 'back_to_requests_menu']
    ];

    try {
        // بررسی وجود عکس پروفایل
        if (!empty($requester->telegram_photo_id) && $requester->telegram_photo_id !== 'null') {
            try {
                // ارسال عکس با کپشن
                $this->telegram->sendPhoto($chatId, $requester->telegram_photo_id, $message, $inlineKeyboard);
            } catch (\Exception $e) {
                // اگر ارسال عکس با خطا مواجه شد، پیام متنی ارسال کن
                error_log("Error sending photo for incoming request detail: " . $e->getMessage());
                $this->telegram->sendMessage($chatId, $message, $inlineKeyboard);
            }
        } else {
            // اگر عکس وجود ندارد، پیام متنی ارسال کن
            $this->telegram->sendMessage($chatId, $message, $inlineKeyboard);
        }
    } catch (\Exception $e) {
        // مدیریت خطاهای کلی
        error_log("Error in showIncomingRequestDetail: " . $e->getMessage());
        $this->telegram->sendMessage($chatId, "❌ خطایی در نمایش جزئیات درخواست رخ داد.", $inlineKeyboard);
    }

    $user->update(['state' => 'viewing_incoming_detail:' . $request->id]);
}


    private function showNextIncomingRequest($user, $chatId, $currentRequestId, $messageId)
    {
        $nextRequest = \App\Models\ContactRequest::where('requested_id', $user->id)
            ->whereIn('status', ['pending', 'waiting_for_subscription'])
            ->where('id', '>', $currentRequestId)
            ->orderBy('id', 'ASC')
            ->first();

        if ($nextRequest) {
            $this->showIncomingRequestDetail($user, $chatId, $nextRequest->id, $messageId);
        } else {
            // رفتن به اولین درخواست
            $firstRequest = \App\Models\ContactRequest::where('requested_id', $user->id)
                ->whereIn('status', ['pending', 'waiting_for_subscription'])
                ->orderBy('id', 'ASC')
                ->first();

            if ($firstRequest && $firstRequest->id != $currentRequestId) {
                $this->showIncomingRequestDetail($user, $chatId, $firstRequest->id, $messageId);
            } else {
                $this->telegram->sendMessage($chatId, "⚠️ درخواست بعدی وجود ندارد.");
            }
        }
    }

    private function showPrevIncomingRequest($user, $chatId, $currentRequestId, $messageId)
    {
        $prevRequest = \App\Models\ContactRequest::where('requested_id', $user->id)
            ->whereIn('status', ['pending', 'waiting_for_subscription'])
            ->where('id', '<', $currentRequestId)
            ->orderBy('id', 'DESC')
            ->first();

        if ($prevRequest) {
            $this->showIncomingRequestDetail($user, $chatId, $prevRequest->id, $messageId);
        } else {
            // رفتن به آخرین درخواست
            $lastRequest = \App\Models\ContactRequest::where('requested_id', $user->id)
                ->whereIn('status', ['pending', 'waiting_for_subscription'])
                ->orderBy('id', 'DESC')
                ->first();

            if ($lastRequest && $lastRequest->id != $currentRequestId) {
                $this->showIncomingRequestDetail($user, $chatId, $lastRequest->id, $messageId);
            } else {
                $this->telegram->sendMessage($chatId, "⚠️ درخواست قبلی وجود ندارد.");
            }
        }
    }
    private function showOutgoingRequestsList($user, $chatId, $page = 1)
    {
        $perPage = 5;
        $offset = ($page - 1) * $perPage;

        // گرفتن درخواست‌های ارسالی
        $requests = \App\Models\ContactRequest::with('requested')
            ->where('requester_id', $user->id)
            ->orderBy('created_at', 'DESC')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $total = \App\Models\ContactRequest::where('requester_id', $user->id)->count();
        $totalPages = ceil($total / $perPage);

        if ($requests->isEmpty()) {
            $message = "📭 هیچ درخواست ارسالی ندارید.";
            $this->telegram->sendMessage($chatId, $message);
            $this->showMyContactRequests($user, $chatId);
            return;
        }

        $message = "📤 **درخواست‌های ارسالی شما**\n\n";
        $message .= "📄 صفحه {$page} از {$totalPages}\n\n";

        foreach ($requests as $index => $request) {
            $position = $offset + $index + 1;

            $statusEmoji = match ($request->status) {
                'pending' => '⏳',
                'approved' => '✅',
                'rejected' => '❌',
                'waiting_for_subscription' => '💤',
                default => '📝'
            };

            $statusText = match ($request->status) {
                'pending' => 'در انتظار',
                'approved' => 'تأیید شده',
                'rejected' => 'رد شده',
                'waiting_for_subscription' => 'منتظر اشتراک',
                default => $request->status
            };

            $message .= "{$position}. {$statusEmoji} **{$request->requested->first_name_display}**\n";
            $message .= "   📝 وضعیت: {$statusText}\n";

            if ($request->status === 'approved' && $request->responded_at) {
                $message .= "   ✅ تأیید شده در: " . $this->toJalali($request->responded_at)."\n";
            }

            $message .= "   🕒 ارسال شده: " . $this->toJalali($request->created_at). "\n";
            $message .= "   🔘 کد: `{$request->id}`\n\n";
        }

        // دکمه‌های صفحه‌بندی
        $inlineKeyboard = ['inline_keyboard' => []];

        // دکمه‌های انتخاب (برای درخواست‌های تأیید شده می‌توان اطلاعات تماس را نشان داد)
        foreach ($requests as $index => $request) {
            $position = $offset + $index + 1;

            if ($request->status === 'approved') {
                $inlineKeyboard['inline_keyboard'][] = [
                    [
                        'text' => "{$position}. مشاهده اطلاعات تماس {$request->requested->first_name_display}",
                        'callback_data' => "view_contact_info:{$request->requested->id}:{$request->id}"
                    ]
                ];
            }
         
        }

        // ردیف دکمه‌های صفحه‌بندی
        $paginationButtons = [];
        if ($page > 1) {
            $paginationButtons[] = ['text' => '⏪ قبلی', 'callback_data' => "outgoing_page:" . ($page - 1)];
        }
        $paginationButtons[] = ['text' => '🔙 بازگشت', 'callback_data' => 'back_to_requests_menu'];
        if ($page < $totalPages) {
            $paginationButtons[] = ['text' => 'بعدی ⏩', 'callback_data' => "outgoing_page:" . ($page + 1)];
        }

        $inlineKeyboard['inline_keyboard'][] = $paginationButtons;

        $this->telegram->sendMessage($chatId, $message, $inlineKeyboard);
        $user->update(['state' => 'viewing_outgoing_list:' . $page]);
    }
}
