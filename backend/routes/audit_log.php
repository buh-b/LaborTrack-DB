<?php
// routes/audit_log.php — Audit log (admin only, read-only)
// GET /backend/routes/audit_log.php
//     ?action=X          filter by action (account_create, account_update,
//                        account_delete, payroll_approve, payroll_unapprove)
//     &target_type=X     filter by target_type (account, payroll_period)
//     &target_id=X       filter by the affected row's id
//     &account_id=X      filter by who performed the action
//     &from=YYYY-MM-DD   filter by created_at >= from
//     &to=YYYY-MM-DD     filter by created_at <= to (inclusive, end of day)
//     &limit=N           default 100, max 500
//     &offset=N          default 0
//
// Entries are written by logAudit() (see middleware/helpers.php) from
// accounts.php (create/update/delete) and payroll.php (approve/unapprove).
// This route only reads — nothing here writes to audit_log.

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') json_err('Method not allowed.', 405);

requireAdmin();

$pdo = getDB();

$where  = [];
$params = [];

if (!empty($_GET['action'])) {
    $allowed = ['account_create', 'account_update', 'account_delete', 'payroll_approve', 'payroll_unapprove'];
    if (!in_array($_GET['action'], $allowed, true)) json_err('Invalid action filter.');
    $where[]  = 'al.action = ?';
    $params[] = $_GET['action'];
}
if (!empty($_GET['target_type'])) {
    $where[]  = 'al.target_type = ?';
    $params[] = $_GET['target_type'];
}
if (!empty($_GET['target_id'])) {
    $where[]  = 'al.target_id = ?';
    $params[] = (int)$_GET['target_id'];
}
if (!empty($_GET['account_id'])) {
    $where[]  = 'al.account_id = ?';
    $params[] = (int)$_GET['account_id'];
}
if (!empty($_GET['from'])) {
    $where[]  = 'al.created_at >= ?';
    $params[] = $_GET['from'] . ' 00:00:00';
}
if (!empty($_GET['to'])) {
    $where[]  = 'al.created_at <= ?';
    $params[] = $_GET['to'] . ' 23:59:59';
}

$limit  = min(500, max(1, intVal_($_GET, 'limit', 100)));
$offset = max(0, intVal_($_GET, 'offset', 0));

$sql = 'SELECT al.audit_id, al.account_id, al.username_snapshot, al.action,
               al.target_type, al.target_id, al.details, al.created_at
        FROM   audit_log al'
     . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY al.created_at DESC, al.audit_id DESC'
     . " LIMIT {$limit} OFFSET {$offset}";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Total count for pagination (respecting the same filters)
$countSql = 'SELECT COUNT(*) FROM audit_log al' . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '');
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

json_ok([
    'total'   => $total,
    'limit'   => $limit,
    'offset'  => $offset,
    'entries' => array_map(fn($r) => [
        'audit_id'         => (int)$r['audit_id'],
        'account_id'       => $r['account_id'] !== null ? (int)$r['account_id'] : null,
        'username_snapshot' => $r['username_snapshot'],
        'action'           => $r['action'],
        'target_type'      => $r['target_type'],
        'target_id'        => $r['target_id'] !== null ? (int)$r['target_id'] : null,
        'details'          => $r['details'] !== null ? json_decode($r['details'], true) : null,
        'created_at'       => $r['created_at'],
    ], $rows),
]);
