<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $u = current_user();
  if (!$u || ($u['role'] ?? '') !== 'manager') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
  }

  $pdo = db();
  $rows = $pdo->query("SELECT id, name, full_name, email, phone FROM tour_operators ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['ok' => true, 'operators' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}