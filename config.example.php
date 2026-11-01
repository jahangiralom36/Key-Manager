<?php
// ====================================================================
// CONFIG.PHP — CENTRAL CONFIGURATION & EXTERNAL PROVIDER ENGINE
// ====================================================================
declare(strict_types=1);

define('SITE_URL', 'https://keymanager.shop/');
define('SITE_NAME', 'Reseller Panel');

// Database credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'CHANGE_ME');
define('DB_USER', 'CHANGE_ME');
define('DB_PASS', 'CHANGE_ME');

// ====================================================================
// EXACT TELEGRAM BOT TOKEN (CASE-SENSITIVE VERIFIED)
// ====================================================================
define('BOT_TOKEN', 'CHANGE_ME');
define('TG_BOT_USERNAME', 'keymanagerverifyBoT');
define('TG_API', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

define('ADMIN_USERNAME', 'adminrkb');
define('ADMIN_PASSWORD', 'CHANGE_ME');
define('ADMIN_TELEGRAM_ID', 7897443102);

// EXTERNAL LICENSE PROVIDER API
define('PROVIDER_API_BASE', 'https://licensemanager.shop/api/v1');
define('PROVIDER_API_URL', 'https://licensemanager.shop/api/v1/generate_key.php');
define('PROVIDER_API_TOKEN', 'CHANGE_ME');
define('LM_API_BASE', PROVIDER_API_BASE);
define('LM_API_TOKEN', PROVIDER_API_TOKEN);

// ANGRY MOD (PINXT) DIRECT RESET API
define('ANGRYMOD_RESET_URL', 'https://angrymod.com/Pinxt/reset.php');
define('ANGRYMOD_PINXT_TOKEN', 'CHANGE_ME');

// OPTIONAL: Telegram webhook secret (bot.php fake request block korbe).
// Set korle ekbar ei URL open korte hobe (browser e):
// https://api.telegram.org/bot<BOT_TOKEN>/setWebhook?url=https://keymanager.shop/bot.php&secret_token=<ei secret>
define('TG_WEBHOOK_SECRET', '');

// Database Connection (table gulo database.sql e ache, code e kono CREATE/ALTER nei)
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die("Database connection error. Please verify configuration.");
}

// Shared helpers + reset engine (GitHub e ache)
require_once __DIR__ . '/core.php';
