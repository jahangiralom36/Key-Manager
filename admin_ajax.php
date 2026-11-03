<?php
// ====================================================================
// ADMIN_AJAX.PHP — admin.php er authenticated AJAX (reports, history, deposits, stock, chat)
// admin.php theke include hoy, tai ekhane auth check already hoye geche.
// ====================================================================
if (!isset($pdo) || empty($_SESSION['admin_auth'])) { http_response_code(403); exit; }

function adm_date(?string $d): ?string {
    $d = trim((string)$d);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
}

// ---------- 1. SALES REPORT (hack -> variant/validity -> koyta bikri) ----------
if ($action === 'report_sales') {
    $from = adm_date($_POST['from'] ?? '');
    $to   = adm_date($_POST['to'] ?? '');
    $where = '1=1'; $params = [];
    if ($from) { $where .= ' AND DATE(l.created_at) >= ?'; $params[] = $from; }
    if ($to)   { $where .= ' AND DATE(l.created_at) <= ?'; $params[] = $to; }

    $st = $pdo->prepare("SELECT COALESCE(p.name, 'Unknown') AS product_name,
                                COALESCE(pl.plan_title, l.validity) AS plan_title,
                                COALESCE(l.plan_id, 0) AS plan_id,
                                COUNT(*) AS sold,
                                COALESCE(SUM(CASE WHEN l.price > 0 THEN l.price ELSE COALESCE(pl.reseller_price, 0) END), 0) AS revenue
                         FROM licenses l
                         LEFT JOIN products p ON l.product_id = p.id
                         LEFT JOIN product_plans pl ON l.plan_id = pl.id
                         WHERE $where
                         GROUP BY l.product_id, l.plan_id, product_name, plan_title
                         ORDER BY product_name ASC, sold DESC");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $totalSold = 0; $totalRev = 0.0;
    foreach ($rows as &$r) { $r['sold'] = (int)$r['sold']; $r['revenue'] = round((float)$r['revenue'], 2); $totalSold += $r['sold']; $totalRev += $r['revenue']; }
    unset($r);
    echo json_encode(['success' => true, 'rows' => $rows, 'total_sold' => $totalSold, 'total_revenue' => round($totalRev, 2)]);
    exit;
}

// ---------- 2. KEY HISTORY SEARCH ----------
function adm_history_query(PDO $pdo, array $in, ?int $limit, int $offset = 0): array {
    $q = trim((string)($in['q'] ?? ''));
    $from = adm_date($in['from'] ?? ''); $to = adm_date($in['to'] ?? '');
    $where = '1=1'; $params = [];
    if ($q !== '') {
        $where .= ' AND (l.license_key LIKE ? OR u.username LIKE ? OR l.validity LIKE ? OR p.name LIKE ?)';
        $like = '%' . $q . '%'; array_push($params, $like, $like, $like, $like);
    }
    if ($from) { $where .= ' AND DATE(l.created_at) >= ?'; $params[] = $from; }
    if ($to)   { $where .= ' AND DATE(l.created_at) <= ?'; $params[] = $to; }
    $base = "FROM licenses l JOIN users u ON l.user_id = u.id
             LEFT JOIN products p ON l.product_id = p.id
             LEFT JOIN product_plans pl ON l.plan_id = pl.id WHERE $where";
    $cnt = $pdo->prepare("SELECT COUNT(*) $base"); $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $sql = "SELECT l.id, l.license_key, u.username, COALESCE(p.name, '') AS product_name,
                   COALESCE(pl.plan_title, l.validity) AS plan_title, l.validity,
                   l.price, l.source, l.created_at $base ORDER BY l.id DESC";
    if ($limit !== null) $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
    $st = $pdo->prepare($sql); $st->execute($params);
    return ['total' => $total, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)];
}

if ($action === 'history_search') {
    $page = max(1, (int)($_POST['page'] ?? 1)); $per = 30;
    $res = adm_history_query($pdo, $_POST, $per, ($page - 1) * $per);
    echo json_encode(['success' => true, 'total' => $res['total'], 'page' => $page, 'per_page' => $per, 'rows' => $res['rows']]);
    exit;
}

// ---------- 3. DEPOSIT / TRANSACTION SEARCH ----------
if ($action === 'deposits_search') {
    $q = trim((string)($_POST['q'] ?? ''));
    $type = strtoupper(trim((string)($_POST['type'] ?? '')));
    $status = strtolower(trim((string)($_POST['status'] ?? '')));
    $where = '1=1'; $params = [];
    if ($q !== '') { $where .= ' AND (t.trx_id LIKE ? OR u.username LIKE ? OR t.sender_num LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like, $like); }
    if (in_array($type, ['AUTO', 'MANUAL', 'ADMIN_ADD', 'ADMIN_DEDUCT'], true)) { $where .= ' AND t.type = ?'; $params[] = $type; }
    if (in_array($status, ['pending', 'approved', 'rejected'], true)) { $where .= ' AND t.status = ?'; $params[] = $status; }
    $st = $pdo->prepare("SELECT t.id, t.user_id, u.username, t.method, t.amount, t.bonus_amount, t.sender_num, t.trx_id, t.status, t.type, t.note, t.created_at
                         FROM transactions t JOIN users u ON t.user_id = u.id WHERE $where ORDER BY t.id DESC LIMIT 100");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'rows' => $rows]);
    exit;
}

// ---------- 4. RESELLER STATS (koto invest / koto dollar kinse) ----------
if ($action === 'reseller_stats') {
    $q = trim((string)($_POST['q'] ?? ''));
    $params = []; $where = '1=1';
    if ($q !== '') { $where = 'u.username LIKE ?'; $params[] = '%' . $q . '%'; }
    $st = $pdo->prepare("SELECT u.id, u.username, u.role, u.balance,
            (SELECT COALESCE(SUM(amount),0) FROM transactions t WHERE t.user_id=u.id AND t.status='approved' AND t.type IN ('AUTO','MANUAL')) AS deposited,
            (SELECT COALESCE(SUM(bonus_amount),0) FROM transactions t WHERE t.user_id=u.id AND t.status='approved') AS bonus,
            (SELECT COALESCE(SUM(amount),0) FROM transactions t WHERE t.user_id=u.id AND t.status='approved' AND t.type='ADMIN_ADD') AS admin_added,
            (SELECT COALESCE(SUM(-amount),0) FROM transactions t WHERE t.user_id=u.id AND t.status='approved' AND t.type='ADMIN_DEDUCT') AS admin_deducted,
            (SELECT COUNT(*) FROM licenses l WHERE l.user_id=u.id) AS keys_bought,
            (SELECT COALESCE(SUM(l.price),0) FROM licenses l WHERE l.user_id=u.id) AS spent
         FROM users u WHERE $where ORDER BY deposited DESC, u.id DESC LIMIT 300");
    $st->execute($params);
    echo json_encode(['success' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ---------- 5. STOCK & API OVERVIEW ----------
if ($action === 'stock_overview') {
    $rows = $pdo->query("SELECT p.name AS product_name, pl.id AS plan_id, pl.plan_title, pl.api_plan_id,
            (SELECT COUNT(*) FROM stock_keys s WHERE s.plan_id = pl.id AND s.status = 'available') AS stock_available,
            (SELECT COUNT(*) FROM stock_keys s WHERE s.plan_id = pl.id AND s.status = 'used') AS stock_used,
            (SELECT COUNT(*) FROM licenses l WHERE l.plan_id = pl.id) AS sold
         FROM product_plans pl JOIN products p ON pl.product_id = p.id
         ORDER BY p.name ASC, pl.duration_days ASC, pl.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['api_linked'] = ((int)$r['api_plan_id'] > 0); }
    unset($r);
    echo json_encode(['success' => true, 'rows' => $rows]);
    exit;
}

// ---------- 6. CHAT (admin <-> reseller) ----------
if ($action === 'chat_list') {
    $rows = $pdo->query("SELECT * FROM (
            SELECT u.id, u.username, u.role,
              (SELECT COUNT(*) FROM messages m WHERE m.user_id = u.id AND m.sender_role = 'user' AND m.is_read = 0) AS unread,
              (SELECT m.body FROM messages m WHERE m.user_id = u.id ORDER BY m.id DESC LIMIT 1) AS last_body,
              (SELECT m.created_at FROM messages m WHERE m.user_id = u.id ORDER BY m.id DESC LIMIT 1) AS last_at
            FROM users u) x
         ORDER BY (last_at IS NULL), last_at DESC, username ASC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'rows' => $rows]);
    exit;
}

if ($action === 'chat_fetch') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $after = (int)($_POST['after_id'] ?? 0);
    $st = $pdo->prepare("SELECT id, sender_role, body, created_at FROM messages WHERE user_id = ? AND id > ? ORDER BY id ASC LIMIT 200");
    $st->execute([$uid, $after]);
    $pdo->prepare("UPDATE messages SET is_read = 1 WHERE user_id = ? AND sender_role = 'user' AND is_read = 0")->execute([$uid]);
    echo json_encode(['success' => true, 'rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'chat_send') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $body = trim((string)($_POST['body'] ?? ''));
    if ($uid <= 0 || $body === '' || mb_strlen($body) > 2000) {
        echo json_encode(['success' => false, 'error' => 'Message empty or too long']);
        exit;
    }
    $chk = $pdo->prepare("SELECT id FROM users WHERE id = ?"); $chk->execute([$uid]);
    if (!$chk->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'User not found']); exit; }
    $pdo->prepare("INSERT INTO messages (user_id, sender_role, body, is_read) VALUES (?, 'admin', ?, 0)")->execute([$uid, $body]);
    echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
    exit;
}

if ($action === 'chat_unread_total') {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM messages WHERE sender_role = 'user' AND is_read = 0")->fetchColumn();
    echo json_encode(['success' => true, 'unread' => $n]);
    exit;
}

// ---------- 7. CSV EXPORT (GET admin.php?export=keys|transactions) ----------
function adm_csv_stream(string $filename, array $header, iterable $rows): void {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // Excel e Bangla/UTF-8 thik dekhabe
    fputcsv($out, $header);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

if (!empty($GLOBALS['__admin_export'])) {
    $ex = $GLOBALS['__admin_export'];
    if ($ex === 'keys') {
        $res = adm_history_query($pdo, $_GET, 200000, 0);
        $rows = [];
        foreach ($res['rows'] as $r) $rows[] = [$r['id'], $r['license_key'], $r['username'], $r['product_name'], $r['plan_title'], $r['price'], $r['source'], $r['created_at']];
        adm_csv_stream('keys_' . date('Ymd_His') . '.csv', ['ID', 'Key', 'Reseller', 'Product', 'Validity', 'Price USD', 'Source', 'Date'], $rows);
    }
    if ($ex === 'transactions') {
        $st = $pdo->query("SELECT t.id, u.username, t.type, t.method, t.amount, t.bonus_amount, t.sender_num, t.trx_id, t.status, t.note, t.created_at
                           FROM transactions t JOIN users u ON t.user_id = u.id ORDER BY t.id DESC LIMIT 200000");
        adm_csv_stream('transactions_' . date('Ymd_His') . '.csv', ['ID', 'Reseller', 'Type', 'Method', 'Amount USD', 'Bonus USD', 'Sender', 'TrxID', 'Status', 'Note', 'Date'], $st->fetchAll(PDO::FETCH_NUM));
    }
}
