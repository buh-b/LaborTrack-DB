<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// admin side GET all accounts
if ($method === 'GET') {
    requireSystemAdmin();

    $pdo  = getDB();
    $search = $_GET['search'] ?? '';
    $sql = 'SELECT a.account_id, a.employee_id, a.username, a.email, a.access_level,
                   CONCAT(e.first_name, " ", e.last_name) AS full_name
            FROM   accounts a
            LEFT   JOIN employees e ON e.employee_id = a.employee_id
            WHERE  1=1';
    $params = [];
    if ($search !== '') {
        $sql .= ' AND (a.username LIKE ? OR CONCAT(e.first_name, " ", e.last_name) LIKE ? OR a.email LIKE ?)';
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }
    $sql .= ' ORDER BY a.account_id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    json_ok(array_map(fn($r) => [
        'account_id'   => (int)$r['account_id'],
        'employee_id'  => $r['employee_id'] !== null ? (int)$r['employee_id'] : null,
        'username'     => $r['username'],
        'email'        => $r['email'],
        'access_level' => $r['access_level'],
        'full_name'    => $r['full_name'],
    ], $rows));
}

// POST create account 
if ($method === 'POST') {
    requireSystemAdmin();

    $body        = bodyJson();
    $employeeId  = intVal_($body, 'employee_id');
    $username    = str($body, 'username');
    $password    = str($body, 'password');
    $email       = str($body, 'email');
    $accessLevel = str($body, 'access_level', 'employee');

    if ($username === '')  json_err('username is required.');
    if ($password === '')  json_err('password is required.');
    if ($email === '')     json_err('email is required.');
    if (!in_array($accessLevel, ['employee', 'supervisor', 'human_resources', 'system_admin'], true)) {
        json_err('access_level must be employee, supervisor, human_resources, or system_admin.');
    }
    if (strlen($password) < 6) json_err('Password must be at least 6 characters.');

    $pdo = getDB();

    $dupUser = $pdo->prepare('SELECT account_id FROM accounts WHERE username = ? LIMIT 1');
    $dupUser->execute([$username]);
    if ($dupUser->fetch()) json_err('That username is already taken.');

    $dupEmail = $pdo->prepare('SELECT account_id FROM accounts WHERE email = ? LIMIT 1');
    $dupEmail->execute([$email]);
    if ($dupEmail->fetch()) json_err('That email is already in use.');

    if ($employeeId) {
        $emp = $pdo->prepare('SELECT employee_id FROM employees WHERE employee_id = ? LIMIT 1');
        $emp->execute([$employeeId]);
        if (!$emp->fetch()) json_err('Employee not found.', 404);

        $dup = $pdo->prepare('SELECT account_id FROM accounts WHERE employee_id = ? LIMIT 1');
        $dup->execute([$employeeId]);
        if ($dup->fetch()) json_err('This employee already has an account.');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare(
        'INSERT INTO accounts (employee_id, username, password_hash, email, access_level)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$employeeId ?: null, $username, $hash, $email, $accessLevel]);
    $newAccountId = (int)$pdo->lastInsertId();

    logAudit($pdo, 'account_create', 'account', $newAccountId, [
        'username'     => $username,
        'email'        => $email,
        'access_level' => $accessLevel,
        'employee_id'  => $employeeId ?: null,
    ]);

    json_ok(['account_id' => $newAccountId, 'message' => 'Account created successfully.']);
}

// PUT update account admin side
if ($method === 'PUT') {
    requireSystemAdmin();

    $body        = bodyJson();
    $accountId   = intVal_($body, 'account_id');
    $username    = str($body, 'username');
    $email       = str($body, 'email');
    $accessLevel = str($body, 'access_level');
    $password    = str($body, 'password');
    $hasEmpKey   = array_key_exists('employee_id', $body);
    $employeeId  = intVal_($body, 'employee_id');

    if (!$accountId) json_err('account_id is required.');

    $pdo = getDB();
    $acc = $pdo->prepare('SELECT account_id, username, email, access_level, employee_id FROM accounts WHERE account_id = ? LIMIT 1');
    $acc->execute([$accountId]);
    $before = $acc->fetch();
    if (!$before) json_err('Account not found.', 404);

    $fields  = [];
    $params  = [];
    $changed = []; // for audit log: field => ['from' => ..., 'to' => ...]

    if ($username !== '') {
        $dup = $pdo->prepare('SELECT account_id FROM accounts WHERE username = ? AND account_id != ? LIMIT 1');
        $dup->execute([$username, $accountId]);
        if ($dup->fetch()) json_err('That username is already taken.');
        $fields[] = 'username = ?'; $params[] = $username;
        if ($username !== $before['username']) $changed['username'] = ['from' => $before['username'], 'to' => $username];
    }
    if ($email !== '') {
        $dup = $pdo->prepare('SELECT account_id FROM accounts WHERE email = ? AND account_id != ? LIMIT 1');
        $dup->execute([$email, $accountId]);
        if ($dup->fetch()) json_err('That email is already in use.');
        $fields[] = 'email = ?'; $params[] = $email;
        if ($email !== $before['email']) $changed['email'] = ['from' => $before['email'], 'to' => $email];
    }
    if ($accessLevel !== '' && in_array($accessLevel, ['employee', 'supervisor', 'human_resources', 'system_admin'], true)) {
        $fields[] = 'access_level = ?'; $params[] = $accessLevel;
        if ($accessLevel !== $before['access_level']) $changed['access_level'] = ['from' => $before['access_level'], 'to' => $accessLevel];
    }
    if ($password !== '') {
        if (strlen($password) < 6) json_err('Password must be at least 6 characters.');
        $fields[] = 'password_hash = ?';
        $params[] = password_hash($password, PASSWORD_BCRYPT);
        $changed['password'] = ['from' => '(hidden)', 'to' => '(changed)'];
    }
    if ($hasEmpKey) {
        if ($employeeId) {
            $emp = $pdo->prepare('SELECT employee_id FROM employees WHERE employee_id = ? LIMIT 1');
            $emp->execute([$employeeId]);
            if (!$emp->fetch()) json_err('Employee not found.', 404);

            $dup = $pdo->prepare('SELECT account_id FROM accounts WHERE employee_id = ? AND account_id != ? LIMIT 1');
            $dup->execute([$employeeId, $accountId]);
            if ($dup->fetch()) json_err('This employee already has an account.');

            $fields[] = 'employee_id = ?'; $params[] = $employeeId;
            if ($employeeId !== (int)($before['employee_id'] ?? 0)) {
                $changed['employee_id'] = ['from' => $before['employee_id'], 'to' => $employeeId];
            }
        } else {
            $fields[] = 'employee_id = NULL';
            if ($before['employee_id'] !== null) {
                $changed['employee_id'] = ['from' => $before['employee_id'], 'to' => null];
            }
        }
    }

    if (empty($fields)) json_err('Nothing to update.');

    $params[] = $accountId;
    $pdo->prepare('UPDATE accounts SET ' . implode(', ', $fields) . ' WHERE account_id = ?')
        ->execute($params);

    if (!empty($changed)) {
        logAudit($pdo, 'account_update', 'account', $accountId, $changed);
    }

    json_ok(['message' => 'Account updated successfully.']);
}

// DELETE
if ($method === 'DELETE') {
    requireSystemAdmin();

    $id = intVal_($_GET, 'id');
    if (!$id) json_err('id query param is required.');
    if ($id === currentAccountId()) json_err('You cannot delete your own account.');

    $pdo = getDB();

    // Snapshot before deleting so the audit entry stays meaningful afterward.
    $snap = $pdo->prepare('SELECT username, email, access_level FROM accounts WHERE account_id = ? LIMIT 1');
    $snap->execute([$id]);
    $deletedAccount = $snap->fetch();
    if (!$deletedAccount) json_err('Account not found.', 404);

    $stmt = $pdo->prepare('DELETE FROM accounts WHERE account_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) json_err('Account not found.', 404);

    logAudit($pdo, 'account_delete', 'account', $id, $deletedAccount);

    json_ok(['message' => 'Account deleted.']);
}

json_err('Method not allowed.', 405);
