<?php
// =============================================================================
// routes/shift_categories.php — Shift category CRUD
//
// GET    /backend/routes/shift_categories.php        → list all (auth required)
// POST   /backend/routes/shift_categories.php        → create (admin only)
// PUT    /backend/routes/shift_categories.php        → update (admin only)
// DELETE /backend/routes/shift_categories.php?id=X   → delete (admin only)
// =============================================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    requireAuth();
    $rows = getDB()->query('SELECT * FROM shift_categories ORDER BY shift_category_id')->fetchAll();
    json_ok(array_map(fn($r) => [
        'shift_category_id'   => (int)$r['shift_category_id'],
        'category_name'       => $r['category_name'],
        'rate_multiplier'     => (float)$r['rate_multiplier'],
        'standard_start_time' => $r['standard_start_time'],
        'standard_end_time'   => $r['standard_end_time'],
    ], $rows));
}

if ($method === 'POST') {
    requireAdmin();
    $body = bodyJson();
    $name = str($body, 'category_name');
    if ($name === '') json_err('category_name is required.');
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO shift_categories (category_name, rate_multiplier, standard_start_time, standard_end_time)
         VALUES (?, ?, ?, ?)'
    )->execute([
        $name,
        floatVal_($body, 'rate_multiplier', 1.0),
        str($body, 'standard_start_time') ?: null,
        str($body, 'standard_end_time')   ?: null,
    ]);
    json_ok(['shift_category_id' => (int)$pdo->lastInsertId(), 'message' => 'Shift category created.']);
}

if ($method === 'PUT') {
    requireAdmin();
    $body = bodyJson();
    $id   = intVal_($body, 'shift_category_id');
    if (!$id) json_err('shift_category_id is required.');
    $pdo = getDB();
    $chk = $pdo->prepare('SELECT shift_category_id FROM shift_categories WHERE shift_category_id = ?');
    $chk->execute([$id]);
    if (!$chk->fetch()) json_err('Shift category not found.', 404);
    $pdo->prepare(
        'UPDATE shift_categories SET category_name = ?, rate_multiplier = ?, standard_start_time = ?, standard_end_time = ? WHERE shift_category_id = ?'
    )->execute([
        str($body, 'category_name'),
        floatVal_($body, 'rate_multiplier', 1.0),
        str($body, 'standard_start_time') ?: null,
        str($body, 'standard_end_time')   ?: null,
        $id,
    ]);
    json_ok(['message' => 'Shift category updated.']);
}

if ($method === 'DELETE') {
    requireAdmin();
    $id = intVal_($_GET, 'id');
    if (!$id) json_err('id query param is required.');
    $pdo = getDB();
    $chk = $pdo->prepare('SELECT COUNT(*) FROM time_logs WHERE shift_category_id = ?');
    $chk->execute([$id]);
    if ((int)$chk->fetchColumn() > 0) json_err('Cannot delete a shift category that has time logs assigned.');
    $stmt = $pdo->prepare('DELETE FROM shift_categories WHERE shift_category_id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) json_err('Shift category not found.', 404);
    json_ok(['message' => 'Shift category deleted.']);
}

json_err('Method not allowed.', 405);
