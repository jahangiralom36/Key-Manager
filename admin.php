<?php
/**
 * ====================================================================
 * MASTER CLOUD COMMAND CENTER — ENTERPRISE ADMIN PRO V5.0
 * ARCHITECTURE: CYBER FINTECH NOC / SECURE LICENSING CONSOLE
 * FEATURES: RESELLER CUSTOM PRICING, SUB-ACCOUNTS CONTROL, SNZPAY, NOTICES
 * ====================================================================
 */
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';

// NOTE: Table/column migration ekhane nei — sob database.sql e ache.

// --- UTILITY & AUTH HELPER FUNCTIONS ---

// Reset API <option> list (plan form gulor jonno)
function resetApiOptionsHtml(int $selected = 0): string {
    $html = '<option value="0">Auto-detect (by product name)</option>';
    foreach (lm_reset_apis() as $id => $info) {
        $sel = ($id === $selected) ? ' selected' : '';
        $html .= '<option value="' . $id . '"' . $sel . '>#' . $id . ' • ' . htmlspecialchars($info['name']) . '</option>';
    }
    return $html;
}

function isAuthorizedAdmin(PDO $pdo, $chatId): bool {
    if ((string)$chatId === (string)ADMIN_TELEGRAM_ID) return true;
    $stmt = $pdo->prepare("SELECT id FROM bot_admins WHERE telegram_id = ? LIMIT 1");
    $stmt->execute([$chatId]);
    return (bool)$stmt->fetch();
}

function detectNetworkAndGeo(string $ip): array {
    $info = [
        'city'    => 'Dhaka Area',
        'region'  => 'Bangladesh',
        'country' => 'Bangladesh',
        'isp'     => 'Broadband / ISP',
        'network' => 'WiFi / Broadband'
    ];
    
    if (in_array($ip, ['127.0.0.1', '::1', 'UNKNOWN', '']) || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        $info['city'] = 'Localhost Datacenter';
        $info['region'] = 'Intranet';
        $info['network'] = 'Localhost / WiFi';
        return $info;
    }
    
    try {
        $ch = curl_init("http://ip-api.com/json/{$ip}?fields=status,country,regionName,city,isp,org,as");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($res ?: '', true);
        if (isset($data['status']) && $data['status'] === 'success') {
            $info['city'] = $data['city'] ?? 'Dhaka';
            $info['region'] = $data['regionName'] ?? 'Bangladesh';
            $info['country'] = $data['country'] ?? 'Bangladesh';
            $ispFull = $data['isp'] ?? ($data['org'] ?? '');
            $info['isp'] = $ispFull;
            
            $lower = strtolower($ispFull . ' ' . ($data['as'] ?? ''));
            if (strpos($lower, 'grameenphone') !== false || strpos($lower, 'gp') !== false) {
                $info['network'] = 'Grameenphone (GP)';
            } elseif (strpos($lower, 'robi') !== false || strpos($lower, 'airtel') !== false || strpos($lower, 'axiata') !== false) {
                $info['network'] = 'Robi / Airtel';
            } elseif (strpos($lower, 'banglalink') !== false || strpos($lower, 'sheba') !== false) {
                $info['network'] = 'Banglalink (BL)';
            } elseif (strpos($lower, 'teletalk') !== false) {
                $info['network'] = 'Teletalk';
            } else {
                $info['network'] = 'Broadband / ' . ($data['isp'] ?? 'Optical Fiber');
            }
        }
    } catch (Exception $e) {}
    
    return $info;
}

function verifyTelegramWebAppData(string $initData, string $botToken): ?array {
    parse_str($initData, $data);
    if (empty($data['hash'])) return null;
    $hash = $data['hash'];
    unset($data['hash']);
    ksort($data);
    $dataCheckArr = [];
    foreach ($data as $k => $v) {
        $dataCheckArr[] = "$k=$v";
    }
    $dataCheckString = implode("\n", $dataCheckArr);
    $secretKey = hash_hmac('sha256', $botToken, "WebAppData", true);
    $calcHash = bin2hex(hash_hmac('sha256', $dataCheckString, $secretKey, true));
    if (hash_equals($calcHash, $hash) && !empty($data['user'])) {
        return json_decode($data['user'], true);
    }
    return null;
}

// --- TELEGRAM MINI APP AUTH WEBHOOK ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'tg_webapp_auth') {
    header('Content-Type: application/json');
    $initData = trim($_POST['init_data'] ?? '');
    $userData = verifyTelegramWebAppData($initData, BOT_TOKEN);

    if ($userData && !empty($userData['id'])) {
        $tgUid = $userData['id'];
        if (isAuthorizedAdmin($pdo, $tgUid)) {
            $_SESSION['admin_auth'] = true;
            $_SESSION['admin_tg_id'] = $tgUid;
            $_SESSION['admin_user'] = $userData['username'] ?? ($userData['first_name'] ?? 'Commander');
            
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
            $stmtCheck = $pdo->prepare("SELECT id FROM user_login_logs WHERE (telegram_id = ? OR ip_address = ?) AND login_time >= NOW() - INTERVAL 24 HOUR LIMIT 1");
            $stmtCheck->execute([$tgUid, $ip]);
            if (!$stmtCheck->fetchColumn()) {
                $geo = detectNetworkAndGeo($ip);
                $stmtLog = $pdo->prepare("INSERT INTO user_login_logs (username, telegram_id, telegram_username, telegram_name, ip_address, city, region, country, isp, network_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $tgFullName = trim(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? ''));
                $stmtLog->execute([
                    $_SESSION['admin_user'],
                    $tgUid,
                    $userData['username'] ?? '',
                    $tgFullName,
                    $ip,
                    $geo['city'],
                    $geo['region'],
                    $geo['country'],
                    $geo['isp'],
                    $geo['network']
                ]);
            }

            if (function_exists('logActivity')) {
                logActivity($pdo, null, 'ROOT_ADMIN', 'MINI_APP_LOGIN', "Root Admin verified via Mini App (UID: $tgUid)");
            }
            echo json_encode(['success' => true]);
            exit;
        } else {
            echo json_encode(['success' => false, 'error' => 'Access Denied: Telegram Account Unauthorized']);
            exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'Digital Signature Mismatch']);
    exit;
}

// --- AJAX ENDPOINTS (TELEMETRY, RESELLER DOSSIER & SUB-ACCOUNTS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];

    // 1. Provider Products Fetch
    if ($action === 'get_provider_products') {
        $token = defined('PROVIDER_API_TOKEN') ? PROVIDER_API_TOKEN : (defined('LM_API_TOKEN') ? LM_API_TOKEN : '');
        $url = 'https://licensemanager.shop/api/v1/products.php';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'X-API-Token: ' . $token,
                'Accept: application/json'
            ]
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        echo $response ?: json_encode(['products' => []]);
        exit;
    }

    // 2. Fetch Reseller's Sub-Accounts list
    if ($action === 'get_reseller_sub_accounts') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $stmtSubs = $pdo->prepare("
            SELECT u.*, 
            (SELECT COUNT(*) FROM licenses WHERE user_id = u.id) AS sub_keys_count,
            (SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE user_id = u.id AND status = 'approved') AS sub_deposit_total
            FROM users u 
            WHERE u.created_by = ? 
            ORDER BY u.id DESC
        ");
        $stmtSubs->execute([$userId]);
        $subs = $stmtSubs->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'sub_accounts' => $subs]);
        exit;
    }

    // 3. Fetch Reseller's Custom Pricing Matrix
    if ($action === 'get_reseller_custom_pricing') {
        $userId = (int)($_POST['user_id'] ?? 0);
        
        $dbPlans = $pdo->query("SELECT p.name as product_name, pl.id as plan_id, pl.plan_title, pl.reseller_price, pl.user_price
                                FROM product_plans pl 
                                JOIN products p ON pl.product_id = p.id 
                                WHERE p.is_active = 1 
                                ORDER BY p.name ASC, pl.id ASC")->fetchAll(PDO::FETCH_ASSOC);

        $stmtCustom = $pdo->prepare("SELECT plan_id, custom_price, custom_discount FROM user_plan_pricing WHERE user_id = ?");
        $stmtCustom->execute([$userId]);
        $customs = $stmtCustom->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

        $pricingList = [];
        foreach ($dbPlans as $pl) {
            $planId = (int)$pl['plan_id'];
            $cPrice = isset($customs[$planId]) ? (float)$customs[$planId]['custom_price'] : 0.00;
            $cDiscount = isset($customs[$planId]) ? (int)$customs[$planId]['custom_discount'] : 0;

            $pricingList[] = [
                'plan_id'         => $planId,
                'product_name'    => $pl['product_name'],
                'plan_title'      => $pl['plan_title'],
                'default_price'   => (float)$pl['reseller_price'],
                'custom_price'    => $cPrice,
                'custom_discount' => $cDiscount
            ];
        }

        echo json_encode(['success' => true, 'pricing' => $pricingList]);
        exit;
    }

    // 4. Drill-down: Today Sold Keys Detail
    if ($action === 'get_today_keys_detail') {
        $stmt = $pdo->prepare("
            SELECT l.*, u.username, u.telegram_username, u.telegram_chat_id 
            FROM licenses l 
            JOIN users u ON l.user_id = u.id 
            WHERE DATE(l.created_at) = CURDATE() 
            ORDER BY l.id DESC
        ");
        $stmt->execute();
        $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $keys]);
        exit;
    }

    // 5. Drill-down: Today Verified Deposits Detail
    if ($action === 'get_today_deposits_detail') {
        $stmt = $pdo->prepare("
            SELECT t.*, u.username, u.telegram_username 
            FROM transactions t 
            JOIN users u ON t.user_id = u.id 
            WHERE DATE(t.created_at) = CURDATE() 
            ORDER BY t.id DESC
        ");
        $stmt->execute();
        $deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $deposits]);
        exit;
    }

    // 6. Drill-down: Comprehensive Reseller History & Audit Dossier
    if ($action === 'get_reseller_dossier') {
        $userId = (int)($_POST['user_id'] ?? 0);
        
        $stmtU = $pdo->prepare("SELECT id, username, balance, status, role, api_enabled, custom_bdt_rate, telegram_chat_id, telegram_username, created_at FROM users WHERE id = ? LIMIT 1");
        $stmtU->execute([$userId]);
        $user = $stmtU->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            echo json_encode(['success' => false, 'error' => 'User not located']);
            exit;
        }

        $stmtK = $pdo->prepare("SELECT * FROM licenses WHERE user_id = ? ORDER BY id DESC LIMIT 100");
        $stmtK->execute([$userId]);
        $keys = $stmtK->fetchAll(PDO::FETCH_ASSOC);

        $stmtD = $pdo->prepare("SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT 100");
        $stmtD->execute([$userId]);
        $deposits = $stmtD->fetchAll(PDO::FETCH_ASSOC);

        $stmtA = $pdo->prepare("SELECT * FROM activity_logs WHERE user_id = ? OR details LIKE ? ORDER BY id DESC LIMIT 50");
        $stmtA->execute([$userId, "%" . $user['username'] . "%"]);
        $activities = $stmtA->fetchAll(PDO::FETCH_ASSOC);

        $totalSpent = 0.00;
        foreach ($keys as $k) {
            $totalSpent += (float)($k['price'] ?? 0.00);
        }

        $totalDeposited = 0.00;
        foreach ($deposits as $d) {
            if ($d['status'] === 'approved') {
                $totalDeposited += (float)$d['amount'];
            }
        }

        echo json_encode([
            'success' => true,
            'user' => $user,
            'stats' => [
                'total_keys_count' => count($keys),
                'total_spent_usd'  => number_format($totalSpent, 2),
                'total_deposited'  => number_format($totalDeposited, 2)
            ],
            'keys' => $keys,
            'deposits' => $deposits,
            'activities' => $activities
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown Action Request']);
    exit;
}

// --- LOGOUT ROUTE ---
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_auth'], $_SESSION['admin_tg_id'], $_SESSION['admin_user']);
    header('Location: admin.php');
    exit;
}

// --- AUTH WALL (404 CAMOUFLAGE + MINI APP HANDSHAKE) ---
if (empty($_SESSION['admin_auth']) || empty($_SESSION['admin_tg_id']) || !isAuthorizedAdmin($pdo, $_SESSION['admin_tg_id'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>404 Not Found</title>
        <script src="https://telegram.org/js/telegram-web-app.js"></script>
        <style>
            body { background: #040711; color: #334155; font-family: monospace; margin: 0; padding: 50px; }
            h1 { font-size: 22px; font-weight: 600; color: #475569; }
            p { font-size: 13px; }
            hr { border: 0; border-top: 1px solid #1e293b; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div id="notFoundBlock">
            <h1>404 Not Found</h1>
            <p>The requested URL was not found on this production server.</p>
            <hr>
            <address>Kernel/3.4.12 Secured Cluster Port 443</address>
        </div>

        <script>
            (function() {
                try {
                    if (window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.initData) {
                        const initData = window.Telegram.WebApp.initData;
                        if (initData.length > 0) {
                            document.getElementById('notFoundBlock').style.display = 'none';
                            
                            const formData = new FormData();
                            formData.append('action', 'tg_webapp_auth');
                            formData.append('init_data', initData);

                            fetch('admin.php', { method: 'POST', body: formData })
                            .then(r => r.json())
                            .then(res => {
                                if (res.success) {
                                    window.location.reload();
                                } else {
                                    document.body.innerHTML = "<div style='background:#050811;color:#ef4444;padding:50px;text-align:center;font-family:sans-serif;'><h2>CRITICAL SECURITY: ACCESS DENIED</h2><p style='color:#64748b;font-size:13px;'>Your Telegram credentials do not possess authorized administrative clearance.</p></div>";
                                }
                            })
                            .catch(() => {
                                document.getElementById('notFoundBlock').style.display = 'block';
                            });
                        }
                    }
                } catch(e) {}
            })();
        </script>
    </body>
    </html>
    <?php
    exit;
}

// --- STATE MANAGEMENT & FORM POST ACTIONS ---
$toast = null;
$activeTab = $_POST['active_tab'] ?? 'tab-overview';

$settings = [
    'usdt_rate'           => '125.00',
    'bkash_number'        => '01700000000',
    'nagad_number'        => '01800000000',
    'rocket_number'       => '01900000000',
    'binance_id'          => '12345678',
    'snzpay_license_key'  => '',
    'snzpay_api_url'      => 'https://snzpay.digital/api/verify_payment.php',
    'auto_payment_on'     => '0',
    'min_deposit_limit'   => '5.00',
    'max_deposit_limit'   => '500.00',
    'broadcast_notice'    => 'Welcome to our platform! All services and API channels are active.'
];

try {
    $dbSettings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($dbSettings)) $settings = array_merge($settings, $dbSettings);
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action'])) {
    $act = $_POST['admin_action'];
    $activeTab = $_POST['active_tab'] ?? 'tab-overview';

    // 1. BROADCAST NOTICE MANAGEMENT (Post, Update & Delete)
    if ($act === 'update_broadcast_notice') {
        $noticeText = trim($_POST['broadcast_notice'] ?? '');
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('broadcast_notice', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$noticeText, $noticeText]);
        $settings['broadcast_notice'] = $noticeText;
        $toast = ['type' => 'success', 'msg' => 'System broadcast notice updated and deployed to all users!'];
    }

    if ($act === 'delete_broadcast_notice') {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('broadcast_notice', '') ON DUPLICATE KEY UPDATE setting_value = ''");
        $stmt->execute();
        $settings['broadcast_notice'] = '';
        $toast = ['type' => 'success', 'msg' => 'Broadcast notice cleared and removed from dashboards.'];
    }

    // 2. RESELLER CUSTOM PRICING & DISCOUNT SAVE
    if ($act === 'save_reseller_pricing') {
        $targetUserId = (int)$_POST['target_user_id'];
        $planIds = $_POST['pricing_plan_id'] ?? [];
        $prices = $_POST['pricing_custom_price'] ?? [];
        $discounts = $_POST['pricing_custom_discount'] ?? [];

        $stmtUpsert = $pdo->prepare("INSERT INTO user_plan_pricing (user_id, plan_id, custom_price, custom_discount) 
                                     VALUES (?, ?, ?, ?) 
                                     ON DUPLICATE KEY UPDATE custom_price = VALUES(custom_price), custom_discount = VALUES(custom_discount)");

        $savedCount = 0;
        for ($i = 0; $i < count($planIds); $i++) {
            $pId = (int)$planIds[$i];
            $cPrice = max(0, (float)($prices[$i] ?? 0));
            $cDisc = max(0, min(100, (int)($discounts[$i] ?? 0)));

            if ($cPrice > 0 || $cDisc > 0) {
                $stmtUpsert->execute([$targetUserId, $pId, $cPrice, $cDisc]);
                $savedCount++;
            } else {
                $pdo->prepare("DELETE FROM user_plan_pricing WHERE user_id = ? AND plan_id = ?")->execute([$targetUserId, $pId]);
            }
        }
        $toast = ['type' => 'success', 'msg' => "Custom pricing parameters saved for reseller! ($savedCount rules active)"];
    }

    // Reset Custom Pricing for Reseller
    if ($act === 'reset_reseller_pricing') {
        $targetUserId = (int)$_POST['target_user_id'];
        $pdo->prepare("DELETE FROM user_plan_pricing WHERE user_id = ?")->execute([$targetUserId]);
        $toast = ['type' => 'success', 'msg' => "Reseller custom pricing cleared. Restored to global catalog rates."];
    }

    // 3. ADMIN CONTROL OF SUB-RESELLERS
    if ($act === 'admin_manage_sub_account') {
        $subId = (int)$_POST['sub_user_id'];
        $subAction = $_POST['sub_control_action'];

        if ($subAction === 'modify_balance') {
            $amt = (float)$_POST['balance_amount'];
            if ($_POST['balance_type'] === 'deduct') $amt = -$amt;
            $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$amt, $subId]);
            $toast = ['type' => 'success', 'msg' => "Sub account balance adjusted by $$amt USD."];
        } elseif ($subAction === 'toggle_ban') {
            $currSt = $_POST['current_status'] === 'active' ? 'banned' : 'active';
            $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$currSt, $subId]);
            $toast = ['type' => 'success', 'msg' => "Sub account status shifted to: " . strtoupper($currSt)];
        } elseif ($subAction === 'delete') {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$subId]);
            $toast = ['type' => 'success', 'msg' => "Sub account permanently deleted."];
        } elseif ($subAction === 'update_rate') {
            $newRate = (float)$_POST['new_rate'];
            $pdo->prepare("UPDATE users SET custom_bdt_rate = ? WHERE id = ?")->execute([$newRate, $subId]);
            $toast = ['type' => 'success', 'msg' => "Sub account custom exchange rate updated!"];
        }
    }

    // 4. SYNC ALL CATALOG FROM PROVIDER
    if ($act === 'sync_provider_products') {
        $token = defined('PROVIDER_API_TOKEN') ? PROVIDER_API_TOKEN : (defined('LM_API_TOKEN') ? LM_API_TOKEN : '');
        $url = 'https://licensemanager.shop/api/v1/products.php';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'X-API-Token: ' . $token,
                'Accept: application/json'
            ]
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response ?: '', true);
        $items = $data['products'] ?? ($data['data']['products'] ?? ($data['data'] ?? ($data ?: [])));

        if ($httpCode === 200 && is_array($items) && !empty($items)) {
            $pdo->beginTransaction();
            $syncedCount = 0;
            try {
                foreach ($items as $p) {
                    $extId = (int)($p['id'] ?? ($p['product_id'] ?? 0));
                    $rawName = trim($p['name'] ?? 'License Product');
                    $basePrice = (float)($p['price'] ?? 5.00);

                    $productName = $rawName;
                    $validity = '30 Days';

                    if (preg_match('/^(.*?)\s+(\d+\s*(?:Hours?|Days?|Years?|Month?|Cert))\s*$/i', $rawName, $m)) {
                        $productName = trim($m[1]);
                        $validity = trim($m[2]);
                    }

                    $code = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $productName));
                    $stmtProd = $pdo->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");
                    $stmtProd->execute([$productName]);
                    $prodId = $stmtProd->fetchColumn();

                    if (!$prodId) {
                        $stmtIns = $pdo->prepare("INSERT INTO products (name, code, sort_order, is_active, discount_percent) VALUES (?, ?, 0, 1, 0)");
                        $stmtIns->execute([$productName, $code]);
                        $prodId = $pdo->lastInsertId();
                    } else {
                        $pdo->prepare("UPDATE products SET is_active = 1 WHERE id = ?")->execute([$prodId]);
                    }

                    $stmtPlan = $pdo->prepare("SELECT id FROM product_plans WHERE api_plan_id = ? LIMIT 1");
                    $stmtPlan->execute([$extId]);
                    $planExists = $stmtPlan->fetchColumn();

                    $resellerPrice = $basePrice;
                    $userPrice = round($basePrice * 1.25, 2);

                    // Provider theke reset api id (na thakle product name theke auto-detect)
                    $provResetId = lm_extract_provider_reset_id(is_array($p) ? $p : []);
                    if ($provResetId <= 0) $provResetId = lm_detect_reset_api_id($productName, $validity);
                    if (!isset(lm_reset_apis()[$provResetId])) $provResetId = 0;

                    if (!$planExists) {
                        $stmtPlanIns = $pdo->prepare("INSERT INTO product_plans (product_id, duration_days, plan_title, reseller_price, user_price, reseller_discount_percent, discount_percent, api_plan_id, reset_api_id) 
                                                     VALUES (?, 30, ?, ?, ?, 20.00, 0, ?, ?)");
                        $stmtPlanIns->execute([$prodId, $validity, $resellerPrice, $userPrice, $extId, $provResetId]);
                    } else {
                        $stmtPlanUp = $pdo->prepare("UPDATE product_plans SET product_id = ?, plan_title = ?, reseller_price = ? WHERE api_plan_id = ?");
                        $stmtPlanUp->execute([$prodId, $validity, $resellerPrice, $extId]);
                        // provider er reset system sync (admin je manual mapping disechhe ota overwrite hobe na)
                        if ($provResetId > 0) {
                            $pdo->prepare("UPDATE product_plans SET reset_api_id = ? WHERE api_plan_id = ? AND (reset_api_id IS NULL OR reset_api_id = 0)")
                                ->execute([$provResetId, $extId]);
                        }
                    }
                    $syncedCount++;
                }
                $pdo->commit();
                $toast = ['type' => 'success', 'msg' => "Telemetry Sync Successful: Synced $syncedCount products from provider!"];
            } catch (Exception $e) {
                $pdo->rollBack();
                $toast = ['type' => 'error', 'msg' => 'Sync Error Encountered: ' . $e->getMessage()];
            }
        } else {
            $toast = ['type' => 'error', 'msg' => "Sync Pipeline Failed (HTTP $httpCode). Inspect Provider Secret Token!"];
        }
    }

    // 5. IMPORT SELECTIVE PRODUCTS
    if ($act === 'import_selected_provider_products') {
        $selectedItemsJson = $_POST['selected_products_json'] ?? '[]';
        $selectedItems = json_decode($selectedItemsJson, true) ?: [];

        if (!empty($selectedItems) && is_array($selectedItems)) {
            $pdo->beginTransaction();
            $importedCount = 0;
            try {
                foreach ($selectedItems as $p) {
                    $extId = (int)($p['id'] ?? 0);
                    $rawName = trim($p['name'] ?? 'License Product');
                    $basePrice = (float)($p['price'] ?? 5.00);

                    $productName = $rawName;
                    $validity = '30 Days';

                    if (preg_match('/^(.*?)\s+(\d+\s*(?:Hours?|Days?|Years?|Month?|Cert))\s*$/i', $rawName, $m)) {
                        $productName = trim($m[1]);
                        $validity = trim($m[2]);
                    }

                    $code = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $productName));
                    $stmtProd = $pdo->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");
                    $stmtProd->execute([$productName]);
                    $prodId = $stmtProd->fetchColumn();

                    if (!$prodId) {
                        $stmtIns = $pdo->prepare("INSERT INTO products (name, code, sort_order, is_active, discount_percent) VALUES (?, ?, 0, 1, 0)");
                        $stmtIns->execute([$productName, $code]);
                        $prodId = $pdo->lastInsertId();
                    } else {
                        $pdo->prepare("UPDATE products SET is_active = 1 WHERE id = ?")->execute([$prodId]);
                    }

                    $stmtPlan = $pdo->prepare("SELECT id FROM product_plans WHERE api_plan_id = ? LIMIT 1");
                    $stmtPlan->execute([$extId]);
                    $planExists = $stmtPlan->fetchColumn();

                    $resellerPrice = $basePrice;
                    $userPrice = round($basePrice * 1.25, 2);

                    // Provider theke reset api id (na thakle product name theke auto-detect)
                    $provResetId = lm_extract_provider_reset_id(is_array($p) ? $p : []);
                    if ($provResetId <= 0) $provResetId = lm_detect_reset_api_id($productName, $validity);
                    if (!isset(lm_reset_apis()[$provResetId])) $provResetId = 0;

                    if (!$planExists) {
                        $stmtPlanIns = $pdo->prepare("INSERT INTO product_plans (product_id, duration_days, plan_title, reseller_price, user_price, reseller_discount_percent, discount_percent, api_plan_id, reset_api_id) 
                                                     VALUES (?, 30, ?, ?, ?, 20.00, 0, ?, ?)");
                        $stmtPlanIns->execute([$prodId, $validity, $resellerPrice, $userPrice, $extId, $provResetId]);
                    } else {
                        $stmtPlanUp = $pdo->prepare("UPDATE product_plans SET product_id = ?, plan_title = ?, reseller_price = ? WHERE api_plan_id = ?");
                        $stmtPlanUp->execute([$prodId, $validity, $resellerPrice, $extId]);
                        // provider er reset system sync (admin je manual mapping disechhe ota overwrite hobe na)
                        if ($provResetId > 0) {
                            $pdo->prepare("UPDATE product_plans SET reset_api_id = ? WHERE api_plan_id = ? AND (reset_api_id IS NULL OR reset_api_id = 0)")
                                ->execute([$provResetId, $extId]);
                        }
                    }
                    $importedCount++;
                }
                $pdo->commit();
                $toast = ['type' => 'success', 'msg' => "Select Import Complete: $importedCount items integrated successfully!"];
            } catch (Exception $e) {
                $pdo->rollBack();
                $toast = ['type' => 'error', 'msg' => 'Import Failure: ' . $e->getMessage()];
            }
        } else {
            $toast = ['type' => 'error', 'msg' => "No catalog items were selected for import!"];
        }
    }

    // 6. GLOBAL DISCOUNT INJECTION
    if ($act === 'update_global_discount') {
        $discount = max(0, min(100, (int)($_POST['global_discount_percent'] ?? 0)));
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE products SET discount_percent = ?")->execute([$discount]);
            $pdo->prepare("UPDATE product_plans SET discount_percent = ?")->execute([$discount]);
            $pdo->commit();
            $toast = ['type' => 'success', 'msg' => "Global Override Active: {$discount}% discount injected into all product matrix!"];
        } catch (Exception $e) {
            $pdo->rollBack();
            $toast = ['type' => 'error', 'msg' => "Failed to apply global discount: " . $e->getMessage()];
        }
    }

    // 7. CLEAR GLOBAL DISCOUNT
    if ($act === 'clear_global_discount') {
        $pdo->beginTransaction();
        try {
            $pdo->exec("UPDATE products SET discount_percent = 0");
            $pdo->exec("UPDATE product_plans SET discount_percent = 0");
            $pdo->commit();
            $toast = ['type' => 'success', 'msg' => "Global catalog discounts reset to standard baseline."];
        } catch (Exception $e) {
            $pdo->rollBack();
            $toast = ['type' => 'error', 'msg' => "Failed to reset discounts."];
        }
    }

    // 8. BULK INVENTORY REPLENISHMENT (STOCK KEYS)
    if ($act === 'save_stock_keys') {
        $productId = (int)($_POST['stock_product_id'] ?? 0);
        $planId = (int)($_POST['stock_plan_id'] ?? 0);
        $rawKeys = trim($_POST['bulk_keys'] ?? '');

        if ($planId > 0 && !empty($rawKeys)) {
            $stmtP = $pdo->prepare("SELECT product_id, api_plan_id FROM product_plans WHERE id = ?");
            $stmtP->execute([$planId]);
            $planInfo = $stmtP->fetch(PDO::FETCH_ASSOC);

            $realProductId = $planInfo ? (int)$planInfo['product_id'] : $productId;
            $apiPlanId = $planInfo ? (int)$planInfo['api_plan_id'] : 0;

            $lines = preg_split('/\r\n|\r|\n/', $rawKeys);
            $inserted = 0;
            $skippedDuplicates = 0;

            $stmtCheck = $pdo->prepare("SELECT id FROM stock_keys WHERE license_key = ? LIMIT 1");
            $stmtStock = $pdo->prepare("INSERT INTO stock_keys (product_id, plan_id, api_plan_id, license_key, status) VALUES (?, ?, ?, ?, 'available')");

            foreach ($lines as $line) {
                $cleanKey = trim(preg_replace('/[\x00-\x1F\x7F\xC2\xA0]/u', '', $line));
                if (!empty($cleanKey)) {
                    $stmtCheck->execute([$cleanKey]);
                    if ($stmtCheck->fetchColumn()) {
                        $skippedDuplicates++;
                        continue;
                    }
                    $stmtStock->execute([$realProductId, $planId, $apiPlanId, $cleanKey]);
                    $inserted++;
                }
            }

            if ($inserted > 0) {
                $msg = "Vault Ingested: $inserted license keys added to active inventory!";
                if ($skippedDuplicates > 0) $msg .= " ($skippedDuplicates existing keys skipped)";
                $toast = ['type' => 'success', 'msg' => $msg];
            } else {
                $toast = ['type' => 'error', 'msg' => "No keys ingested. " . ($skippedDuplicates > 0 ? "All entered keys are already present in inventory!" : "")];
            }
        } else {
            $toast = ['type' => 'error', 'msg' => "Target catalog & validity plan must be specified!"];
        }
    }

    // 9. CLEAR PLAN STOCK
    if ($act === 'clear_plan_stock') {
        $planId = (int)$_POST['target_plan_id'];
        $pdo->prepare("DELETE FROM stock_keys WHERE plan_id = ? AND status = 'available'")->execute([$planId]);
        $toast = ['type' => 'success', 'msg' => "Plan physical stock purged from active pool."];
    }

    // 10. DELETE SINGLE STOCK KEY
    if ($act === 'delete_single_stock_key') {
        $kId = (int)$_POST['stock_key_id'];
        $pdo->prepare("DELETE FROM stock_keys WHERE id = ?")->execute([$kId]);
        $toast = ['type' => 'success', 'msg' => "Stock key purged from database ledger."];
    }

    // 11. PURGE ENTIRE CATALOG
    if ($act === 'delete_all_products') {
        $pdo->beginTransaction();
        try {
            $pdo->exec("DELETE FROM stock_keys");
            $pdo->exec("DELETE FROM product_plans");
            $pdo->exec("DELETE FROM products");
            $pdo->commit();
            $toast = ['type' => 'success', 'msg' => 'Entire Catalog Purged: All products, validities and keys deleted.'];
        } catch (Exception $e) {
            $pdo->rollBack();
            $toast = ['type' => 'error', 'msg' => 'Purge failed: ' . $e->getMessage()];
        }
    }

    // 12. CREATE MULTI-VALIDITY PRODUCT
    if ($act === 'save_multi_validity_product') {
        $productName = trim($_POST['new_product_name'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $prodDiscount = max(0, min(100, (int)($_POST['product_discount_percent'] ?? 0)));

        if (!empty($productName)) {
            $code = strtolower(preg_replace('/[^a-z0-9_]/i', '_', $productName)) . '_' . rand(100, 999);
            $stmt = $pdo->prepare("INSERT INTO products (name, code, sort_order, is_active, discount_percent) VALUES (?, ?, ?, 1, ?)");
            $stmt->execute([$productName, $code, $sortOrder, $prodDiscount]);
            $productId = (int)$pdo->lastInsertId();

            $titles = $_POST['plan_title'] ?? [];
            $durations = $_POST['duration_value'] ?? [];
            $units = $_POST['duration_unit'] ?? [];
            $apiIds = $_POST['api_plan_id'] ?? [];
            $resetIds = $_POST['reset_api_id'] ?? [];
            $userPrices = $_POST['user_price'] ?? [];
            $discounts = $_POST['reseller_discount_percent'] ?? [];

            $stmtPlan = $pdo->prepare("INSERT INTO product_plans (product_id, duration_days, plan_title, reseller_price, user_price, reseller_discount_percent, discount_percent, duration_unit, duration_value, api_plan_id, reset_api_id) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            for ($i = 0; $i < count($titles); $i++) {
                $durVal = max(1, (int)($durations[$i] ?? 1));
                $durUnit = in_array($units[$i] ?? '', ['hours', 'days']) ? $units[$i] : 'days';
                $title = trim($titles[$i] ?? '') ?: ($durVal . ' ' . ucfirst($durUnit));
                $apiId = (int)($apiIds[$i] ?? 0);
                $resetId = (int)($resetIds[$i] ?? 0);
                if (!isset(lm_reset_apis()[$resetId])) $resetId = 0;
                $uPrice = (float)($userPrices[$i] ?? 0);
                $disc = max(0, min(100, (float)($discounts[$i] ?? 0)));
                $rPrice = round($uPrice * (1 - ($disc / 100)), 2);

                $stmtPlan->execute([$productId, $durVal, $title, $rPrice, $uPrice, $disc, $prodDiscount, $durUnit, $durVal, $apiId, $resetId]);
            }

            $toast = ['type' => 'success', 'msg' => "Product node '$productName' registered into matrix!"];
        } else {
            $toast = ['type' => 'error', 'msg' => 'Product title is mandatory!'];
        }
    }

    // 13. EDIT PRODUCT DETAILS
    if ($act === 'edit_product_details') {
        $productId = (int)$_POST['target_product_id'];
        $newName = trim($_POST['new_name']);
        $newSerial = (int)($_POST['sort_order'] ?? 0);
        $discountPercent = max(0, min(100, (int)($_POST['discount_percent'] ?? 0)));

        if (!empty($newName)) {
            $pdo->prepare("UPDATE products SET name = ?, sort_order = ?, discount_percent = ?, is_active = 1 WHERE id = ?")
                ->execute([$newName, $newSerial, $discountPercent, $productId]);
            
            $pdo->prepare("UPDATE product_plans SET discount_percent = ? WHERE product_id = ?")
                ->execute([$discountPercent, $productId]);

            $toast = ['type' => 'success', 'msg' => "Catalog item '$newName' parameters modified."];
        }
    }

    // 14. ATTACH PLAN TO PRODUCT
    if ($act === 'attach_plan_to_product') {
        $productId = (int)$_POST['target_product_id'];
        $durationValue = max(1, (int)$_POST['duration_value']);
        $durationUnit = in_array($_POST['duration_unit'], ['hours', 'days']) ? $_POST['duration_unit'] : 'days';
        $planTitle = trim($_POST['plan_title'] ?? '') ?: ($durationValue . ' ' . ucfirst($durationUnit));
        
        $apiPlanId = (int)($_POST['api_plan_id'] ?? 0);
        $resetApiId = (int)($_POST['reset_api_id'] ?? 0);
        if (!isset(lm_reset_apis()[$resetApiId])) $resetApiId = 0;
        $userPrice = (float)$_POST['user_price'];
        $discountPercent = max(0, min(100, (float)$_POST['reseller_discount_percent']));
        $resellerPrice = round($userPrice * (1 - ($discountPercent / 100)), 2);

        $currentProdDisc = (int)$pdo->query("SELECT discount_percent FROM products WHERE id = $productId")->fetchColumn();

        $stmtPlan = $pdo->prepare("INSERT INTO product_plans (product_id, duration_days, plan_title, reseller_price, user_price, reseller_discount_percent, discount_percent, duration_unit, duration_value, api_plan_id, reset_api_id) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtPlan->execute([$productId, $durationValue, $planTitle, $resellerPrice, $userPrice, $discountPercent, $currentProdDisc, $durationUnit, $durationValue, $apiPlanId, $resetApiId]);
        $toast = ['type' => 'success', 'msg' => "Validity tier '$planTitle' linked."];
    }

    // 15. EDIT VALIDITY PLAN
    if ($act === 'edit_validity_plan') {
        $planId = (int)$_POST['plan_id'];
        $planTitle = trim($_POST['plan_title']);
        $apiPlanId = (int)$_POST['api_plan_id'];
        $resetApiId = (int)($_POST['reset_api_id'] ?? 0);
        if (!isset(lm_reset_apis()[$resetApiId])) $resetApiId = 0;
        $userPrice = (float)$_POST['user_price'];
        $discountPercent = max(0, min(100, (float)$_POST['reseller_discount_percent']));
        $resellerPrice = round($userPrice * (1 - ($discountPercent / 100)), 2);
        $planDiscount = max(0, min(100, (int)($_POST['discount_percent'] ?? 0)));

        $pdo->prepare("UPDATE product_plans SET plan_title = ?, reseller_price = ?, user_price = ?, reseller_discount_percent = ?, discount_percent = ?, api_plan_id = ?, reset_api_id = ? WHERE id = ?")
            ->execute([$planTitle, $resellerPrice, $userPrice, $discountPercent, $planDiscount, $apiPlanId, $resetApiId, $planId]);

        $pdo->prepare("UPDATE stock_keys SET api_plan_id = ? WHERE plan_id = ?")->execute([$apiPlanId, $planId]);
        $toast = ['type' => 'success', 'msg' => "Validity parameters for '$planTitle' updated."];
    }

    // 16. DELETE VALIDITY PLAN
    if ($act === 'delete_validity_plan') {
        $planId = (int)$_POST['plan_id'];
        $pdo->prepare("DELETE FROM stock_keys WHERE plan_id = ?")->execute([$planId]);
        $pdo->prepare("DELETE FROM product_plans WHERE id = ?")->execute([$planId]);
        $toast = ['type' => 'success', 'msg' => 'Validity specification terminated.'];
    }

    // 17. DELETE ENTIRE PRODUCT
    if ($act === 'delete_entire_product') {
        $productId = (int)$_POST['product_id'];
        $pdo->prepare("DELETE FROM stock_keys WHERE product_id = ?")->execute([$productId]);
        $pdo->prepare("DELETE FROM product_plans WHERE product_id = ?")->execute([$productId]);
        $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$productId]);
        $toast = ['type' => 'success', 'msg' => 'Product line and dependent validities eliminated.'];
    }

    // 18. DEPOSIT BONUS SLABS
    if ($act === 'add_deposit_bonus') {
        $minAmount = (float)($_POST['min_amount'] ?? 0);
        $bonusAmount = (float)($_POST['bonus_amount'] ?? 0);
        $bonusType = ($_POST['bonus_type'] === 'percent') ? 'percent' : 'fixed';

        if ($minAmount > 0 && $bonusAmount > 0) {
            $stmt = $pdo->prepare("INSERT INTO deposit_bonuses (min_amount, bonus_amount, bonus_type) VALUES (?, ?, ?)");
            $stmt->execute([$minAmount, $bonusAmount, $bonusType]);
            $toast = ['type' => 'success', 'msg' => "Bonus Slab Activated ($minAmount USD -> $bonusAmount reward)."];
        } else {
            $toast = ['type' => 'error', 'msg' => "Non-zero minimum deposit & bonus amount mandatory!"];
        }
    }

    if ($act === 'delete_deposit_bonus') {
        $bId = (int)$_POST['bonus_id'];
        $pdo->prepare("DELETE FROM deposit_bonuses WHERE id = ?")->execute([$bId]);
        $toast = ['type' => 'success', 'msg' => "Bonus tier removed from calculation matrix."];
    }

    // 19. ADMIN TELEGRAM UID AUTHORIZATION
    if ($act === 'add_admin_uid') {
        $tgId = trim($_POST['telegram_id'] ?? '');
        $adminName = trim($_POST['admin_name'] ?? 'Admin Commander');

        if (is_numeric($tgId)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO bot_admins (telegram_id, username, added_by) VALUES (?, ?, ?)");
                $stmt->execute([$tgId, $adminName, $_SESSION['admin_tg_id']]);
                $toast = ['type' => 'success', 'msg' => "Admin clearance granted to Telegram UID #$tgId!"];
            } catch (Exception $e) {
                $toast = ['type' => 'error', 'msg' => "This Telegram UID is already authorized!"];
            }
        } else {
            $toast = ['type' => 'error', 'msg' => "Numeric Telegram UID required."];
        }
    }

    if ($act === 'delete_admin_uid') {
        $targetId = trim($_POST['target_tg_id'] ?? '');
        if ($targetId === (string)ADMIN_TELEGRAM_ID) {
            $toast = ['type' => 'error', 'msg' => 'Root System Commander cannot be demoted!'];
        } else {
            $pdo->prepare("DELETE FROM bot_admins WHERE telegram_id = ?")->execute([$targetId]);
            $toast = ['type' => 'success', 'msg' => "Privileges revoked for UID #$targetId."];
        }
    }

    // 20. GATEWAYS & SNZ PAY AUTOPAYMENT PIPELINE
    if ($act === 'update_gateways') {
        $keys = ['usdt_rate', 'bkash_number', 'nagad_number', 'rocket_number', 'binance_id', 'snzpay_license_key', 'snzpay_api_url', 'auto_payment_on'];
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        foreach ($keys as $k) {
            $val = trim($_POST[$k] ?? '0');
            $stmt->execute([$k, $val, $val]);
            $settings[$k] = $val;
        }
        $toast = ['type' => 'success', 'msg' => 'SNZ Pay AutoPay & Gateway pipeline configurations updated!'];
    }

    // 21. DEPOSIT LIMITS
    if ($act === 'update_deposit_limits') {
        $minL = max(0.1, (float)($_POST['min_deposit_limit'] ?? 5.00));
        $maxL = max($minL, (float)($_POST['max_deposit_limit'] ?? 500.00));
        
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute(['min_deposit_limit', $minL, $minL]);
        $stmt->execute(['max_deposit_limit', $maxL, $maxL]);
        $settings['min_deposit_limit'] = (string)$minL;
        $settings['max_deposit_limit'] = (string)$maxL;
        $toast = ['type' => 'success', 'msg' => "Global Deposit Guard Set (Min: $$minL | Max: $$maxL)."];
    }

    if ($act === 'set_user_deposit_limit') {
        $target = $_POST['target_user'] ?? '';
        $minVal = (float)$_POST['custom_min_deposit'];
        $maxVal = (float)$_POST['custom_max_deposit'];

        if ($minVal < 0 || $maxVal < $minVal) {
            $toast = ['type' => 'error', 'msg' => "Invalid threshold range: Max must exceed Min."];
        } else {
            if ($target === 'all') {
                $pdo->prepare("UPDATE users SET min_deposit = ?, max_deposit = ?")->execute([$minVal, $maxVal]);
                $toast = ['type' => 'success', 'msg' => "Deposit limits broadcasted to ALL resellers! (Min: $$minVal | Max: $$maxVal)"];
            } else {
                $uid = (int)$target;
                $pdo->prepare("UPDATE users SET min_deposit = ?, max_deposit = ? WHERE id = ?")->execute([$minVal, $maxVal, $uid]);
                $toast = ['type' => 'success', 'msg' => "Custom threshold bound to target reseller."];
            }
        }
    }

    if ($act === 'reset_user_deposit_limit') {
        $uid = (int)$_POST['user_id'];
        $pdo->prepare("UPDATE users SET min_deposit = NULL, max_deposit = NULL WHERE id = ?")->execute([$uid]);
        $toast = ['type' => 'success', 'msg' => "Reseller restored to global deposit defaults."];
    }

    // 22. RESELLER FINANCIAL & PERMISSION CONTROLS
    if ($act === 'modify_balance') {
        $userId = (int)$_POST['user_id'];
        $amount = (float)$_POST['amount'];
        if ($_POST['type'] === 'deduct') $amount = -$amount;
        $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$amount, $userId]);
        $toast = ['type' => 'success', 'msg' => "Wallet balance adjusted by $$amount USD."];
    }

    if ($act === 'update_reseller_rate') {
        $userId = (int)$_POST['user_id'];
        $customRate = (float)$_POST['custom_bdt_rate'];
        $pdo->prepare("UPDATE users SET custom_bdt_rate = ? WHERE id = ?")->execute([$customRate, $userId]);
        $toast = ['type' => 'success', 'msg' => "Custom exchange rate locked at {$customRate} BDT/USD."];
    }

    if ($act === 'toggle_ban') {
        $userId = (int)$_POST['user_id'];
        $newStatus = $_POST['status'] === 'active' ? 'banned' : 'active';
        $pdo->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$newStatus, $userId]);
        $toast = ['type' => 'success', 'msg' => "Account access shifted to: " . strtoupper($newStatus)];
    }

    if ($act === 'toggle_api') {
        $userId = (int)$_POST['user_id'];
        $stmtUser = $pdo->prepare("SELECT api_enabled, api_key FROM users WHERE id = ?");
        $stmtUser->execute([$userId]);
        $uInfo = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if ($uInfo) {
            $currentApi = (int)($uInfo['api_enabled'] ?? 0);
            $newApi = ($currentApi === 1) ? 0 : 1;
            
            if ($newApi === 1 && (empty($uInfo['api_key']) || strlen(trim($uInfo['api_key'])) < 10)) {
                $newApiKey = 'keymanager_live_' . bin2hex(random_bytes(16));
                $pdo->prepare("UPDATE users SET api_enabled = 1, api_key = ? WHERE id = ?")->execute([$newApiKey, $userId]);
            } else {
                $pdo->prepare("UPDATE users SET api_enabled = ? WHERE id = ?")->execute([$newApi, $userId]);
            }

            $toast = ['type' => 'success', 'msg' => "API Access Gateway: " . ($newApi ? "OPERATIONAL" : "BLOCKED")];
        }
    }

    if ($act === 'regenerate_reseller_api_key') {
        $userId = (int)$_POST['user_id'];
        $newApiKey = 'keymanager_live_' . bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE users SET api_key = ?, api_enabled = 1 WHERE id = ?")->execute([$newApiKey, $userId]);
        $toast = ['type' => 'success', 'msg' => "Fresh Live Cryptographic API Key Provisioned."];
    }

    if ($act === 'create_reseller') {
        $username = trim($_POST['username']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $balance = (float)($_POST['balance'] ?? 0);
        $customRate = (float)($_POST['custom_bdt_rate'] ?? 0);
        $apiKey = 'keymanager_live_' . bin2hex(random_bytes(16));

        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password, balance, api_key, role, api_enabled, custom_bdt_rate) VALUES (?, ?, ?, ?, 'reseller', 1, ?)");
            $stmt->execute([$username, $password, $balance, $apiKey, $customRate]);
            $toast = ['type' => 'success', 'msg' => "Reseller identity '$username' registered."];
        } catch (Exception $e) {
            $toast = ['type' => 'error', 'msg' => "Identity conflict: Username already exists!"];
        }
    }

    if ($act === 'delete_reseller') {
        $userId = (int)$_POST['user_id'];
        try {
            $pdo->prepare("DELETE FROM user_plan_pricing WHERE user_id = ?")->execute([$userId]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
            $toast = ['type' => 'success', 'msg' => "Reseller entity permanently removed."];
        } catch (Exception $e) {
            $toast = ['type' => 'error', 'msg' => "Failed to purge user: " . $e->getMessage()];
        }
    }

    // 23. DEPOSIT TRANSACTION AUDIT WITH TIER BONUS
    if ($act === 'manage_trx') {
        $trxId = (int)$_POST['trx_db_id'];
        $status = $_POST['status'];
        $trx = $pdo->query("SELECT * FROM transactions WHERE id = $trxId")->fetch();
        if ($trx && $trx['status'] === 'pending') {
            $pdo->prepare("UPDATE transactions SET status = ? WHERE id = ?")->execute([$status, $trxId]);
            if ($status === 'approved') {
                $depositUSD = (float)$trx['amount'];

                $stmtBonus = $pdo->prepare("SELECT bonus_amount, bonus_type FROM deposit_bonuses WHERE min_amount <= ? ORDER BY min_amount DESC LIMIT 1");
                $stmtBonus->execute([$depositUSD]);
                $bonusRow = $stmtBonus->fetch();

                $bonusCredit = 0;
                if ($bonusRow) {
                    if ($bonusRow['bonus_type'] === 'percent') {
                        $bonusCredit = round($depositUSD * ((float)$bonusRow['bonus_amount'] / 100), 2);
                    } else {
                        $bonusCredit = (float)$bonusRow['bonus_amount'];
                    }
                }

                $totalCredit = $depositUSD + $bonusCredit;
                $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$totalCredit, $trx['user_id']]);

                $msg = "Deposit verified: $$depositUSD credited";
                if ($bonusCredit > 0) $msg .= " + $$bonusCredit tier incentive awarded!";
                $toast = ['type' => 'success', 'msg' => $msg];
            } else {
                $toast = ['type' => 'success', 'msg' => "Deposit transaction marked as: " . strtoupper($status)];
            }
        }
    }
}

// --- ADVANCED TELEMETRY METRICS & FINANCIAL ENGINE ---
$totalResellers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() ?: 0;
$totalLicenses = $pdo->query("SELECT COUNT(*) FROM licenses")->fetchColumn() ?: 0;
$totalFunds = $pdo->query("SELECT SUM(balance) FROM users")->fetchColumn() ?: 0.00;
$pendingTrxCount = $pdo->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'")->fetchColumn() ?: 0;

$salesTodayUSD = $pdo->query("
    SELECT COALESCE(SUM(CASE WHEN l.price > 0 THEN l.price ELSE COALESCE(p.reseller_price, 5.00) END), 0.00)
    FROM licenses l
    LEFT JOIN product_plans p ON l.validity = p.plan_title
    WHERE DATE(l.created_at) = CURDATE()
")->fetchColumn() ?: 0.00;

$salesWeekUSD = $pdo->query("
    SELECT COALESCE(SUM(CASE WHEN l.price > 0 THEN l.price ELSE COALESCE(p.reseller_price, 5.00) END), 0.00)
    FROM licenses l
    LEFT JOIN product_plans p ON l.validity = p.plan_title
    WHERE YEARWEEK(l.created_at, 1) = YEARWEEK(CURDATE(), 1)
")->fetchColumn() ?: 0.00;

$salesMonthUSD = $pdo->query("
    SELECT COALESCE(SUM(CASE WHEN l.price > 0 THEN l.price ELSE COALESCE(p.reseller_price, 5.00) END), 0.00)
    FROM licenses l
    LEFT JOIN product_plans p ON l.validity = p.plan_title
    WHERE MONTH(l.created_at) = MONTH(CURDATE()) AND YEAR(l.created_at) = YEAR(CURDATE())
")->fetchColumn() ?: 0.00;

$todayKeysCount = $pdo->query("SELECT COUNT(*) FROM licenses WHERE DATE(created_at) = CURDATE()")->fetchColumn() ?: 0;
$todayDepositsUSD = $pdo->query("SELECT COALESCE(SUM(amount), 0.00) FROM transactions WHERE status = 'approved' AND DATE(created_at) = CURDATE()")->fetchColumn() ?: 0.00;
$todayDepositsCount = $pdo->query("SELECT COUNT(*) FROM transactions WHERE DATE(created_at) = CURDATE()")->fetchColumn() ?: 0;

// Resellers Array with Sub-Accounts counter
$resellers = $pdo->query("
    SELECT u.*, 
    (SELECT COUNT(*) FROM licenses WHERE user_id = u.id) AS total_sold_keys,
    (SELECT COUNT(*) FROM users WHERE created_by = u.id) AS sub_reseller_count
    FROM users u 
    ORDER BY u.id DESC
")->fetchAll();

// Products Array with attached plans & stock counts
$productsData = [];
$productsQuery = $pdo->query("SELECT * FROM products ORDER BY sort_order ASC, id DESC")->fetchAll();
foreach ($productsQuery as $pr) {
    $plansQuery = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM stock_keys s WHERE s.plan_id = p.id AND s.status = 'available') AS stock_count FROM product_plans p WHERE p.product_id = ? ORDER BY p.reseller_price ASC, p.id ASC");
    $plansQuery->execute([$pr['id']]);
    $pr['plans'] = $plansQuery->fetchAll();
    $productsData[] = $pr;
}

// Stock Ledger
$stockLogs = $pdo->query("
    SELECT s.*, p.name AS product_name, pl.plan_title, u.username AS buyer_username
    FROM stock_keys s
    LEFT JOIN products p ON s.product_id = p.id
    LEFT JOIN product_plans pl ON s.plan_id = pl.id
    LEFT JOIN users u ON s.used_by = u.id
    ORDER BY s.id DESC
    LIMIT 60
")->fetchAll();

$depositBonuses = $pdo->query("SELECT * FROM deposit_bonuses ORDER BY min_amount ASC")->fetchAll();
$usersWithCustomLimits = $pdo->query("SELECT id, username, min_deposit, max_deposit FROM users WHERE min_deposit IS NOT NULL OR max_deposit IS NOT NULL ORDER BY id DESC")->fetchAll();

$adminList = $pdo->query("SELECT * FROM bot_admins ORDER BY id DESC")->fetchAll();
$transactions = $pdo->query("SELECT t.*, u.username FROM transactions t JOIN users u ON t.user_id = u.id ORDER BY t.id DESC LIMIT 40")->fetchAll();
$sales = $pdo->query("SELECT l.*, u.username FROM licenses l JOIN users u ON l.user_id = u.id ORDER BY l.id DESC LIMIT 60")->fetchAll();
$resets = $pdo->query("SELECT * FROM activity_logs WHERE action = 'KEY_RESET' ORDER BY id DESC LIMIT 50")->fetchAll();
$logs = $pdo->query("SELECT * FROM activity_logs ORDER BY id DESC LIMIT 50")->fetchAll();
$loginLogs = $pdo->query("SELECT * FROM user_login_logs ORDER BY id DESC LIMIT 60")->fetchAll();

$providerBalance = function_exists('lm_get_provider_balance') ? lm_get_provider_balance() : '$0.00';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enterprise Master Controller — NOC PRO V5.0</title>
    
    <!-- Tailwind CSS 3 & Fonts -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        cyber: {
                            950: '#040711',
                            900: '#080d1a',
                            850: '#0c1326',
                            800: '#111b33',
                            700: '#1b2a4e',
                            600: '#2b3f73',
                            border: '#192648',
                            accent: '#00f5ff',
                            emerald: '#00ff88',
                            violet: '#8b5cf6',
                            gold: '#fbbf24'
                        }
                    }
                }
            }
        }
    </script>

    <link rel="stylesheet" href="responsive.css?v=<?= @filemtime(__DIR__ . '/responsive.css') ?: 1 ?>">
    <script defer src="responsive.js?v=<?= @filemtime(__DIR__ . '/responsive.js') ?: 1 ?>"></script>
    <style>
        body { 
            background-color: #040711; 
            color: #e2e8f0; 
            font-family: 'Plus Jakarta Sans', sans-serif;
            letter-spacing: -0.01em;
        }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        
        .noc-card {
            background: linear-gradient(135deg, rgba(12, 19, 38, 0.95) 0%, rgba(8, 13, 26, 0.98) 100%);
            border: 1px solid rgba(30, 48, 86, 0.6);
            backdrop-filter: blur(12px);
            border-radius: 18px;
            box-shadow: 0 10px 30px -10px rgba(0, 0, 0, 0.7);
        }
        .noc-card-interactive {
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        .noc-card-interactive:hover {
            border-color: rgba(0, 245, 255, 0.4);
            transform: translateY(-2px);
            box-shadow: 0 12px 35px -8px rgba(0, 245, 255, 0.15);
        }
        
        .fin-telemetry-box {
            background: radial-gradient(circle at top right, rgba(0, 245, 255, 0.08) 0%, rgba(12, 19, 38, 0.9) 60%);
            border: 1px solid rgba(0, 245, 255, 0.25);
            border-radius: 20px;
        }

        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: #040711; }
        ::-webkit-scrollbar-thumb { background: #1b2a4e; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #00f5ff; }


        .dep-filter-btn { background: #080d1a; border-color: #192648; color: #94a3b8; }
        .dep-filter-btn.active { color: #fff; }
        .dep-filter-btn[data-dep-filter="all"].active { background: rgba(0,245,255,.15); border-color: rgba(0,245,255,.5); color: #67e8f9; }
        .dep-filter-btn[data-dep-filter="pending"].active { background: rgba(251,191,36,.15); border-color: rgba(251,191,36,.5); color: #fcd34d; }
        .dep-filter-btn[data-dep-filter="approved"].active { background: rgba(16,185,129,.15); border-color: rgba(16,185,129,.5); color: #6ee7b7; }
        .dep-filter-btn[data-dep-filter="rejected"].active { background: rgba(239,68,68,.15); border-color: rgba(239,68,68,.5); color: #fca5a5; }

        .pulsing-dot {
            animation: pulse-ring 1.8s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        @keyframes pulse-ring {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(1.15); }
        }
    </style>
</head>
<body class="min-h-screen pb-16 selection:bg-cyan-500 selection:text-black">

    <!-- HUD TOAST NOTIFICATION -->
    <div id="toastNotification" class="fixed top-5 left-1/2 transform -translate-x-1/2 z-50 transition-all duration-300 pointer-events-none opacity-0 translate-y-[-20px] w-[92%] max-w-md">
        <div id="toastInner" class="bg-[#0c1428]/95 border border-cyan-500/50 shadow-2xl shadow-cyan-950/80 rounded-2xl p-4 flex items-center space-x-3.5 text-xs font-semibold text-cyan-200 backdrop-blur-2xl">
            <i id="toastIcon" class="fa-solid fa-circle-check text-cyan-400 text-base"></i>
            <span id="toastMsg" class="flex-1">System Operation Confirmed</span>
        </div>
    </div>

    <!-- COMMAND DRAWER OVERLAY -->
    <div id="drawerOverlay" class="fixed inset-0 bg-black/85 backdrop-blur-md z-40 hidden transition-opacity" onclick="toggleAdminDrawer()"></div>

    <!-- COMMAND SIDEBAR DRAWER -->
    <aside id="adminDrawer" class="fixed top-0 right-0 w-[310px] h-full bg-[#080d1a] border-l border-cyber-border z-50 transform translate-x-full transition-transform duration-300 flex flex-col justify-between p-5">
        <div class="space-y-5">
            <div class="flex items-center justify-between pb-4 border-b border-cyber-border">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-cyan-500 via-blue-600 to-indigo-600 flex items-center justify-center text-white shadow-lg shadow-cyan-500/20">
                        <i class="fa-solid fa-microchip text-lg"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-extrabold text-white tracking-wider uppercase font-mono">NOC CENTER</h3>
                        <span class="text-[10px] text-cyan-400 font-mono tracking-widest uppercase">COMMAND DECK</span>
                    </div>
                </div>
                <button type="button" onclick="toggleAdminDrawer()" class="text-gray-400 hover:text-white p-1 rounded-lg">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <nav class="space-y-1.5 text-xs font-bold font-mono">
                <button onclick="switchAdminTab('tab-overview', 'Overview')" class="drawer-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-[#111b33] hover:text-cyan-300 transition text-left">
                    <i class="fa-solid fa-gauge-high w-4 text-cyan-400"></i><span>Overview</span>
                </button>
                <button onclick="switchAdminTab('tab-products', 'Products')" class="drawer-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-[#111b33] hover:text-cyan-300 transition text-left">
                    <i class="fa-solid fa-cubes w-4 text-emerald-400"></i><span>Products & Catalog</span>
                </button>
                <button onclick="switchAdminTab('tab-resellers', 'Resellers')" class="drawer-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-[#111b33] hover:text-cyan-300 transition text-left">
                    <i class="fa-solid fa-users-gear w-4 text-blue-400"></i><span>Resellers Hub</span>
                </button>
                <button onclick="switchAdminTab('tab-admins', 'Authorized Root Admins')" class="drawer-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-[#111b33] hover:text-cyan-300 transition text-left">
                    <i class="fa-solid fa-shield-halved w-4 text-amber-400"></i><span>Admins Registry</span>
                </button>
                <button onclick="switchAdminTab('tab-sales', 'Live License Feed')" class="drawer-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-[#111b33] hover:text-cyan-300 transition text-left">
                    <i class="fa-solid fa-satellite-dish w-4 text-fuchsia-400"></i><span>License Sales Feed</span>
                </button>
                <button onclick="switchAdminTab('tab-resets', 'HWID & Key Resets')" class="drawer-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-[#111b33] hover:text-cyan-300 transition text-left">
                    <i class="fa-solid fa-arrows-spin w-4 text-purple-400"></i><span>Key Resets (HWID)</span>
                </button>
                <button onclick="switchAdminTab('tab-gateways', 'Payment Gateway Pipeline')" class="drawer-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-[#111b33] hover:text-cyan-300 transition text-left">
                    <i class="fa-solid fa-money-bill-transfer w-4 text-orange-400"></i><span>SNZ Pay & Gateways</span>
                </button>
                <button onclick="switchAdminTab('tab-deposits', 'Deposits')" class="drawer-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-[#111b33] hover:text-cyan-300 transition text-left">
                    <i class="fa-solid fa-vault w-4 text-teal-400"></i><span>Deposits Ledger</span>
                </button>
                <button onclick="switchAdminTab('tab-logs', 'Security & Login Telemetry')" class="drawer-btn w-full flex items-center space-x-3 px-4 py-3 rounded-xl text-gray-300 hover:bg-[#111b33] hover:text-cyan-300 transition text-left">
                    <i class="fa-solid fa-fingerprint w-4 text-rose-400"></i><span>Audit & Geo Logs</span>
                </button>
            </nav>
        </div>

        <div class="pt-4 border-t border-cyber-border">
            <a href="admin.php?logout=1" class="w-full flex items-center justify-center space-x-2.5 py-3 rounded-xl bg-red-500/10 border border-red-500/25 text-red-400 hover:bg-red-500/20 text-xs font-bold font-mono transition">
                <i class="fa-solid fa-power-off"></i>
                <span>TERMINATE SESSION</span>
            </a>
        </div>
    </aside>

    <!-- TOP TELEMETRY NAVBAR -->
    <header class="w-full bg-[#080d1a]/90 border-b border-cyber-border px-4 py-3.5 sticky top-0 z-30 backdrop-blur-xl">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center space-x-3.5">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-cyan-500 via-blue-600 to-indigo-600 flex items-center justify-center text-white text-lg font-black shadow-lg shadow-cyan-500/30">
                    <i class="fa-solid fa-bolt-lightning"></i>
                </div>
                <div>
                    <div class="flex items-center space-x-2.5">
                        <h1 class="text-base font-black text-white tracking-wider uppercase font-mono leading-none">NOC CONSOLE</h1>
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-widest bg-cyan-500/10 text-cyan-400 border border-cyan-500/30 font-mono">
                            ENTERPRISE PRO
                        </span>
                    </div>
                    <div class="flex items-center space-x-2 mt-1">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 pulsing-dot"></span>
                        <span id="headerSectionTitle" class="text-[11px] text-gray-400 font-mono font-medium">Overview</span>
                    </div>
                </div>
            </div>

            <div class="flex items-center space-x-3">
                <div class="hidden md:flex items-center px-3.5 py-1.5 rounded-xl bg-[#0c1326] border border-cyber-border text-xs font-mono">
                    <span class="text-gray-400 mr-2 flex items-center gap-1.5">
                        <i class="fa-solid fa-cloud-arrow-down text-cyan-400"></i> Provider:
                    </span>
                    <strong class="text-emerald-400 font-bold"><?= $providerBalance ?></strong>
                </div>

                <button type="button" onclick="toggleAdminDrawer()" class="w-10 h-10 flex items-center justify-center bg-[#0c1326] border border-cyan-500/30 hover:border-cyan-400 text-cyan-400 rounded-xl active:scale-95 transition shadow-lg shadow-cyan-950/40">
                    <i class="fa-solid fa-bars-staggered text-base"></i>
                </button>
            </div>
        </div>
    </header>

    <!-- MAIN BODY CONTAINER -->
    <main class="max-w-7xl mx-auto px-4 mt-6 space-y-6">

        <!-- ================================================================= -->
        <!-- TAB 1: TELEMETRY OVERVIEW (FINANCIAL RADAR, NOTICE BOARD & TILES) -->
        <!-- ================================================================= -->
        <section id="tab-overview" class="tab-section space-y-5">
            
            <!-- 0. BROADCAST NOTICE MANAGEMENT TERMINAL (Post & Delete) -->
            <div class="noc-card p-5 border border-amber-500/30 bg-gradient-to-r from-[#0d162d] via-[#080d1a] to-[#121c38] space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-cyber-border">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-2xl bg-amber-500/20 text-amber-400 flex items-center justify-center text-lg border border-amber-500/40 animate-pulse">
                            <i class="fa-solid fa-bullhorn"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-white font-mono uppercase tracking-wider">Broadcast Notice</h3>
                        </div>
                    </div>

                    <?php if (!empty($settings['broadcast_notice'])): ?>
                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 font-mono flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span> Live Active Notice
                        </span>
                    <?php else: ?>
                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-gray-500/20 text-gray-400 border border-gray-500/30 font-mono">
                            No Notice Active
                        </span>
                    <?php endif; ?>
                </div>

                <form method="POST" class="space-y-3">
                    <input type="hidden" name="admin_action" value="update_broadcast_notice">
                    <input type="hidden" name="active_tab" value="tab-overview">
                    
                    <textarea name="broadcast_notice" rows="2" placeholder="Write broadcast message for users here..." class="w-full bg-[#040711] border border-cyber-border p-3.5 rounded-2xl text-xs text-amber-200 font-sans outline-none focus:border-amber-400 leading-relaxed"><?= htmlspecialchars($settings['broadcast_notice'] ?? '') ?></textarea>
                    
                    <div class="flex items-center justify-between gap-2 flex-wrap">
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-orange-500 hover:opacity-95 text-gray-950 font-black text-xs uppercase font-mono tracking-wider shadow-md transition flex items-center gap-2">
                            <i class="fa-solid fa-paper-plane"></i> Deploy Notice
                        </button>

                        <?php if (!empty($settings['broadcast_notice'])): ?>
                            <button type="submit" formaction="admin.php" onclick="this.form.admin_action.value='delete_broadcast_notice';" class="px-4 py-2.5 rounded-xl bg-red-500/15 border border-red-500/30 text-red-400 hover:bg-red-500/25 text-xs font-bold font-mono transition flex items-center gap-1.5">
                                <i class="fa-solid fa-trash-can"></i> Delete Notice
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- 1. HIGH-TECH FINANCIAL TELEMETRY HERO MATRIX -->
            <div class="fin-telemetry-box p-6 space-y-5">
                <div class="flex flex-wrap items-center justify-between gap-4 pb-4 border-b border-cyber-border">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-2xl bg-cyan-500/15 border border-cyan-500/30 text-cyan-400 flex items-center justify-center text-lg">
                            <i class="fa-solid fa-chart-line"></i>
                        </div>
                        <div>
                            <h2 class="text-sm font-extrabold text-white uppercase tracking-wider font-mono">Revenue</h2>
                        </div>
                    </div>
                </div>

                <!-- Financial Metric Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 font-mono">
                    <div class="bg-[#080d1a]/80 border border-cyan-500/30 rounded-2xl p-4 flex flex-col justify-between">
                        <div class="flex items-center justify-between text-xs text-gray-400 pb-2">
                            <span class="uppercase tracking-wider flex items-center gap-1.5 text-cyan-300">
                                <i class="fa-solid fa-sun text-cyan-400"></i> Today Revenue
                            </span>
                        </div>
                        <div class="mt-2">
                            <h3 class="text-2xl sm:text-3xl font-black text-cyan-300">$<?= number_format((float)$salesTodayUSD, 2) ?></h3>
                        </div>
                    </div>

                    <div class="bg-[#080d1a]/80 border border-blue-500/30 rounded-2xl p-4 flex flex-col justify-between">
                        <div class="flex items-center justify-between text-xs text-gray-400 pb-2">
                            <span class="uppercase tracking-wider flex items-center gap-1.5 text-blue-300">
                                <i class="fa-solid fa-calendar-week text-blue-400"></i> This Week Revenue
                            </span>
                        </div>
                        <div class="mt-2">
                            <h3 class="text-2xl sm:text-3xl font-black text-blue-300">$<?= number_format((float)$salesWeekUSD, 2) ?></h3>
                        </div>
                    </div>

                    <div class="bg-[#080d1a]/80 border border-emerald-500/30 rounded-2xl p-4 flex flex-col justify-between">
                        <div class="flex items-center justify-between text-xs text-gray-400 pb-2">
                            <span class="uppercase tracking-wider flex items-center gap-1.5 text-emerald-300">
                                <i class="fa-solid fa-calendar-days text-emerald-400"></i> This Month Revenue
                            </span>
                            <span class="text-[10px] bg-emerald-500/15 text-emerald-300 px-2 py-0.5 rounded border border-emerald-500/25"><?= date('F') ?></span>
                        </div>
                        <div class="mt-2">
                            <h3 class="text-2xl sm:text-3xl font-black text-emerald-300">$<?= number_format((float)$salesMonthUSD, 2) ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. OPERATIONAL DRILL-DOWN TILES -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 font-mono">
                <div onclick="openTodayKeysModal()" class="noc-card noc-card-interactive p-5 cursor-pointer border border-amber-500/40 bg-gradient-to-br from-[#0c1426] to-[#121c38]">
                    <div class="flex items-center justify-between">
                        <div class="w-10 h-10 rounded-2xl bg-amber-500/15 text-amber-400 flex items-center justify-center text-base border border-amber-500/30">
                            <i class="fa-solid fa-key"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <span class="text-xs text-gray-400 font-semibold block font-sans">Today's Keys</span>
                        <div class="flex items-baseline space-x-2 mt-1">
                            <h2 class="text-3xl font-black text-amber-400"><?= $todayKeysCount ?></h2>
                            <span class="text-xs text-gray-500 font-bold">Keys Generated</span>
                        </div>
                    </div>
                </div>

                <div onclick="openTodayDepositsModal()" class="noc-card noc-card-interactive p-5 cursor-pointer border border-teal-500/40 bg-gradient-to-br from-[#0c1426] to-[#0a202e]">
                    <div class="flex items-center justify-between">
                        <div class="w-10 h-10 rounded-2xl bg-teal-500/15 text-teal-400 flex items-center justify-center text-base border border-teal-500/30">
                            <i class="fa-solid fa-vault"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <span class="text-xs text-gray-400 font-semibold block font-sans">Today's Deposits</span>
                        <div class="flex items-baseline space-x-2 mt-1">
                            <h2 class="text-3xl font-black text-teal-300">$<?= number_format((float)$todayDepositsUSD, 2) ?></h2>
                            <span class="text-xs text-gray-500 font-bold">(<?= $todayDepositsCount ?> reqs)</span>
                        </div>
                    </div>
                </div>

                <div class="noc-card p-5 border border-emerald-500/30">
                    <div class="flex items-center justify-between">
                        <div class="w-10 h-10 rounded-2xl bg-emerald-500/15 text-emerald-400 flex items-center justify-center text-base border border-emerald-500/20">
                            <i class="fa-solid fa-wallet"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <span class="text-xs text-gray-400 font-semibold block font-sans">Balance</span>
                        <h2 class="text-3xl font-black text-emerald-400 mt-1">$<?= number_format((float)$totalFunds, 2) ?></h2>
                    </div>
                </div>

                <div onclick="switchAdminTab('tab-deposits', 'Deposits')" class="noc-card noc-card-interactive p-5 cursor-pointer border border-rose-500/30">
                    <div class="flex items-center justify-between">
                        <div class="w-10 h-10 rounded-2xl bg-rose-500/15 text-rose-400 flex items-center justify-center text-base border border-rose-500/20">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                        </div>
                    </div>
                    <div class="mt-4">
                        <span class="text-xs text-gray-400 font-semibold block font-sans">Pending</span>
                        <h2 class="text-3xl font-black text-rose-400 mt-1"><?= $pendingTrxCount ?></h2>
                    </div>
                </div>
            </div>

            <!-- 3. LIVE CATALOG TELEMETRY & SYNC PIPELINE -->
            <div class="noc-card p-5 flex flex-wrap items-center justify-between gap-4 border border-cyan-500/30 bg-gradient-to-r from-[#0a1122] to-[#0c1730]">
                <div class="flex items-center space-x-3.5">
                    <div class="w-12 h-12 rounded-2xl bg-cyan-500/15 text-cyan-400 flex items-center justify-center text-2xl border border-cyan-500/30">
                        <i class="fa-solid fa-arrows-rotate"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-white font-mono uppercase tracking-wider">Provider Sync</h3>
                    </div>
                </div>
                <form method="POST" class="inline">
                    <input type="hidden" name="admin_action" value="sync_provider_products">
                    <input type="hidden" name="active_tab" value="tab-products">
                    <button type="submit" class="px-5 py-3 rounded-xl bg-gradient-to-r from-cyan-400 via-teal-400 to-emerald-400 text-gray-950 font-black text-xs uppercase font-mono shadow-lg shadow-cyan-950/50 hover:opacity-95 transition flex items-center gap-2">
                        <i class="fa-solid fa-rotate text-sm"></i>
                        <span>Sync All from Provider</span>
                    </button>
                </form>
            </div>

        </section>

        <!-- ================================================================= -->
        <!-- TAB 2: PRODUCTS & CATALOG MATRIX (WITH GLOBAL DISCOUNT & STOCK)   -->
        <!-- ================================================================= -->
        <section id="tab-products" class="tab-section hidden space-y-5">
            
            <!-- GLOBAL OVERRIDE DISCOUNT BANNER -->
            <div class="noc-card p-4 border border-emerald-500/30 flex flex-col sm:flex-row items-center justify-between gap-3 bg-gradient-to-r from-[#091624] to-[#080d1a]">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-2xl bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 flex items-center justify-center text-lg">
                        <i class="fa-solid fa-tags"></i>
                    </div>
                    <div>
                        <h4 class="text-sm font-extrabold text-white flex items-center gap-2 font-mono">
                            <span>Global Discount</span>
                            <span class="text-[10px] font-mono bg-emerald-500/20 text-emerald-300 px-2 py-0.5 rounded-full font-bold">1-Click Apply</span>
                        </h4>
                    </div>
                </div>

                <div class="flex items-center space-x-2 w-full sm:w-auto">
                    <form method="POST" class="flex items-center space-x-2 w-full sm:w-auto">
                        <input type="hidden" name="admin_action" value="update_global_discount">
                        <input type="hidden" name="active_tab" value="tab-products">
                        <div class="relative flex-1 sm:w-32">
                            <input type="number" min="0" max="100" name="global_discount_percent" placeholder="e.g. 20" required class="w-full bg-[#040711] border border-cyber-border px-3.5 py-2.5 rounded-xl text-xs font-mono font-bold text-emerald-400 outline-none focus:border-emerald-400 pr-7">
                            <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-500 text-xs font-bold font-mono">%</span>
                        </div>
                        <button type="submit" class="px-4 py-2.5 bg-gradient-to-r from-emerald-500 to-teal-400 hover:opacity-95 text-gray-950 font-black rounded-xl text-xs uppercase font-mono transition shadow-md whitespace-nowrap">
                            Inject
                        </button>
                    </form>

                    <form method="POST" class="inline" onsubmit="return confirm('Clear discount parameter from all products?');">
                        <input type="hidden" name="admin_action" value="clear_global_discount">
                        <input type="hidden" name="active_tab" value="tab-products">
                        <button type="submit" class="px-3.5 py-2.5 bg-red-500/10 border border-red-500/30 text-red-400 hover:bg-red-500/20 font-bold rounded-xl text-xs font-mono whitespace-nowrap" title="Reset Global Discounts">
                            Reset (0%)
                        </button>
                    </form>
                </div>
            </div>

            <!-- Product Sub-navigation Tabs -->
            <div class="flex space-x-1.5 bg-[#080d1a] p-1.5 rounded-2xl border border-cyber-border text-xs font-mono font-bold">
                <button type="button" onclick="switchProductSubTab('prod-list')" id="subtab-btn-list" class="flex-1 py-2.5 rounded-xl text-gray-950 bg-gradient-to-r from-cyan-400 via-teal-400 to-emerald-400 transition text-center shadow-md">
                    <i class="fa-solid fa-boxes-stacked mr-1.5"></i> Catalog Matrix (<?= count($productsData) ?>)
                </button>
                <button type="button" onclick="switchProductSubTab('prod-add')" id="subtab-btn-add" class="flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center">
                    <i class="fa-solid fa-plus mr-1.5"></i> + Create Product
                </button>
                <button type="button" onclick="switchProductSubTab('prod-stock')" id="subtab-btn-stock" class="flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center">
                    <i class="fa-solid fa-cubes-stacked mr-1.5 text-amber-400"></i> Stock Vault & Keys
                </button>
            </div>

            <!-- SUB-SECTION 1: CATALOG MATRIX -->
            <div id="prod-list" class="space-y-4">
                <div class="noc-card p-4 flex flex-wrap items-center justify-between gap-3 border border-cyber-border">
                    <div class="flex items-center space-x-2.5">
                        <span class="w-8 h-8 rounded-xl bg-cyan-500/15 text-cyan-400 flex items-center justify-center text-sm border border-cyan-500/20">
                            <i class="fa-solid fa-network-wired"></i>
                        </span>
                        <div>
                            <h4 class="text-xs font-extrabold text-white font-mono uppercase">Catalog</h4>
                        </div>
                    </div>

                    <div class="flex items-center space-x-2">
                        <form method="POST" class="inline">
                            <input type="hidden" name="admin_action" value="sync_provider_products">
                            <input type="hidden" name="active_tab" value="tab-products">
                            <button type="submit" class="px-3.5 py-2 rounded-xl bg-cyan-500 hover:bg-cyan-400 text-gray-950 font-black text-xs uppercase font-mono flex items-center gap-1.5 shadow-md transition" title="Sync All Products">
                                <i class="fa-solid fa-rotate text-xs"></i> Sync All
                            </button>
                        </form>

                        <button type="button" onclick="openSelectProviderModal()" class="px-3.5 py-2 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-400 hover:opacity-95 text-gray-950 font-black text-xs uppercase font-mono flex items-center gap-1.5 shadow-md transition">
                            <i class="fa-solid fa-list-check text-xs"></i> Selective Import
                        </button>

                        <form method="POST" class="inline" onsubmit="return confirm('CRITICAL WARNING: Are you sure you want to permanently purge ALL catalog products, plans, and inventory keys?');">
                            <input type="hidden" name="admin_action" value="delete_all_products">
                            <input type="hidden" name="active_tab" value="tab-products">
                            <button type="submit" class="px-3.5 py-2 rounded-xl bg-red-500/15 border border-red-500/30 text-red-400 hover:bg-red-500/25 font-bold text-xs uppercase font-mono flex items-center gap-1.5 transition">
                                <i class="fa-solid fa-trash-can text-xs"></i> Purge All
                            </button>
                        </form>
                    </div>
                </div>

                <div class="space-y-3">
                    <?php if (empty($productsData)): ?>
                        <div class="noc-card p-10 text-center text-gray-500 text-xs font-mono">
                            No active products registered in catalog matrix. Utilize <b>"Sync All"</b> or <b>"Selective Import"</b> to populate.
                        </div>
                    <?php else: ?>
                        <?php foreach ($productsData as $prod): ?>
                            <div class="noc-card p-4 space-y-3">
                                <div class="flex items-center justify-between cursor-pointer" onclick="toggleProductAccordion('prod-acc-<?= $prod['id'] ?>', this)">
                                    <div class="flex items-center space-x-3">
                                        <span class="w-8 h-8 rounded-xl bg-cyan-500/15 text-cyan-300 border border-cyan-500/30 flex items-center justify-center font-mono font-bold text-xs" title="Sorting Priority Serial">
                                            #<?= (int)($prod['sort_order'] ?? 0) ?>
                                        </span>
                                        <div>
                                            <h4 class="font-bold text-white text-sm leading-tight flex items-center gap-2">
                                                <span><?= htmlspecialchars($prod['name']) ?></span>
                                                <?php if (!empty($prod['discount_percent']) && (int)$prod['discount_percent'] > 0): ?>
                                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-black bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 font-mono">
                                                        <?= (int)$prod['discount_percent'] ?>% OFF
                                                    </span>
                                                <?php endif; ?>
                                                <button type="button" onclick="event.stopPropagation(); openEditProdModal(<?= $prod['id'] ?>, '<?= htmlspecialchars(addslashes($prod['name'])) ?>', <?= (int)$prod['sort_order'] ?>, <?= (int)($prod['discount_percent'] ?? 0) ?>)" class="text-gray-400 hover:text-cyan-300 p-1" title="Modify Product Name & Discount">
                                                    <i class="fa-solid fa-pen-to-square text-xs"></i>
                                                </button>
                                            </h4>
                                            <span class="text-[10px] text-gray-400 font-mono mt-0.5 block">
                                                <?= count($prod['plans']) ?> Validity Tier(s) Linked
                                            </span>
                                        </div>
                                    </div>

                                    <div class="flex items-center space-x-2" onclick="event.stopPropagation()">
                                        <button type="button" onclick="openAttachPlanModal(<?= $prod['id'] ?>, '<?= htmlspecialchars(addslashes($prod['name'])) ?>')" class="px-3 py-1.5 rounded-xl bg-cyan-500/15 text-cyan-300 hover:bg-cyan-500/25 text-xs font-bold font-mono flex items-center gap-1.5 border border-cyan-500/30">
                                            <i class="fa-solid fa-plus text-[10px]"></i> Add Plan
                                        </button>
                                        <form method="POST" class="inline" onsubmit="return confirm('Purge entire product and all associated validities?');">
                                            <input type="hidden" name="admin_action" value="delete_entire_product">
                                            <input type="hidden" name="active_tab" value="tab-products">
                                            <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                                            <button type="submit" class="w-8 h-8 rounded-xl bg-red-500/15 text-red-400 hover:bg-red-500/25 flex items-center justify-center text-xs border border-red-500/30" title="Delete Product">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                        <div class="w-7 h-7 flex items-center justify-center text-gray-400">
                                            <i class="fa-solid fa-chevron-down text-xs transition-transform duration-200"></i>
                                        </div>
                                    </div>
                                </div>

                                <div id="prod-acc-<?= $prod['id'] ?>" class="hidden pt-3 border-t border-cyber-border space-y-2">
                                    <?php if (empty($prod['plans'])): ?>
                                        <p class="text-[11px] text-gray-500 italic py-2 font-mono">No validity tiers configured for this node. Utilize "+ Add Plan" to link.</p>
                                    <?php else: ?>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-xs font-mono">
                                            <?php foreach ($prod['plans'] as $pl): ?>
                                                <div class="bg-[#080d1a] border border-cyber-border p-3 rounded-xl flex items-center justify-between">
                                                    <div>
                                                        <div class="flex items-center space-x-2 flex-wrap">
                                                            <strong class="text-white text-xs font-sans"><?= htmlspecialchars($pl['plan_title']) ?></strong>
                                                            <span class="text-[10px] text-amber-300 font-bold bg-amber-500/10 px-1.5 py-0.5 rounded border border-amber-500/20">
                                                                ID: #<?= (int)$pl['api_plan_id'] ?>
                                                            </span>
                                                            <span class="text-[9px] text-emerald-400 bg-emerald-500/10 px-1.5 py-0.5 rounded border border-emerald-500/20 font-bold">
                                                                Vault: <?= (int)($pl['stock_count'] ?? 0) ?> Keys
                                                            </span>
                                                            <?php if (!empty($pl['discount_percent']) && (int)$pl['discount_percent'] > 0): ?>
                                                                <span class="text-[9px] text-emerald-400 font-bold">
                                                                    (<?= (int)$pl['discount_percent'] ?>% Off)
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="text-[11px] text-gray-400 mt-1.5">
                                                            User Price: <span class="text-gray-300 font-bold">$<?= number_format((float)($pl['user_price'] ?? $pl['reseller_price']), 2) ?></span> • 
                                                            Reseller: <span class="text-emerald-400 font-extrabold">$<?= number_format((float)$pl['reseller_price'], 2) ?></span>
                                                        </div>
                                                    </div>
                                                    <div class="flex items-center space-x-1.5">
                                                        <button type="button" onclick="openEditPlanModal(<?= htmlspecialchars(json_encode(array_merge($pl, ['product_name' => $prod['name']]))) ?>)" class="p-1.5 rounded-lg text-gray-400 hover:text-cyan-300">
                                                            <i class="fa-regular fa-pen-to-square text-sm"></i>
                                                        </button>
                                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this validity plan configuration?');">
                                                            <input type="hidden" name="admin_action" value="delete_validity_plan">
                                                            <input type="hidden" name="active_tab" value="tab-products">
                                                            <input type="hidden" name="plan_id" value="<?= $pl['id'] ?>">
                                                            <button type="submit" class="p-1.5 rounded-lg text-gray-400 hover:text-red-400">
                                                                <i class="fa-solid fa-trash text-xs"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SUB-SECTION 2: CREATE PRODUCT FORM -->
            <div id="prod-add" class="hidden noc-card p-6 space-y-4">
                <div class="flex justify-between items-center pb-3 border-b border-cyber-border">
                    <div>
                        <h3 class="text-sm font-bold text-cyan-400 font-mono flex items-center gap-2">
                            <i class="fa-solid fa-circle-plus"></i> Add Product
                        </h3>
                    </div>
                    <button type="button" onclick="addValidityRow()" class="px-3.5 py-2 rounded-xl bg-cyan-500/20 text-cyan-300 border border-cyan-500/40 text-xs font-bold font-mono hover:bg-cyan-500/30 flex items-center gap-1.5">
                        <i class="fa-solid fa-plus"></i> + Add Duration Row
                    </button>
                </div>

                <form method="POST" class="space-y-4 text-xs font-sans">
                    <input type="hidden" name="admin_action" value="save_multi_validity_product">
                    <input type="hidden" name="active_tab" value="tab-products">

                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3.5">
                        <div class="sm:col-span-2">
                            <label class="text-gray-400 block mb-1 font-semibold">Product Title</label>
                            <input type="text" name="new_product_name" required placeholder="e.g. 8BP Ezteam Android" class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-white outline-none focus:border-cyan-400">
                        </div>
                        <div>
                            <label class="text-gray-400 block mb-1 font-semibold">Priority Sort Order (#)</label>
                            <input type="number" name="sort_order" value="<?= count($productsData) + 1 ?>" required class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-cyan-300 font-mono font-bold outline-none focus:border-cyan-400">
                        </div>
                        <div>
                            <label class="text-emerald-400 block mb-1 font-semibold">Discount Factor (%)</label>
                            <input type="number" min="0" max="100" name="product_discount_percent" value="0" placeholder="0" class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-emerald-400 font-mono font-bold outline-none focus:border-emerald-400">
                        </div>
                    </div>

                    <div id="validityRowsContainer" class="space-y-3">
                        <div class="validity-row bg-[#080d1a] p-4 rounded-2xl border border-cyber-border space-y-3 relative">
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div>
                                    <label class="text-gray-400 block mb-1 font-semibold">Plan Label</label>
                                    <input type="text" name="plan_title[]" placeholder="e.g. 1 Days" required class="w-full bg-[#0c1326] border border-cyber-border p-2.5 rounded-xl text-white outline-none focus:border-cyan-400">
                                </div>
                                <div>
                                    <label class="text-gray-400 block mb-1 font-semibold">Duration Value</label>
                                    <input type="number" name="duration_value[]" min="1" value="1" required class="w-full bg-[#0c1326] border border-cyber-border p-2.5 rounded-xl text-white font-mono outline-none focus:border-cyan-400">
                                </div>
                                <div>
                                    <label class="text-gray-400 block mb-1 font-semibold">Temporal Unit</label>
                                    <select name="duration_unit[]" class="w-full bg-[#0c1326] border border-cyber-border p-2.5 rounded-xl text-cyan-300 font-mono font-bold outline-none">
                                        <option value="hours">Hours</option>
                                        <option value="days" selected>Days</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-amber-400 block mb-1 font-bold font-mono">Provider Plan ID</label>
                                    <input type="number" name="api_plan_id[]" placeholder="e.g. 59" class="w-full bg-[#0c1326] border border-cyber-border p-2.5 rounded-xl text-amber-300 font-mono font-bold outline-none focus:border-amber-400">
                                </div>
                                <div>
                                    <label class="text-fuchsia-400 block mb-1 font-bold font-mono">Reset API</label>
                                    <select name="reset_api_id[]" class="w-full bg-[#0c1326] border border-cyber-border p-2.5 rounded-xl text-fuchsia-300 font-mono font-bold outline-none focus:border-fuchsia-400"><?= resetApiOptionsHtml(0) ?></select>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="text-cyan-300 block mb-1 font-bold font-mono">End-User Benchmark Price ($)</label>
                                    <input type="number" step="0.01" name="user_price[]" placeholder="e.g. 5.00" required class="w-full bg-[#0c1326] border border-cyber-border p-2.5 rounded-xl text-white font-mono font-bold outline-none focus:border-cyan-400">
                                </div>
                                <div>
                                    <label class="text-fuchsia-400 block mb-1 font-bold font-mono">Reseller Wholesale Discount (%)</label>
                                    <input type="number" step="0.1" min="0" max="100" name="reseller_discount_percent[]" value="0" class="w-full bg-[#0c1326] border border-cyber-border p-2.5 rounded-xl text-fuchsia-300 font-mono font-bold outline-none focus:border-fuchsia-400">
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="w-full py-3.5 bg-gradient-to-r from-cyan-400 via-teal-400 to-emerald-400 text-gray-950 font-black rounded-xl text-xs uppercase font-mono tracking-wider transition">
                        + Deploy Product Node & Validities
                    </button>
                </form>
            </div>

            <!-- SUB-SECTION 3: STOCK VAULT & BULK INVENTORY -->
            <div id="prod-stock" class="hidden noc-card p-6 space-y-6">
                <div class="pb-3 border-b border-cyber-border">
                    <h3 class="text-sm font-bold text-amber-400 font-mono flex items-center gap-2">
                        <i class="fa-solid fa-boxes-packing"></i> Stock Keys
                    </h3>
                </div>

                <form method="POST" class="space-y-4 text-xs font-sans">
                    <input type="hidden" name="admin_action" value="save_stock_keys">
                    <input type="hidden" name="active_tab" value="tab-products">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="text-gray-400 block mb-1 font-semibold">1. Select Target Product Node</label>
                            <select id="stockProductSelect" name="stock_product_id" onchange="onStockProductSelectChange()" required class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-cyan-300 font-bold font-mono outline-none focus:border-cyan-400">
                                <option value="">-- Choose Target Product --</option>
                                <?php foreach ($productsData as $pd): ?>
                                    <option value="<?= $pd['id'] ?>"><?= htmlspecialchars($pd['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="text-gray-400 block mb-1 font-semibold">2. Select Target Validity Tier</label>
                            <select id="stockPlanSelect" name="stock_plan_id" required class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-amber-300 font-bold font-mono outline-none focus:border-cyan-400">
                                <option value="">-- First Select Product Above --</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="text-gray-300 block mb-1 font-bold font-mono uppercase tracking-wider">
                            Paste License Serial Keys (One Key Per Line):
                        </label>
                        <textarea name="bulk_keys" rows="6" required placeholder="XREG-1111-2222-3333&#10;XREG-4444-5555-6666&#10;XREG-7777-8888-9999" class="w-full bg-[#040711] border border-cyber-border p-3.5 rounded-2xl text-emerald-300 font-mono text-xs outline-none focus:border-amber-400 leading-relaxed"></textarea>
                    </div>

                    <button type="submit" class="w-full py-3.5 bg-gradient-to-r from-amber-500 to-orange-500 text-gray-950 font-black rounded-xl text-xs uppercase font-mono tracking-wider transition hover:opacity-95 shadow-lg shadow-amber-950/40">
                        <i class="fa-solid fa-arrow-down-to-bracket mr-1.5"></i> Ingest Keys into Physical Vault
                    </button>
                </form>

                <!-- Stock Inventory Summary Table -->
                <div class="pt-4 border-t border-cyber-border space-y-3">
                    <h4 class="text-xs font-bold text-white uppercase tracking-wider font-mono flex items-center gap-2">
                        <i class="fa-solid fa-warehouse text-cyan-400"></i> Stock Levels
                    </h4>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs font-mono">
                            <thead>
                                <tr class="text-gray-400 border-b border-cyber-border">
                                    <th class="pb-2.5">Product Title</th>
                                    <th class="pb-2.5">Validity Tier</th>
                                    <th class="pb-2.5">Available Vault Inventory</th>
                                    <th class="pb-2.5 text-right">Emergency Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-cyber-border">
                                <?php 
                                $hasAnyStock = false;
                                foreach ($productsData as $prItem):
                                    foreach ($prItem['plans'] as $plItem):
                                        if (($plItem['stock_count'] ?? 0) > 0):
                                            $hasAnyStock = true;
                                ?>
                                    <tr>
                                        <td class="py-2.5 font-bold text-white font-sans"><?= htmlspecialchars($prItem['name']) ?></td>
                                        <td class="py-2.5 text-cyan-300"><?= htmlspecialchars($plItem['plan_title']) ?></td>
                                        <td class="py-2.5 text-emerald-400 font-black">
                                            <i class="fa-solid fa-cube text-xs mr-1"></i> <?= $plItem['stock_count'] ?> Ready Keys
                                        </td>
                                        <td class="py-2.5 text-right">
                                            <form method="POST" class="inline" onsubmit="return confirm('Purge all available stock for this plan?');">
                                                <input type="hidden" name="admin_action" value="clear_plan_stock">
                                                <input type="hidden" name="active_tab" value="tab-products">
                                                <input type="hidden" name="target_plan_id" value="<?= $plItem['id'] ?>">
                                                <button type="submit" class="px-2.5 py-1 bg-red-500/15 border border-red-500/30 text-red-400 hover:bg-red-500/25 rounded text-[10px] font-bold">Purge Stock</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php 
                                        endif;
                                    endforeach;
                                endforeach;
                                if (!$hasAnyStock):
                                ?>
                                    <tr><td colspan="4" class="py-5 text-center text-gray-500 italic font-mono">Vault inventory depleted. All dispatches currently routed through provider API.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- DETAILED STOCK VAULT AUDIT -->
                <div class="pt-4 border-t border-cyber-border space-y-3">
                    <div class="flex items-center justify-between">
                        <h4 class="text-xs font-bold text-white uppercase tracking-wider font-mono flex items-center gap-2">
                            <i class="fa-solid fa-clock-rotate-left text-amber-400"></i> Stock Ledger
                        </h4>
                        <span class="text-[10px] font-mono text-gray-400">Audit Depth: <?= count($stockLogs) ?></span>
                    </div>

                    <div class="overflow-x-auto max-h-72 overflow-y-auto pr-1">
                        <table class="w-full text-left text-xs font-mono">
                            <thead>
                                <tr class="text-gray-400 border-b border-cyber-border">
                                    <th class="pb-2.5">Product / Plan</th>
                                    <th class="pb-2.5">License Key Serial</th>
                                    <th class="pb-2.5">Vault Status</th>
                                    <th class="pb-2.5">Dispatched To</th>
                                    <th class="pb-2.5 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-cyber-border text-[11px]">
                                <?php if (empty($stockLogs)): ?>
                                    <tr><td colspan="5" class="py-5 text-center text-gray-500 italic">No keys logged in vault history.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($stockLogs as $sl): ?>
                                        <tr class="hover:bg-[#0c1326]">
                                            <td class="py-2 font-sans font-bold text-white">
                                                <?= htmlspecialchars($sl['product_name'] ?? 'Product') ?> 
                                                <span class="text-cyan-300 font-mono text-[10px] block">(<?= htmlspecialchars($sl['plan_title'] ?? 'Plan') ?> | API #<?= (int)($sl['api_plan_id'] ?? 0) ?>)</span>
                                            </td>
                                            <td class="py-2 text-fuchsia-300 font-bold select-all tracking-wider text-[11px]"><?= htmlspecialchars($sl['license_key']) ?></td>
                                            <td class="py-2">
                                                <?php if ($sl['status'] === 'available'): ?>
                                                    <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
                                                        IN VAULT
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-red-500/20 text-red-400 border border-red-500/30">
                                                        DISPATCHED
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-2 font-sans text-gray-300">
                                                <?php if (!empty($sl['buyer_username'])): ?>
                                                    <strong class="text-white"><?= htmlspecialchars($sl['buyer_username']) ?></strong>
                                                    <span class="text-[9px] text-gray-500 font-mono block"><?= $sl['used_at'] ?></span>
                                                <?php else: ?>
                                                    <span class="text-gray-500 italic font-mono">Unassigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-2 text-right">
                                                <form method="POST" class="inline" onsubmit="return confirm('Purge this key entry?');">
                                                    <input type="hidden" name="admin_action" value="delete_single_stock_key">
                                                    <input type="hidden" name="active_tab" value="tab-products">
                                                    <input type="hidden" name="stock_key_id" value="<?= $sl['id'] ?>">
                                                    <button type="submit" class="p-1 rounded text-gray-400 hover:text-red-400" title="Delete Serial Key">
                                                        <i class="fa-solid fa-trash text-[10px]"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </section>

        <!-- ================================================================= -->
        <!-- TAB 3: RESELLERS COMMAND HUB (DIRECTORY, DOSSIER & BONUSES)       -->
        <!-- ================================================================= -->
        <section id="tab-resellers" class="tab-section hidden space-y-5">
            
            <!-- Reseller Command Sub-navigation -->
            <div class="flex space-x-1.5 bg-[#080d1a] p-1.5 rounded-2xl border border-cyber-border text-xs font-mono font-bold">
                <button type="button" onclick="switchResellerSubTab('reseller-list-sec')" id="subtab-btn-reseller-list" class="flex-1 py-2.5 rounded-xl text-gray-950 bg-gradient-to-r from-emerald-400 via-teal-400 to-cyan-400 transition text-center shadow-md">
                    <i class="fa-solid fa-users-gear mr-1.5"></i> Resellers Directory (<?= count($resellers) ?>)
                </button>
                <button type="button" onclick="switchResellerSubTab('reseller-add-sec')" id="subtab-btn-reseller-add" class="flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center">
                    <i class="fa-solid fa-user-plus mr-1.5"></i> + Provision Reseller
                </button>
                <button type="button" onclick="switchResellerSubTab('reseller-bonus-sec')" id="subtab-btn-reseller-bonus" class="flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center">
                    <i class="fa-solid fa-gift mr-1.5 text-amber-400"></i> Bonus Slabs (<?= count($depositBonuses) ?>)
                </button>
            </div>

            <!-- SUB-SECTION 1: RESELLERS HUB WITH LIVE SEARCH & DOSSIER TRIGGER -->
            <div id="reseller-list-sec" class="space-y-4">
                
                <!-- Live Search Radar -->
                <div class="relative">
                    <input type="text" id="resellerLiveSearch" oninput="filterResellerCards(this.value)" placeholder="Search by Username, Telegram @username, or Telegram UID..." class="w-full bg-[#080d1a] border border-cyber-border text-xs text-white rounded-2xl p-4 pl-11 outline-none focus:border-cyan-400 font-mono placeholder-gray-500 shadow-inner">
                    <div class="absolute left-4 top-1/2 -translate-y-1/2 text-cyan-400 pointer-events-none">
                        <i class="fa-solid fa-crosshairs text-sm"></i>
                    </div>
                </div>

                <!-- Resellers Card Grid / List -->
                <div id="resellerCardsContainer" class="space-y-3">
                    <?php if (empty($resellers)): ?>
                        <div class="noc-card p-10 text-center text-gray-500 text-xs font-mono">
                            No reseller accounts provisioned yet. Utilize <b>"+ Provision Reseller"</b> to onboard.
                        </div>
                    <?php else: ?>
                        <?php foreach($resellers as $u): ?>
                            <div class="noc-card noc-card-interactive reseller-item-card p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3.5"
                                 data-username="<?= strtolower(htmlspecialchars($u['username'])) ?>"
                                 data-tg-username="<?= strtolower(htmlspecialchars($u['telegram_username'] ?? '')) ?>"
                                 data-tg-id="<?= htmlspecialchars((string)($u['telegram_chat_id'] ?? '')) ?>">
                                
                                <div class="flex items-center space-x-3.5 overflow-hidden">
                                    <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-emerald-500/20 via-cyan-500/20 to-blue-500/20 text-emerald-400 border border-emerald-500/30 flex items-center justify-center font-extrabold text-base flex-shrink-0 font-mono">
                                        <?= strtoupper(substr($u['username'], 0, 1)) ?>
                                    </div>
                                    <div class="overflow-hidden">
                                        <div class="flex items-center space-x-2 flex-wrap gap-y-1">
                                            <h4 class="font-extrabold text-white text-sm truncate leading-none font-sans"><?= htmlspecialchars($u['username']) ?></h4>
                                            <span class="px-2 py-0.5 rounded text-[9px] font-black uppercase font-mono <?= $u['status'] === 'active' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'bg-red-500/20 text-red-400 border border-red-500/30' ?>">
                                                <?= $u['status'] ?>
                                            </span>
                                            <span class="px-2 py-0.5 rounded text-[9px] font-black uppercase font-mono <?= ((int)($u['api_enabled'] ?? 1) === 1) ? 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/30' : 'bg-gray-500/20 text-gray-400 border border-gray-500/30' ?>">
                                                API: <?= ((int)($u['api_enabled'] ?? 1) === 1) ? 'ACTIVE' : 'LOCKED' ?>
                                            </span>
                                            <?php if ((int)($u['sub_reseller_count'] ?? 0) > 0): ?>
                                                <span class="px-2 py-0.5 rounded text-[9px] font-bold font-mono bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                                    <?= (int)$u['sub_reseller_count'] ?> Sub(s)
                                                </span>
                                            <?php endif; ?>
                                            <?php if ((float)($u['custom_bdt_rate'] ?? 0) > 0): ?>
                                                <span class="px-2 py-0.5 rounded text-[9px] font-bold font-mono bg-purple-500/20 text-purple-300 border border-purple-500/30">
                                                    Rate: <?= $u['custom_bdt_rate'] ?>৳
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex items-center space-x-2.5 text-[11px] text-gray-400 font-mono mt-1.5 flex-wrap">
                                            <span>Balance: <strong class="text-emerald-400 font-bold">$<?= number_format((float)$u['balance'], 2) ?></strong></span>
                                            <span class="text-gray-600">•</span>
                                            <span>Keys: <strong class="text-cyan-300"><?= (int)($u['total_sold_keys'] ?? 0) ?></strong></span>
                                            <?php if (!empty($u['telegram_chat_id'])): ?>
                                                <span class="text-gray-600">•</span>
                                                <span class="text-amber-300 font-bold">TG: #<?= $u['telegram_chat_id'] ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="flex items-center space-x-2 self-end sm:self-center flex-shrink-0">
                                    <button type="button" onclick="openResellerDossierModal(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['username'])) ?>')" class="px-3 py-2 rounded-xl bg-purple-500/15 border border-purple-500/30 text-purple-300 hover:bg-purple-500/25 flex items-center gap-1.5 text-xs font-mono font-bold transition shadow-sm" title="View Full Reseller Ledger & History">
                                        <i class="fa-solid fa-clock-rotate-left text-xs"></i>
                                        <span>Dossier</span>
                                    </button>

                                    <!-- Edit Icon: Opens Reseller Pricing, Subs & Control -->
                                    <button type="button" onclick="openResellerManageModal(<?= htmlspecialchars(json_encode($u)) ?>)" class="px-3.5 py-2 rounded-xl bg-gradient-to-r from-cyan-500 to-blue-600 text-gray-950 font-black text-xs font-mono hover:opacity-95 flex items-center gap-1.5 shadow-md shadow-cyan-950/40 transition" title="Edit Reseller Parameters, Prices & Sub-Accounts">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                        <span>Manage & Prices</span>
                                    </button>

                                    <form method="POST" class="inline" onsubmit="return confirm('CRITICAL: Terminate reseller account <?= htmlspecialchars(addslashes($u['username'])) ?> permanently?');">
                                        <input type="hidden" name="admin_action" value="delete_reseller">
                                        <input type="hidden" name="active_tab" value="tab-resellers">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="w-9 h-9 rounded-xl bg-red-500/15 border border-red-500/30 text-red-400 hover:bg-red-500/25 flex items-center justify-center text-xs transition" title="Purge Reseller">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SUB-SECTION 2: PROVISION NEW RESELLER FORM -->
            <div id="reseller-add-sec" class="hidden noc-card p-6 space-y-4">
                <div class="pb-3 border-b border-cyber-border">
                    <h3 class="text-sm font-bold text-cyan-400 font-mono flex items-center gap-2">
                        <i class="fa-solid fa-user-plus"></i> New Reseller
                    </h3>
                </div>

                <form method="POST" class="space-y-4 text-xs font-sans">
                    <input type="hidden" name="admin_action" value="create_reseller">
                    <input type="hidden" name="active_tab" value="tab-resellers">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="text-gray-400 block mb-1 font-semibold">Reseller Username</label>
                            <input type="text" name="username" placeholder="e.g. cyber_operator" required class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-white outline-none focus:border-cyan-400 font-mono">
                        </div>
                        <div>
                            <label class="text-gray-400 block mb-1 font-semibold">Access Password</label>
                            <input type="password" name="password" placeholder="••••••••" required class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-white outline-none focus:border-cyan-400 font-mono">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <div>
                            <label class="text-emerald-400 block mb-1 font-semibold font-mono">Initial Liquidity Balance ($ USD)</label>
                            <input type="number" step="0.5" name="balance" placeholder="0.00" value="0.00" class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-emerald-300 font-mono font-bold outline-none focus:border-emerald-400">
                        </div>
                        <div>
                            <label class="text-cyan-300 block mb-1 font-semibold font-mono">Custom Exchange Rate (BDT/USD)</label>
                            <input type="number" step="0.5" name="custom_bdt_rate" placeholder="Leave empty for global (125)" class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-cyan-300 font-mono outline-none focus:border-cyan-400">
                        </div>
                    </div>

                    <button type="submit" class="w-full py-3.5 bg-gradient-to-r from-emerald-400 via-teal-400 to-cyan-400 text-gray-950 font-black rounded-xl text-xs uppercase font-mono tracking-wider transition hover:opacity-95 shadow-lg shadow-cyan-950/40">
                        + Deploy Reseller Credentials
                    </button>
                </form>
            </div>

            <!-- SUB-SECTION 3: DEPOSIT BONUS SLABS -->
            <div id="reseller-bonus-sec" class="hidden noc-card p-6 space-y-6">
                <div class="pb-3 border-b border-cyber-border">
                    <h3 class="text-sm font-bold text-amber-400 font-mono flex items-center gap-2">
                        <i class="fa-solid fa-gift"></i> Deposit Bonus
                    </h3>
                </div>

                <!-- Add Bonus Slab Form -->
                <form method="POST" class="bg-[#080d1a] p-4 rounded-2xl border border-amber-500/30 space-y-3.5 text-xs font-sans">
                    <input type="hidden" name="admin_action" value="add_deposit_bonus">
                    <input type="hidden" name="active_tab" value="tab-resellers">

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3.5">
                        <div>
                            <label class="text-gray-300 block mb-1 font-semibold font-mono">Minimum Deposit ($ USD)</label>
                            <input type="number" step="0.1" name="min_amount" placeholder="e.g. 5.00" required class="w-full bg-[#040711] border border-cyber-border p-2.5 rounded-xl text-emerald-400 font-mono font-bold outline-none focus:border-amber-400">
                        </div>
                        <div>
                            <label class="text-gray-300 block mb-1 font-semibold font-mono">Incentive Reward Value</label>
                            <input type="number" step="0.01" name="bonus_amount" placeholder="e.g. 0.50 or 10" required class="w-full bg-[#040711] border border-cyber-border p-2.5 rounded-xl text-amber-300 font-mono font-bold outline-none focus:border-amber-400">
                        </div>
                        <div>
                            <label class="text-gray-300 block mb-1 font-semibold font-mono">Calculation Mode</label>
                            <select name="bonus_type" class="w-full bg-[#040711] border border-cyber-border p-2.5 rounded-xl text-cyan-300 font-mono font-bold outline-none">
                                <option value="fixed" selected>Fixed Dollar Amount ($ USD)</option>
                                <option value="percent">Percentage Factor (%)</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" class="w-full py-3 bg-gradient-to-r from-amber-500 to-orange-500 text-gray-950 font-black rounded-xl text-xs uppercase font-mono tracking-wider transition shadow-md hover:opacity-95">
                        + Deploy Incentive Slab
                    </button>
                </form>

                <!-- Active Bonus Slabs Table -->
                <div class="space-y-3">
                    <h4 class="text-xs font-bold text-white uppercase tracking-wider font-mono flex items-center gap-2">
                        <i class="fa-solid fa-table-list text-cyan-400"></i> Bonus Slabs
                    </h4>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs font-mono">
                            <thead>
                                <tr class="text-gray-400 border-b border-cyber-border">
                                    <th class="pb-2.5">Minimum Deposit Gate</th>
                                    <th class="pb-2.5">Credit Incentive</th>
                                    <th class="pb-2.5">Type</th>
                                    <th class="pb-2.5 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-cyber-border">
                                <?php if (empty($depositBonuses)): ?>
                                    <tr><td colspan="4" class="py-5 text-center text-gray-500 italic">No deposit bonus slabs currently configured.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($depositBonuses as $db): ?>
                                        <tr class="hover:bg-[#0c1326]">
                                            <td class="py-2.5 text-emerald-400 font-bold text-xs">
                                                $<?= number_format((float)$db['min_amount'], 2) ?> USD +
                                            </td>
                                            <td class="py-2.5 text-amber-300 font-black text-xs">
                                                <?= $db['bonus_type'] === 'percent' ? $db['bonus_amount'] . '%' : '+$' . number_format((float)$db['bonus_amount'], 2) . ' USD' ?>
                                            </td>
                                            <td class="py-2.5">
                                                <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase <?= $db['bonus_type'] === 'percent' ? 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/30' : 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' ?>">
                                                    <?= $db['bonus_type'] ?>
                                                </span>
                                            </td>
                                            <td class="py-2.5 text-right">
                                                <form method="POST" class="inline" onsubmit="return confirm('Purge this bonus slab?');">
                                                    <input type="hidden" name="admin_action" value="delete_deposit_bonus">
                                                    <input type="hidden" name="active_tab" value="tab-resellers">
                                                    <input type="hidden" name="bonus_id" value="<?= $db['id'] ?>">
                                                    <button type="submit" class="px-2.5 py-1 bg-red-500/15 border border-red-500/30 text-red-400 hover:bg-red-500/25 rounded text-[10px] font-bold">
                                                        <i class="fa-solid fa-trash mr-1"></i> Delete
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </section>

        <!-- ================================================================= -->
        <!-- TAB 4: ROOT & DELEGATED ADMINS REGISTRY                           -->
        <!-- ================================================================= -->
        <section id="tab-admins" class="tab-section hidden space-y-5">
            <div class="noc-card p-6 space-y-3.5">
                <h3 class="text-sm font-bold text-amber-400 font-mono flex items-center gap-2">
                    <i class="fa-solid fa-shield-halved"></i> Add Admin
                </h3>
                <form method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs font-mono">
                    <input type="hidden" name="admin_action" value="add_admin_uid">
                    <input type="hidden" name="active_tab" value="tab-admins">
                    <input type="number" name="telegram_id" placeholder="Telegram Chat ID (e.g. 7897443102)" required class="bg-[#040711] border border-cyber-border p-3 rounded-xl text-cyan-300 outline-none focus:border-cyan-400">
                    <input type="text" name="admin_name" placeholder="Designation Note (e.g. Operations Manager)" class="bg-[#040711] border border-cyber-border p-3 rounded-xl text-white outline-none focus:border-cyan-400 font-sans">
                    <button type="submit" class="py-3 bg-gradient-to-r from-amber-500 to-orange-500 text-gray-950 font-black rounded-xl text-xs uppercase tracking-wider shadow-md">
                        + Authorize Clearance
                    </button>
                </form>
            </div>

            <div class="noc-card p-6 space-y-3">
                <h3 class="text-sm font-bold text-white font-mono flex items-center gap-2">
                    <i class="fa-solid fa-crown text-amber-400"></i> Admins
                </h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs font-mono">
                        <thead>
                            <tr class="text-gray-400 border-b border-cyber-border">
                                <th class="pb-2.5">Telegram Unique ID</th>
                                <th class="pb-2.5">Designation</th>
                                <th class="pb-2.5">Security Level</th>
                                <th class="pb-2.5 text-right">Revocation</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyber-border">
                            <tr class="bg-[#0c1326]/60">
                                <td class="py-3 font-bold text-amber-300">
                                    <i class="fa-solid fa-shield-cat mr-1 text-amber-400"></i> <?= ADMIN_TELEGRAM_ID ?>
                                </td>
                                <td class="py-3 font-bold text-white font-sans">System Root Commander (Immutable)</td>
                                <td class="py-3"><span class="px-2.5 py-0.5 rounded text-[10px] font-black bg-amber-500/20 text-amber-300 border border-amber-500/40">MASTER ROOT</span></td>
                                <td class="py-3 text-right text-gray-500 italic text-[11px]">System Locked</td>
                            </tr>
                            <?php foreach ($adminList as $adm): ?>
                                <tr class="hover:bg-[#0c1326]">
                                    <td class="py-2.5 font-bold text-cyan-300"><?= $adm['telegram_id'] ?></td>
                                    <td class="py-2.5 text-gray-300 font-sans"><?= htmlspecialchars($adm['username'] ?? 'Delegated Admin') ?></td>
                                    <td class="py-2.5"><span class="px-2 py-0.5 rounded text-[10px] font-bold bg-cyan-500/20 text-cyan-300 border border-cyan-500/30">DELEGATED</span></td>
                                    <td class="py-2.5 text-right">
                                        <form method="POST" class="inline" onsubmit="return confirm('Revoke clearance for UID <?= $adm['telegram_id'] ?>?');">
                                            <input type="hidden" name="admin_action" value="delete_admin_uid">
                                            <input type="hidden" name="active_tab" value="tab-admins">
                                            <input type="hidden" name="target_tg_id" value="<?= $adm['telegram_id'] ?>">
                                            <button type="submit" class="px-2.5 py-1 rounded-lg bg-red-500/15 border border-red-500/30 text-red-400 hover:bg-red-500/25 text-[11px] font-bold">
                                                Revoke
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ================================================================= -->
        <!-- TAB 5: LICENSE SALES FEED & DISPATCH STREAM                       -->
        <!-- ================================================================= -->
        <section id="tab-sales" class="tab-section hidden space-y-5">
            <div class="noc-card p-6 space-y-4">
                <div class="flex justify-between items-center pb-3 border-b border-cyber-border">
                    <div class="flex items-center space-x-3">
                        <div class="w-9 h-9 rounded-xl bg-cyan-500/15 text-cyan-400 flex items-center justify-center text-sm border border-cyan-500/30">
                            <i class="fa-solid fa-satellite-dish"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-white font-mono uppercase tracking-wider">Sales Feed</h3>
                        </div>
                    </div>
                    <span class="text-xs text-cyan-400 font-mono">Delivered Total: <strong class="text-white"><?= $totalLicenses ?></strong></span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs font-mono">
                        <thead>
                            <tr class="text-gray-400 border-b border-cyber-border">
                                <th class="pb-2.5">Dispatch Time</th>
                                <th class="pb-2.5">Reseller Identity</th>
                                <th class="pb-2.5">Catalog Plan</th>
                                <th class="pb-2.5">Cryptographic Serial Key</th>
                                <th class="pb-2.5">Cost</th>
                                <th class="pb-2.5 text-right">Channel</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyber-border text-[11px]">
                            <?php if (empty($sales)): ?>
                                <tr><td colspan="6" class="py-6 text-center text-gray-500 font-mono italic">No license keys generated yet.</td></tr>
                            <?php else: ?>
                                <?php foreach($sales as $s): ?>
                                    <tr class="hover:bg-[#0c1326]">
                                        <td class="py-2.5 text-gray-400"><?= $s['created_at'] ?></td>
                                        <td class="py-2.5 font-bold text-white font-sans"><?= htmlspecialchars($s['username']) ?></td>
                                        <td class="py-2.5 text-cyan-300 font-semibold"><?= htmlspecialchars($s['validity'] ?? ($s['product_name'] ?? 'License Node')) ?></td>
                                        <td class="py-2.5 text-fuchsia-300 font-bold select-all tracking-wider text-[11px]"><?= $s['license_key'] ?></td>
                                        <td class="py-2.5 text-emerald-400 font-bold">$<?= number_format((float)($s['price'] ?? 0), 2) ?></td>
                                        <td class="py-2.5 text-right">
                                            <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 uppercase">
                                                <?= htmlspecialchars($s['buy_source'] ?? 'WEB') ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ================================================================= -->
        <!-- TAB 6: KEY RESETS & HWID TELEMETRY                                -->
        <!-- ================================================================= -->
        <section id="tab-resets" class="tab-section hidden space-y-5">
            <div class="noc-card p-6 space-y-4">
                <div class="flex justify-between items-center pb-3 border-b border-cyber-border">
                    <div class="flex items-center space-x-3">
                        <div class="w-9 h-9 rounded-xl bg-purple-500/15 text-purple-400 flex items-center justify-center text-sm border border-purple-500/30">
                            <i class="fa-solid fa-arrows-spin"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-white font-mono uppercase tracking-wider">Key Resets</h3>
                        </div>
                    </div>
                    <span class="text-xs text-purple-400 font-mono">Logged Resets: <strong class="text-white"><?= count($resets) ?></strong></span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs font-mono">
                        <thead>
                            <tr class="text-gray-400 border-b border-cyber-border">
                                <th class="pb-2.5">Reset Timestamp</th>
                                <th class="pb-2.5">Reseller Identity</th>
                                <th class="pb-2.5">Reset Audit Trail</th>
                                <th class="pb-2.5 text-right">Originating IP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyber-border text-[11px]">
                            <?php if (empty($resets)): ?>
                                <tr><td colspan="4" class="py-6 text-center text-gray-500 italic">No HWID key reset operations recorded.</td></tr>
                            <?php else: ?>
                                <?php foreach($resets as $r): ?>
                                    <tr class="hover:bg-[#0c1326]">
                                        <td class="py-2.5 text-gray-400"><?= $r['created_at'] ?></td>
                                        <td class="py-2.5 text-cyan-300 font-bold font-sans"><?= htmlspecialchars($r['username'] ?? 'User') ?></td>
                                        <td class="py-2.5 text-white"><?= htmlspecialchars($r['details']) ?></td>
                                        <td class="py-2.5 text-right text-gray-500"><?= $r['ip_address'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ================================================================= -->
        <!-- TAB 7: SNZ PAY AUTOPAY PIPELINE & DEPOSIT LIMITS                  -->
        <!-- ================================================================= -->
        <section id="tab-gateways" class="tab-section hidden space-y-5">
            
            <div class="flex space-x-1.5 bg-[#080d1a] p-1.5 rounded-2xl border border-cyber-border text-xs font-mono font-bold">
                <button type="button" onclick="switchGatewaySubTab('gateways-settings-sec')" id="subtab-btn-gateways-settings" class="flex-1 py-2.5 rounded-xl text-gray-950 bg-gradient-to-r from-orange-400 via-amber-400 to-yellow-400 transition text-center shadow-md">
                    <i class="fa-solid fa-sliders mr-1.5"></i> SNZ Pay & Gateway Pipelines
                </button>
                <button type="button" onclick="switchGatewaySubTab('gateways-limits-sec')" id="subtab-btn-gateways-limits" class="flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center">
                    <i class="fa-solid fa-shield-halved mr-1.5 text-emerald-400"></i> Deposit Limits Guard (Min / Max)
                </button>
            </div>

            <!-- GATEWAYS SUB-SECTION 1: SNZ PAY & MANUAL NUMBERS -->
            <div id="gateways-settings-sec" class="noc-card p-6 space-y-5">
                <div>
                    <h3 class="text-sm font-bold text-emerald-400 font-mono flex items-center gap-2">
                        <i class="fa-solid fa-network-wired"></i> SNZ Pay
                    </h3>
                </div>

                <form method="POST" class="space-y-4 text-xs font-sans">
                    <input type="hidden" name="admin_action" value="update_gateways">
                    <input type="hidden" name="active_tab" value="tab-gateways">

                    <!-- SNZ Pay AutoPay Engine Box -->
                    <div class="bg-[#080d1a] p-4 rounded-2xl border border-amber-500/40 space-y-3 bg-gradient-to-r from-[#0d172e] via-[#090f20] to-[#121430]">
                        <div class="flex justify-between items-center pb-2 border-b border-cyber-border">
                            <label class="text-xs font-bold text-amber-300 font-mono flex items-center gap-2">
                                <i class="fa-solid fa-bolt-lightning text-amber-400"></i> SNZ Pay API Autonomous Gateway (snzpay.digital)
                            </label>
                            <label class="flex items-center space-x-2 cursor-pointer text-gray-300 text-xs font-mono">
                                <input type="checkbox" name="auto_payment_on" value="1" <?= ($settings['auto_payment_on'] ?? '0') === '1' ? 'checked' : '' ?> class="rounded text-amber-500 w-4 h-4 bg-[#040711] border-cyber-border">
                                <span class="text-emerald-400 font-bold">Enable SNZ Pay Engine</span>
                            </label>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-gray-300 block mb-1 font-bold font-mono">SNZ Pay Active License Key (Brand Key)</label>
                                <input type="text" name="snzpay_license_key" value="<?= htmlspecialchars($settings['snzpay_license_key'] ?? '') ?>" placeholder="e.g. SNZ_LIVE_KEY_XXXXX" class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-amber-300 font-mono text-xs outline-none focus:border-amber-400 font-bold">
                            </div>
                            <div>
                                <label class="text-gray-300 block mb-1 font-bold font-mono">SNZ Pay Endpoint URL</label>
                                <input type="url" name="snzpay_api_url" value="<?= htmlspecialchars($settings['snzpay_api_url'] ?? 'https://snzpay.digital/api/verify_payment.php') ?>" class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-white font-mono text-xs outline-none focus:border-cyan-400">
                            </div>
                        </div>
                    </div>

                    <!-- Global Exchange Rate -->
                    <div class="bg-[#080d1a] p-4 rounded-2xl border border-emerald-500/30">
                        <label class="text-xs font-bold text-emerald-400 font-mono block mb-1.5">
                            Global Valuation Benchmark (1 USD = ? BDT)
                        </label>
                        <input type="number" step="0.01" name="usdt_rate" value="<?= htmlspecialchars($settings['usdt_rate']) ?>" required class="w-full sm:w-64 bg-[#040711] border border-cyber-border p-3 rounded-xl text-emerald-300 font-mono font-black text-sm outline-none focus:border-emerald-400">
                    </div>

                    <!-- Manual Payment Numbers -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-pink-400 block mb-1 font-bold font-mono">bKash Receiving Number (Personal)</label>
                            <input type="text" name="bkash_number" value="<?= htmlspecialchars($settings['bkash_number']) ?>" required class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-white font-mono outline-none focus:border-cyan-400">
                        </div>
                        <div>
                            <label class="text-orange-400 block mb-1 font-bold font-mono">Nagad Receiving Number (Personal)</label>
                            <input type="text" name="nagad_number" value="<?= htmlspecialchars($settings['nagad_number']) ?>" required class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-white font-mono outline-none focus:border-cyan-400">
                        </div>
                        <div>
                            <label class="text-purple-400 block mb-1 font-bold font-mono">Rocket Receiving Number (Personal)</label>
                            <input type="text" name="rocket_number" value="<?= htmlspecialchars($settings['rocket_number']) ?>" required class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-white font-mono outline-none focus:border-cyan-400">
                        </div>
                        <div>
                            <label class="text-yellow-400 block mb-1 font-bold font-mono">Binance Pay ID (USDT Settlement)</label>
                            <input type="text" name="binance_id" value="<?= htmlspecialchars($settings['binance_id']) ?>" required class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-white font-mono outline-none focus:border-cyan-400">
                        </div>
                    </div>

                    <button type="submit" class="py-3 px-8 bg-emerald-500 hover:bg-emerald-400 text-gray-950 font-black rounded-xl text-xs uppercase font-mono tracking-wider transition">
                        Commit Gateway Parameters
                    </button>
                </form>
            </div>

            <!-- GATEWAYS SUB-SECTION 2: DEPOSIT LIMITS GUARD (MIN / MAX) -->
            <div id="gateways-limits-sec" class="hidden space-y-5">
                <div class="noc-card p-6 space-y-3.5">
                    <div class="flex items-center justify-between pb-3 border-b border-cyber-border">
                        <div>
                            <h4 class="text-sm font-bold text-white font-mono flex items-center gap-2">
                                <i class="fa-solid fa-earth-americas text-cyan-400"></i> Deposit Limits
                            </h4>
                        </div>
                        <span class="text-xs font-mono text-emerald-400 font-bold">Min: $<?= number_format((float)($settings['min_deposit_limit'] ?? 5), 2) ?> • Max: $<?= number_format((float)($settings['max_deposit_limit'] ?? 500), 2) ?></span>
                    </div>

                    <form method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-3.5 text-xs font-sans">
                        <input type="hidden" name="admin_action" value="update_deposit_limits">
                        <input type="hidden" name="active_tab" value="tab-gateways">

                        <div>
                            <label class="text-gray-400 block mb-1 font-semibold font-mono">Minimum Deposit ($ USD)</label>
                            <input type="number" step="0.5" name="min_deposit_limit" value="<?= htmlspecialchars($settings['min_deposit_limit'] ?? '5.00') ?>" required class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-emerald-300 font-mono font-bold outline-none focus:border-cyan-400">
                        </div>
                        <div>
                            <label class="text-gray-400 block mb-1 font-semibold font-mono">Maximum Deposit ($ USD)</label>
                            <input type="number" step="1" name="max_deposit_limit" value="<?= htmlspecialchars($settings['max_deposit_limit'] ?? '500.00') ?>" required class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-white font-mono font-bold outline-none focus:border-cyan-400">
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full py-3 bg-cyan-500 hover:bg-cyan-400 text-gray-950 font-black rounded-xl text-xs uppercase font-mono tracking-wider transition">
                                Save Global Guard
                            </button>
                        </div>
                    </form>
                </div>

                <div class="noc-card p-6 space-y-3.5">
                    <div class="pb-3 border-b border-cyber-border">
                        <h4 class="text-sm font-bold text-amber-400 font-mono flex items-center gap-2">
                            <i class="fa-solid fa-user-lock"></i> Custom Limits
                        </h4>
                    </div>

                    <form method="POST" class="grid grid-cols-1 sm:grid-cols-4 gap-3.5 text-xs font-sans">
                        <input type="hidden" name="admin_action" value="set_user_deposit_limit">
                        <input type="hidden" name="active_tab" value="tab-gateways">

                        <div>
                            <label class="text-gray-400 block mb-1 font-semibold">Select Account Target</label>
                            <select name="target_user" required class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-cyan-300 font-bold font-mono outline-none focus:border-cyan-400">
                                <option value="all">★ Universal (All Accounts)</option>
                                <?php foreach ($resellers as $usr): ?>
                                    <option value="<?= $usr['id'] ?>"><?= htmlspecialchars($usr['username']) ?> (Bal: $<?= number_format((float)$usr['balance'], 2) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="text-gray-400 block mb-1 font-semibold font-mono">Min Threshold ($ USD)</label>
                            <input type="number" step="0.5" name="custom_min_deposit" placeholder="e.g. 5.00" required class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-emerald-300 font-mono font-bold outline-none focus:border-cyan-400">
                        </div>
                        <div>
                            <label class="text-gray-400 block mb-1 font-semibold font-mono">Max Threshold ($ USD)</label>
                            <input type="number" step="1" name="custom_max_deposit" placeholder="e.g. 100.00" required class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-white font-mono font-bold outline-none focus:border-cyan-400">
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full py-3 bg-gradient-to-r from-amber-500 to-orange-500 text-gray-950 font-black rounded-xl text-xs uppercase font-mono tracking-wider transition">
                                Apply Override
                            </button>
                        </div>
                    </form>
                </div>

                <div class="noc-card p-6 space-y-3">
                    <h4 class="text-xs font-bold text-white uppercase tracking-wider font-mono flex items-center gap-2">
                        <i class="fa-solid fa-list-check text-cyan-400"></i> Custom Limit Users
                    </h4>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs font-mono">
                            <thead>
                                <tr class="text-gray-400 border-b border-cyber-border">
                                    <th class="pb-2.5">Reseller Identity</th>
                                    <th class="pb-2.5">Min Deposit</th>
                                    <th class="pb-2.5">Max Deposit</th>
                                    <th class="pb-2.5 text-right">Reset Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-cyber-border">
                                <?php if (empty($usersWithCustomLimits)): ?>
                                    <tr><td colspan="4" class="py-5 text-center text-gray-500 italic">No accounts have individual deposit limits configured. All accounts adhere to global guard.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($usersWithCustomLimits as $ucl): ?>
                                        <tr class="hover:bg-[#0c1326]">
                                            <td class="py-2.5 font-bold text-white font-sans"><?= htmlspecialchars($ucl['username']) ?></td>
                                            <td class="py-2.5 text-emerald-400 font-bold">$<?= number_format((float)($ucl['min_deposit'] ?? $settings['min_deposit_limit']), 2) ?></td>
                                            <td class="py-2.5 text-cyan-300 font-bold">$<?= number_format((float)($ucl['max_deposit'] ?? $settings['max_deposit_limit']), 2) ?></td>
                                            <td class="py-2.5 text-right">
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="admin_action" value="reset_user_deposit_limit">
                                                    <input type="hidden" name="active_tab" value="tab-gateways">
                                                    <input type="hidden" name="user_id" value="<?= $ucl['id'] ?>">
                                                    <button type="submit" class="px-2.5 py-1 bg-red-500/15 border border-red-500/30 text-red-400 hover:bg-red-500/25 rounded text-[10px] font-bold">Reset</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </section>

        <!-- ================================================================= -->
        <!-- TAB 8: DEPOSITS VERIFICATION & CLEARING TERMINAL                  -->
        <!-- ================================================================= -->
        <section id="tab-deposits" class="tab-section hidden space-y-5">
            <div class="noc-card p-6 space-y-4">
                <div class="flex justify-between items-center pb-3 border-b border-cyber-border">
                    <div class="flex items-center space-x-3">
                        <div class="w-9 h-9 rounded-xl bg-teal-500/15 text-teal-400 flex items-center justify-center text-sm border border-teal-500/30">
                            <i class="fa-solid fa-vault"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-white font-mono uppercase tracking-wider">Deposits</h3>
                        </div>
                    </div>
                                    </div>
                <?php
                $depCounts = ['all' => count($transactions), 'pending' => 0, 'approved' => 0, 'rejected' => 0];
                foreach ($transactions as $__t) { if (isset($depCounts[$__t['status']])) $depCounts[$__t['status']]++; }
                ?>
                <div class="grid grid-cols-4 gap-2" id="depFilterBar">
                    <button type="button" data-dep-filter="all" onclick="filterDeposits('all')" class="dep-filter-btn py-2 rounded-xl text-[11px] font-bold font-mono border transition">All <span class="opacity-70">(<?= $depCounts['all'] ?>)</span></button>
                    <button type="button" data-dep-filter="pending" onclick="filterDeposits('pending')" class="dep-filter-btn py-2 rounded-xl text-[11px] font-bold font-mono border transition">Pending <span class="opacity-70">(<?= $depCounts['pending'] ?>)</span></button>
                    <button type="button" data-dep-filter="approved" onclick="filterDeposits('approved')" class="dep-filter-btn py-2 rounded-xl text-[11px] font-bold font-mono border transition">Approved <span class="opacity-70">(<?= $depCounts['approved'] ?>)</span></button>
                    <button type="button" data-dep-filter="rejected" onclick="filterDeposits('rejected')" class="dep-filter-btn py-2 rounded-xl text-[11px] font-bold font-mono border transition">Rejected <span class="opacity-70">(<?= $depCounts['rejected'] ?>)</span></button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs font-mono">
                        <thead>
                            <tr class="text-gray-400 border-b border-cyber-border">
                                <th class="pb-2.5">Reseller Identity</th>
                                <th class="pb-2.5">Gateway</th>
                                <th class="pb-2.5">Requested USD</th>
                                <th class="pb-2.5">Sender Number</th>
                                <th class="pb-2.5">Transaction ID</th>
                                <th class="pb-2.5">Status</th>
                                <th class="pb-2.5 text-right">Audit Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyber-border text-[11px]">
                            <?php if (empty($transactions)): ?>
                                <tr><td colspan="7" class="py-6 text-center text-gray-500 italic">No deposits.</td></tr>
                            <?php else: ?>
                                <?php foreach($transactions as $t): ?>
                                    <tr class="hover:bg-[#0c1326] dep-row" data-status="<?= htmlspecialchars($t['status']) ?>">
                                        <td class="py-2.5 font-bold text-white font-sans"><?= htmlspecialchars($t['username']) ?></td>
                                        <td class="py-2.5 text-cyan-400 font-bold"><?= $t['method'] ?></td>
                                        <td class="py-2.5 font-black text-emerald-400 text-xs">$<?= number_format((float)$t['amount'], 2) ?></td>
                                        <td class="py-2.5 text-gray-300"><?= $t['sender_num'] ?></td>
                                        <td class="py-2.5 text-cyan-300 font-bold select-all"><?= $t['trx_id'] ?></td>
                                        <td class="py-2.5">
                                            <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase <?= $t['status'] === 'approved' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : ($t['status'] === 'pending' ? 'bg-amber-500/20 text-amber-400 border border-amber-500/30' : 'bg-red-500/20 text-red-400 border border-red-500/30') ?>">
                                                <?= strtoupper($t['status']) ?>
                                            </span>
                                        </td>
                                        <td class="py-2.5 text-right">
                                            <?php if ($t['status'] === 'pending'): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="admin_action" value="manage_trx">
                                                    <input type="hidden" name="active_tab" value="tab-deposits">
                                                    <input type="hidden" name="trx_db_id" value="<?= $t['id'] ?>">
                                                    <button type="submit" name="status" value="approved" class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-500 rounded text-[10px] font-bold text-white mr-1 shadow-sm transition">Approve</button>
                                                    <button type="submit" name="status" value="rejected" class="px-2.5 py-1 bg-red-600 hover:bg-red-500 rounded text-[10px] font-bold text-white shadow-sm transition">Reject</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-gray-500 text-[10px]">Settled</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ================================================================= -->
        <!-- TAB 9: SECURITY, ACTIVITY & GEO TELEMETRY AUDIT                   -->
        <!-- ================================================================= -->
        <section id="tab-logs" class="tab-section hidden space-y-5">
            <div class="flex space-x-1.5 bg-[#080d1a] p-1.5 rounded-2xl border border-cyber-border text-xs font-mono font-bold">
                <button type="button" onclick="switchLogsSubTab('logs-activity-sec')" id="subtab-btn-logs-activity" class="flex-1 py-2.5 rounded-xl text-gray-950 bg-gradient-to-r from-pink-500 via-rose-500 to-cyan-400 transition text-center shadow-md">
                    <i class="fa-solid fa-fingerprint mr-1.5"></i> Security & Activity Trail
                </button>
                <button type="button" onclick="switchLogsSubTab('logs-geo-sec')" id="subtab-btn-logs-geo" class="flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center">
                    <i class="fa-solid fa-map-location-dot mr-1.5 text-emerald-400"></i> Operator & Geo Network Radar
                </button>
            </div>

            <div id="logs-activity-sec" class="noc-card p-6 space-y-4">
                <div class="flex justify-between items-center pb-3 border-b border-cyber-border">
                    <h3 class="text-sm font-bold text-cyan-400 font-mono flex items-center gap-2">
                        <i class="fa-solid fa-tower-broadcast"></i> Audit Logs
                    </h3>
                    <span class="text-xs text-gray-400 font-mono">Recent Records: <?= count($logs) ?></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs font-mono">
                        <thead>
                            <tr class="text-gray-400 border-b border-cyber-border">
                                <th class="pb-2.5">Timestamp</th>
                                <th class="pb-2.5">User Identity</th>
                                <th class="pb-2.5">Operation Tag</th>
                                <th class="pb-2.5">Details</th>
                                <th class="pb-2.5 text-right">Origin IP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyber-border text-[11px]">
                            <?php if (empty($logs)): ?>
                                <tr><td colspan="5" class="py-6 text-center text-gray-500 italic">No activity logged yet.</td></tr>
                            <?php else: ?>
                                <?php foreach($logs as $l): ?>
                                    <tr class="hover:bg-[#0c1326]">
                                        <td class="py-2 text-gray-500"><?= $l['created_at'] ?></td>
                                        <td class="py-2 text-cyan-300 font-bold font-sans"><?= htmlspecialchars($l['username'] ?? 'System') ?></td>
                                        <td class="py-2"><span class="px-2 py-0.5 rounded bg-[#1e123a] text-cyan-300 border border-purple-500/20 font-bold"><?= $l['action'] ?></span></td>
                                        <td class="py-2 text-gray-300"><?= htmlspecialchars($l['details']) ?></td>
                                        <td class="py-2 text-right text-gray-500"><?= $l['ip_address'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="logs-geo-sec" class="hidden space-y-4">
                <div class="noc-card p-4 flex items-center justify-between border border-emerald-500/30">
                    <div class="flex items-center space-x-3">
                        <span class="w-10 h-10 rounded-xl bg-emerald-500/15 text-emerald-400 flex items-center justify-center text-base border border-emerald-500/30">
                            <i class="fa-solid fa-satellite"></i>
                        </span>
                        <div>
                            <h4 class="text-xs font-bold text-white font-mono uppercase">Logins</h4>
                        </div>
                    </div>
                    <span class="text-xs font-mono text-emerald-400 font-bold"><?= count($loginLogs) ?> Sign-Ins</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
                    <?php if (empty($loginLogs)): ?>
                        <div class="col-span-2 noc-card p-10 text-center text-gray-500 text-xs font-mono">
                            No login intelligence events stored yet.
                        </div>
                    <?php else: ?>
                        <?php foreach ($loginLogs as $ll): ?>
                            <div class="noc-card p-4 space-y-3">
                                <div class="flex items-center justify-between pb-2.5 border-b border-cyber-border">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-9 h-9 rounded-xl bg-cyan-500/15 text-cyan-300 border border-cyan-500/30 flex items-center justify-center text-xs font-black font-mono">
                                            <?= strtoupper(substr($ll['username'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <h4 class="font-extrabold text-white text-xs leading-none font-sans"><?= htmlspecialchars($ll['username']) ?></h4>
                                            <span class="text-[9px] text-gray-400 font-mono mt-1 block"><?= $ll['login_time'] ?></span>
                                        </div>
                                    </div>
                                    <span class="px-2.5 py-0.5 rounded text-[9px] font-mono font-bold bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">
                                        <?= htmlspecialchars($ll['network_type'] ?? 'WiFi') ?>
                                    </span>
                                </div>

                                <div class="grid grid-cols-2 gap-2 text-xs font-mono">
                                    <div class="bg-[#040711] p-2.5 rounded-xl border border-cyber-border">
                                        <span class="text-[9px] text-gray-400 block font-semibold">Location / Area</span>
                                        <strong class="text-cyan-300 text-xs truncate block mt-0.5">
                                            <?= htmlspecialchars($ll['city'] ?: 'Dhaka') ?>, <?= htmlspecialchars($ll['region'] ?: 'Bangladesh') ?>
                                        </strong>
                                    </div>
                                    <div class="bg-[#040711] p-2.5 rounded-xl border border-cyber-border">
                                        <span class="text-[9px] text-gray-400 block font-semibold">Operator / ISP</span>
                                        <strong class="text-amber-300 text-xs truncate block mt-0.5">
                                            <?= htmlspecialchars($ll['network_type'] ?: 'WiFi / Broadband') ?>
                                        </strong>
                                    </div>
                                </div>

                                <div class="bg-[#040711] p-2.5 rounded-xl border border-cyber-border text-[10px] text-gray-300 font-mono flex items-center justify-between">
                                    <div>
                                        <span class="text-gray-400">Telegram:</span>
                                        <?php if (!empty($ll['telegram_id'])): ?>
                                            <span class="text-white font-bold"><?= htmlspecialchars($ll['telegram_name'] ?: 'User') ?></span> 
                                            (<?= !empty($ll['telegram_username']) ? '@' . htmlspecialchars($ll['telegram_username']) : '#' . $ll['telegram_id'] ?>)
                                        <?php else: ?>
                                            <span class="text-gray-500 italic">Web Session</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-gray-500"><?= $ll['ip_address'] ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </section>

    </main>

    <!-- ================================================================= -->
    <!-- MODAL 1: TODAY KEYS SOLD DRILL-DOWN AUDITOR                       -->
    <!-- ================================================================= -->
    <div id="todayKeysModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="noc-card p-6 w-full max-w-4xl space-y-4 border border-amber-500/40 max-h-[90vh] flex flex-col justify-between">
            <div class="flex justify-between items-center pb-3 border-b border-cyber-border">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-2xl bg-amber-500/15 border border-amber-500/30 text-amber-400 flex items-center justify-center text-lg">
                        <i class="fa-solid fa-key"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-white font-mono uppercase tracking-wider">Today's Keys</h3>
                    </div>
                </div>
                <button type="button" onclick="closeModal('todayKeysModal')" class="text-gray-400 hover:text-white p-1 rounded-lg">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <div id="todayKeysContainer" class="overflow-y-auto max-h-[58vh] pr-1">
                <div class="text-center py-10 text-gray-400 font-mono text-xs">
                    <i class="fa-solid fa-spinner fa-spin text-2xl text-amber-400 mb-2"></i><br>Querying today's dispatch records...
                </div>
            </div>

            <div class="flex items-center justify-between pt-3 border-t border-cyber-border text-xs font-mono">
                <span id="todayKeysSummaryText" class="text-gray-400">Loading audit records...</span>
                <button type="button" onclick="closeModal('todayKeysModal')" class="px-5 py-2 rounded-xl bg-[#0c1326] border border-cyber-border text-gray-300 hover:text-white font-bold transition">
                    Close Terminal
                </button>
            </div>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- MODAL 2: TODAY VERIFIED DEPOSITS DRILL-DOWN AUDITOR               -->
    <!-- ================================================================= -->
    <div id="todayDepositsModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="noc-card p-6 w-full max-w-4xl space-y-4 border border-teal-500/40 max-h-[90vh] flex flex-col justify-between">
            <div class="flex justify-between items-center pb-3 border-b border-cyber-border">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-2xl bg-teal-500/15 border border-teal-500/30 text-teal-400 flex items-center justify-center text-lg">
                        <i class="fa-solid fa-vault"></i>
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-white font-mono uppercase tracking-wider">Today's Deposits</h3>
                    </div>
                </div>
                <button type="button" onclick="closeModal('todayDepositsModal')" class="text-gray-400 hover:text-white p-1 rounded-lg">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <div id="todayDepositsContainer" class="overflow-y-auto max-h-[58vh] pr-1">
                <div class="text-center py-10 text-gray-400 font-mono text-xs">
                    <i class="fa-solid fa-spinner fa-spin text-2xl text-teal-400 mb-2"></i><br>Loading today's deposit transactions...
                </div>
            </div>

            <div class="flex items-center justify-between pt-3 border-t border-cyber-border text-xs font-mono">
                <span id="todayDepositsSummaryText" class="text-gray-400">Loading records...</span>
                <button type="button" onclick="closeModal('todayDepositsModal')" class="px-5 py-2 rounded-xl bg-[#0c1326] border border-cyber-border text-gray-300 hover:text-white font-bold transition">
                    Close Terminal
                </button>
            </div>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- MODAL 3: RESELLER DEEP DOSSIER & COMPREHENSIVE HISTORY LEDGER     -->
    <!-- ================================================================= -->
    <div id="resellerDossierModal" class="fixed inset-0 bg-black/90 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="noc-card p-6 w-full max-w-4xl space-y-4 border border-purple-500/40 max-h-[92vh] flex flex-col justify-between">
            <div class="flex justify-between items-start pb-3 border-b border-cyber-border">
                <div class="flex items-center space-x-3.5">
                    <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-purple-500/20 via-cyan-500/20 to-blue-500/20 text-purple-400 border border-purple-500/30 flex items-center justify-center text-xl font-bold font-mono">
                        <i class="fa-solid fa-id-card"></i>
                    </div>
                    <div>
                        <div class="flex items-center space-x-2">
                            <h3 id="dossierResellerUsername" class="text-base font-extrabold text-white font-sans">Reseller Dossier</h3>
                            <span id="dossierStatusBadge" class="px-2 py-0.5 rounded text-[9px] font-black uppercase font-mono bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">ACTIVE</span>
                        </div>
                        <div class="flex items-center space-x-2 text-[11px] text-gray-400 font-mono mt-1">
                            <span id="dossierTelegramInfo">TG: #Not Linked</span>
                            <span>•</span>
                            <span id="dossierCreatedDate">Registered: 2026-01-01</span>
                        </div>
                    </div>
                </div>
                <button type="button" onclick="closeModal('resellerDossierModal')" class="text-gray-400 hover:text-white p-1 rounded-lg">
                    <i class="fa-solid fa-xmark text-lg"></i>
                </button>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 font-mono text-xs">
                <div class="bg-[#080d1a] border border-cyber-border p-3 rounded-xl">
                    <span class="text-[10px] text-gray-400 block font-semibold">Current Balance</span>
                    <span id="dossierBalance" class="text-emerald-400 font-extrabold text-base block mt-1">$0.00</span>
                </div>
                <div class="bg-[#080d1a] border border-cyber-border p-3 rounded-xl">
                    <span class="text-[10px] text-gray-400 block font-semibold">Total Delivered Keys</span>
                    <span id="dossierKeysCount" class="text-cyan-300 font-extrabold text-base block mt-1">0 Keys</span>
                </div>
                <div class="bg-[#080d1a] border border-cyber-border p-3 rounded-xl">
                    <span class="text-[10px] text-gray-400 block font-semibold">Total Keys Cost</span>
                    <span id="dossierSpentUSD" class="text-amber-300 font-extrabold text-base block mt-1">$0.00</span>
                </div>
                <div class="bg-[#080d1a] border border-cyber-border p-3 rounded-xl">
                    <span class="text-[10px] text-gray-400 block font-semibold">Total Deposited</span>
                    <span id="dossierDepositedUSD" class="text-teal-300 font-extrabold text-base block mt-1">$0.00</span>
                </div>
            </div>

            <div class="flex space-x-1.5 bg-[#080d1a] p-1.5 rounded-xl border border-cyber-border text-xs font-mono font-bold">
                <button type="button" onclick="switchDossierTab('dossier-tab-keys')" id="btn-dossier-keys" class="flex-1 py-2 rounded-lg text-gray-950 bg-gradient-to-r from-purple-400 to-cyan-400 transition text-center shadow">
                    <i class="fa-solid fa-key mr-1"></i> Keys Bought (<span id="dossierBadgeKeys">0</span>)
                </button>
                <button type="button" onclick="switchDossierTab('dossier-tab-deposits')" id="btn-dossier-deposits" class="flex-1 py-2 rounded-lg text-gray-400 hover:text-white transition text-center">
                    <i class="fa-solid fa-vault mr-1"></i> Deposits History (<span id="dossierBadgeDeposits">0</span>)
                </button>
                <button type="button" onclick="switchDossierTab('dossier-tab-activity')" id="btn-dossier-activity" class="flex-1 py-2 rounded-lg text-gray-400 hover:text-white transition text-center">
                    <i class="fa-solid fa-fingerprint mr-1"></i> Activity & Resets
                </button>
            </div>

            <div class="overflow-y-auto max-h-[46vh] pr-1">
                <div id="dossier-tab-keys" class="space-y-2">
                    <table class="w-full text-left text-xs font-mono">
                        <thead>
                            <tr class="text-gray-400 border-b border-cyber-border">
                                <th class="pb-2">Time</th>
                                <th class="pb-2">Product / Validity</th>
                                <th class="pb-2">Serial Key</th>
                                <th class="pb-2">Price</th>
                                <th class="pb-2 text-right">Channel</th>
                            </tr>
                        </thead>
                        <tbody id="dossierKeysTableBody" class="divide-y divide-cyber-border text-[11px]"></tbody>
                    </table>
                </div>

                <div id="dossier-tab-deposits" class="hidden space-y-2">
                    <table class="w-full text-left text-xs font-mono">
                        <thead>
                            <tr class="text-gray-400 border-b border-cyber-border">
                                <th class="pb-2">Time</th>
                                <th class="pb-2">Method</th>
                                <th class="pb-2">Amount ($)</th>
                                <th class="pb-2">Sender No</th>
                                <th class="pb-2">TrxID</th>
                                <th class="pb-2 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody id="dossierDepositsTableBody" class="divide-y divide-cyber-border text-[11px]"></tbody>
                    </table>
                </div>

                <div id="dossier-tab-activity" class="hidden space-y-2">
                    <table class="w-full text-left text-xs font-mono">
                        <thead>
                            <tr class="text-gray-400 border-b border-cyber-border">
                                <th class="pb-2">Time</th>
                                <th class="pb-2">Action</th>
                                <th class="pb-2">Audit Detail</th>
                                <th class="pb-2 text-right">IP Address</th>
                            </tr>
                        </thead>
                        <tbody id="dossierActivityTableBody" class="divide-y divide-cyber-border text-[11px]"></tbody>
                    </table>
                </div>
            </div>

            <div class="flex items-center justify-end pt-3 border-t border-cyber-border text-xs font-mono">
                <button type="button" onclick="closeModal('resellerDossierModal')" class="px-5 py-2 rounded-xl bg-[#0c1326] border border-cyber-border text-gray-300 hover:text-white font-bold transition">
                    Close Dossier
                </button>
            </div>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- MODAL 4: SELECT PROVIDER PRODUCTS FOR SELECTIVE IMPORT            -->
    <!-- ================================================================= -->
    <div id="selectProviderProdModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="noc-card p-6 w-full max-w-xl space-y-4 border border-cyan-500/40 max-h-[90vh] flex flex-col justify-between">
            <div class="flex justify-between items-center pb-2 border-b border-cyber-border">
                <div>
                    <h3 class="text-sm font-bold text-white font-mono flex items-center gap-2">
                        <i class="fa-solid fa-list-check text-emerald-400"></i> Import Products
                    </h3>
                </div>
                <button onclick="closeModal('selectProviderProdModal')" class="text-gray-400 hover:text-white"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>

            <div id="providerProdListContainer" class="space-y-2 overflow-y-auto max-h-[55vh] pr-1 text-xs font-mono">
                <div class="text-center py-8 text-gray-400"><i class="fa-solid fa-spinner fa-spin text-xl text-cyan-400 mb-2"></i><br>Connecting to Provider API Gateway...</div>
            </div>

            <form method="POST" id="importSelectedForm" onsubmit="return prepareSelectedProductsImport()">
                <input type="hidden" name="admin_action" value="import_selected_provider_products">
                <input type="hidden" name="active_tab" value="tab-products">
                <input type="hidden" id="selectedProductsJsonInput" name="selected_products_json">
                
                <div class="flex items-center justify-between pt-3 border-t border-cyber-border gap-2 font-mono">
                    <button type="button" onclick="toggleSelectAllProviderProds()" class="px-3.5 py-2.5 rounded-xl bg-[#080d1a] border border-cyber-border text-cyan-300 text-xs font-bold hover:bg-[#101729]">
                        Toggle Select All
                    </button>
                    <button type="submit" id="btnImportSelected" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-cyan-500 to-emerald-400 text-gray-950 font-black text-xs uppercase shadow-md hover:opacity-95 transition">
                        Ingest Selected Nodes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- MODAL 5: RESELLER PARAMETERS, CUSTOM PRICING & SUB-ACCOUNTS (V5)  -->
    <!-- ================================================================= -->
    <div id="manageResellerModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="noc-card p-6 w-full max-w-2xl space-y-4 border border-cyan-500/40 max-h-[92vh] overflow-y-auto flex flex-col justify-between">
            <div class="flex justify-between items-center pb-2 border-b border-cyber-border">
                <div class="flex items-center space-x-3">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-cyan-500 to-blue-600 flex items-center justify-center text-white font-bold text-sm">
                        <i class="fa-solid fa-user-gear"></i>
                    </div>
                    <div>
                        <h3 id="modalResellerUsername" class="text-sm font-bold text-white font-sans leading-none">Username</h3>
                        <span id="modalResellerTgTag" class="text-[10px] text-gray-400 font-mono mt-1 block">Account Command Terminal</span>
                    </div>
                </div>
                <button onclick="closeModal('manageResellerModal')" class="text-gray-400 hover:text-white"><i class="fa-solid fa-xmark text-base"></i></button>
            </div>

            <!-- Modal Sub-Tabs (1. General & Balances | 2. Custom Pricing & Discounts | 3. Sub-Resellers Control) -->
            <div class="flex space-x-1.5 bg-[#080d1a] p-1.5 rounded-xl border border-cyber-border text-xs font-mono font-bold">
                <button type="button" onclick="switchManageResellerModalTab('reseller-subtab-general')" id="btn-reseller-subtab-general" class="flex-1 py-2 rounded-lg text-gray-950 bg-gradient-to-r from-cyan-400 to-emerald-400 transition text-center shadow">
                    <i class="fa-solid fa-wallet mr-1"></i> General & Balance
                </button>
                <button type="button" onclick="switchManageResellerModalTab('reseller-subtab-pricing')" id="btn-reseller-subtab-pricing" class="flex-1 py-2 rounded-lg text-gray-400 hover:text-white transition text-center">
                    <i class="fa-solid fa-tags mr-1"></i> Product Prices & Discounts
                </button>
                <button type="button" onclick="switchManageResellerModalTab('reseller-subtab-subs')" id="btn-reseller-subtab-subs" class="flex-1 py-2 rounded-lg text-gray-400 hover:text-white transition text-center">
                    <i class="fa-solid fa-users-viewfinder mr-1"></i> Sub-Accounts Control (<span id="modalSubsCountBadge">0</span>)
                </button>
            </div>

            <!-- SUB-TAB 1: GENERAL & FINANCIAL PARAMETERS -->
            <div id="reseller-subtab-general" class="space-y-4">
                <div class="bg-[#080d1a] border border-cyber-border rounded-xl px-3 py-2 flex items-center justify-between">
                    <div class="overflow-hidden mr-2">
                        <span class="text-[9px] uppercase tracking-wider text-cyan-400 font-bold font-mono">Live API Key</span>
                        <span id="modalResellerApiKey" class="font-mono text-xs text-gray-300 truncate block mt-0.5">keymanager_live_...</span>
                    </div>
                    <div class="flex items-center space-x-1.5 flex-shrink-0 font-mono">
                        <button type="button" onclick="copyModalResellerApiKey()" class="px-2.5 py-1 bg-[#121c32] hover:bg-[#182644] text-cyan-300 rounded text-[11px] font-bold">
                            <i class="fa-regular fa-copy mr-1"></i> Copy
                        </button>
                        <form method="POST" class="inline" onsubmit="return confirm('Provision brand new API key for this user?');">
                            <input type="hidden" name="admin_action" value="regenerate_reseller_api_key">
                            <input type="hidden" name="active_tab" value="tab-resellers">
                            <input type="hidden" id="modalRegenUserId" name="user_id">
                            <button type="submit" class="px-2.5 py-1 bg-amber-500/20 hover:bg-amber-500/30 text-amber-300 rounded text-[11px] font-bold">
                                <i class="fa-solid fa-rotate mr-1"></i> New
                            </button>
                        </form>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2 text-xs font-mono">
                    <div class="bg-[#080d1a] border border-cyber-border p-2.5 rounded-xl">
                        <span class="text-[10px] text-gray-400 block font-semibold">Wallet Liquidity</span>
                        <span id="modalResellerBalance" class="text-base font-black text-emerald-400 mt-1 block leading-tight">$0.00</span>
                    </div>
                    <div class="bg-[#080d1a] border border-cyber-border p-2.5 rounded-xl">
                        <span class="text-[10px] text-gray-400 block font-semibold">Delivered Units</span>
                        <span id="modalResellerSold" class="text-base font-black text-cyan-300 mt-1 block leading-tight">0 Keys</span>
                    </div>
                </div>

                <form method="POST" class="bg-[#080d1a] border border-cyber-border p-3 rounded-xl space-y-1.5 text-xs font-sans">
                    <input type="hidden" name="admin_action" value="update_reseller_rate">
                    <input type="hidden" name="active_tab" value="tab-resellers">
                    <input type="hidden" id="modalRateUserId" name="user_id">
                    <span class="text-[10px] text-gray-400 block font-semibold font-mono">Custom Exchange Valuation ($1 = ? BDT)</span>
                    <div class="flex items-center space-x-2">
                        <input type="number" step="0.5" id="modalResellerRateInput" name="custom_bdt_rate" required class="flex-1 bg-[#040711] border border-cyber-border px-3 py-2 rounded-xl text-xs text-white font-mono font-bold outline-none focus:border-cyan-400">
                        <button type="submit" class="px-4 py-2 bg-cyan-500 hover:bg-cyan-400 text-gray-950 rounded-xl text-xs font-black uppercase font-mono transition">Save Rate</button>
                    </div>
                </form>

                <form method="POST" class="bg-[#080d1a] border border-cyber-border p-3 rounded-xl space-y-1.5 text-xs font-sans">
                    <input type="hidden" name="admin_action" value="modify_balance">
                    <input type="hidden" name="active_tab" value="tab-resellers">
                    <input type="hidden" id="modalBalanceUserId" name="user_id">
                    <span class="text-[10px] text-gray-400 block font-semibold font-mono">Adjust Liquidity Balance ($ USD)</span>
                    <div class="flex items-center space-x-2">
                        <input type="number" step="0.5" name="amount" placeholder="Amount ($)" required class="flex-1 bg-[#040711] border border-cyber-border px-3 py-2 rounded-xl text-xs text-emerald-400 font-mono font-bold outline-none focus:border-emerald-400">
                        <button type="submit" name="type" value="add" class="px-3 py-2 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-xs font-bold font-mono transition">+ Add</button>
                        <button type="submit" name="type" value="deduct" class="px-3 py-2 bg-red-600 hover:bg-red-500 text-white rounded-xl text-xs font-bold font-mono transition">- Cut</button>
                    </div>
                </form>

                <div class="grid grid-cols-2 gap-2 text-xs font-mono">
                    <form method="POST" class="w-full">
                        <input type="hidden" name="admin_action" value="toggle_ban">
                        <input type="hidden" name="active_tab" value="tab-resellers">
                        <input type="hidden" id="modalBanUserId" name="user_id">
                        <input type="hidden" id="modalBanUserStatus" name="status">
                        <button type="submit" id="modalBanToggleBtn" class="w-full py-2.5 rounded-xl text-xs font-bold uppercase transition">Toggle Status</button>
                    </form>

                    <form method="POST" class="w-full">
                        <input type="hidden" name="admin_action" value="toggle_api">
                        <input type="hidden" name="active_tab" value="tab-resellers">
                        <input type="hidden" id="modalApiUserId" name="user_id">
                        <button type="submit" id="modalApiToggleBtn" class="w-full py-2.5 rounded-xl text-xs font-bold uppercase transition">Toggle API</button>
                    </form>
                </div>
            </div>

            <!-- SUB-TAB 2: RESELLER CUSTOM PRICING & INDIVIDUAL DISCOUNT -->
            <div id="reseller-subtab-pricing" class="hidden space-y-3">
                <div class="flex justify-between items-center bg-[#080d1a] p-3 rounded-xl border border-cyber-border text-xs">
                    <div>
                        <span class="text-white font-bold block font-mono">Special Pricing Matrix for this Reseller</span>
                        <span class="text-[10px] text-gray-400">Leave custom price as 0.00 to charge standard catalog price.</span>
                    </div>
                    <form method="POST" onsubmit="return confirm('Reset all custom prices for this reseller back to default catalog?');">
                        <input type="hidden" name="admin_action" value="reset_reseller_pricing">
                        <input type="hidden" name="active_tab" value="tab-resellers">
                        <input type="hidden" id="modalResetPricingUserId" name="target_user_id">
                        <button type="submit" class="px-3 py-1.5 rounded-xl bg-red-500/15 border border-red-500/30 text-red-400 hover:bg-red-500/25 text-[11px] font-bold font-mono">
                            Reset to Default
                        </button>
                    </form>
                </div>

                <form method="POST" class="space-y-3">
                    <input type="hidden" name="admin_action" value="save_reseller_pricing">
                    <input type="hidden" name="active_tab" value="tab-resellers">
                    <input type="hidden" id="modalSavePricingUserId" name="target_user_id">

                    <div id="resellerPricingRowsContainer" class="space-y-2 max-h-[48vh] overflow-y-auto pr-1">
                        <div class="text-center py-8 text-gray-400 font-mono text-xs">
                            <i class="fa-solid fa-spinner fa-spin text-xl text-cyan-400 mb-2"></i><br>Loading product matrix...
                        </div>
                    </div>

                    <button type="submit" class="w-full py-3 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-400 text-gray-950 font-black text-xs uppercase font-mono tracking-wider shadow-md hover:opacity-95 transition">
                        Save Custom Pricing & Discounts
                    </button>
                </form>
            </div>

            <!-- SUB-TAB 3: RESELLER'S SUB-ACCOUNTS FULL CONTROL -->
            <div id="reseller-subtab-subs" class="hidden space-y-3">
                <div class="bg-[#080d1a] p-3 rounded-xl border border-cyber-border text-xs">
                    <span class="text-white font-bold block font-mono">All Sub-Accounts Created by This Reseller</span>
                    <span class="text-[10px] text-gray-400">Directly add/cut balance, toggle status or delete sub-accounts from here.</span>
                </div>

                <div id="resellerSubsListContainer" class="space-y-2 max-h-[48vh] overflow-y-auto pr-1">
                    <div class="text-center py-8 text-gray-400 font-mono text-xs">
                        <i class="fa-solid fa-spinner fa-spin text-xl text-amber-400 mb-2"></i><br>Loading sub-accounts...
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- ================================================================= -->
    <!-- MODAL 6: EDIT PRODUCT TITLE & INDIVIDUAL DISCOUNT                 -->
    <!-- ================================================================= -->
    <div id="editProdModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="noc-card p-6 w-full max-w-sm space-y-4 border border-cyan-500/40">
            <div class="flex justify-between items-center pb-2 border-b border-cyber-border">
                <h3 class="text-sm font-bold text-white font-mono flex items-center gap-2">
                    <i class="fa-solid fa-pen-to-square text-cyan-400"></i> Edit Product
                </h3>
                <button onclick="closeModal('editProdModal')" class="text-gray-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" class="space-y-3.5 text-xs font-sans">
                <input type="hidden" name="admin_action" value="edit_product_details">
                <input type="hidden" name="active_tab" value="tab-products">
                <input type="hidden" id="editTargetProdId" name="target_product_id">
                <div>
                    <label class="text-gray-400 block mb-1 font-semibold">Product Title</label>
                    <input type="text" id="editTargetProdName" name="new_name" required class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-white outline-none focus:border-cyan-400">
                </div>
                <div>
                    <label class="text-gray-400 block mb-1 font-semibold">Priority Serial (#)</label>
                    <input type="number" id="editTargetSortOrder" name="sort_order" required class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-cyan-300 font-mono font-bold outline-none focus:border-cyan-400">
                </div>
                <div>
                    <label class="text-emerald-400 block mb-1 font-semibold font-mono">Individual Node Discount (%)</label>
                    <input type="number" min="0" max="100" id="editTargetDiscount" name="discount_percent" value="0" class="w-full bg-[#040711] border border-cyber-border p-3 rounded-xl text-emerald-300 font-mono font-bold outline-none focus:border-emerald-400">
                </div>
                <button type="submit" class="w-full py-3 rounded-xl bg-cyan-500 text-gray-950 font-black text-xs uppercase font-mono tracking-wider">
                    Commit Changes
                </button>
            </form>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- MODAL 7: ATTACH PLAN TO PRODUCT                                   -->
    <!-- ================================================================= -->
    <div id="attachPlanModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="noc-card p-6 w-full max-w-md space-y-4 border border-cyan-500/40">
            <div class="flex justify-between items-center pb-2 border-b border-cyber-border">
                <h3 class="text-sm font-bold text-white font-mono">Link Plan to <span id="attachProdNameSpan" class="text-cyan-300"></span></h3>
                <button onclick="closeModal('attachPlanModal')" class="text-gray-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" class="space-y-3.5 text-xs font-sans">
                <input type="hidden" name="admin_action" value="attach_plan_to_product">
                <input type="hidden" name="active_tab" value="tab-products">
                <input type="hidden" id="attachTargetProdId" name="target_product_id">

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="text-gray-400 block mb-1">Duration Value</label>
                        <input type="number" name="duration_value" min="1" value="1" required class="w-full bg-[#040711] border border-cyber-border p-2.5 rounded-xl text-white font-mono outline-none">
                    </div>
                    <div>
                        <label class="text-gray-400 block mb-1">Temporal Unit</label>
                        <select name="duration_unit" class="w-full bg-[#040711] border border-cyber-border p-2.5 rounded-xl text-cyan-300 font-mono font-bold outline-none">
                            <option value="hours">Hours</option>
                            <option value="days" selected>Days</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="text-gray-400 block mb-1">Plan Label</label>
                        <input type="text" name="plan_title" placeholder="e.g. 7 Days" required class="w-full bg-[#040711] border border-cyber-border p-2.5 rounded-xl text-white outline-none">
                    </div>
                    <div>
                        <label class="text-amber-400 block mb-1 font-bold font-mono">Provider Plan ID</label>
                        <input type="number" name="api_plan_id" placeholder="e.g. 59" class="w-full bg-[#040711] border border-cyber-border p-2.5 rounded-xl text-amber-300 font-mono outline-none">
                    </div>
                    <div>
                        <label class="text-fuchsia-400 block mb-1 font-bold font-mono">Reset API</label>
                        <select name="reset_api_id" class="w-full bg-[#040711] border border-cyber-border p-2.5 rounded-xl text-fuchsia-300 font-mono outline-none"><?= resetApiOptionsHtml(0) ?></select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2.5 bg-[#080d1a] p-3 rounded-xl border border-cyber-border">
                    <div>
                        <label class="text-cyan-300 block mb-1 font-bold font-mono">Benchmark Price ($)</label>
                        <input type="number" step="0.01" name="user_price" placeholder="10.00" required class="w-full bg-[#0c1326] border border-cyber-border p-2 rounded-lg text-white font-mono outline-none">
                    </div>
                    <div>
                        <label class="text-fuchsia-400 block mb-1 font-bold font-mono">Wholesale Disc (%)</label>
                        <input type="number" step="0.1" name="reseller_discount_percent" placeholder="10" value="0" class="w-full bg-[#0c1326] border border-cyber-border p-2 rounded-lg text-fuchsia-300 font-mono outline-none">
                    </div>
                </div>

                <button type="submit" class="w-full py-3 bg-gradient-to-r from-cyan-400 via-teal-400 to-emerald-400 text-gray-950 font-black rounded-xl text-xs uppercase font-mono tracking-wider">
                    + Link Validity Plan
                </button>
            </form>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- MODAL 8: EDIT VALIDITY PLAN PARAMETERS                            -->
    <!-- ================================================================= -->
    <div id="editPlanModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 hidden flex items-center justify-center p-4">
        <div class="noc-card p-6 w-full max-w-md space-y-4 border border-cyan-500/40">
            <div class="flex justify-between items-center pb-2 border-b border-cyber-border">
                <h3 class="text-sm font-bold text-white font-mono flex items-center gap-2">
                    <i class="fa-solid fa-pen-ruler text-cyan-400"></i> Edit Plan
                </h3>
                <button onclick="closeModal('editPlanModal')" class="text-gray-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
            </div>

            <form method="POST" class="space-y-3.5 text-xs font-sans">
                <input type="hidden" name="admin_action" value="edit_validity_plan">
                <input type="hidden" name="active_tab" value="tab-products">
                <input type="hidden" id="editPlanId" name="plan_id">

                <div class="grid grid-cols-2 gap-2.5">
                    <div>
                        <label class="text-gray-400 block mb-1 font-semibold">Plan Label</label>
                        <input type="text" id="editPlanTitle" name="plan_title" required class="w-full bg-[#040711] border border-cyber-border p-2.5 rounded-xl text-white outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label class="text-amber-400 block mb-1 font-bold font-mono">Provider Plan ID</label>
                        <input type="number" id="editApiPlanId" name="api_plan_id" placeholder="e.g. 59" class="w-full bg-[#040711] border border-cyber-border p-2.5 rounded-xl text-amber-300 font-mono font-bold outline-none focus:border-amber-400">
                    </div>
                    <div>
                        <label class="text-fuchsia-400 block mb-1 font-bold font-mono">Reset API</label>
                        <select id="editResetApiId" name="reset_api_id" class="w-full bg-[#040711] border border-cyber-border p-2.5 rounded-xl text-fuchsia-300 font-mono font-bold outline-none focus:border-fuchsia-400"><?= resetApiOptionsHtml(0) ?></select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2.5 bg-[#080d1a] p-3 rounded-xl border border-cyber-border">
                    <div>
                        <label class="text-cyan-300 block mb-1 font-bold font-mono">Benchmark Price ($)</label>
                        <input type="number" step="0.01" id="editUserPrice" name="user_price" oninput="calcEditModalPrice()" required class="w-full bg-[#0c1326] border border-cyber-border p-2 rounded-lg text-white font-mono font-bold outline-none">
                    </div>
                    <div>
                        <label class="text-fuchsia-400 block mb-1 font-bold font-mono">Wholesale Disc (%)</label>
                        <input type="number" step="0.1" id="editDiscountPercent" name="reseller_discount_percent" oninput="calcEditModalPrice()" required class="w-full bg-[#0c1326] border border-cyber-border p-2 rounded-lg text-fuchsia-300 font-mono font-bold outline-none">
                    </div>
                </div>

                <div class="text-[11px] font-mono text-emerald-400 font-bold text-center" id="editModalPricePreview">
                    Reseller Benchmark: $0.00
                </div>

                <button type="submit" class="w-full py-3 bg-cyan-500 hover:bg-cyan-400 text-gray-950 font-black rounded-xl text-xs uppercase font-mono tracking-wider transition mt-2">
                    Update Validity Tier
                </button>
            </form>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- HIGH-PERFORMANCE NOC CLIENT-SIDE JAVASCRIPT ENGINE                -->
    <!-- ================================================================= -->
    <script>
        const productsGlobalData = <?= json_encode($productsData) ?>;

        function toggleAdminDrawer() {
            const drawer = document.getElementById('adminDrawer');
            const overlay = document.getElementById('drawerOverlay');
            drawer.classList.toggle('translate-x-full');
            overlay.classList.toggle('hidden');
        }

        function filterDeposits(status) {
            document.querySelectorAll('#tab-deposits .dep-row').forEach(tr => {
                tr.style.display = (status === 'all' || tr.dataset.status === status) ? '' : 'none';
            });
            document.querySelectorAll('.dep-filter-btn').forEach(b => b.classList.toggle('active', b.dataset.depFilter === status));
            try { sessionStorage.setItem('admin_dep_filter', status); } catch (e) {}
        }
        document.addEventListener('DOMContentLoaded', () => {
            let f = 'all';
            try { f = sessionStorage.getItem('admin_dep_filter') || 'all'; } catch (e) {}
            // pending thakle seta age dekhao
            if (f === 'all' && document.querySelector('#tab-deposits .dep-row[data-status="pending"]')) f = 'pending';
            filterDeposits(f);
        });

        function switchAdminTab(sectionId, titleName = '') {
            const drawer = document.getElementById('adminDrawer');
            if (!drawer.classList.contains('translate-x-full')) {
                toggleAdminDrawer();
            }

            document.querySelectorAll('.tab-section').forEach(sec => sec.classList.add('hidden'));
            const targetSection = document.getElementById(sectionId);
            if (targetSection) targetSection.classList.remove('hidden');

            if (titleName) {
                document.getElementById('headerSectionTitle').innerText = titleName;
            }

            sessionStorage.setItem('admin_active_tab', sectionId);
            sessionStorage.setItem('admin_active_title', titleName);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function switchProductSubTab(subId) {
            document.getElementById('prod-list').classList.add('hidden');
            document.getElementById('prod-add').classList.add('hidden');
            document.getElementById('prod-stock').classList.add('hidden');

            document.getElementById('subtab-btn-list').className = "flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center";
            document.getElementById('subtab-btn-add').className = "flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center";
            document.getElementById('subtab-btn-stock').className = "flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center";

            document.getElementById(subId).classList.remove('hidden');
            const activeBtn = (subId === 'prod-list') ? document.getElementById('subtab-btn-list') : ((subId === 'prod-add') ? document.getElementById('subtab-btn-add') : document.getElementById('subtab-btn-stock'));
            activeBtn.className = "flex-1 py-2.5 rounded-xl text-gray-950 bg-gradient-to-r from-cyan-400 via-teal-400 to-emerald-400 transition text-center shadow-md font-bold";
        }

        function switchResellerSubTab(subId) {
            document.getElementById('reseller-list-sec').classList.add('hidden');
            document.getElementById('reseller-add-sec').classList.add('hidden');
            document.getElementById('reseller-bonus-sec').classList.add('hidden');

            document.getElementById('subtab-btn-reseller-list').className = "flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center";
            document.getElementById('subtab-btn-reseller-add').className = "flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center";
            document.getElementById('subtab-btn-reseller-bonus').className = "flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center";

            document.getElementById(subId).classList.remove('hidden');
            let activeBtn = document.getElementById('subtab-btn-reseller-list');
            if (subId === 'reseller-add-sec') activeBtn = document.getElementById('subtab-btn-reseller-add');
            if (subId === 'reseller-bonus-sec') activeBtn = document.getElementById('subtab-btn-reseller-bonus');
            activeBtn.className = "flex-1 py-2.5 rounded-xl text-gray-950 bg-gradient-to-r from-emerald-400 via-teal-400 to-cyan-400 transition text-center shadow-md font-bold";
        }

        function switchLogsSubTab(subId) {
            document.getElementById('logs-activity-sec').classList.add('hidden');
            document.getElementById('logs-geo-sec').classList.add('hidden');

            document.getElementById('subtab-btn-logs-activity').className = "flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center";
            document.getElementById('subtab-btn-logs-geo').className = "flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center";

            document.getElementById(subId).classList.remove('hidden');
            const activeBtn = (subId === 'logs-activity-sec') ? document.getElementById('subtab-btn-logs-activity') : document.getElementById('subtab-btn-logs-geo');
            activeBtn.className = "flex-1 py-2.5 rounded-xl text-gray-950 bg-gradient-to-r from-pink-500 via-rose-500 to-cyan-400 transition text-center shadow-md font-bold";
        }

        function switchGatewaySubTab(subId) {
            document.getElementById('gateways-settings-sec').classList.add('hidden');
            document.getElementById('gateways-limits-sec').classList.add('hidden');

            document.getElementById('subtab-btn-gateways-settings').className = "flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center";
            document.getElementById('subtab-btn-gateways-limits').className = "flex-1 py-2.5 rounded-xl text-gray-400 hover:text-white transition text-center";

            document.getElementById(subId).classList.remove('hidden');
            const activeBtn = (subId === 'gateways-settings-sec') ? document.getElementById('subtab-btn-gateways-settings') : document.getElementById('subtab-btn-gateways-limits');
            activeBtn.className = "flex-1 py-2.5 rounded-xl text-gray-950 bg-gradient-to-r from-orange-400 via-amber-400 to-yellow-400 transition text-center shadow-md font-bold";
        }

        function filterResellerCards(query) {
            const q = query.trim().toLowerCase();
            const cards = document.querySelectorAll('.reseller-item-card');
            cards.forEach(card => {
                const u = card.getAttribute('data-username') || '';
                const tgUser = card.getAttribute('data-tg-username') || '';
                const tgId = card.getAttribute('data-tg-id') || '';

                if (u.includes(q) || tgUser.includes(q) || tgId.includes(q)) {
                    card.classList.remove('hidden');
                } else {
                    card.classList.add('hidden');
                }
            });
        }

        // -------------------------------------------------------------
        // RESELLER MANAGE MODAL WITH PRICING & SUBS TABS (V5.0)
        // -------------------------------------------------------------
        let activeModalApiKey = '';

        function switchManageResellerModalTab(tabId) {
            document.getElementById('reseller-subtab-general').classList.add('hidden');
            document.getElementById('reseller-subtab-pricing').classList.add('hidden');
            document.getElementById('reseller-subtab-subs').classList.add('hidden');

            document.getElementById('btn-reseller-subtab-general').className = "flex-1 py-2 rounded-lg text-gray-400 hover:text-white transition text-center";
            document.getElementById('btn-reseller-subtab-pricing').className = "flex-1 py-2 rounded-lg text-gray-400 hover:text-white transition text-center";
            document.getElementById('btn-reseller-subtab-subs').className = "flex-1 py-2 rounded-lg text-gray-400 hover:text-white transition text-center";

            document.getElementById(tabId).classList.remove('hidden');
            let btn = document.getElementById('btn-reseller-subtab-general');
            if (tabId === 'reseller-subtab-pricing') btn = document.getElementById('btn-reseller-subtab-pricing');
            if (tabId === 'reseller-subtab-subs') btn = document.getElementById('btn-reseller-subtab-subs');
            btn.className = "flex-1 py-2 rounded-lg text-gray-950 bg-gradient-to-r from-cyan-400 to-emerald-400 transition text-center shadow font-bold";
        }

        function openResellerManageModal(u) {
            activeModalApiKey = u.api_key || '';
            document.getElementById('modalResellerUsername').innerText = u.username;
            document.getElementById('modalResellerApiKey').innerText = u.api_key || 'No Key Generated';
            document.getElementById('modalResellerBalance').innerText = '$' + parseFloat(u.balance || 0).toFixed(2);
            document.getElementById('modalResellerSold').innerText = (u.total_sold_keys || 0) + ' Units';

            const tgText = u.telegram_chat_id ? `TG: #${u.telegram_chat_id} ${u.telegram_username ? '(@'+u.telegram_username+')' : ''}` : 'No Telegram Linked';
            document.getElementById('modalResellerTgTag').innerText = tgText;

            // Form bindings
            document.getElementById('modalRateUserId').value = u.id;
            document.getElementById('modalResellerRateInput').value = u.custom_bdt_rate || 0;
            document.getElementById('modalBalanceUserId').value = u.id;
            document.getElementById('modalRegenUserId').value = u.id;
            document.getElementById('modalBanUserId').value = u.id;
            document.getElementById('modalBanUserStatus').value = u.status;
            document.getElementById('modalResetPricingUserId').value = u.id;
            document.getElementById('modalSavePricingUserId').value = u.id;

            const banBtn = document.getElementById('modalBanToggleBtn');
            if (u.status === 'active') {
                banBtn.className = "w-full py-2.5 rounded-xl text-xs font-bold uppercase transition bg-red-500/20 text-red-400 border border-red-500/30 hover:bg-red-500/30";
                banBtn.innerText = "Lock Account";
            } else {
                banBtn.className = "w-full py-2.5 rounded-xl text-xs font-bold uppercase transition bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-500/30";
                banBtn.innerText = "Unlock Account";
            }

            document.getElementById('modalApiUserId').value = u.id;
            const apiBtn = document.getElementById('modalApiToggleBtn');
            const isApiActive = (parseInt(u.api_enabled) === 1 || u.api_enabled === "1" || u.api_enabled === null || u.api_enabled === undefined);

            if (isApiActive) {
                apiBtn.className = "w-full py-2.5 rounded-xl text-xs font-bold uppercase transition bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 hover:bg-red-500/20 hover:text-red-400";
                apiBtn.innerText = "API: ACTIVE (Lock)";
            } else {
                apiBtn.className = "w-full py-2.5 rounded-xl text-xs font-bold uppercase transition bg-red-500/20 text-red-400 border border-red-500/30 hover:bg-emerald-500/20 hover:text-emerald-400";
                apiBtn.innerText = "API: LOCKED (Unlock)";
            }

            // Load Custom Pricing & Sub-accounts via AJAX
            loadResellerPricingMatrix(u.id);
            loadResellerSubAccounts(u.id);

            switchManageResellerModalTab('reseller-subtab-general');
            document.getElementById('manageResellerModal').classList.remove('hidden');
        }

        async function loadResellerPricingMatrix(userId) {
            const container = document.getElementById('resellerPricingRowsContainer');
            container.innerHTML = '<div class="text-center py-8 text-gray-400 font-mono text-xs"><i class="fa-solid fa-spinner fa-spin text-xl text-cyan-400 mb-2"></i><br>Loading product pricing matrix...</div>';

            try {
                const fd = new FormData();
                fd.append('ajax_action', 'get_reseller_custom_pricing');
                fd.append('user_id', userId);
                const res = await fetch('admin.php', { method: 'POST', body: fd });
                const json = await res.json();

                if (!json.success || !json.pricing || json.pricing.length === 0) {
                    container.innerHTML = '<div class="text-center py-6 text-gray-500 font-mono text-xs">No active catalog plans found.</div>';
                    return;
                }

                let html = '';
                json.pricing.forEach(item => {
                    html += `
                        <div class="bg-[#040711] p-3 rounded-xl border border-cyber-border space-y-2 text-xs font-mono">
                            <input type="hidden" name="pricing_plan_id[]" value="${item.plan_id}">
                            <div class="flex justify-between items-center">
                                <div>
                                    <strong class="text-white text-xs block font-sans">${item.product_name}</strong>
                                    <span class="text-[10px] text-cyan-300">${item.plan_title} (Catalog: $${item.default_price.toFixed(2)})</span>
                                </div>
                                ${item.custom_price > 0 ? '<span class="text-[9px] px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 font-bold">CUSTOM PRICE ACTIVE</span>' : ''}
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-[10px] text-gray-400 block mb-0.5">Special Price ($ USD)</label>
                                    <input type="number" step="0.01" name="pricing_custom_price[]" value="${item.custom_price > 0 ? item.custom_price.toFixed(2) : ''}" placeholder="$${item.default_price.toFixed(2)}" class="w-full bg-[#080d1a] border border-cyber-border px-2.5 py-1.5 rounded-lg text-emerald-300 font-bold text-xs outline-none focus:border-cyan-400">
                                </div>
                                <div>
                                    <label class="text-[10px] text-gray-400 block mb-0.5">Special Discount (%)</label>
                                    <input type="number" min="0" max="100" name="pricing_custom_discount[]" value="${item.custom_discount > 0 ? item.custom_discount : ''}" placeholder="0%" class="w-full bg-[#080d1a] border border-cyber-border px-2.5 py-1.5 rounded-lg text-fuchsia-300 font-bold text-xs outline-none focus:border-cyan-400">
                                </div>
                            </div>
                        </div>
                    `;
                });
                container.innerHTML = html;
            } catch (err) {
                container.innerHTML = '<div class="text-center py-6 text-red-400 font-mono text-xs">Failed to load custom pricing rules.</div>';
            }
        }

        async function loadResellerSubAccounts(userId) {
            const container = document.getElementById('resellerSubsListContainer');
            const badge = document.getElementById('modalSubsCountBadge');
            container.innerHTML = '<div class="text-center py-8 text-gray-400 font-mono text-xs"><i class="fa-solid fa-spinner fa-spin text-xl text-amber-400 mb-2"></i><br>Querying sub-accounts...</div>';

            try {
                const fd = new FormData();
                fd.append('ajax_action', 'get_reseller_sub_accounts');
                fd.append('user_id', userId);
                const res = await fetch('admin.php', { method: 'POST', body: fd });
                const json = await res.json();

                if (!json.success || !json.sub_accounts || json.sub_accounts.length === 0) {
                    badge.innerText = '0';
                    container.innerHTML = '<div class="text-center py-8 text-gray-500 font-mono text-xs">This reseller has not created any sub-accounts yet.</div>';
                    return;
                }

                badge.innerText = json.sub_accounts.length;
                let html = '';
                json.sub_accounts.forEach(sub => {
                    const bal = parseFloat(sub.balance || 0).toFixed(2);
                    const st = sub.status || 'active';
                    html += `
                        <div class="bg-[#040711] p-3.5 rounded-xl border border-cyber-border space-y-2.5 text-xs font-mono">
                            <div class="flex justify-between items-center">
                                <div>
                                    <h5 class="text-white font-bold font-sans text-xs flex items-center gap-2">
                                        <span>${sub.username}</span>
                                        <span class="text-[9px] px-1.5 py-0.5 rounded uppercase ${st === 'active' ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' : 'bg-red-500/20 text-red-400 border border-red-500/30'}">${st}</span>
                                        <span class="text-[9px] px-1.5 py-0.5 rounded uppercase bg-cyan-500/20 text-cyan-300 border border-cyan-500/30">${sub.role}</span>
                                    </h5>
                                    <span class="text-[10px] text-gray-400">Rate: ${sub.custom_bdt_rate || 125} BDT • Keys: ${sub.sub_keys_count || 0}</span>
                                </div>
                                <div class="text-right">
                                    <span class="text-emerald-400 font-black text-sm block">$${bal}</span>
                                </div>
                            </div>

                            <div class="flex items-center space-x-1.5 pt-2 border-t border-cyber-border">
                                <form method="POST" class="flex-1 flex gap-1">
                                    <input type="hidden" name="admin_action" value="admin_manage_sub_account">
                                    <input type="hidden" name="active_tab" value="tab-resellers">
                                    <input type="hidden" name="sub_user_id" value="${sub.id}">
                                    <input type="hidden" name="sub_control_action" value="modify_balance">
                                    <input type="number" step="0.5" name="balance_amount" placeholder="$ Amount" required class="w-20 bg-[#080d1a] border border-cyber-border px-2 py-1 rounded text-xs text-white outline-none">
                                    <button type="submit" name="balance_type" value="add" class="px-2 py-1 rounded bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-[10px]">+ Bal</button>
                                    <button type="submit" name="balance_type" value="deduct" class="px-2 py-1 rounded bg-amber-600 hover:bg-amber-500 text-white font-bold text-[10px]">- Cut</button>
                                </form>

                                <form method="POST" class="inline">
                                    <input type="hidden" name="admin_action" value="admin_manage_sub_account">
                                    <input type="hidden" name="active_tab" value="tab-resellers">
                                    <input type="hidden" name="sub_user_id" value="${sub.id}">
                                    <input type="hidden" name="sub_control_action" value="toggle_ban">
                                    <input type="hidden" name="current_status" value="${st}">
                                    <button type="submit" class="px-2.5 py-1 rounded bg-[#121c32] hover:bg-[#182644] text-[10px] text-cyan-300 font-bold border border-cyan-500/30">
                                        ${st === 'active' ? 'Lock' : 'Unlock'}
                                    </button>
                                </form>

                                <form method="POST" class="inline" onsubmit="return confirm('Permanently delete sub-account ${sub.username}?');">
                                    <input type="hidden" name="admin_action" value="admin_manage_sub_account">
                                    <input type="hidden" name="active_tab" value="tab-resellers">
                                    <input type="hidden" name="sub_user_id" value="${sub.id}">
                                    <input type="hidden" name="sub_control_action" value="delete">
                                    <button type="submit" class="p-1 px-2 rounded bg-red-500/20 text-red-400 hover:bg-red-500/30 border border-red-500/30 text-[10px] font-bold">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    `;
                });
                container.innerHTML = html;
            } catch (err) {
                container.innerHTML = '<div class="text-center py-6 text-red-400 font-mono text-xs">Failed to load sub-accounts.</div>';
            }
        }

        function copyModalResellerApiKey() {
            if (activeModalApiKey) {
                copyText(activeModalApiKey, 'Reseller Live API Key Copied!');
            } else {
                showToast('No API Key generated for this user!', 'error');
            }
        }

        // -------------------------------------------------------------
        // DRILL DOWNS & MODALS LOGIC
        // -------------------------------------------------------------
        async function openTodayKeysModal() {
            const modal = document.getElementById('todayKeysModal');
            const container = document.getElementById('todayKeysContainer');
            const summaryText = document.getElementById('todayKeysSummaryText');
            modal.classList.remove('hidden');
            container.innerHTML = '<div class="text-center py-10 text-gray-400 font-mono text-xs"><i class="fa-solid fa-spinner fa-spin text-2xl text-amber-400 mb-2"></i><br>Querying today\'s dispatch records...</div>';

            try {
                const fd = new FormData();
                fd.append('ajax_action', 'get_today_keys_detail');
                const res = await fetch('admin.php', { method: 'POST', body: fd });
                const json = await res.json();

                if (!json.success || !Array.isArray(json.data) || json.data.length === 0) {
                    container.innerHTML = '<div class="text-center py-10 text-gray-500 font-mono text-xs">No licenses have been generated today yet.</div>';
                    summaryText.innerText = 'Total Units Dispatched Today: 0';
                    return;
                }

                summaryText.innerText = `Total Units Dispatched Today: ${json.data.length}`;
                let html = `
                    <table class="w-full text-left text-xs font-mono">
                        <thead>
                            <tr class="text-gray-400 border-b border-cyber-border">
                                <th class="pb-2.5">Time</th>
                                <th class="pb-2.5">Reseller Identity</th>
                                <th class="pb-2.5">Catalog Plan</th>
                                <th class="pb-2.5">Serial License Key</th>
                                <th class="pb-2.5">Price</th>
                                <th class="pb-2.5 text-right">Channel</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyber-border text-[11px]">
                `;

                json.data.forEach(item => {
                    const price = parseFloat(item.price || 0).toFixed(2);
                    const source = (item.buy_source || 'PORTAL').toUpperCase();
                    const tg = item.telegram_chat_id ? `(#${item.telegram_chat_id})` : '';
                    html += `
                        <tr class="hover:bg-[#0c1326]">
                            <td class="py-2.5 text-gray-400">${item.created_at || 'Today'}</td>
                            <td class="py-2.5 font-sans font-bold text-white">
                                ${item.username} <span class="text-amber-300 font-mono text-[10px] block">${tg}</span>
                            </td>
                            <td class="py-2.5 text-cyan-300 font-semibold">${item.validity || item.product_name || 'License Node'}</td>
                            <td class="py-2.5 text-fuchsia-300 font-bold select-all tracking-wider text-[11px]">${item.license_key}</td>
                            <td class="py-2.5 text-emerald-400 font-bold">$${price}</td>
                            <td class="py-2.5 text-right">
                                <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 uppercase">
                                    ${source}
                                </span>
                            </td>
                        </tr>
                    `;
                });

                html += '</tbody></table>';
                container.innerHTML = html;
            } catch (err) {
                container.innerHTML = '<div class="text-center py-8 text-red-400 font-mono text-xs">Failed to communicate with telemetry endpoint.</div>';
            }
        }

        async function openTodayDepositsModal() {
            const modal = document.getElementById('todayDepositsModal');
            const container = document.getElementById('todayDepositsContainer');
            const summaryText = document.getElementById('todayDepositsSummaryText');
            modal.classList.remove('hidden');
            container.innerHTML = '<div class="text-center py-10 text-gray-400 font-mono text-xs"><i class="fa-solid fa-spinner fa-spin text-2xl text-teal-400 mb-2"></i><br>Loading today\'s deposit transactions...</div>';

            try {
                const fd = new FormData();
                fd.append('ajax_action', 'get_today_deposits_detail');
                const res = await fetch('admin.php', { method: 'POST', body: fd });
                const json = await res.json();

                if (!json.success || !Array.isArray(json.data) || json.data.length === 0) {
                    container.innerHTML = '<div class="text-center py-10 text-gray-500 font-mono text-xs">No deposit transactions logged today yet.</div>';
                    summaryText.innerText = 'Total Requests: 0';
                    return;
                }

                let sumApproved = 0.00;
                json.data.forEach(d => {
                    if (d.status === 'approved') sumApproved += parseFloat(d.amount || 0);
                });

                summaryText.innerText = `Approved Liquidity: $${sumApproved.toFixed(2)} USD (${json.data.length} total requests)`;

                let html = `
                    <table class="w-full text-left text-xs font-mono">
                        <thead>
                            <tr class="text-gray-400 border-b border-cyber-border">
                                <th class="pb-2.5">Time</th>
                                <th class="pb-2.5">Reseller</th>
                                <th class="pb-2.5">Gateway</th>
                                <th class="pb-2.5">Amount ($)</th>
                                <th class="pb-2.5">Sender Number</th>
                                <th class="pb-2.5">Transaction ID</th>
                                <th class="pb-2.5 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyber-border text-[11px]">
                `;

                json.data.forEach(item => {
                    const amt = parseFloat(item.amount || 0).toFixed(2);
                    const st = item.status || 'pending';
                    const badgeClass = (st === 'approved') ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30' : ((st === 'pending') ? 'bg-amber-500/20 text-amber-400 border-amber-500/30' : 'bg-red-500/20 text-red-400 border-red-500/30');

                    html += `
                        <tr class="hover:bg-[#0c1326]">
                            <td class="py-2.5 text-gray-400">${item.created_at || 'Today'}</td>
                            <td class="py-2.5 font-sans font-bold text-white">${item.username}</td>
                            <td class="py-2.5 text-cyan-400 font-bold">${item.method}</td>
                            <td class="py-2.5 text-emerald-400 font-black text-xs">$${amt}</td>
                            <td class="py-2.5 text-gray-300">${item.sender_num}</td>
                            <td class="py-2.5 text-cyan-300 font-bold select-all">${item.trx_id}</td>
                            <td class="py-2.5 text-right">
                                <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase ${badgeClass} border">
                                    ${st.toUpperCase()}
                                </span>
                            </td>
                        </tr>
                    `;
                });

                html += '</tbody></table>';
                container.innerHTML = html;
            } catch (err) {
                container.innerHTML = '<div class="text-center py-8 text-red-400 font-mono text-xs">Failed to communicate with deposit registry.</div>';
            }
        }

        async function openResellerDossierModal(userId, username) {
            const modal = document.getElementById('resellerDossierModal');
            modal.classList.remove('hidden');

            document.getElementById('dossierResellerUsername').innerText = `${username} (Dossier)`;
            document.getElementById('dossierKeysTableBody').innerHTML = '<tr><td colspan="5" class="py-8 text-center text-gray-400"><i class="fa-solid fa-spinner fa-spin text-xl text-purple-400 mb-2"></i><br>Querying audit records...</td></tr>';
            document.getElementById('dossierDepositsTableBody').innerHTML = '<tr><td colspan="6" class="py-8 text-center text-gray-400"><i class="fa-solid fa-spinner fa-spin text-xl text-teal-400 mb-2"></i><br>Querying deposit ledger...</td></tr>';
            document.getElementById('dossierActivityTableBody').innerHTML = '<tr><td colspan="4" class="py-8 text-center text-gray-400"><i class="fa-solid fa-spinner fa-spin text-xl text-cyan-400 mb-2"></i><br>Querying activity trail...</td></tr>';

            switchDossierTab('dossier-tab-keys');

            try {
                const fd = new FormData();
                fd.append('ajax_action', 'get_reseller_dossier');
                fd.append('user_id', userId);
                const res = await fetch('admin.php', { method: 'POST', body: fd });
                const json = await res.json();

                if (!json.success) {
                    alert(json.error || 'Failed to locate reseller dossier');
                    closeModal('resellerDossierModal');
                    return;
                }

                const u = json.user;
                const stats = json.stats;

                const stBadge = document.getElementById('dossierStatusBadge');
                stBadge.innerText = (u.status || 'ACTIVE').toUpperCase();
                stBadge.className = (u.status === 'active') ? "px-2 py-0.5 rounded text-[9px] font-black uppercase font-mono bg-emerald-500/20 text-emerald-400 border border-emerald-500/30" : "px-2 py-0.5 rounded text-[9px] font-black uppercase font-mono bg-red-500/20 text-red-400 border border-red-500/30";

                const tgInfo = u.telegram_chat_id ? `TG: #${u.telegram_chat_id} ${u.telegram_username ? '(@'+u.telegram_username+')' : ''}` : 'No Telegram Linked';
                document.getElementById('dossierTelegramInfo').innerText = tgInfo;
                document.getElementById('dossierCreatedDate').innerText = `Registered: ${u.created_at || 'Recent'}`;

                document.getElementById('dossierBalance').innerText = '$' + parseFloat(u.balance || 0).toFixed(2);
                document.getElementById('dossierKeysCount').innerText = `${stats.total_keys_count || 0} Units`;
                document.getElementById('dossierSpentUSD').innerText = '$' + (stats.total_spent_usd || '0.00');
                document.getElementById('dossierDepositedUSD').innerText = '$' + (stats.total_deposited || '0.00');

                document.getElementById('dossierBadgeKeys').innerText = stats.total_keys_count || 0;
                document.getElementById('dossierBadgeDeposits').innerText = (json.deposits || []).length;

                const tbodyKeys = document.getElementById('dossierKeysTableBody');
                if (json.keys && json.keys.length > 0) {
                    let kHtml = '';
                    json.keys.forEach(k => {
                        const pr = parseFloat(k.price || 0).toFixed(2);
                        const ch = (k.buy_source || 'PORTAL').toUpperCase();
                        kHtml += `
                            <tr class="hover:bg-[#0c1326]">
                                <td class="py-2 text-gray-400">${k.created_at || 'N/A'}</td>
                                <td class="py-2 text-white font-sans font-bold">${k.validity || k.product_name || 'License Package'}</td>
                                <td class="py-2 text-fuchsia-300 font-bold select-all tracking-wider text-[11px]">${k.license_key}</td>
                                <td class="py-2 text-emerald-400 font-bold">$${pr}</td>
                                <td class="py-2 text-right">
                                    <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-cyan-500/20 text-cyan-300 border border-cyan-500/30">
                                        ${ch}
                                    </span>
                                </td>
                            </tr>
                        `;
                    });
                    tbodyKeys.innerHTML = kHtml;
                } else {
                    tbodyKeys.innerHTML = '<tr><td colspan="5" class="py-6 text-center text-gray-500 italic">No licenses purchased by this reseller yet.</td></tr>';
                }

                const tbodyDep = document.getElementById('dossierDepositsTableBody');
                if (json.deposits && json.deposits.length > 0) {
                    let dHtml = '';
                    json.deposits.forEach(d => {
                        const amt = parseFloat(d.amount || 0).toFixed(2);
                        const st = d.status || 'pending';
                        const badgeClass = (st === 'approved') ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30' : ((st === 'pending') ? 'bg-amber-500/20 text-amber-400 border-amber-500/30' : 'bg-red-500/20 text-red-400 border-red-500/30');
                        dHtml += `
                            <tr class="hover:bg-[#0c1326]">
                                <td class="py-2 text-gray-400">${d.created_at || 'N/A'}</td>
                                <td class="py-2 text-cyan-300 font-bold">${d.method}</td>
                                <td class="py-2 text-emerald-400 font-bold">$${amt}</td>
                                <td class="py-2 text-gray-300">${d.sender_num}</td>
                                <td class="py-2 text-amber-300 font-bold select-all">${d.trx_id}</td>
                                <td class="py-2 text-right">
                                    <span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase ${badgeClass} border">
                                        ${st.toUpperCase()}
                                    </span>
                                </td>
                            </tr>
                        `;
                    });
                    tbodyDep.innerHTML = dHtml;
                } else {
                    tbodyDep.innerHTML = '<tr><td colspan="6" class="py-6 text-center text-gray-500 italic">No deposit requests recorded for this account.</td></tr>';
                }

                const tbodyAct = document.getElementById('dossierActivityTableBody');
                if (json.activities && json.activities.length > 0) {
                    let aHtml = '';
                    json.activities.forEach(a => {
                        aHtml += `
                            <tr class="hover:bg-[#0c1326]">
                                <td class="py-2 text-gray-400">${a.created_at || 'N/A'}</td>
                                <td class="py-2"><span class="px-2 py-0.5 rounded bg-purple-500/20 text-purple-300 border border-purple-500/30 font-bold">${a.action}</span></td>
                                <td class="py-2 text-white">${a.details}</td>
                                <td class="py-2 text-right text-gray-500">${a.ip_address}</td>
                            </tr>
                        `;
                    });
                    tbodyAct.innerHTML = aHtml;
                } else {
                    tbodyAct.innerHTML = '<tr><td colspan="4" class="py-6 text-center text-gray-500 italic">No security or HWID reset events recorded.</td></tr>';
                }

            } catch (err) {
                alert('Failure parsing reseller dossier telemetry.');
            }
        }

        function switchDossierTab(tabId) {
            document.getElementById('dossier-tab-keys').classList.add('hidden');
            document.getElementById('dossier-tab-deposits').classList.add('hidden');
            document.getElementById('dossier-tab-activity').classList.add('hidden');

            document.getElementById('btn-dossier-keys').className = "flex-1 py-2 rounded-lg text-gray-400 hover:text-white transition text-center";
            document.getElementById('btn-dossier-deposits').className = "flex-1 py-2 rounded-lg text-gray-400 hover:text-white transition text-center";
            document.getElementById('btn-dossier-activity').className = "flex-1 py-2 rounded-lg text-gray-400 hover:text-white transition text-center";

            document.getElementById(tabId).classList.remove('hidden');
            let btn = document.getElementById('btn-dossier-keys');
            if (tabId === 'dossier-tab-deposits') btn = document.getElementById('btn-dossier-deposits');
            if (tabId === 'dossier-tab-activity') btn = document.getElementById('btn-dossier-activity');
            btn.className = "flex-1 py-2 rounded-lg text-gray-950 bg-gradient-to-r from-purple-400 to-cyan-400 transition text-center shadow font-bold";
        }

        function onStockProductSelectChange() {
            const prodSelect = document.getElementById('stockProductSelect');
            const planSelect = document.getElementById('stockPlanSelect');
            const prodId = parseInt(prodSelect.value);
            planSelect.innerHTML = '<option value="">-- Choose Validity Tier --</option>';

            const matchedProd = productsGlobalData.find(p => parseInt(p.id) === prodId);
            if (matchedProd && matchedProd.plans && matchedProd.plans.length > 0) {
                matchedProd.plans.forEach(pl => {
                    const opt = document.createElement('option');
                    opt.value = pl.id;
                    opt.innerText = `${pl.plan_title} (Current Vault: ${pl.stock_count || 0} | Provider ID: #${pl.api_plan_id || 0})`;
                    planSelect.appendChild(opt);
                });
            } else {
                const opt = document.createElement('option');
                opt.value = '';
                opt.innerText = 'No validity tiers configured for this node';
                planSelect.appendChild(opt);
            }
        }

        let rawProviderProducts = [];
        async function openSelectProviderModal() {
            const modal = document.getElementById('selectProviderProdModal');
            const container = document.getElementById('providerProdListContainer');
            modal.classList.remove('hidden');
            container.innerHTML = '<div class="text-center py-8 text-gray-400"><i class="fa-solid fa-spinner fa-spin text-xl text-cyan-400 mb-2"></i><br>Connecting to Provider API Gateway...</div>';

            try {
                const formData = new FormData();
                formData.append('ajax_action', 'get_provider_products');
                const res = await fetch('admin.php', { method: 'POST', body: formData });
                const data = await res.json();
                const items = data.products || (data.data && data.data.products) || data.data || (Array.isArray(data) ? data : []);

                rawProviderProducts = items;

                if (!Array.isArray(items) || items.length === 0) {
                    container.innerHTML = '<div class="text-center py-6 text-red-400 font-bold">No products returned or Invalid Provider Secret Token!</div>';
                    return;
                }

                container.innerHTML = '';
                items.forEach((p, idx) => {
                    const id = p.id || p.product_id || 0;
                    const name = p.name || 'License Node';
                    const price = parseFloat(p.price || 0).toFixed(2);

                    const row = document.createElement('label');
                    row.className = "flex items-center justify-between p-3 bg-[#080d1a] rounded-xl border border-cyber-border cursor-pointer hover:border-cyan-500/40 transition";
                    row.innerHTML = `
                        <div class="flex items-center space-x-3 overflow-hidden">
                            <input type="checkbox" value="${idx}" class="provider-item-check rounded text-cyan-500 w-4 h-4 bg-[#040711] border-cyber-border">
                            <div>
                                <strong class="text-white text-xs block truncate font-sans">${name}</strong>
                                <span class="text-[10px] text-gray-400 font-mono">Plan ID: #${id} • Benchmark: $${price}</span>
                            </div>
                        </div>
                        <span class="text-xs font-mono font-bold text-emerald-400">$${price}</span>
                    `;
                    container.appendChild(row);
                });
            } catch (err) {
                container.innerHTML = '<div class="text-center py-6 text-red-400 font-bold">Failed to communicate with Provider API.</div>';
            }
        }

        let selectAllState = false;
        function toggleSelectAllProviderProds() {
            selectAllState = !selectAllState;
            document.querySelectorAll('.provider-item-check').forEach(cb => cb.checked = selectAllState);
        }

        function prepareSelectedProductsImport() {
            const checks = document.querySelectorAll('.provider-item-check:checked');
            if (checks.length === 0) {
                alert('Please select at least one catalog node to ingest!');
                return false;
            }
            const selected = [];
            checks.forEach(cb => {
                const idx = parseInt(cb.value);
                if (rawProviderProducts[idx]) {
                    selected.push(rawProviderProducts[idx]);
                }
            });
            document.getElementById('selectedProductsJsonInput').value = JSON.stringify(selected);
            return true;
        }

        function toggleProductAccordion(accId, headerEl) {
            const acc = document.getElementById(accId);
            const chevron = headerEl.querySelector('.fa-chevron-down');
            if (acc) {
                acc.classList.toggle('hidden');
                if (chevron) chevron.classList.toggle('rotate-180');
            }
        }

        function openEditProdModal(id, name, sortOrder, discountPercent = 0) {
            document.getElementById('editTargetProdId').value = id;
            document.getElementById('editTargetProdName').value = name;
            document.getElementById('editTargetSortOrder').value = sortOrder;
            document.getElementById('editTargetDiscount').value = discountPercent || 0;
            document.getElementById('editProdModal').classList.remove('hidden');
        }

        function openAttachPlanModal(id, name) {
            document.getElementById('attachTargetProdId').value = id;
            document.getElementById('attachProdNameSpan').innerText = name;
            document.getElementById('attachPlanModal').classList.remove('hidden');
        }

        function calcEditModalPrice() {
            const userPrice = parseFloat(document.getElementById('editUserPrice').value) || 0;
            const discount = parseFloat(document.getElementById('editDiscountPercent').value) || 0;
            const resellerPrice = (userPrice * (1 - (discount / 100))).toFixed(2);
            document.getElementById('editModalPricePreview').innerText = `Reseller Benchmark: $${resellerPrice} (-${discount}%)`;
        }

        function openEditPlanModal(plan) {
            document.getElementById('editPlanId').value = plan.id;
            document.getElementById('editPlanTitle').value = plan.plan_title;
            document.getElementById('editApiPlanId').value = plan.api_plan_id || 0;
            document.getElementById('editResetApiId').value = String(plan.reset_api_id || 0);
            document.getElementById('editUserPrice').value = plan.user_price || plan.reseller_price || 0;
            document.getElementById('editDiscountPercent').value = plan.reseller_discount_percent || 0;
            calcEditModalPrice();
            document.getElementById('editPlanModal').classList.remove('hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        function addValidityRow() {
            const container = document.getElementById('validityRowsContainer');
            const row = document.createElement('div');
            row.className = "validity-row bg-[#080d1a] p-4 rounded-2xl border border-cyber-border space-y-3 relative";
            row.innerHTML = `
                <button type="button" onclick="this.parentElement.remove()" class="absolute top-2.5 right-3 text-red-400 hover:text-red-300 text-xs font-mono"><i class="fa-solid fa-xmark"></i> Remove</button>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div>
                        <label class="text-gray-400 block mb-1 font-semibold">Plan Label</label>
                        <input type="text" name="plan_title[]" placeholder="e.g. 7 Days" required class="w-full bg-[#0c1326] border border-cyber-border p-2.5 rounded-xl text-white outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label class="text-gray-400 block mb-1 font-semibold">Duration Value</label>
                        <input type="number" name="duration_value[]" min="1" value="7" required class="w-full bg-[#0c1326] border border-cyber-border p-2.5 rounded-xl text-white font-mono outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label class="text-gray-400 block mb-1 font-semibold">Temporal Unit</label>
                        <select name="duration_unit[]" class="w-full bg-[#0c1326] border border-cyber-border p-2.5 rounded-xl text-cyan-300 font-mono font-bold outline-none">
                            <option value="hours">Hours</option>
                            <option value="days" selected>Days</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-amber-400 block mb-1 font-bold font-mono">Provider Plan ID</label>
                        <input type="number" name="api_plan_id[]" placeholder="e.g. 59" class="w-full bg-[#0c1326] border border-cyber-border p-2.5 rounded-xl text-amber-300 font-mono font-bold outline-none focus:border-amber-400">
                    </div>
                                <div>
                                    <label class="text-fuchsia-400 block mb-1 font-bold font-mono">Reset API</label>
                                    <select name="reset_api_id[]" class="w-full bg-[#0c1326] border border-cyber-border p-2.5 rounded-xl text-fuchsia-300 font-mono font-bold outline-none focus:border-fuchsia-400"><?= resetApiOptionsHtml(0) ?></select>
                                </div>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="text-cyan-300 block mb-1 font-bold font-mono">Benchmark Price ($)</label>
                        <input type="number" step="0.01" name="user_price[]" placeholder="e.g. 15.00" required class="w-full bg-[#0c1326] border border-cyber-border p-2.5 rounded-xl text-white font-mono font-bold outline-none focus:border-cyan-400">
                    </div>
                    <div>
                        <label class="text-fuchsia-400 block mb-1 font-bold font-mono">Wholesale Discount (%)</label>
                        <input type="number" step="0.1" min="0" max="100" name="reseller_discount_percent[]" value="0" class="w-full bg-[#0c1326] border border-cyber-border p-2.5 rounded-xl text-fuchsia-300 font-mono font-bold outline-none focus:border-fuchsia-400">
                    </div>
                </div>
            `;
            container.appendChild(row);
        }

        function copyText(text, successMessage = 'Copied to System Clipboard!') {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    showToast(successMessage, 'success');
                });
            } else {
                const textArea = document.createElement("textarea");
                textArea.value = text;
                textArea.style.position = "fixed";
                textArea.style.opacity = "0";
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    document.execCommand('copy');
                    showToast(successMessage, 'success');
                } catch (err) {
                    showToast('Failed to copy', 'error');
                }
                document.body.removeChild(textArea);
            }
        }

        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toastNotification');
            const toastInner = document.getElementById('toastInner');
            const toastMsg = document.getElementById('toastMsg');
            const toastIcon = document.getElementById('toastIcon');

            toastMsg.innerText = msg;
            if (type === 'error') {
                toastIcon.className = "fa-solid fa-triangle-exclamation text-red-400 text-base";
                toastInner.className = "bg-[#180e1a]/95 border border-red-500/60 shadow-2xl rounded-2xl p-4 flex items-center space-x-3.5 text-xs font-semibold text-red-200 backdrop-blur-2xl";
            } else {
                toastIcon.className = "fa-solid fa-circle-check text-cyan-400 text-base";
                toastInner.className = "bg-[#0c1428]/95 border border-cyan-500/60 shadow-2xl rounded-2xl p-4 flex items-center space-x-3.5 text-xs font-semibold text-cyan-200 backdrop-blur-2xl";
            }

            toast.classList.remove('opacity-0', 'translate-y-[-20px]');
            toast.classList.add('opacity-100', 'translate-y-0');

            setTimeout(() => {
                toast.classList.remove('opacity-100', 'translate-y-0');
                toast.classList.add('opacity-0', 'translate-y-[-20px]');
            }, 3200);
        }

        window.addEventListener('DOMContentLoaded', () => {
            const initialTab = '<?= $activeTab ?>' !== 'tab-overview' ? '<?= $activeTab ?>' : (sessionStorage.getItem('admin_active_tab') || 'tab-overview');
            const initialTitle = sessionStorage.getItem('admin_active_title') || 'Overview';
            
            document.querySelectorAll('.tab-section').forEach(sec => sec.classList.add('hidden'));
            const targetSection = document.getElementById(initialTab);
            if (targetSection) targetSection.classList.remove('hidden');
            document.getElementById('headerSectionTitle').innerText = initialTitle;

            <?php if ($toast): ?>
                showToast("<?= addslashes($toast['msg']) ?>", "<?= $toast['type'] ?>");
            <?php endif; ?>
        });
    </script>
</body>
</html>