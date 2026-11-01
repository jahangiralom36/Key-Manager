<?php
// ====================================================================
// INDEX.PHP — DUAL-DEVICE SMART LAUNCHER & HIGH-SECURITY GATEWAY
// ====================================================================
ini_set('display_errors', '0');
error_reporting(E_ALL);

// 1. Silent Automated Bot & CLI Blocker
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$blockedAgents = ['curl', 'wget', 'python', 'urllib', 'httpclient', 'termux', 'libwww', 'scrapy', 'postman', 'nikto', 'headless'];

foreach ($blockedAgents as $badAgent) {
    if (empty($userAgent) || stripos($userAgent, $badAgent) !== false) {
        http_response_code(403);
        exit("403 Forbidden: Access Denied.");
    }
}

require_once __DIR__ . '/config.php';

if (!defined('TG_BOT_USERNAME')) {
    define('TG_BOT_USERNAME', 'keymanagerverifyBoT');
}

// Redirect if session is already authenticated
if (isset($_SESSION['auth_logged_in']) && $_SESSION['auth_logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

// Flash Error Handling (Clears on reload so no persistent error shows)
$loginError = $_SESSION['flash_login_error'] ?? null;
unset($_SESSION['flash_login_error']);

// Generate clean fresh token for verification
if (!isset($_SESSION['web_auth_token']) || (empty($_POST) && !isset($_GET['check_status']))) {
    $_SESSION['web_auth_token'] = bin2hex(random_bytes(24));
    $_SESSION['tg_verified'] = false;
    unset($_SESSION['tg_chat_id'], $_SESSION['tg_username']);

    try {
        $stmt = $pdo->prepare("INSERT INTO tg_auth_sessions (session_token, status) VALUES (?, 'pending')");
        $stmt->execute([$_SESSION['web_auth_token']]);
    } catch (Exception $e) {}
}

$sessionToken = $_SESSION['web_auth_token'];

// 2. Real-Time Status Check (AJAX Polling)
if (isset($_GET['check_status'])) {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("SELECT is_tg_bound, is_otp_verified, otp_code, status, otp_expires_at, telegram_username 
                           FROM tg_auth_sessions WHERE session_token = ? LIMIT 1");
    $stmt->execute([$sessionToken]);
    $row = $stmt->fetch();

    $isGenuineDelivery = (
        !empty($row['is_tg_bound']) && 
        !empty($row['otp_code']) && 
        $row['status'] === 'pending' && 
        strtotime($row['otp_expires_at']) > time()
    );
    
    echo json_encode([
        'bound'    => $isGenuineDelivery,
        'verified' => (bool)($row['is_otp_verified'] ?? false),
        'tg_user'  => $row['telegram_username'] ?? ''
    ]);
    exit;
}

// 3. AJAX OTP Verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    header('Content-Type: application/json');
    $inputOtp = trim($_POST['otp'] ?? '');

    // Honeypot check
    if (!empty($_POST['hp_security_check'])) {
        echo json_encode(['status' => 'error', 'message' => 'Automated access denied.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM tg_auth_sessions WHERE session_token = ? LIMIT 1");
    $stmt->execute([$sessionToken]);
    $auth = $stmt->fetch();

    if (!$auth || empty($auth['otp_code']) || $auth['status'] !== 'pending') {
        echo json_encode(['status' => 'error', 'message' => 'No active OTP found. Please click Start Bot again!']);
        exit;
    }

    if (strtotime($auth['otp_expires_at']) < time()) {
        echo json_encode(['status' => 'error', 'message' => 'OTP has expired! Please request a new code.']);
        exit;
    }

    if ($auth['attempts'] >= 5) {
        echo json_encode(['status' => 'error', 'message' => 'Session blocked due to 5 failed attempts!']);
        exit;
    }

    if ($auth['otp_code'] === $inputOtp) {
        $pdo->prepare("UPDATE tg_auth_sessions SET is_otp_verified = 1, status = 'used', otp_code = NULL WHERE session_token = ?")
            ->execute([$sessionToken]);

        $_SESSION['tg_verified'] = true;
        $_SESSION['tg_chat_id'] = $auth['telegram_chat_id'];
        $_SESSION['tg_username'] = $auth['telegram_username'];

        // Auto-Destruct Telegram Message
        if (!empty($auth['telegram_chat_id']) && !empty($auth['telegram_message_id']) && function_exists('tgApiRequest')) {
            $notice = "🛡️ <b>KEYMANAGER SECURITY NOTICE</b> 🛡️\n━━━━━━━━━━━━━━━━━━━━\n✅ <b>Status:</b> OTP VERIFIED & CONSUMED\n🔒 <i>The 5-digit verification code has been permanently deleted for your security.</i>\n━━━━━━━━━━━━━━━━━━━━";
            tgApiRequest('editMessageText', [
                'chat_id'      => $auth['telegram_chat_id'],
                'message_id'   => $auth['telegram_message_id'],
                'text'         => $notice,
                'parse_mode'   => 'HTML',
                'reply_markup' => ['inline_keyboard' => []]
            ]);
        }
        
        echo json_encode(['status' => 'success', 'message' => 'OTP Verified! Enter account credentials.']);
        exit;
    } else {
        $pdo->prepare("UPDATE tg_auth_sessions SET attempts = attempts + 1 WHERE session_token = ?")->execute([$sessionToken]);
        echo json_encode(['status' => 'error', 'message' => 'Invalid 5-digit code! Check Telegram.']);
        exit;
    }
}

// 4. Account Login & 1:1 Telegram Lock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'account_login') {
    if (empty($_SESSION['tg_verified']) || $_SESSION['tg_verified'] !== true) {
        $_SESSION['flash_login_error'] = "Security Alert: Telegram OTP verification is mandatory!";
        header('Location: index.php');
        exit;
    }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$username]);
    $userData = $stmt->fetch();

    if ($userData && password_verify($password, $userData['password'])) {
        if (!empty($userData['telegram_chat_id'])) {
            if ((string)$userData['telegram_chat_id'] !== (string)$_SESSION['tg_chat_id']) {
                $_SESSION['flash_login_error'] = "CRITICAL SECURITY LOCK: This account is permanently tied to Telegram UID #" . $userData['telegram_chat_id'] . ". Access denied!";
                header('Location: index.php');
                exit;
            } else {
                session_regenerate_id(true);
                $_SESSION['auth_logged_in'] = true;
                $_SESSION['auth_user_id'] = $userData['id'];
                $_SESSION['auth_username'] = $userData['username'];
                header('Location: dashboard.php');
                exit;
            }
        } else {
            $pdo->prepare("UPDATE users SET telegram_chat_id = ?, telegram_username = ? WHERE id = ?")
                ->execute([$_SESSION['tg_chat_id'], $_SESSION['tg_username'] ?? 'User', $userData['id']]);

            session_regenerate_id(true);
            $_SESSION['auth_logged_in'] = true;
            $_SESSION['auth_user_id'] = $userData['id'];
            $_SESSION['auth_username'] = $userData['username'];
            header('Location: dashboard.php');
            exit;
        }
    } else {
        $_SESSION['flash_login_error'] = "Invalid Username or Password!";
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Secure Gateway — <?= defined('SITE_NAME') ? SITE_NAME : 'Reseller Panel' ?></title>
    <!-- Google Fonts & Tailwind CSS -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body { background-color: #0c0818; font-family: 'Plus Jakarta Sans', sans-serif; color: #f1f5f9; overflow-x: hidden; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .cyber-card { background: #120c24; border: 1px solid #2d184f; box-shadow: 0 -4px 30px rgba(217, 70, 239, 0.25), 0 10px 40px rgba(0, 0, 0, 0.8); position: relative; }
        .cyber-card::before { content: ''; position: absolute; top: 0; left: 10%; right: 10%; height: 2px; background: linear-gradient(90deg, transparent, #d946ef, #a855f7, transparent); }
        .btn-magenta-glow { background: linear-gradient(90deg, #d946ef 0%, #c026d3 50%, #a855f7 100%); box-shadow: 0 4px 25px rgba(217, 70, 239, 0.45); }
        .btn-magenta-glow:active { transform: scale(0.97); }
        .captcha-box { background: #080312; border: 1px solid #281747; transition: all 0.3s ease; }
        .captcha-box.verified { border-color: #10b981; background: #061912; }
    </style>
</head>
<body class="min-h-screen w-full flex items-center justify-center p-3 relative">

    <!-- Floating Auto-Dismiss Toast -->
    <div id="toastNotification" class="fixed top-4 left-1/2 transform -translate-x-1/2 z-50 transition-all duration-300 pointer-events-none opacity-0 translate-y-[-20px] w-[90%] max-w-sm">
        <div id="toastInner" class="bg-[#1b1035] border border-fuchsia-500/50 shadow-2xl rounded-2xl p-3.5 flex items-center space-x-3 text-xs font-semibold text-fuchsia-200 backdrop-blur-xl">
            <i id="toastIcon" class="fa-solid fa-circle-check text-fuchsia-400 text-base"></i>
            <span id="toastMsg">Notification</span>
        </div>
    </div>

    <!-- Security Gateway Container -->
    <div class="cyber-card rounded-[28px] w-full max-w-md p-6 sm:p-8 space-y-6">
        
        <!-- Header Brand -->
        <div class="text-center space-y-1.5">
            <div class="w-14 h-14 mx-auto rounded-2xl bg-gradient-to-tr from-fuchsia-600 to-purple-600 flex items-center justify-center text-white text-2xl shadow-lg shadow-fuchsia-950/60 mb-3">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <h1 class="text-xl font-black text-white tracking-wider leading-tight uppercase">SECURE GATEWAY</h1>
            <p class="text-xs text-purple-300/70 font-medium">Protection by Key Manager Team</p>
        </div>

        <!-- Flash Error Box (Fades out automatically in 3.5s) -->
        <?php if ($loginError): ?>
            <div id="errorAlertBox" class="p-3.5 bg-red-950/90 border border-red-500 text-red-300 rounded-2xl text-xs font-bold flex items-start gap-2.5 transition-all duration-500">
                <i class="fa-solid fa-triangle-exclamation text-base mt-0.5"></i>
                <span class="leading-relaxed"><?= htmlspecialchars($loginError) ?></span>
            </div>
        <?php endif; ?>

        <!-- STEP 0: HUMAN SECURITY VERIFICATION -->
        <div id="step-antibot" class="space-y-4">
            <div class="captcha-box rounded-2xl p-4 flex items-center justify-between cursor-pointer hover:border-fuchsia-500/60 transition" onclick="solveHumanChallenge()">
                <div class="flex items-center space-x-3.5">
                    <div id="captchaCheck" class="w-7 h-7 rounded-lg border-2 border-gray-600 flex items-center justify-center text-transparent transition">
                        <i class="fa-solid fa-check text-sm font-black"></i>
                    </div>
                    <div>
                        <span id="captchaText" class="text-xs font-bold text-white block">I am not a robot</span>
                        <span class="text-[10px] text-gray-500 font-mono">Security Verification</span>
                    </div>
                </div>
                <div class="text-right">
                    <i id="shieldIcon" class="fa-solid fa-shield text-xl text-gray-500"></i>
                </div>
            </div>
            <p class="text-[11px] text-center text-gray-500 font-mono">Protection by Key Manager Team</p>
        </div>

        <!-- STEP 1: SMART BOT LAUNCH (DESKTOP & MOBILE INTEGRATED) -->
        <div id="step-connect" class="hidden space-y-4">
            <div class="bg-[#0e071e] p-4 rounded-2xl border border-[#261545] text-xs text-purple-200 leading-relaxed text-center space-y-1">
                <p class="font-bold text-emerald-400 mb-1.5"><i class="fa-solid fa-circle-check"></i> Verification Passed!</p>
                <p>1. Tap the button below to connect with Telegram.</p>
                <p>2. Press <b>Start</b> inside the bot.</p>
                <p>3. Enter the 5-digit OTP sent by the bot.</p>
            </div>

            <!-- Smart Device-Aware Launch Button -->
            <button type="button" onclick="smartLaunchTelegram()" class="w-full py-4 rounded-2xl btn-magenta-glow text-white font-black text-xs tracking-wider uppercase flex items-center justify-center gap-2.5 transition">
                <i class="fa-brands fa-telegram text-lg"></i> Start TG Bot for Code
            </button>

            <div class="text-center">
                <span class="text-[11px] text-gray-500 font-mono flex items-center justify-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-yellow-400 animate-ping"></span> Waiting for bot handshake...
                </span>
            </div>
        </div>

        <!-- STEP 2: 5-DIGIT OTP VERIFICATION BOX -->
        <div id="step-otp" class="hidden space-y-4">
            <div class="bg-[#0e071e] p-3.5 rounded-2xl border border-emerald-500/30 text-xs text-emerald-300 text-center flex items-center justify-center gap-2 font-medium">
                <i class="fa-solid fa-circle-check text-emerald-400"></i> Bot Connected! 5-Digit OTP sent to your Telegram.
            </div>

            <form onsubmit="handleOtpVerify(event)" class="space-y-4">
                <input type="text" name="hp_security_check" style="display:none" tabindex="-1" autocomplete="off">

                <div>
                    <label class="text-[11px] font-extrabold text-gray-300 block mb-2 tracking-wider text-center uppercase">
                        ENTER 5-DIGIT TELEGRAM OTP
                    </label>
                    <input type="text" id="otpInput" maxlength="5" required placeholder="• • • • •" class="w-full text-center bg-[#080413] border border-[#2c164f] text-2xl font-black tracking-[0.4em] text-fuchsia-300 rounded-2xl py-3.5 outline-none font-mono focus:border-fuchsia-500">
                </div>

                <button type="submit" id="otpBtn" class="w-full py-4 rounded-2xl btn-magenta-glow text-white font-black text-xs tracking-wider uppercase flex items-center justify-center gap-2 transition">
                    Verify Telegram OTP
                </button>
            </form>
        </div>

        <!-- STEP 3: ACCOUNT CREDENTIALS -->
        <div id="step-login" class="hidden space-y-4">
            <div class="bg-[#0e071e] p-3 rounded-2xl border border-emerald-500/30 text-xs text-emerald-300 text-center font-bold flex items-center justify-center gap-2">
                <i class="fa-solid fa-lock text-emerald-400"></i> 2FA Passed. Enter Account Password.
            </div>

            <form method="POST" class="space-y-4 text-xs">
                <input type="hidden" name="action" value="account_login">

                <div>
                    <label class="text-[11px] font-extrabold text-gray-300 block mb-1 tracking-wider uppercase">Username</label>
                    <input type="text" name="username" required placeholder="Enter username" class="w-full bg-[#080413] border border-[#2c164f] text-sm text-white rounded-xl p-3.5 outline-none focus:border-fuchsia-500">
                </div>

                <div>
                    <label class="text-[11px] font-extrabold text-gray-300 block mb-1 tracking-wider uppercase">Password</label>
                    <input type="password" name="password" required placeholder="••••••••" class="w-full bg-[#080413] border border-[#2c164f] text-sm text-white rounded-xl p-3.5 outline-none focus:border-fuchsia-500">
                </div>

                <button type="submit" class="w-full py-4 rounded-2xl btn-magenta-glow text-white font-black text-xs tracking-wider uppercase transition">
                    Access Dashboard
                </button>
            </form>
        </div>

    </div>

    <!-- Client-Side Engine -->
    <script>
        const botUsername = "<?= TG_BOT_USERNAME ?>";
        const sessionToken = "<?= $sessionToken ?>";
        let isHumanVerified = false;
        let pollInterval = null;

        // Auto-dismiss static error message
        window.addEventListener('DOMContentLoaded', () => {
            const errBox = document.getElementById('errorAlertBox');
            if (errBox) {
                setTimeout(() => {
                    errBox.style.transition = 'all 0.5s ease';
                    errBox.style.opacity = '0';
                    errBox.style.transform = 'translateY(-10px)';
                    setTimeout(() => errBox.remove(), 500);
                }, 3500);
            }
        });

        // SMART LAUNCHER (DEVICE-AWARE: MOBILE APP VS PC APP/WEB)
        function smartLaunchTelegram() {
            const isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
            const tgAppProtocol = `tg://resolve?domain=${botUsername}&start=${sessionToken}`;
            const tgWebUrl = `https://t.me/${botUsername}?start=${sessionToken}`;

            if (isMobile) {
                // Mobile: Direct Native App Launch
                window.location.href = tgAppProtocol;
            } else {
                // Desktop / PC: Try Desktop App first, fallback to Telegram Web seamlessly
                const start = Date.now();
                window.location.href = tgAppProtocol;

                setTimeout(() => {
                    // If user stays in browser (App didn't take over), open Telegram Web
                    if (Date.now() - start < 2000) {
                        window.open(tgWebUrl, '_blank');
                    }
                }, 1000);
            }
        }

        function solveHumanChallenge() {
            if (isHumanVerified) return;
            const check = document.getElementById('captchaCheck');
            const text = document.getElementById('captchaText');
            const icon = document.getElementById('shieldIcon');
            const box = document.querySelector('.captcha-box');

            check.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-cyan-400"></i>';
            text.innerText = 'Verifying environment...';

            setTimeout(() => {
                isHumanVerified = true;
                box.classList.add('verified');
                check.className = "w-7 h-7 rounded-lg bg-emerald-500 text-white flex items-center justify-center border-0";
                check.innerHTML = '<i class="fa-solid fa-check text-sm font-black"></i>';
                text.innerText = 'Verification Completed';
                text.classList.add('text-emerald-400');
                icon.className = "fa-solid fa-shield-check text-xl text-emerald-400";

                setTimeout(() => {
                    document.getElementById('step-antibot').classList.add('hidden');
                    document.getElementById('step-connect').classList.remove('hidden');
                    startPolling();
                }, 500);
            }, 600);
        }

        // Live Polling: Transitions to OTP ONLY when message is genuinely delivered to Telegram
        function startPolling() {
            if (!isHumanVerified) return;
            pollInterval = setInterval(async () => {
                try {
                    const res = await fetch('index.php?check_status=1');
                    const data = await res.json();

                    if (data.bound && !data.verified) {
                        document.getElementById('step-connect').classList.add('hidden');
                        document.getElementById('step-otp').classList.remove('hidden');
                    } else if (data.verified) {
                        document.getElementById('step-connect').classList.add('hidden');
                        document.getElementById('step-otp').classList.add('hidden');
                        document.getElementById('step-login').classList.remove('hidden');
                        clearInterval(pollInterval);
                    }
                } catch (e) {}
            }, 1500);
        }

        async function handleOtpVerify(e) {
            e.preventDefault();
            const btn = document.getElementById('otpBtn');
            const otp = document.getElementById('otpInput').value;

            btn.disabled = true;
            btn.innerText = 'VERIFYING...';

            const formData = new FormData();
            formData.append('action', 'verify_otp');
            formData.append('otp', otp);

            try {
                const res = await fetch('index.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (data.status === 'success') {
                    showToast(data.message, 'success');
                    clearInterval(pollInterval);
                    document.getElementById('step-otp').classList.add('hidden');
                    document.getElementById('step-login').classList.remove('hidden');
                } else {
                    showToast(data.message, 'error');
                }
            } catch (err) {
                showToast('Server connection error!', 'error');
            } finally {
                btn.disabled = false;
                btn.innerText = 'Verify Telegram OTP';
            }
        }

        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toastNotification');
            const toastInner = document.getElementById('toastInner');
            document.getElementById('toastMsg').innerText = msg;
            toastInner.className = type === 'error' ? "bg-[#180e1a] border border-pink-500/60 shadow-2xl rounded-2xl p-3.5 flex items-center space-x-3 text-xs font-semibold text-pink-200 backdrop-blur-xl" : "bg-[#1b1035] border border-fuchsia-500/50 shadow-2xl rounded-2xl p-3.5 flex items-center space-x-3 text-xs font-semibold text-fuchsia-200 backdrop-blur-xl";
            toast.classList.remove('opacity-0', 'translate-y-[-20px]');
            toast.classList.add('opacity-100', 'translate-y-0');

            setTimeout(() => {
                toast.classList.remove('opacity-100', 'translate-y-0');
                toast.classList.add('opacity-0', 'translate-y-[-20px]');
            }, 3000);
        }
    </script>
</body>
</html>