<?php
// middleware/helpers.php — Shared auth helpers, JSON responses, input casting
// Session shape (set on successful login, see routes/auth.php):
//   $_SESSION['account_id']    (int)
//   $_SESSION['employee_id']   (int|null)
//   $_SESSION['access_level']  ('admin'|'employee')
//   $_SESSION['username']      (string)


declare(strict_types=1);

// CORS 
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin) ||
    preg_match('#^https?://[a-z0-9\-]+\.dcism\.org$#', $origin)
) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Session 
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'None',
    ]);
    session_start();
}

// JSON response helpers
function json_ok($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function json_err(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

//Request body helper 
function bodyJson(): array {
    $raw    = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : [];
}

// Input casting helpers 
function str(array $body, string $key, string $default = ''): string {
    if (!isset($body[$key]) || $body[$key] === null) return $default;
    return trim((string)$body[$key]);
}

function intVal_(array $body, string $key, ?int $default = null): ?int {
    if (!isset($body[$key]) || $body[$key] === null || $body[$key] === '') return $default;
    return (int)$body[$key];
}

function floatVal_(array $body, string $key, float $default = 0.0): float {
    if (!isset($body[$key]) || $body[$key] === null || $body[$key] === '') return $default;
    return (float)$body[$key];
}

// Auth helpers 
// access_level is one of: employee, supervisor, human_resources, system_admin
function isLoggedIn(): bool            { return isset($_SESSION['account_id']); }
function currentAccountId(): ?int      { return $_SESSION['account_id']   ?? null; }
function currentEmployeeId(): ?int     { return $_SESSION['employee_id']  ?? null; }
function currentAccessLevel(): ?string { return $_SESSION['access_level'] ?? null; }

// Department of the currently logged-in employee (used for Supervisor scoping).
// Cached per-request since it's called from several places (GET filters, etc.).
function currentDepartmentId(): ?int {
    static $deptId  = null;
    static $loaded  = false;
    if ($loaded) return $deptId;
    $loaded = true;

    $empId = currentEmployeeId();
    if ($empId === null) return null;

    $stmt = getDB()->prepare('SELECT department_id FROM employees WHERE employee_id = ?');
    $stmt->execute([$empId]);
    $row = $stmt->fetch();
    $deptId = ($row && $row['department_id'] !== null) ? (int)$row['department_id'] : null;
    return $deptId;
}

function requireAuth(): void {
    if (!isLoggedIn()) json_err('Authentication required.', 401);
}

// Generic — pass any set of allowed access_level values
function requireRole(array $allowed): void {
    requireAuth();
    if (!in_array(currentAccessLevel(), $allowed, true)) {
        json_err('Forbidden.', 403);
    }
}

function requireSystemAdmin(): void {
    requireRole(['system_admin']);
}

function requireSupervisor(): void {
    // system_admin can always act as a fallback/override
    requireRole(['supervisor', 'system_admin']);
}

function requireHumanResources(): void {
    requireRole(['human_resources', 'system_admin']);
}

// Kept as an alias for any call site still using the old 2-tier name.
// Treated as System Admin only — replace call sites with the more specific
// requireSystemAdmin() / requireHumanResources() / requireSupervisor() over time.
function requireAdmin(): void {
    requireSystemAdmin();
}

// ── Audit log ──────────────────────────────────────────────────────────────────
// Writes one row to audit_log. Never throws out to the caller — a logging
// failure should not block or roll back the action that triggered it, so
// errors are swallowed after being reported to the PHP error log.
//
//   $action     one of: account_create, account_update, account_delete,
//               payroll_approve, payroll_unapprove
//   $targetType e.g. 'account', 'payroll_period'
//   $targetId   id of the affected row (nullable, e.g. after a delete)
//   $details    associative array of context (old/new values, etc.); stored as JSON
function logAudit(
    PDO $pdo,
    string $action,
    string $targetType,
    ?int $targetId,
    ?array $details = null
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (account_id, username_snapshot, action, target_type, target_id, details)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            currentAccountId(),
            $_SESSION['username'] ?? 'system',
            $action,
            $targetType,
            $targetId,
            $details !== null ? json_encode($details) : null,
        ]);
    } catch (Throwable $e) {
        error_log('[audit_log] failed to write entry: ' . $e->getMessage());
    }
}
