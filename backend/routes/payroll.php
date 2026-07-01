<?php
// list all periods (admin)
// periods for one dept (admin)
// 
// compute without saving (admin)
// create Draft period (admin)
// all records in period (admin)
// edit one Draft record (admin)
// approve whole period (admin)
// own approved records (employee)


declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

requireAuth();

$pdo    = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ─────────────────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Compute payroll figures for every active employee in a department
 * for a given year/month. Returns an array keyed by employee_id.
 * Does NOT write to the database.
 */
function computePayroll(PDO $pdo, int $deptId, int $year, int $month): array {
    // Date range for the month
    $from = sprintf('%04d-%02d-01', $year, $month);
    $to   = (new DateTime($from))->format('Y-m-t');   // last day of month

    // Pull all time logs for active employees in this department for the month
    $stmt = $pdo->prepare(
        "SELECT tl.employee_id,
                e.full_name,
                e.current_hourly_rate,
                tl.total_hours,
                tl.overtime_hours,
                COALESCE(sc.rate_multiplier,  1.0) AS shift_multiplier,
                COALESCE(oc.rate_multiplier,  1.0) AS overtime_multiplier
         FROM   time_logs tl
         JOIN   employees e
                ON  e.employee_id    = tl.employee_id
                AND e.department_id  = ?
                AND e.employment_status = 'Active'
         LEFT JOIN shift_categories    sc ON sc.shift_category_id    = tl.shift_category_id
         LEFT JOIN overtime_categories oc ON oc.overtime_category_id = tl.overtime_category_id
         WHERE  tl.clock_out IS NOT NULL
         AND    DATE(tl.clock_in) BETWEEN ? AND ?"
    );
    $stmt->execute([$deptId, $from, $to]);
    $logs = $stmt->fetchAll();

    // Aggregate per employee
    $map = [];   // employee_id → accumulated values

    foreach ($logs as $log) {
        $empId = (int)$log['employee_id'];

        if (!isset($map[$empId])) {
            $map[$empId] = [
                'employee_id'          => $empId,
                'full_name'            => $log['full_name'],
                'hourly_rate_snapshot' => (float)$log['current_hourly_rate'],
                'regular_hours'        => 0.0,
                'overtime_hours'       => 0.0,
                'regular_pay'          => 0.0,
                'overtime_pay'         => 0.0,
                'bonus'                => 0.0,
                'deductions'           => 0.0,
                'net_pay'              => 0.0,
                'notes'                => null,
            ];
        }

        $totalHours    = (float)$log['total_hours'];
        $overtimeHours = (float)($log['overtime_hours'] ?? 0);
        $regularHours  = max(0.0, $totalHours - $overtimeHours);
        $hourlyRate    = (float)$log['current_hourly_rate'];
        $shiftMult     = (float)$log['shift_multiplier'];
        $otMult        = (float)$log['overtime_multiplier'];

        $map[$empId]['regular_hours']  += $regularHours;
        $map[$empId]['overtime_hours'] += $overtimeHours;
        $map[$empId]['regular_pay']    += round($regularHours  * $hourlyRate * $shiftMult, 4);
        $map[$empId]['overtime_pay']   += round($overtimeHours * $hourlyRate * $otMult,    4);
    }

    // Final rounding and net_pay
    foreach ($map as &$rec) {
        $rec['regular_hours']  = round($rec['regular_hours'],  2);
        $rec['overtime_hours'] = round($rec['overtime_hours'], 2);
        $rec['regular_pay']    = round($rec['regular_pay'],    2);
        $rec['overtime_pay']   = round($rec['overtime_pay'],   2);
        $rec['net_pay']        = round(
            $rec['regular_pay'] + $rec['overtime_pay'] + $rec['bonus'] - $rec['deductions'],
            2
        );
    }
    unset($rec);

    return array_values($map);
}

function castRecord(array $r): array {
    return [
        'record_id'            => (int)$r['record_id'],
        'period_id'            => (int)$r['period_id'],
        'employee_id'          => (int)$r['employee_id'],
        'full_name'            => $r['full_name'] ?? null,
        'hourly_rate_snapshot' => (float)$r['hourly_rate_snapshot'],
        'regular_hours'        => (float)$r['regular_hours'],
        'overtime_hours'       => (float)$r['overtime_hours'],
        'regular_pay'          => (float)$r['regular_pay'],
        'overtime_pay'         => (float)$r['overtime_pay'],
        'bonus'                => (float)$r['bonus'],
        'deductions'           => (float)$r['deductions'],
        'net_pay'              => (float)$r['net_pay'],
        'notes'                => $r['notes'],
    ];
}

function castPeriod(array $r): array {
    return [
        'period_id'       => (int)$r['period_id'],
        'department_id'   => (int)$r['department_id'],
        'department_name' => $r['department_name'] ?? null,
        'period_year'     => (int)$r['period_year'],
        'period_month'    => (int)$r['period_month'],
        'status'          => $r['status'],
        'generated_by'    => (int)$r['generated_by'],
        'approved_by'     => $r['approved_by'] !== null ? (int)$r['approved_by'] : null,
        'generated_at'    => $r['generated_at'],
        'approved_at'     => $r['approved_at'],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// GET: list periods (admin)
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'periods') {
    requireAdmin();

    $where  = [];
    $params = [];

    if (!empty($_GET['department_id'])) {
        $where[]  = 'pp.department_id = ?';
        $params[] = (int)$_GET['department_id'];
    }
    if (!empty($_GET['status'])) {
        $where[]  = 'pp.status = ?';
        $params[] = $_GET['status'];
    }

    $sql = 'SELECT pp.*, d.department_name
            FROM   payroll_periods pp
            JOIN   departments d ON d.department_id = pp.department_id'
         . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY pp.period_year DESC, pp.period_month DESC, d.department_name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_ok(array_map('castPeriod', $stmt->fetchAll()));
}

// ─────────────────────────────────────────────────────────────────────────────
// GET: preview (compute without saving)
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'preview') {
    requireAdmin();

    $deptId = intVal_($_GET, 'department_id');
    $year   = intVal_($_GET, 'year');
    $month  = intVal_($_GET, 'month');

    if (!$deptId) json_err('department_id is required.');
    if (!$year)   json_err('year is required.');
    if (!$month || $month < 1 || $month > 12) json_err('month must be 1–12.');

    // Check if a period already exists for this dept/month/year
    $exists = $pdo->prepare(
        'SELECT period_id, status FROM payroll_periods
         WHERE  department_id = ? AND period_year = ? AND period_month = ? LIMIT 1'
    );
    $exists->execute([$deptId, $year, $month]);
    $existing = $exists->fetch();

    $records = computePayroll($pdo, $deptId, $year, $month);

    json_ok([
        'department_id'   => $deptId,
        'period_year'     => $year,
        'period_month'    => $month,
        'already_exists'  => (bool)$existing,
        'existing_status' => $existing ? $existing['status'] : null,
        'existing_period_id' => $existing ? (int)$existing['period_id'] : null,
        'records'         => $records,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// GET: records in a period (admin)
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'records') {
    requireAdmin();

    $periodId = intVal_($_GET, 'period_id');
    if (!$periodId) json_err('period_id is required.');

    // Verify period exists
    $pStmt = $pdo->prepare(
        'SELECT pp.*, d.department_name
         FROM   payroll_periods pp
         JOIN   departments d ON d.department_id = pp.department_id
         WHERE  pp.period_id = ? LIMIT 1'
    );
    $pStmt->execute([$periodId]);
    $period = $pStmt->fetch();
    if (!$period) json_err('Payroll period not found.', 404);

    $rStmt = $pdo->prepare(
        'SELECT pr.*, e.full_name
         FROM   payroll_records pr
         JOIN   employees e ON e.employee_id = pr.employee_id
         WHERE  pr.period_id = ?
         ORDER  BY e.full_name'
    );
    $rStmt->execute([$periodId]);
    $records = array_map('castRecord', $rStmt->fetchAll());

    json_ok([
        'period'  => castPeriod($period),
        'records' => $records,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// GET: my_history (employee sees own approved records)
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'my_history') {
    requireAuth();

    $empId = currentEmployeeId();
    if ($empId === null) json_err('No employee record linked to this account.', 403);

    $stmt = $pdo->prepare(
        "SELECT pr.*,
                pp.period_year, pp.period_month, pp.status,
                d.department_name
         FROM   payroll_records pr
         JOIN   payroll_periods pp ON pp.period_id     = pr.period_id
         JOIN   departments     d  ON d.department_id  = pp.department_id
         WHERE  pr.employee_id = ?
         AND    pp.status      = 'Approved'
         ORDER  BY pp.period_year DESC, pp.period_month DESC"
    );
    $stmt->execute([$empId]);
    $rows = $stmt->fetchAll();

    json_ok(array_map(fn($r) => array_merge(castRecord($r), [
        'period_year'     => (int)$r['period_year'],
        'period_month'    => (int)$r['period_month'],
        'department_name' => $r['department_name'],
    ]), $rows));
}

// ─────────────────────────────────────────────────────────────────────────────
// POST: generate — create Draft period + records
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'generate') {
    requireAdmin();

    $body   = bodyJson();
    $deptId = intVal_($body, 'department_id');
    $year   = intVal_($body, 'year');
    $month  = intVal_($body, 'month');

    if (!$deptId) json_err('department_id is required.');
    if (!$year)   json_err('year is required.');
    if (!$month || $month < 1 || $month > 12) json_err('month must be 1–12.');

    // Verify department exists
    $dChk = $pdo->prepare('SELECT department_id FROM departments WHERE department_id = ? LIMIT 1');
    $dChk->execute([$deptId]);
    if (!$dChk->fetch()) json_err('Department not found.', 404);

    // Block duplicate period for same dept/month/year
    $dup = $pdo->prepare(
        'SELECT period_id FROM payroll_periods
         WHERE  department_id = ? AND period_year = ? AND period_month = ? LIMIT 1'
    );
    $dup->execute([$deptId, $year, $month]);
    if ($dup->fetch()) {
        json_err("A payroll period already exists for this department and month. Delete or edit the existing draft.");
    }

    // Check that no employee in this dept already appears in another period for the same month
    // (prevents an employee appearing in two department payrolls for the same month)
    $conflict = $pdo->prepare(
        "SELECT e.full_name
         FROM   employees e
         JOIN   payroll_records pr ON pr.employee_id = e.employee_id
         JOIN   payroll_periods pp ON pp.period_id   = pr.period_id
         WHERE  e.department_id = ?
         AND    pp.period_year  = ?
         AND    pp.period_month = ?
         LIMIT  1"
    );
    $conflict->execute([$deptId, $year, $month]);
    $conflictEmp = $conflict->fetch();
    if ($conflictEmp) {
        json_err("Employee '{$conflictEmp['full_name']}' already has a payroll record for this month in another period.");
    }

    $records = computePayroll($pdo, $deptId, $year, $month);
    if (empty($records)) json_err('No time logs found for active employees in this department for the selected month.');

    // Insert period
    $pdo->prepare(
        'INSERT INTO payroll_periods (department_id, period_year, period_month, status, generated_by)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$deptId, $year, $month, 'Draft', currentAccountId()]);

    $periodId = (int)$pdo->lastInsertId();

    // Insert records
    $insRec = $pdo->prepare(
        'INSERT INTO payroll_records
            (period_id, employee_id, hourly_rate_snapshot,
             regular_hours, overtime_hours,
             regular_pay, overtime_pay,
             bonus, deductions, net_pay, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($records as $rec) {
        $insRec->execute([
            $periodId,
            $rec['employee_id'],
            $rec['hourly_rate_snapshot'],
            $rec['regular_hours'],
            $rec['overtime_hours'],
            $rec['regular_pay'],
            $rec['overtime_pay'],
            0.0,    // bonus starts at 0
            0.0,    // deductions start at 0
            $rec['regular_pay'] + $rec['overtime_pay'],  // net_pay before bonus/deductions
            null,
        ]);
    }

    json_ok([
        'period_id'    => $periodId,
        'record_count' => count($records),
        'message'      => 'Draft payroll period generated. Review and edit records before approving.',
    ], 201);
}

// ─────────────────────────────────────────────────────────────────────────────
// PUT: edit one record in a Draft period (admin)
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'PUT' && $action === 'record') {
    requireAdmin();

    $body     = bodyJson();
    $recordId = intVal_($body, 'record_id');
    if (!$recordId) json_err('record_id is required.');

    // Fetch record + period
    $stmt = $pdo->prepare(
        'SELECT pr.*, pp.status
         FROM   payroll_records pr
         JOIN   payroll_periods pp ON pp.period_id = pr.period_id
         WHERE  pr.record_id = ? LIMIT 1'
    );
    $stmt->execute([$recordId]);
    $rec = $stmt->fetch();
    if (!$rec)                      json_err('Payroll record not found.', 404);
    if ($rec['status'] !== 'Draft') json_err('Only Draft payroll records can be edited.');

    $fields = [];
    $params = [];

    $editableFloats = ['regular_hours', 'overtime_hours', 'regular_pay', 'overtime_pay', 'bonus', 'deductions'];
    foreach ($editableFloats as $col) {
        if (array_key_exists($col, $body)) {
            $val = floatVal_($body, $col, 0.0);
            if ($val < 0) json_err("$col cannot be negative.");
            $fields[] = "$col = ?";
            $params[] = $val;
        }
    }

    if (array_key_exists('notes', $body)) {
        $fields[] = 'notes = ?';
        $params[] = str($body, 'notes') ?: null;
    }

    if (empty($fields)) json_err('Nothing to update.');

    // Recompute net_pay from either updated or existing values
    $regular_pay  = array_key_exists('regular_pay',  $body) ? floatVal_($body, 'regular_pay',  0.0) : (float)$rec['regular_pay'];
    $overtime_pay = array_key_exists('overtime_pay', $body) ? floatVal_($body, 'overtime_pay', 0.0) : (float)$rec['overtime_pay'];
    $bonus        = array_key_exists('bonus',        $body) ? floatVal_($body, 'bonus',        0.0) : (float)$rec['bonus'];
    $deductions   = array_key_exists('deductions',   $body) ? floatVal_($body, 'deductions',   0.0) : (float)$rec['deductions'];
    $net_pay      = round($regular_pay + $overtime_pay + $bonus - $deductions, 2);

    $fields[] = 'net_pay = ?';
    $params[] = $net_pay;
    $params[] = $recordId;

    $pdo->prepare(
        'UPDATE payroll_records SET ' . implode(', ', $fields) . ' WHERE record_id = ?'
    )->execute($params);

    // Return updated record
    $updated = $pdo->prepare(
        'SELECT pr.*, e.full_name
         FROM   payroll_records pr
         JOIN   employees e ON e.employee_id = pr.employee_id
         WHERE  pr.record_id = ?'
    );
    $updated->execute([$recordId]);
    json_ok(castRecord($updated->fetch()));
}

// ─────────────────────────────────────────────────────────────────────────────
// POST: approve — lock the whole period
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST' && $action === 'approve') {
    requireAdmin();

    $periodId = intVal_($_GET, 'period_id');
    if (!$periodId) json_err('period_id query param is required.');

    $stmt = $pdo->prepare(
        'SELECT * FROM payroll_periods WHERE period_id = ? LIMIT 1'
    );
    $stmt->execute([$periodId]);
    $period = $stmt->fetch();
    if (!$period)                      json_err('Payroll period not found.', 404);
    if ($period['status'] === 'Approved') json_err('This payroll period is already approved.');

    $pdo->prepare(
        'UPDATE payroll_periods
         SET    status = ?, approved_by = ?, approved_at = NOW()
         WHERE  period_id = ?'
    )->execute(['Approved', currentAccountId(), $periodId]);

    json_ok(['message' => 'Payroll period approved and locked.', 'period_id' => $periodId]);
}

json_err('Not found.', 404);
