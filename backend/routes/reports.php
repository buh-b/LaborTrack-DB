<?php
// GET /backend/routes/reports.php?action=department_labor_cost
//     optional: department_id, date_from, date_to
// GET /backend/routes/reports.php?action=employee_earnings
//     optional: department_id, date_from, date_to
//
// Both actions are admin-only and compute cost/earnings as
// SUM(total_hours * current_hourly_rate) over time_logs with a
// completed clock_out (total_hours IS NOT NULL).

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

requireAuth();
requireAdmin();

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method !== 'GET') {
    json_err('Method not allowed.', 405);
}

// ─────────────────────────────────────────────────────────────────────────────
// GET: department_labor_cost
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'department_labor_cost') {

    $deptId   = intVal_($_GET, 'department_id');
    $dateFrom = str($_GET, 'date_from');
    $dateTo   = str($_GET, 'date_to');

    $where  = ['tl.total_hours IS NOT NULL'];
    $params = [];

    if ($deptId) {
        $where[]  = 'd.department_id = ?';
        $params[] = $deptId;
    }
    if ($dateFrom !== '') {
        $where[]  = 'DATE(tl.clock_in) >= ?';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[]  = 'DATE(tl.clock_in) <= ?';
        $params[] = $dateTo;
    }

    $sql = 'SELECT
                d.department_id,
                d.department_name,
                COUNT(DISTINCT e.employee_id)               AS employee_count,
                COALESCE(SUM(tl.total_hours), 0)             AS total_hours_logged,
                COALESCE(SUM(tl.total_hours * e.current_hourly_rate), 0) AS total_labor_cost
            FROM   departments d
            JOIN   employees   e  ON e.department_id = d.department_id
            JOIN   time_logs   tl ON tl.employee_id  = e.employee_id
            WHERE  ' . implode(' AND ', $where) . '
            GROUP  BY d.department_id, d.department_name
            ORDER  BY total_labor_cost DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    json_ok(array_map(fn($r) => [
        'department_id'      => (int)$r['department_id'],
        'department_name'    => $r['department_name'],
        'employee_count'     => (int)$r['employee_count'],
        'total_hours_logged' => (float)$r['total_hours_logged'],
        'total_labor_cost'   => (float)$r['total_labor_cost'],
    ], $stmt->fetchAll()));
}

// ─────────────────────────────────────────────────────────────────────────────
// GET: employee_earnings
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'employee_earnings') {

    $deptId   = intVal_($_GET, 'department_id');
    $dateFrom = str($_GET, 'date_from');
    $dateTo   = str($_GET, 'date_to');

    // Date range applies inside the LEFT JOIN's ON clause so employees
    // with zero logged hours in range still appear in the report.
    $logJoin  = ['tl.employee_id = e.employee_id', 'tl.total_hours IS NOT NULL'];
    $params   = [];

    if ($dateFrom !== '') {
        $logJoin[] = 'DATE(tl.clock_in) >= ?';
        $params[]  = $dateFrom;
    }
    if ($dateTo !== '') {
        $logJoin[] = 'DATE(tl.clock_in) <= ?';
        $params[]  = $dateTo;
    }

    $where = [];
    if ($deptId) {
        $where[]  = 'e.department_id = ?';
        $params[] = $deptId;
    }

    $sql = 'SELECT
                e.employee_id,
                e.full_name,
                d.department_name,
                e.current_hourly_rate,
                COUNT(tl.log_id)                              AS shifts_logged,
                COALESCE(SUM(tl.total_hours), 0)               AS total_hours_worked,
                COALESCE(SUM(tl.total_hours * e.current_hourly_rate), 0) AS total_earnings
            FROM       employees e
            LEFT JOIN  departments d  ON d.department_id = e.department_id
            LEFT JOIN  time_logs   tl ON ' . implode(' AND ', $logJoin) . '
            ' . (count($where) ? 'WHERE ' . implode(' AND ', $where) : '') . '
            GROUP  BY e.employee_id, e.full_name, d.department_name, e.current_hourly_rate
            ORDER  BY total_earnings DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    json_ok(array_map(fn($r) => [
        'employee_id'         => (int)$r['employee_id'],
        'full_name'           => $r['full_name'],
        'department_name'     => $r['department_name'],
        'current_hourly_rate' => (float)$r['current_hourly_rate'],
        'shifts_logged'       => (int)$r['shifts_logged'],
        'total_hours_worked'  => (float)$r['total_hours_worked'],
        'total_earnings'      => (float)$r['total_earnings'],
    ], $stmt->fetchAll()));
}

json_err('Not found.', 404);
