<?php
namespace App\Core;

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/TelegramAPI.php';
require_once __DIR__ . '/ProfileFieldManager.php';
//require_once __DIR__ . '/PerformanceMonitor.php';
use App\Models\User;
use App\Models\Wallet;
use App\Models\ChargeCode;
use App\Models\Transaction;
use App\Models\ProfileField;
use App\Models\Administrator;
use App\Models\ContactRequestHistory;
use App\Models\UserFilter;
use App\Models\SystemFilter;
use App\Models\Referral;
use Exception;


class BotCore
{
    private $telegram;
    private $updateManager;

    private static $databaseOptimized = false; // 🔴 جلوگیری از اجرای تکراری

    public function __construct()
    {
        $this->telegram = new TelegramAPI($_ENV['TELEGRAM_BOT_TOKEN']);
        $this->updateManager = new UpdateManager();
        // 🔴 اجرای بهینه‌سازی دیتابیس هنگام راه‌اندازی
        $this->optimizeDatabase();
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


    public function handleUpdate()
{
    try {
        $lastUpdateId = $this->getLastUpdateId();
        echo "🔄 Checking for updates...\n";
        
        $updates = $this->getUpdates($lastUpdateId);
        
        foreach ($updates as $update) {
            echo "🎯 Processing update ID: " . ($update['update_id'] ?? 'unknown') . "\n";
            $this->processUpdate($update);
            
            if (isset($update['update_id'])) {
                $this->saveLastUpdateId($update['update_id'] + 1);
            }
        }
        
        echo "✅ Processed " . count($updates) . " update(s)\n";
        
    } catch (Exception $e) {
        echo "❌ Error in handleUpdate: " . $e->getMessage() . "\n";
    }
}
    public function processUpdate($update)
{
    echo "🔍 FULL UPDATE STRUCTURE:\n";
    echo json_encode($update, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    echo "=====================\n";
    
    if (isset($update['message'])) {
        echo "📨 Processing as message\n";
        return $this->processMessage($update['message']);
    }
    
    if (isset($update['callback_query'])) {
        echo "🔘 Processing as callback query\n";
        return $this->processCallbackQuery($update['callback_query']);
    }
    
    // بررسی سایر ساختارهای ممکن
    if (isset($update['edited_message'])) {
        echo "📝 Processing as edited message\n";
        return $this->processMessage($update['edited_message']);
    }
    
    if (isset($update['channel_post'])) {
        echo "📢 Processing as channel post\n";
        return $this->processMessage($update['channel_post']);
    }
    
    echo "❌ Unknown update type\n";
    return false;
}

  private function findOrCreateUser($from, $chatId = null)
{
    $telegramId = $from['id'];
    
    // اول سعی کن از Eloquent استفاده کنی
    if (class_exists('App\Models\User') && class_exists('Illuminate\Database\Eloquent\Model')) {
        try {
            $user = \App\Models\User::where('telegram_id', $telegramId)->first();
            
            if (!$user) {
                // ایجاد کاربر جدید با Eloquent
                $user = \App\Models\User::create([
                    'telegram_id' => $telegramId,
                    'first_name' => $from['first_name'] ?? '',
                    'username' => $from['username'] ?? '',
                    'state' => 'start'
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
            $user->getWallet = function() {
                $wallet = new \stdClass();
                $wallet->balance = 0;
                $wallet->currency = 'تومان';
                $wallet->formatBalance = function() use ($wallet) {
                    return number_format($wallet->balance) . ' ' . $wallet->currency;
                };
                return $wallet;
            };
            $user->getFormattedBalance = function() {
                return number_format(0) . ' تومان';
            };
        }
        
        return $user;
    } else {
        // ایجاد کاربر جدید با PDO
        echo "🆕 Creating new user with PDO: {$telegramId}\n";
        
        $sql = "INSERT INTO users (telegram_id, first_name, username, state, created_at) VALUES (?, ?, ?, ?, NOW())";
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
        
        if ($user instanceof \stdClass) {
            $user->getWallet = function() {
                $wallet = new \stdClass();
                $wallet->balance = 0;
                $wallet->currency = 'تومان';
                $wallet->formatBalance = function() use ($wallet) {
                    return number_format($wallet->balance) . ' ' . $wallet->currency;
                };
                return $wallet;
            };
            $user->getFormattedBalance = function() {
                return number_format(0) . ' تومان';
            };
        }
        
        return $user;
    }
}
    private function processCallbackQuery($callbackQuery)
    {
        PerformanceMonitor::start('callback_' . $callbackQuery['data']);
        $chatId = $callbackQuery['message']['chat']['id'];
        $data = $callbackQuery['data'];
        $from = $callbackQuery['from'];

        echo "🔄 Callback: $data from: {$from['first_name']}\n";

        $user = $this->findOrCreateUser($from, $chatId);

        // پردازش کلیه callback data ها
        switch ($data) {
            // منوی اصلی
            case 'main_menu':
                $this->showMainMenu($user, $chatId);
                break;
            case 'profile':
                $this->handleProfileCommand($user, $chatId);
                break;
            case 'wallet':
                $this->handleWallet($user, $chatId);
                break;
            case 'search':
                $this->handleSearch($user, $chatId);
                break;
            case 'referral':
                $this->handleReferral($user, $chatId);
                break;
            case 'help':
                $this->handleHelp($chatId);
                break;

            // منوی کیف پول
            case 'wallet_charge':
                $this->handleCharge($user, $chatId);
                break;
            case 'wallet_transactions':
                $this->handleTransactions($user, $chatId);
                break;

            // منوی پروفایل - سیستم جدید
            case 'profile_edit_start':
                $this->startProfileEdit($user, $chatId);
                break;
            case 'profile_view':
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

            // بازگشت‌ها
            case 'back_to_main':
                $this->showMainMenu($user, $chatId);
                break;
            case 'back_to_wallet':
                $this->handleWallet($user, $chatId);
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

            case str_starts_with($data, 'set_filter_value:'):
                $parts = explode(':', $data);
                if (count($parts) >= 3) {
                    $fieldName = $parts[1];
                    $value = urldecode($parts[2]); // 🔴 decode کردن مقدار
                    $this->setFilterValue($user, $chatId, $fieldName, $value);
                }
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
            case 'edit_filters':
                $this->handleEditFilters($user, $chatId);
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
            case 'contact_history':
                $this->showContactHistory($user, $chatId);
                break;
            case str_starts_with($data, 'contact_history_view:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2 && is_numeric($parts[1])) {
                    $requestedUserId = intval($parts[1]);
                    $this->showContactInfo($user, $chatId, $requestedUserId, 0);
                }
                break;
            case str_starts_with($data, 'contact_history_page:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2 && is_numeric($parts[1])) {
                    $page = intval($parts[1]);
                    $this->showContactHistory($user, $chatId, $page);
                }
                break;

            case str_starts_with($data, 'contact_history_view:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2 && is_numeric($parts[1])) {
                    $requestedUserId = intval($parts[1]);
                    $this->showContactDetails($user, $chatId, $requestedUserId);
                }
                break;

            case 'debug_users':
                $this->debugUsersStatus($user, $chatId);
                break;
            case str_starts_with($data, 'confirm_contact_request:'):
                $parts = explode(':', $data);
                if (count($parts) >= 2 && is_numeric($parts[1])) {
                    $suggestedUserId = intval($parts[1]);
                    $this->processContactPayment($user, $chatId, $suggestedUserId);
                }
                break;

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
            case 'debug_filters':
                $this->debugFilters($user, $chatId);
                break;

            case 'test_filters':
                $this->testFilterSystem($user, $chatId);
                break;
            case 'debug_filters':
                $this->debugFilterSystem($user, $chatId);
                break;
            case 'debug_gender':
                $this->debugGender($user, $chatId);
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
            case 'manage_photos':
                $this->showPhotoManagementMenu($user, $chatId);
                break;

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
        $wallet = $user->getWallet();
        $cost = $this->getContactRequestCost();

        // بررسی دقیق وضعیت پروفایل
        $actualCompletion = $this->checkProfileCompletion($user);
        $completionPercent = $this->calculateProfileCompletion($user);

        // اگر وضعیت در دیتابیس با واقعیت تطابق ندارد، آپدیت کن
        if ($user->is_profile_completed != $actualCompletion) {
            $user->update(['is_profile_completed' => $actualCompletion]);
        }

        $message = "🎯 **منوی اصلی ربات دوستیابی**\n\n";
        $message .= "👤 کاربر: " . $user->first_name . "\n";
        $message .= "💰 موجودی: " . number_format($wallet->balance) . " تومان\n";
        $message .= "📊 وضعیت پروفایل: " . ($actualCompletion ? "✅ تکمیل شده" : "❌ ناقص ({$completionPercent}%)") . "\n\n";

        // 🔴 اضافه کردن وضعیت پیشنهادات
        $suggestionCount = \App\Models\UserSuggestion::getUserSuggestionCount($user->id);
        $message .= "💌 پیشنهادات دریافت شده: " . $suggestionCount . "\n\n";

        if (!$actualCompletion) {
            $message .= "⚠️ **توجه:** برای استفاده از امکانات ربات، لطفاً پروفایل خود را کامل کنید.\n\n";
        }

        $message .= "لطفاً یکی از گزینه‌های زیر را انتخاب کنید:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📜 تاریخچه درخواست‌ها', 'callback_data' => 'contact_history'],
                    ['text' => '💌 دریافت پیشنهاد', 'callback_data' => 'get_suggestion']


                ],
                [
                    //['text' => '🔍 جستجوی افراد', 'callback_data' => 'search'],
                    ['text' => '⚙️ تنظیمات', 'callback_data' => 'settings']

                ],
                [
                    ['text' => '👥 سیستم دعوت', 'callback_data' => 'referral'],
                    ['text' => 'ℹ️ راهنمای استفاده', 'callback_data' => 'help']
                ]
            ]
        ];

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

        $filterStatus = $activeFiltersCount > 0 ? "✅ فعال ({$activeFiltersCount} فیلتر)" : "❌ غیرفعال";

        $message = "⚙️ **منوی تنظیمات**\n\n";
        $message .= "👤 کاربر: " . $user->first_name . "\n";
        $message .= "💰 موجودی: " . number_format($wallet->balance) . " تومان\n";
        $message .= "📊 وضعیت پروفایل: " . ($actualCompletion ? "✅ تکمیل شده" : "❌ ناقص ({$completionPercent}%)") . "\n";
        $message .= "🎛️ وضعیت فیلترها: {$filterStatus}\n\n";
        $message .= "لطفاً یکی از گزینه‌های زیر را انتخاب کنید:";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📝 پروفایل من', 'callback_data' => 'profile'],
                    ['text' => '🎛️ تنظیمات فیلتر', 'callback_data' => 'edit_filters']
                ],
                [
                    ['text' => '💼 کیف پول', 'callback_data' => 'wallet']

                ],
                [
                    ['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu']
                ]
            ]
        ];

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
                    ['text' => '✏️ ویرایش پروفایل', 'callback_data' => 'profile_edit_start'],
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
            $message .= "لطفاً مقدار جدید را وارد کنید:\n";
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
            ['text' => '❌ انصراف', 'callback_data' => 'profile_cancel']
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
        $this->showMainMenu($user, $chatId);
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

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✏️ ویرایش پروفایل', 'callback_data' => 'back_to_profile_menu'],
                    ['text' => '📷 مدیریت عکس‌ها', 'callback_data' => 'managing_photos'],
                    ['text' => '🔙 بازگشت', 'callback_data' => 'profile']
                ]
            ]
        ];

        // اگر کاربر عکس پروفایل دارد، عکس را به همراه متن ارسال کن
        if (!empty($user->profile_photo)) {
            $photoUrl = $this->getProfilePhotoUrl($user->profile_photo);
            $this->telegram->sendPhoto($chatId, $photoUrl, $message, $keyboard);
        } else {
            $this->telegram->sendMessage($chatId, $message, $keyboard);
        }
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
                        $this->showPhotoManagementMenu($user, $chatId);
                    } else {
                        $this->sendMessage($chatId, "لطفاً یک عکس ارسال کنید. برای لغو از گزینه '❌ لغو آپلود عکس' استفاده کنید.");

                        $keyboard = [
                            ['❌ لغو آپلود عکس']
                        ];
                        $this->sendMessage($chatId, "یا از منوی زیر برای لغو استفاده کنید:", $keyboard);
                    }
                }
                break;

            case 'selecting_main_photo':
                return $this->handleMainPhotoSelection($text, $user, $chatId);

            // سایر state های موجود...
            case 'entering_name':
                return $this->handleNameInput($user, $text, $chatId);
            case 'entering_age':
                return $this->handleAgeInput($user, $text, $chatId);
            case 'entering_bio':
                return $this->handleBioInput($user, $text, $chatId);
            case 'entering_city':
                return $this->handleCityInput($user, $text, $chatId);
            case 'entering_income':
                return $this->handleIncomeInput($user, $text, $chatId);

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
        $fieldName = str_replace('editing_', '', $currentState);

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
        // چون بعداً در نمایش به متن تبدیل می‌شه
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

        // رفتن به فیلد بعدی
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

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 شارژ کیف پول', 'callback_data' => 'wallet_charge'],
                    ['text' => '📋 تاریخچه تراکنش‌ها', 'callback_data' => 'wallet_transactions']
                ],
                [
                    ['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'back_to_main'],
                    ['text' => '📜 تاریخچه درخواست‌ها', 'callback_data' => 'contact_history']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }



    private function handleTransactions($user, $chatId)
    {
        $transactions = $user->transactions()->latest()->limit(10)->get();
        $wallet = $user->getWallet();

        $message = "📋 **آخرین تراکنش‌های شما**\n\n";

        if ($transactions->count() > 0) {
            foreach ($transactions as $transaction) {
                $typeEmoji = $transaction->amount > 0 ? '➕' : '➖';

                // تبدیل رشته به تاریخ
                $timestamp = strtotime($transaction->created_at);
                $formattedDate = date('Y-m-d H:i', $timestamp);

                $message .= "{$typeEmoji} **" . number_format(abs($transaction->amount)) . " تومان**\n";
                $message .= "📝 " . $this->getTransactionTypeText($transaction->type) . "\n";
                $message .= "⏰ " . $formattedDate . "\n";
                $message .= "────────────\n";
            }

            $message .= "💰 موجودی فعلی: **" . number_format($wallet->balance) . " تومان**\n\n";
        } else {
            $message .= "📭 هیچ تراکنشی یافت نشد.";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔙 بازگشت به کیف پول', 'callback_data' => 'back_to_wallet']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function handleChargeCodeInput($text, $user, $chatId)
    {
        $code = strtoupper(trim($text));

        $chargeCode = ChargeCode::where('code', $code)->first();

        if (!$chargeCode) {
            $this->telegram->sendMessage($chatId, "❌ کد شارژ نامعتبر است. لطفاً مجدد تلاش کنید:");
            return;
        }

        if (!$chargeCode->isValid()) {
            $this->telegram->sendMessage($chatId, "❌ این کد شارژ قبلاً استفاده شده یا منقضی شده است.");
            $user->update(['state' => 'main_menu']);
            return;
        }

        $wallet = $user->getWallet();
        $wallet->charge($chargeCode->amount, "شارژ با کد: {$code}");

        $chargeCode->update([
            'is_used' => true,
            'used_by' => $user->id,
            'used_at' => date('Y-m-d H:i:s')
        ]);

        $message = "✅ کیف پول شما با موفقیت شارژ شد!\n\n";
        $message .= "💰 مبلغ: " . number_format($chargeCode->amount) . " تومان\n";
        $message .= "💳 موجودی جدید: " . number_format($wallet->balance) . " تومان\n\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'back_to_main']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
        $user->update(['state' => 'main_menu']);
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
            $user->generateInviteCode();
            $user->refresh(); // بارگذاری مجدد کاربر از دیتابیس
        }

        $inviteLink = $user->getInviteLink();
        $stats = Referral::getUserReferralStats($user->id);

        $message = "👥 **سیستم دعوت دوستان**\n\n";

        $message .= "🔗 **لینک دعوت شما:**\n";
        $message .= "`{$inviteLink}`\n\n";

        $message .= "📧 **کد دعوت شما:**\n";
        $message .= "`{$user->invite_code}`\n\n";

        $message .= "📊 **آمار دعوت‌های شما:**\n";
        $message .= "• 👥 کل دعوت‌ها: {$stats['total_referrals']} نفر\n";
        $message .= "• ✅ دعوت‌های موفق (خرید کرده‌اند): {$stats['purchased_referrals']} نفر\n";
        $message .= "• ⏳ دعوت‌های در انتظار: {$stats['pending_referrals']} نفر\n";
        $message .= "• 💰 مجموع پاداش‌ها: " . number_format($stats['total_bonus']) . " تومان\n\n";

        $message .= "🎁 **شرایط پاداش:**\n";
        $message .= "• با هر دعوت موفق، ۱۰٪ از مبلغ اولین خرید دوستتان به عنوان پاداش دریافت می‌کنید\n";
        $message .= "• پاداش بلافاصله پس از خرید به کیف پول شما اضافه می‌شود\n";
        $message .= "• می‌توانید از پاداش برای درخواست اطلاعات تماس استفاده کنید\n\n";

        $message .= "💡 **نحوه استفاده:**\n";
        $message .= "• لینک فوق را برای دوستان خود ارسال کنید\n";
        $message .= "• یا کد دعوت خود را به آنها بدهید\n";
        $message .= "• وقتی دوستان شما اولین خرید را انجام دهند، پاداش دریافت می‌کنید";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📋 کپی لینک دعوت', 'callback_data' => 'copy_invite_link'],
                    ['text' => '📤 اشتراک‌گذاری لینک', 'callback_data' => 'share_invite_link']
                ],
                [
                    ['text' => '🔄 بروزرسانی آمار', 'callback_data' => 'referral'],
                    ['text' => '💼 کیف پول', 'callback_data' => 'wallet']
                ],
                [
                    ['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function handleHelp($chatId)
    {
        $message = "ℹ️ **راهنمای استفاده از ربات**\n\n";
        $message .= "🤝 **ربات دوستیابی**\n";
        $message .= "• ایجاد پروفایل کامل\n";
        $message .= "• جستجوی افراد هم‌شهر\n";
        $message .= "• سیستم کیف پول و شارژ\n";
        $message .= "• دعوت دوستان و دریافت پاداش\n\n";
        $message .= "📞 **پشتیبانی**: برای راهنمایی بیشتر با پشتیبانی تماس بگیرید.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'back_to_main']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function getTransactionTypeText($type)
    {
        $types = [
            'charge' => 'شارژ کیف پول',
            'purchase' => 'دریافت اطلاعات  تماس ',
            'referral_bonus' => '🎁پاداش دعوت',
            'withdraw' => 'برداشت'
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
        $message .= "مقدار جدید: **{$value}**\n\n";

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
        $this->showAdminPanel($user, $chatId);
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
    private function handleAdminAddingState($text, $user, $chatId)
    {
        // همیشه از دیتابیس refresh کنیم
        $user->refresh();

        $state = $user->state;
        $tempData = json_decode($user->temp_data, true) ?? [];

        error_log("🔍 Handle Admin State: {$state}");
        error_log("🔍 Temp Data: " . print_r($tempData, true));

        // اگر temp_data خالی هست، خطا بده
        if (empty($tempData)) {
            $this->telegram->sendMessage($chatId, "❌ داده‌های فیلد گم شده! لطفاً از /admin شروع کنید.");
            $user->update([
                'state' => 'main_menu',
                'temp_data' => null
            ]);
            return;
        }

        switch ($state) {
            case 'admin_adding_field':
                $this->adminAddFieldStep2($user, $chatId, $text, $tempData);
                break;

            case 'admin_adding_field_step2':
                $this->adminAddFieldStep3($user, $chatId, $text, $tempData);
                break;

            case 'admin_adding_field_step3':
                // این برای فیلدهای select استفاده می‌شه
                $this->adminAddFieldStep4($user, $chatId, $text, $tempData);
                break;
        }
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
        // چک کردن تکمیل بودن پروفایل
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

        error_log("🎯 درخواست پیشنهاد برای کاربر: {$user->id} - {$user->first_name}");

        // دریافت فیلترهای کاربر
        $userFilters = UserFilter::getFilters($user->id);
        error_log("📋 فیلترهای کاربر: " . json_encode($userFilters));

        // پیدا کردن پیشنهاد
        $suggestedUser = $this->findSuggestionWithFilters($user, $userFilters);

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

        // نمایش پیشنهاد به کاربر
        $this->showSuggestion($user, $chatId, $suggestedUser);
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
        } else {
            error_log("🔍 استفاده از منطق پیشفرض (بدون فیلتر فعال)");
            $suitableUsers = $this->findSuggestionWithDefaultLogic($user, true);
        }

        error_log("🔍 مجموع کاربران مناسب: " . count($suitableUsers));

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
        error_log("🎯 **شروع findSuitableUsersWithFilters** - کاربر: {$user->id}");
        error_log("📋 فیلترهای ورودی: " . json_encode($filters));


        $pdo = $this->getPDO();
        $conditions = [];
        $params = [];

        error_log("🎯 **اجرای منطق AND بین فیلترها**");

        // 🔴 فیلتر جنسیت - بهبود یافته و تضمینی
        if (isset($filters['gender']) && !empty($filters['gender']) && $filters['gender'] !== '') {
            $genderFilter = trim($filters['gender']);
            error_log("🔵 پردازش فیلتر جنسیت: '{$genderFilter}'");

            if ($genderFilter === 'زن') {
                $genderValues = ['زن', 'female', '2', 'F', 'خانم'];
                $placeholders = implode(',', array_fill(0, count($genderValues), '?'));
                $conditions[] = "gender IN ($placeholders)";
                $params = array_merge($params, $genderValues);
                error_log("✅ فیلتر جنسیت (زن) اعمال شد: " . implode(', ', $genderValues));
            } elseif ($genderFilter === 'مرد') {
                $genderValues = ['مرد', 'male', '1', 'M', 'آقا'];
                $placeholders = implode(',', array_fill(0, count($genderValues), '?'));
                $conditions[] = "gender IN ($placeholders)";
                $params = array_merge($params, $genderValues);
                error_log("✅ فیلتر جنسیت (مرد) اعمال شد: " . implode(', ', $genderValues));
            } else {
                error_log("⚠️ جنسیت نامعتبر: '{$genderFilter}'");
            }
        } else {
            error_log("⚪ فیلتر جنسیت: خالی یا تنظیم نشده");
        }

        // 🔴 فیلتر شهر (OR درون فیلتر) - بهبود یافته
        if (isset($filters['city']) && !empty($filters['city'])) {
            if (is_array($filters['city']) && !empty($filters['city'])) {
                $cityList = array_filter($filters['city']); // حذف مقادیر خالی
                if (!empty($cityList)) {
                    $placeholders = implode(',', array_fill(0, count($cityList), '?'));
                    $conditions[] = "city IN ($placeholders)";
                    $params = array_merge($params, $cityList);
                    error_log("✅ فیلتر شهر اعمال شد (چند شهری): " . implode(', ', $cityList));
                }
            } else if (!is_array($filters['city']) && $filters['city'] !== '') {
                $conditions[] = "city = ?";
                $params[] = $filters['city'];
                error_log("✅ فیلتر شهر اعمال شد (تک شهری): {$filters['city']}");
            }
        } else {
            error_log("⚪ فیلتر شهر: خالی یا تنظیم نشده");
        }

        // 🔴 فیلتر سن - بهبود یافته
        if (isset($filters['min_age']) && !empty($filters['min_age']) && is_numeric($filters['min_age'])) {
            $minAge = intval($filters['min_age']);
            if ($minAge > 0) {
                $conditions[] = "age >= ?";
                $params[] = $minAge;
                error_log("✅ فیلتر حداقل سن اعمال شد: {$minAge}");
            }
        }

        if (isset($filters['max_age']) && !empty($filters['max_age']) && is_numeric($filters['max_age'])) {
            $maxAge = intval($filters['max_age']);
            if ($maxAge > 0) {
                $conditions[] = "age <= ?";
                $params[] = $maxAge;
                error_log("✅ فیلتر حداکثر سن اعمال شد: {$maxAge}");
            }
        }

        // 🔴 ساخت شرط WHERE نهایی - با منطق AND
        $whereClause = "";
        if (!empty($conditions)) {
            $whereClause = "AND " . implode(" AND ", $conditions);
            error_log("🔵 شرط WHERE نهایی: {$whereClause}");
        } else {
            error_log("⚠️ هیچ شرط فیلتری اعمال نشد!");
        }

        if (empty($excludedUsers)) {
            $excludedUsers = [0];
        }

        $excludedStr = implode(',', $excludedUsers);

        // 🔴 کوئری نهایی با منطق AND بین فیلترها
        $sql = "SELECT * FROM users 
        WHERE id NOT IN ($excludedStr) 
        AND is_profile_completed = 1 
        {$whereClause}
        ORDER BY RAND()
        LIMIT 50";

        error_log("🔵 کوئری نهایی: " . $sql);
        error_log("🔵 پارامترها: " . json_encode($params));

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(\PDO::FETCH_CLASS, 'App\Models\User');

            error_log("✅ تعداد کاربران یافت شده: " . count($results));

            // 🔴 دیباگ دقیق نتایج
            if (!empty($results)) {
                error_log("👥 **نتایج فیلتر شده:**");
                foreach ($results as $index => $resultUser) {
                    $genderDisplay = $this->convertGenderForDisplay($resultUser->gender);
                    error_log("   {$index}. {$resultUser->first_name} - جنسیت:{$resultUser->gender} ({$genderDisplay}) - شهر:{$resultUser->city} - سن:{$resultUser->age}");

                    // 🔴 بررسی تطابق با فیلترها
                    $genderMatch = true;
                    $cityMatch = true;

                    // بررسی تطابق جنسیت
                    if (isset($filters['gender']) && !empty($filters['gender'])) {
                        $expectedGenders = $filters['gender'] === 'زن' ?
                            ['زن', 'female', '2', 'F', 'خانم'] :
                            ['مرد', 'male', '1', 'M', 'آقا'];
                        $genderMatch = in_array($resultUser->gender, $expectedGenders);
                    }

                    // بررسی تطابق شهر
                    if (isset($filters['city']) && !empty($filters['city'])) {
                        $cities = is_array($filters['city']) ? $filters['city'] : [$filters['city']];
                        $cityMatch = in_array($resultUser->city, $cities);
                    }

                    if (!$genderMatch || !$cityMatch) {
                        error_log("   ⚠️ هشدار: کاربر {$resultUser->first_name} با فیلترها مطابقت ندارد!");
                        error_log("      جنسیت مطابق: " . ($genderMatch ? "بله" : "خیر"));
                        error_log("      شهر مطابق: " . ($cityMatch ? "بله" : "خیر"));
                    }
                }
            }

            PerformanceMonitor::start('filtered_search');
            return $results;

        } catch (\Exception $e) {
            error_log("❌ خطا در اجرای کوئری: " . $e->getMessage());
            error_log("❌ کوئری مشکل‌دار: " . $sql);
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
        error_log("🔵 استفاده از منطق پیشفرض برای کاربر: {$user->id}");

        // کاربرانی که قبلاً نمایش داده شده‌اند
        $excludedUsers = \App\Models\UserSuggestion::getAlreadyShownUsers($user->id);
        $excludedUsers[] = $user->id;

        // اگر کاربر جنسیت خودش را تنظیم نکرده، همه کاربران کامل را نمایش بده
        if (empty($user->gender)) {
            error_log("🔵 کاربر جنسیت خود را تنظیم نکرده - نمایش همه کاربران کامل");
            $pdo = $this->getPDO();

            if (empty($excludedUsers)) {
                $excludedUsers = [0];
            }

            $excludedStr = implode(',', $excludedUsers);

            $sql = "SELECT * FROM users 
                WHERE id NOT IN ($excludedStr) 
                AND is_profile_completed = 1 
                ORDER BY RAND() 
                LIMIT 50";

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute();
                $results = $stmt->fetchAll(\PDO::FETCH_CLASS, 'App\Models\User');

                error_log("🔵 تعداد کاربران یافت شده (بدون فیلتر جنسیت): " . count($results));

                if ($returnArray) {
                    return $results;
                }

                if (empty($results)) {
                    return null;
                }

                $randomIndex = array_rand($results);
                $suggestedUser = $results[$randomIndex];
                \App\Models\UserSuggestion::create($user->id, $suggestedUser->id);

                return $suggestedUser;

            } catch (\Exception $e) {
                error_log("❌ خطا در منطق پیشفرض بدون جنسیت: " . $e->getMessage());
                return $returnArray ? [] : null;
            }
        }

        // پیدا کردن کاربران با جنسیت مخالف و پروفایل کامل
        $oppositeGender = $this->getOppositeGender($user->gender);

        error_log("🔵 جنسیت کاربر: {$user->gender} -> جنسیت مخالف: {$oppositeGender}");

        $pdo = $this->getPDO();

        if (empty($excludedUsers)) {
            $excludedUsers = [0];
        }

        $excludedStr = implode(',', $excludedUsers);

        // 🔴 کوئری بهبود یافته برای تطابق بهتر جنسیت‌ها
        $sql = "SELECT * FROM users 
            WHERE id NOT IN ($excludedStr) 
            AND is_profile_completed = 1 
            AND (
                gender = ? OR 
                gender = ? OR 
                gender = ? OR
                gender LIKE ? OR
                gender LIKE ?
            )
            ORDER BY RAND() 
            LIMIT 50";

        // ایجاد لیست گسترده‌تری از مقادیر ممکن برای جنسیت مخالف
        $genderValues = [
            $oppositeGender,
            $this->getOppositeGenderEnglish($oppositeGender),
            $this->getOppositeGenderNumeric($oppositeGender),
            "%{$oppositeGender}%",
            "%{$this->getOppositeGenderEnglish($oppositeGender)}%"
        ];

        // حذف مقادیر تکراری و خالی
        $genderValues = array_unique(array_filter($genderValues));

        error_log("🔵 جستجوی جنسیت مخالف با مقادیر: " . implode(', ', $genderValues));

        try {
            $stmt = $pdo->prepare($sql);

            // اگر تعداد پارامترها کمتر از 5 شد، با اولین مقدار تکمیل کن
            while (count($genderValues) < 5) {
                $genderValues[] = $genderValues[0] ?? $oppositeGender;
            }

            $stmt->execute($genderValues);
            $results = $stmt->fetchAll(\PDO::FETCH_OBJ);

            error_log("🔵 تعداد کاربران یافت شده با منطق پیشفرض: " . count($results));

            if ($returnArray) {
                PerformanceMonitor::start('find_suggestion_default');
                return $results;
            }

            if (empty($results)) {
                error_log("❌ هیچ کاربری با منطق پیشفرض یافت نشد");
                return null;
            }

            // انتخاب تصادفی یک کاربر
            $randomIndex = array_rand($results);
            $suggestedUser = $results[$randomIndex];

            // ثبت در تاریخچه
            \App\Models\UserSuggestion::create($user->id, $suggestedUser->id);

            error_log("✅ کاربر انتخاب شده با منطق پیشفرض: {$suggestedUser->id} - {$suggestedUser->first_name}");

            return $suggestedUser;

        } catch (\Exception $e) {
            error_log("❌ خطا در منطق پیشفرض: " . $e->getMessage());
            return $returnArray ? [] : null;
        }
    }

    private function hasActiveFilters($userFilters)
    {
        if (empty($userFilters)) {
            return false;
        }

        // 🔴 بررسی دقیق‌تر فیلترها - بهبود یافته
        foreach ($userFilters as $field => $value) {
            if ($field === 'city') {
                if (is_array($value) && !empty($value)) {
                    $nonEmptyCities = array_filter($value);
                    if (!empty($nonEmptyCities)) {
                        return true;
                    }
                } elseif (!is_array($value) && !empty($value) && $value !== '') {
                    return true;
                }
            } else {
                // برای سایر فیلترها (جنسیت، سن)
                if (!empty($value) && $value !== '' && $value !== null) {
                    return true;
                }
            }
        }

        return false;
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
        $cost = $this->getContactRequestCost();


        $message = "📋 **مشخصات:**\n\n";

        // نمایش فیلدهای عمومی پروفایل
        $activeFields = ProfileField::getActiveFields();
        $displayedFieldsCount = 0;

        foreach ($activeFields as $field) {
            // چک کردن وضعیت نمایش فیلد
            if ($this->shouldDisplayField($user, $field)) {
                $value = $suggestedUser->{$field->field_name} ?? 'تعیین نشده';

                // 🔴 اصلاح: تبدیل جنسیت به فارسی برای نمایش
                if ($field->field_name === 'gender') {
                    $value = $this->convertGenderForDisplay($value);
                } elseif ($field->field_type === 'select' && is_numeric($value)) {
                    $value = $this->convertSelectValueToText($field, $value);
                }

                $message .= "✅ {$field->field_label} : {$value}\n";
                $displayedFieldsCount++;
            }
        }

        // اگر هیچ فیلدی نمایش داده نشد
        if ($displayedFieldsCount === 0) {
            $message .= "👀 اطلاعات بیشتری برای نمایش موجود نیست.\n";
            $message .= "💼 برای مشاهده اطلاعات کامل، اشتراک تهیه کنید.\n";
        }

        $shownCount = \App\Models\UserSuggestion::getShownCount($user->id, $suggestedUser->id);
        $message .= "\n⭐ این فرد {$shownCount} بار برای شما نمایش داده شده است.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📞 درخواست اطلاعات تماس', 'callback_data' => "request_contact:{$suggestedUser->id}"],
                    ['text' => '💌 پیشنهاد بعدی', 'callback_data' => 'get_suggestion']
                ],
                [
                    ['text' => '⚙️ تنظیم فیلترها', 'callback_data' => 'edit_filters'],
                    ['text' => '🔙 منوی اصلی', 'callback_data' => 'main_menu']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
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
        return \App\Models\Subscription::hasActiveSubscription($user->id);
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
    $cost = $this->getContactRequestCost();
    $wallet = $user->getWallet();
    
    // 🔴 چک کردن موجودی کیف پول
    if (!$wallet->hasEnoughBalance($cost)) {
        $message = "📞 **درخواست اطلاعات تماس**\n\n";
        $message .= "❌ موجودی کیف پول شما کافی نیست!\n";
        $message .= "💰 هزینه هر درخواست: " . number_format($cost) . " تومان\n";
        $message .= "💳 موجودی فعلی: " . number_format($wallet->balance) . " تومان\n\n";
        $message .= "لطفاً ابتدا کیف پول خود را شارژ کنید.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💰 شارژ کیف پول', 'callback_data' => 'wallet_charge'],
                    ['text' => '🔙 بازگشت', 'callback_data' => 'main_menu']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
        return;
    }
    
    // اگر موجودی کافی هست، ادامه بده
    $suggestedUser = User::find($suggestedUserId);
    
    if (!$suggestedUser) {
        $this->telegram->sendMessage($chatId, "❌ کاربر پیدا نشد");
        return;
    }

    // 🔴 کسر هزینه از کیف پول
    $deductionResult = $wallet->deduct($cost, "درخواست اطلاعات تماس - کاربر: {$suggestedUserId}");
    
    if (!$deductionResult) {
        $this->telegram->sendMessage($chatId, "❌ خطا در کسر مبلغ از کیف پول");
        return;
    }

    $message = "📞 **اطلاعات تماس کاربر**\n\n";
    $message .= "👤 نام: {$suggestedUser->first_name}\n";
    
    // نمایش نام کاربری اگر وجود دارد
    if (!empty($suggestedUser->username)) {
        $message .= "📧 آیدی تلگرام: @{$suggestedUser->username}\n";
    }
    
    $message .= "🆔 شناسه کاربر: {$suggestedUser->telegram_id}\n\n";
    
    // نمایش تمام فیلدها (حتی مخفی) پس از پرداخت
    $activeFields = ProfileField::getActiveFields();
    foreach ($activeFields as $field) {
        $value = $suggestedUser->{$field->field_name} ?? 'تعیین نشده';
        
        if ($field->field_type === 'select' && is_numeric($value)) {
            $value = $this->convertSelectValueToText($field, $value);
        }
        
        $message .= "**{$field->field_label}**: {$value}\n";
    }
    
    $message .= "\n💰 مبلغ " . number_format($cost) . " تومان از کیف پول شما کسر شد.";
    $message .= "\n💳 موجودی جدید: " . number_format($wallet->balance) . " تومان";
    $message .= "\n\n✅ با تشکر از استفاده شما از سرویس ما";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => '💌 پیشنهاد بعدی', 'callback_data' => 'get_suggestion'],
                ['text' => '🔙 منوی اصلی', 'callback_data' => 'main_menu']
            ]
        ]
    ];

    $this->telegram->sendMessage($chatId, $message, $keyboard);
    
    // علامت‌گذاری درخواست تماس در تاریخچه
    \App\Models\UserSuggestion::markContactRequested($user->id, $suggestedUserId);
}

    // 🔴 متد جدید برای پردازش پرداخت پس از تأیید
    private function processContactPayment($user, $chatId, $suggestedUserId)
    {
        $cost = $this->getContactRequestCost();
        $wallet = $user->getWallet();
        $suggestedUser = User::find($suggestedUserId);

        if (!$suggestedUser) {
            $this->telegram->sendMessage($chatId, "❌ کاربر پیدا نشد");
            return;
        }

        // کسر هزینه از کیف پول با نوع تراکنش "purchase"
        $deductionResult = $wallet->deduct($cost, "خرید اطلاعات تماس - کاربر: {$suggestedUser->first_name}", "purchase"); // 🔴 تغییر نوع به purchase

        if (!$deductionResult) {
            $this->telegram->sendMessage($chatId, "❌ خطا در کسر مبلغ از کیف پول. لطفاً دوباره تلاش کنید.");
            return;
        }

        // اضافه کردن به تاریخچه
        ContactRequestHistory::addToHistory($user->id, $suggestedUserId, $cost);

        // نمایش اطلاعات تماس
        $this->showContactInfo($user, $chatId, $suggestedUserId, $cost);

        // علامت‌گذاری درخواست تماس در تاریخچه
        \App\Models\UserSuggestion::markContactRequested($user->id, $suggestedUserId);
    }

    // 🔴 متد جدید برای نمایش اطلاعات تماس
    private function showContactInfo($user, $chatId, $suggestedUserId, $amountPaid)
    {
        $suggestedUser = User::find($suggestedUserId);

        if (!$suggestedUser) {
            $this->telegram->sendMessage($chatId, "❌ کاربر پیدا نشد");
            return;
        }

        $message = "📞 **اطلاعات تماس کاربر**\n\n";

        $message .= "👤 نام: {$suggestedUser->first_name}\n";

        // نمایش نام کاربری اگر وجود دارد
        if (!empty($suggestedUser->username)) {
            $message .= "📧 آیدی تلگرام: @{$suggestedUser->username}\n";
        }

        $message .= "🆔 شناسه کاربر: {$suggestedUser->telegram_id}\n\n";

        // نمایش تمام فیلدها (حتی مخفی) پس از پرداخت
        $activeFields = ProfileField::getActiveFields();
        foreach ($activeFields as $field) {
            $value = $suggestedUser->{$field->field_name} ?? 'تعیین نشده';

            // 🔴 اصلاح: تبدیل جنسیت به فارسی برای نمایش
            if ($field->field_name === 'gender') {
                $value = $this->convertGenderForDisplay($value);
            }
            // اگر فیلد از نوع select هست و مقدار عددی داره، به متن تبدیل کن 
            elseif ($field->field_type === 'select' && is_numeric($value)) {
                $value = $this->convertSelectValueToText($field, $value);
            }

            $message .= "✅ {$field->field_label} : {$value}\n";
        }

        if ($amountPaid > 0) {
            $message .= "\n✅ **پرداخت موفق**\n";
            $message .= "💰 مبلغ " . number_format($amountPaid) . " تومان از کیف پول شما کسر شد.\n";
            $wallet = $user->getWallet();
            $message .= "💳 موجودی جدید: " . number_format($wallet->balance) . " تومان\n";
            $message .= "📝 این اطلاعات در بخش \"تاریخچه درخواست‌ها\" ذخیره شد.";
        } else {
            $message .= "\n✅ این اطلاعات قبلاً توسط شما خریداری شده است.";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '💌 پیشنهاد بعدی', 'callback_data' => 'get_suggestion'],
                    ['text' => '📜 تاریخچه درخواست‌ها', 'callback_data' => 'contact_history']
                ],
                [
                    ['text' => '🔙 منوی اصلی', 'callback_data' => 'main_menu']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }
    private function getContactRequestCost()
    {
        return 50000; // 50,000 تومان
    }

    private function showContactHistory($user, $chatId, $page = 1)
    {
        $pdo = $this->getPDO();

        // محاسبه صفحه‌بندی
        $perPage = 5;
        $offset = ($page - 1) * $perPage;

        // تعداد کل رکوردها
        $countSql = "SELECT COUNT(*) as total FROM contact_request_history WHERE user_id = ?";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute([$user->id]);
        $totalCount = $countStmt->fetch(\PDO::FETCH_OBJ)->total;
        $totalPages = ceil($totalCount / $perPage);

        // دریافت رکوردهای صفحه جاری - استفاده از bindValue
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

            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => '💌 دریافت پیشنهاد', 'callback_data' => 'get_suggestion'],
                        ['text' => '🔙 منوی اصلی', 'callback_data' => 'main_menu']
                    ]
                ]
            ];

            $this->telegram->sendMessage($chatId, $message, $keyboard);
            return;
        }

        $message = "📜 **تاریخچه درخواست‌های تماس شما**\n\n";
        $message .= "👥 تعداد کل: " . $totalCount . " نفر\n";
        $message .= "📄 صفحه: " . $page . " از " . $totalPages . "\n\n";

        foreach ($history as $index => $record) {
            $globalIndex = $offset + $index + 1;
            $requestDate = date('Y-m-d', strtotime($record->requested_at));

            $message .= "**" . $globalIndex . ". {$record->first_name}**\n";
            $message .= "📅 {$requestDate} | 💰 " . number_format($record->amount_paid) . " تومان\n";
            $message .= "────────────\n";
        }

        // ایجاد کیبورد دینامیک
        $keyboard = ['inline_keyboard' => []];

        // دکمه‌های مشاهده جزئیات برای هر کاربر
        foreach ($history as $record) {
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => "👤 مشاهده {$record->first_name}",
                    'callback_data' => "contact_history_view:{$record->requested_user_id}"
                ]
            ];
        }

        // دکمه‌های صفحه‌بندی
        $paginationButtons = [];
        if ($page > 1) {
            $paginationButtons[] = ['text' => '⏪ صفحه قبلی', 'callback_data' => "contact_history_page:" . ($page - 1)];
        }
        if ($page < $totalPages) {
            $paginationButtons[] = ['text' => 'صفحه بعدی ⏩', 'callback_data' => "contact_history_page:" . ($page + 1)];
        }

        if (!empty($paginationButtons)) {
            $keyboard['inline_keyboard'][] = $paginationButtons;
        }

        // دکمه‌های اصلی
        $keyboard['inline_keyboard'][] = [
            ['text' => '💌 پیشنهاد جدید', 'callback_data' => 'get_suggestion'],
            ['text' => '🔙 منوی اصلی', 'callback_data' => 'main_menu']
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
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

        $message = "👤 **مشخصات کامل کاربر**\n\n";
        $message .= "**{$requestedUser->first_name}**\n";

        if (!empty($requestedUser->username)) {
            $message .= "📧 آیدی: @{$requestedUser->username}\n";
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

                // 🔴 اصلاح: تبدیل جنسیت به فارسی برای نمایش
                if ($field->field_name === 'gender') {
                    $value = $this->convertGenderForDisplay($value);
                }
                // اگر فیلد از نوع select هست و مقدار عددی داره، به متن تبدیل کن 
                elseif ($field->field_type === 'select' && is_numeric($value)) {
                    $value = $this->convertSelectValueToText($field, $value);
                }

                $message .= "• ✅ {$field->field_label} : {$value}\n";
                $displayedCount++;
            }
        }

        if ($displayedCount === 0) {
            $message .= "📝 اطلاعات پروفایل تکمیل نشده است.\n";
        }

        $message .= "\n💡 این اطلاعات قبلاً توسط شما خریداری شده و اکنون رایگان در دسترس شماست.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📜 بازگشت به تاریخچه', 'callback_data' => 'contact_history'],
                    ['text' => '💌 پیشنهاد جدید', 'callback_data' => 'get_suggestion']
                ],
                [
                    ['text' => '🔙 منوی اصلی', 'callback_data' => 'main_menu']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }
    private function showConfirmationMessage($user, $chatId, $suggestedUser, $cost)
    {
        $message = "⚠️ **تأیید درخواست اطلاعات تماس**\n\n";
        $message .= "👤 **{$suggestedUser->first_name}**\n";
        $message .= "💰 مبلغ قابل کسر: **" . number_format($cost) . " تومان**\n";
        $message .= "💳 موجودی فعلی شما: **" . number_format($user->getWallet()->balance) . " تومان**\n\n";
        $message .= "✅ پس از پرداخت، اطلاعات تماس این کاربر در اختیار شما قرار می‌گیرد و در بخش \"تاریخچه درخواست‌ها\" ذخیره می‌شود.\n\n";
        $message .= "آیا مایل به پرداخت هستید؟";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ بله، پرداخت کن', 'callback_data' => "confirm_contact_request:{$suggestedUser->id}"],
                    ['text' => '❌ خیر، انصراف', 'callback_data' => 'cancel_contact_request']
                ]
            ]
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
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
        $plans = \App\Models\SubscriptionPlan::getActivePlans();

        if ($plans->isEmpty()) {
            $this->telegram->sendMessage($chatId, "❌ در حال حاضر هیچ پلن اشتراکی فعال نیست.");
            return;
        }

        $message = "💰 **خرید اشتراک و شارژ کیف پول**\n\n";
        $message .= "لطفاً یکی از پلن‌های زیر را انتخاب کنید:\n\n";

        foreach ($plans as $plan) {
            $message .= "📦 **{$plan->name}**\n";
            $message .= "⏰ مدت: {$plan->duration_days} روز\n";
            $message .= "💵 مبلغ: " . number_format($plan->amount) . " تومان\n";
            $message .= "📝 {$plan->description}\n\n";
        }

        $keyboard = ['inline_keyboard' => []];

        foreach ($plans as $plan) {
            $keyboard['inline_keyboard'][] = [
                [
                    'text' => "📦 {$plan->name} - " . number_format($plan->amount) . " تومان",
                    'callback_data' => "select_plan:{$plan->id}"
                ]
            ];
        }

        $keyboard['inline_keyboard'][] = [
            ['text' => '🔙 بازگشت به کیف پول', 'callback_data' => 'back_to_wallet']
        ];

        $this->telegram->sendMessage($chatId, $message, $keyboard);
    }

    private function handlePlanSelection($user, $chatId, $planId)
    {
        $plan = \App\Models\SubscriptionPlan::getPlan($planId);

        if (!$plan) {
            $this->telegram->sendMessage($chatId, "❌ پلن انتخابی یافت نشد.");
            return;
        }

        $cardNumber = \App\Models\SystemSetting::getValue('card_number', 'شماره کارت تنظیم نشده');

        $message = "💳 **پرداخت برای {$plan->name}**\n\n";
        $message .= "📦 پلن: {$plan->name}\n";
        $message .= "⏰ مدت: {$plan->duration_days} روز\n";
        $message .= "💵 مبلغ: **" . number_format($plan->amount) . " تومان**\n\n";
        $message .= "💳 **شماره کارت برای واریز:**\n";
        $message .= "`{$cardNumber}`\n\n";
        $message .= "📝 **راهنمای پرداخت:**\n";
        $message .= "1. مبلغ فوق را به شماره کارت بالا واریز کنید\n";
        $message .= "2. سپس روی دکمه 'تأیید پرداخت' کلیک کنید\n";
        $message .= "3. درخواست شما برای مدیر ارسال می‌شود\n";
        $message .= "4. پس از تأیید مدیر، کیف پول شما شارژ می‌شود\n\n";
        $message .= "⚠️ لطفاً پس از واریز، حتماً روی تأیید پرداخت کلیک کنید.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تأیید پرداخت', 'callback_data' => "confirm_payment:{$plan->id}"],
                    ['text' => '❌ انصراف', 'callback_data' => 'wallet_charge']
                ]
            ]
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
        $userMessage .= "⏰ زمان تأیید: " . date('Y-m-d H:i') . "\n\n";
        $userMessage .= "با تشکر از پرداخت شما! 💝";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu'],
                    ['text' => '💼 کیف پول', 'callback_data' => 'wallet']
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
    private function getAdminsTelegramIds()
    {
        try {
            $pdo = $this->getPDO();
            $stmt = $pdo->prepare("SELECT telegram_id FROM administrators");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            error_log("❌ خطا در دریافت آیدی ادمین‌ها: " . $e->getMessage());
            return [];
        }
    }

    private function isAdmin($telegramId)
    {
        try {
            $pdo = $this->getPDO();
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM administrators WHERE telegram_id = ?");
            $stmt->execute([$telegramId]);
            $result = $stmt->fetch(PDO::FETCH_OBJ);
            return $result->count > 0;
        } catch (\Exception $e) {
            error_log("❌ خطا در بررسی ادمین: " . $e->getMessage());
            return false;
        }
    }

    // متد جایگزین برای notifyAdminsAboutPayment
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
                    // محاسبه پاداش (10% از مبلغ خرید)
                    $bonusAmount = $purchaseAmount * 0.1;

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
        $shareText .= "من از این ربات دوستیابی عالی استفاده می‌کنم و پیشنهاد می‌کنم تو هم عضو بشی! 🤝\n\n";
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


    /**
     * 🔴 تبدیل stdClass به User object
     */
    private function convertToUserObject($stdClassUser)
    {
        if ($stdClassUser instanceof \App\Models\User) {
            return $stdClassUser; // قبلاً تبدیل شده
        }

        $user = new \App\Models\User();
        foreach ($stdClassUser as $key => $value) {
            $user->$key = $value;
        }
        return $user;
    }
    // در کلاس BotCore
    public function handlePhotoMessage($user, $message)
{
    echo "🖼️ handlePhotoMessage called - User State: {$user->state}\n";

    if (isset($message['photo'])) {
        echo "📸 Photo array structure:\n";
        foreach ($message['photo'] as $index => $photoSize) {
            echo "  [$index] file_id: " . ($photoSize['file_id'] ?? 'NOT FOUND') . "\n";
        }
        
        $photo = end($message['photo']); // بزرگترین سایز
        $botToken = $this->getBotToken();

        echo "🎯 Selected largest photo - file_id: " . ($photo['file_id'] ?? 'NOT FOUND') . "\n";
        echo "🔑 Bot Token: " . (!empty($botToken) ? substr($botToken, 0, 10) . "..." : "MISSING") . "\n";

        $profileManager = new ProfileFieldManager();
        echo "🔧 ProfileFieldManager instantiated\n";

        // تشخیص state کاربر
        $isMain = ($user->state == 'uploading_main_photo');
        echo "🎯 Upload type: " . ($isMain ? "Main Photo" : "Additional Photo") . "\n";

        try {
            echo "🔄 Calling handlePhotoUpload...\n";
            $uploadResult = $profileManager->handlePhotoUpload($user, $photo, $botToken, $isMain);
            echo "📊 Upload result: " . ($uploadResult ? "SUCCESS" : "FAILED") . "\n";

            if ($uploadResult) {
                echo "✅ Sending success message\n";
                $this->sendMessage($user->telegram_id, "✅ عکس با موفقیت آپلود شد!");

                if ($isMain) {
                    echo "🔄 Showing profile menu\n";
                    $this->showProfileMenu($user, $user->telegram_id);
                } else {
                    echo "🔄 Asking for more photos\n";
                    $this->askForMorePhotos($user);
                }
            } else {
                echo "❌ Sending error message\n";
                $this->sendMessage($user->telegram_id, "❌ خطا در آپلود عکس. لطفاً مجدداً تلاش کنید.");
            }
            return true;
        } catch (Exception $e) {
            echo "🔴 Exception in handlePhotoMessage: " . $e->getMessage() . "\n";
            $this->sendMessage($user->telegram_id, "❌ خطا در پردازش عکس: " . $e->getMessage());
            return false;
        }
    }
    
    echo "❌ No photo found in message\n";
    return false;
}
    private function getBotToken()
    {
        return $_ENV['TELEGRAM_BOT_TOKEN'] ?? '8309595970:AAGaX8wstn-Fby_IzF5cU_a1CxGCPfCEQNk';
    }


    private function askForMorePhotos($user)
{
    echo "🔄 askForMorePhotos called\n";
    
    $message = "✅ عکس با موفقیت آپلود شد!\n\n";
    $message .= "آیا می‌خواهید عکس دیگری آپلود کنید؟\n\n";
    $message .= "می‌توانید عکس‌های بیشتری آپلود کنید یا یک عکس را به عنوان اصلی انتخاب کنید.";
    
    // ایجاد دکمه‌های شیشه‌ای (Inline Keyboard)
    $inlineKeyboard = [
        [
            ['text' => '📷 آپلود عکس دیگر', 'callback_data' => 'upload_more_photos']
        ],
        [
            ['text' => '⭐ انتخاب عکس اصلی', 'callback_data' => 'select_main_photo_menu'],
            ['text' => '👀 مشاهده عکس‌ها', 'callback_data' => 'view_all_photos']
        ],
        [
            ['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => 'back_to_main_from_photos']
        ]
    ];
    
    $replyMarkup = [
        'inline_keyboard' => $inlineKeyboard
    ];
    
    $this->sendMessage($user->telegram_id, $message, null, $replyMarkup);
    $this->updateUserState($user->telegram_id, 'managing_photos');
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
                ['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => 'back_to_main']
            ]
        ];

        $replyMarkup = [
            'inline_keyboard' => $inlineKeyboard
        ];

        $this->sendMessage($targetChatId, $message, null, $replyMarkup);
    }

    private function showPhotoManagementMenu($user, $chatId)
{
    try {
        $pdo = $this->getPDO();
        $sql = "SELECT profile_photo, profile_photos FROM users WHERE telegram_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user->telegram_id]);
        $userData = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        $mainPhoto = $userData['profile_photo'] ?? null;
        
        // 🔥 اصلاح: اطمینان از اینکه allPhotos همیشه آرایه است
        $allPhotos = [];
        if (!empty($userData['profile_photos'])) {
            $decoded = json_decode($userData['profile_photos'], true);
            if (is_array($decoded)) {
                $allPhotos = $decoded;
            }
        }
        
        // 🔥 اصلاح: استفاده از count فقط روی آرایه
        $totalPhotos = count($allPhotos) + ($mainPhoto ? 1 : 0);
        
        $message = "📷 مدیریت عکس‌های پروفایل\n\n";
        $message .= "عکس اصلی: " . ($mainPhoto ? "✅ تنظیم شده" : "❌ تنظیم نشده") . "\n";
        $message .= "تعداد عکس‌ها: " . $totalPhotos . "\n\n";
        $message .= "گزینه مورد نظر را انتخاب کنید:";
        
        $inlineKeyboard = [];
        
        if (empty($allPhotos) && !$mainPhoto) {
            // اگر هیچ عکسی ندارد
            $inlineKeyboard[] = [
                ['text' => '📤 آپلود اولین عکس', 'callback_data' => 'upload_first_photo']
            ];
        } else {
            // اگر حداقل یک عکس دارد
            $inlineKeyboard[] = [
                ['text' => '📤 آپلود عکس جدید', 'callback_data' => 'upload_new_photo']
            ];
            
            if (count($allPhotos) > 0) {
                $inlineKeyboard[] = [
                    ['text' => '⭐ انتخاب عکس اصلی', 'callback_data' => 'select_main_photo']
                ];
            }
            
            if ($mainPhoto || count($allPhotos) > 0) {
                $inlineKeyboard[] = [
                    ['text' => '👀 مشاهده عکس‌ها', 'callback_data' => 'view_photos']
                ];
            }
        }
        
        $inlineKeyboard[] = [
            ['text' => '↩️ بازگشت به منوی پروفایل', 'callback_data' => 'back_to_profile_menu']
        ];
        
        $replyMarkup = [
            'inline_keyboard' => $inlineKeyboard
        ];
        
        $this->sendMessage($chatId, $message, null, $replyMarkup);
        $this->updateUserState($user->telegram_id, 'photo_management');
        
    } catch (\Exception $e) {
        echo "❌ Error in showPhotoManagementMenu: " . $e->getMessage() . "\n";
        $this->sendMessage($chatId, "❌ خطا در بارگذاری منوی عکس‌ها.");
    }
}
    private function getPhotoUrl($photoFilename)
    {
        return "http://yourdomain.com/dating_bot/storage/profile_photos/" . $photoFilename;
    }


    /**
     * مدیریت state آپلود عکس
     */
    private function handlePhotoUploadState($user, $text)
    {
        // اگر کاربر متن ارسال کرد (نه عکس)
        if ($text && !isset($message['photo'])) {
            $this->sendMessage($user->telegram_id, "لطفاً یک عکس ارسال کنید. اگر می‌خواهید لغو کنید، از منوی زیر استفاده کنید.");

            $keyboard = [
                ['❌ لغو آپلود عکس']
            ];
            $this->sendMessage($user->telegram_id, "یا از گزینه زیر برای لغو استفاده کنید:", $keyboard);
            return true;
        }

        // اگر کاربر گزینه لغو را زد
        if ($text === '❌ لغو آپلود عکس') {
            $this->sendMessage($user->telegram_id, "آپلود عکس لغو شد.");
            $this->showPhotoManagementMenu($user);
            return true;
        }

        return false;
    }
    /**
     * نمایش انتخاب عکس اصلی
     */
    private function showMainPhotoSelection($user)
    {
        $pdo = $this->getPDO();
        $sql = "SELECT profile_photo, profile_photos FROM users WHERE telegram_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user->telegram_id]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        $allPhotos = $userData['profile_photos'] ? json_decode($userData['profile_photos'], true) : [];

        if (empty($allPhotos)) {
            $this->sendMessage($user->telegram_id, "❌ هیچ عکس اضافی برای انتخاب وجود ندارد. لطفاً ابتدا عکس‌هایی آپلود کنید.");
            $this->showPhotoManagementMenu($user);
            return;
        }

        // ایجاد کیبورد برای انتخاب عکس اصلی
        $keyboard = [];
        foreach ($allPhotos as $index => $photo) {
            $keyboard[] = ["عکس " . ($index + 1)];
        }
        $keyboard[] = ['↩️ بازگشت'];

        $this->sendMessage(
            $user->telegram_id,
            "لطفاً عکس مورد نظر را به عنوان عکس اصلی انتخاب کنید:\n\n" .
            "با انتخاب هر عکس، آن به عنوان تصویر اصلی پروفایل شما تنظیم خواهد شد.",
            $keyboard
        );

        $this->updateUserState($user->telegram_id, 'selecting_main_photo');
    }
    /**
     * مدیریت انتخاب عکس اصلی
     */
    private function handleMainPhotoSelection($user, $text)
    {
        if ($text === '↩️ بازگشت') {
            $this->showPhotoManagementMenu($user);
            return true;
        }

        // تشخیص اینکه کاربر کدام عکس را انتخاب کرده
        if (preg_match('/عکس (\d+)/', $text, $matches)) {
            $photoIndex = intval($matches[1]) - 1;

            $pdo = $this->getPDO();
            $sql = "SELECT profile_photos FROM users WHERE telegram_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user->telegram_id]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);

            $allPhotos = $userData['profile_photos'] ? json_decode($userData['profile_photos'], true) : [];

            if (isset($allPhotos[$photoIndex])) {
                // تنظیم عکس انتخاب شده به عنوان عکس اصلی
                $selectedPhoto = $allPhotos[$photoIndex];

                // حذف عکس انتخاب شده از لیست عکس‌های اضافی
                unset($allPhotos[$photoIndex]);
                $allPhotos = array_values($allPhotos); // بازنشانی ایندکس‌ها

                // آپدیت دیتابیس
                $sql = "UPDATE users SET profile_photo = ?, profile_photos = ? WHERE telegram_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$selectedPhoto, json_encode($allPhotos), $user->telegram_id]);

                $this->sendMessage($user->telegram_id, "✅ عکس مورد نظر با موفقیت به عنوان عکس اصلی پروفایل تنظیم شد!");
                $this->showPhotoManagementMenu($user);
            } else {
                $this->sendMessage($user->telegram_id, "❌ عکس انتخاب شده معتبر نیست.");
                $this->showMainPhotoSelection($user);
            }
            return true;
        }

        $this->sendMessage($user->telegram_id, "لطفاً یکی از عکس‌ها را از منوی زیر انتخاب کنید:");
        $this->showMainPhotoSelection($user);
        return true;
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
                $this->showPhotoManagementMenu($user, $chatId);
                break;
        }

        return true;
    }
   private function processMessage($message)
{
    $chatId = $message['chat']['id'];
    $user = $this->findOrCreateUser($message['from'], $chatId);
    
    echo "📨 Process Message - Chat: $chatId, User State: {$user->state}\n";
    echo "🔍 Message structure: " . json_encode(array_keys($message)) . "\n";
    
    // دیباگ کامل برای عکس
    if (isset($message['photo'])) {
        echo "🎯 PHOTO DIRECTLY FOUND in message['photo']\n";
        echo "📸 Photo array count: " . count($message['photo']) . "\n";
        return $this->handlePhotoMessage($user, $message);
    }
    
    // بررسی ساختارهای مختلف تلگرام
    if (isset($message['message']['photo'])) {
        echo "🎯 PHOTO FOUND in message['message']['photo']\n";
        return $this->handlePhotoMessage($user, $message['message']);
    }
    
    // اگر update از نوع message است
    if (isset($message['message']) && isset($message['message']['photo'])) {
        echo "🎯 PHOTO FOUND in update->message->photo\n";
        return $this->handlePhotoMessage($user, $message['message']);
    }
    
    echo "❌ NO PHOTO detected in any structure\n";
    
    $text = $message['text'] ?? ($message['message']['text'] ?? '');
    
    // بقیه پردازش برای متن
    if (!empty($text)) {
        if (isset($user->state)) {
            return $this->handleProfileState($text, $user, $chatId, $message);
        }
        return $this->handleTextCommand($text, $user, $chatId);
    }
    
    return false;
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
}