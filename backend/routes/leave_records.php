<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// list all forms admin/ employee self
if ($method === 'GET') {
    requireAuth();
    $pdo    = getDB();
    $where  = [];
    $params = [];

    if (currentAccessLevel() !== 'admin') {
        $where[]  = 'lr.employee_id = ?';
        $params[] = currentEmployeeId();
        // Employee can also filter by search (leave type)
        if (!empty($_GET['search'])) {
            $where[]  = 'lr.leave_type LIKE ?';
            $params[] = '%' . $_GET['search'] . '%';
        }
        if (!empty($_GET['status'])) { $where[] = 'lr.leave_status = ?'; $params[] = $_GET['status']; }
    } else {
        if (!empty($_GET['employee_id'])) { $where[] = 'lr.employee_id = ?'; $params[] = (int)$_GET['employee_id']; }
        if (!empty($_GET['status']))      { $where[] = 'lr.leave_status = ?'; $params[] = $_GET['status']; }
        // Admin search by employee name or leave type
        if (!empty($_GET['search'])) {
            $where[]  = '(e.full_name LIKE ? OR lr.leave_type LIKE ?)';
            $params[] = '%' . $_GET['search'] . '%';
            $params[] = '%' . $_GET['search'] . '%';
        }
    }

    $sql = 'SELECT lr.*, e.full_name
            FROM   leave_records lr
            LEFT   JOIN employees e ON e.employee_id = lr.employee_id'
         . (count($where) ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY lr.date_from DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_ok(array_map('castLeave', $stmt->fetchAll()));
}

// POST: file a leave request 
if ($method === 'POST') {
    requireAuth();
    $body       = bodyJson();
    $employeeId = currentAccessLevel() === 'admin'
                  ? intVal_($body, 'employee_id', currentEmployeeId())
                  : currentEmployeeId();
    $leaveType  = str($body, 'leave_type');
    $dateFrom   = str($body, 'date_from');
    $dateTo     = str($body, 'date_to');

    if ($leaveType === '') json_err('leave_type is required.');
    if ($dateFrom  === '') json_err('date_from is required.');
    if ($dateTo    === '') json_err('date_to is required.');
    if ($dateTo < $dateFrom) json_err('date_to must be on or after date_from.');

    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO leave_records (employee_id, leave_type, date_from, date_to, leave_status, remarks)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$employeeId, $leaveType, $dateFrom, $dateTo, 'Pending', str($body, 'remarks') ?: null]);

    json_ok(['leave_id' => (int)$pdo->lastInsertId(), 'message' => 'Leave request filed.']);
}

// approve/reject (admin) - edit (employee)
if ($method === 'PUT') {
    requireAuth();
    $body    = bodyJson();
    $leaveId = intVal_($body, 'leave_id');
    if (!$leaveId) json_err('leave_id is required.');

    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT * FROM leave_records WHERE leave_id = ? LIMIT 1');
    $stmt->execute([$leaveId]);
    $leave = $stmt->fetch();
    if (!$leave) json_err('Leave record not found.', 404);

    if (currentAccessLevel() === 'admin') {
        $newStatus = str($body, 'leave_status', $leave['leave_status']);
        if (!in_array($newStatus, ['Pending', 'Approved', 'Rejected'], true)) {
            json_err('leave_status must be Pending, Approved, or Rejected.');
        }
        $pdo->prepare(
            'UPDATE leave_records SET leave_type = ?, date_from = ?, date_to = ?, leave_status = ?, remarks = ? WHERE leave_id = ?'
        )->execute([
            str($body, 'leave_type', $leave['leave_type']),
            str($body, 'date_from',  $leave['date_from']),
            str($body, 'date_to',    $leave['date_to']),
            $newStatus,
            str($body, 'remarks', $leave['remarks'] ?? ''),
            $leaveId,
        ]);
        json_ok(['message' => 'Leave record updated.']);
    }

    if ((int)$leave['employee_id'] !== currentEmployeeId()) json_err('Forbidden.', 403);
    if ($leave['leave_status'] !== 'Pending') json_err('Only pending requests can be edited.');

    $dateFrom = str($body, 'date_from', $leave['date_from']);
    $dateTo   = str($body, 'date_to',   $leave['date_to']);
    if ($dateTo < $dateFrom) json_err('date_to must be on or after date_from.');
//employee
    $pdo->prepare(
        'UPDATE leave_records SET leave_type = ?, date_from = ?, date_to = ?, remarks = ? WHERE leave_id = ?'
    )->execute([
        str($body, 'leave_type', $leave['leave_type']),
        $dateFrom, $dateTo,
        str($body, 'remarks', $leave['remarks'] ?? ''),
        $leaveId,
    ]);
    json_ok(['message' => 'Leave request updated.']);
}

// DELETE (admin) / cancel (employee)
if ($method === 'DELETE') {
    requireAuth();
    $id = intVal_($_GET, 'id');
    if (!$id) json_err('id query param is required.');

    $pdo  = getDB();
    $stmt = $pdo->prepare('SELECT * FROM leave_records WHERE leave_id = ? LIMIT 1');
    $stmt->execute([$id]);
    $leave = $stmt->fetch();
    if (!$leave) json_err('Leave record not found.', 404);

    if (currentAccessLevel() !== 'admin') {
        if ((int)$leave['employee_id'] !== currentEmployeeId()) json_err('Forbidden.', 403);
        if ($leave['leave_status'] !== 'Pending') json_err('Only pending requests can be cancelled.');
    }

    $pdo->prepare('DELETE FROM leave_records WHERE leave_id = ?')->execute([$id]);
    json_ok(['message' => 'Leave record deleted.']);
}

json_err('Method not allowed.', 405);

function castLeave(array $r): array {
    return [
        'leave_id'     => (int)$r['leave_id'],
        'employee_id'  => (int)$r['employee_id'],
        'leave_type'   => $r['leave_type'],
        'date_from'    => $r['date_from'],
        'date_to'      => $r['date_to'],
        'leave_status' => $r['leave_status'],
        'remarks'      => $r['remarks'],
        'full_name'    => $r['full_name'] ?? null,
    ];
}