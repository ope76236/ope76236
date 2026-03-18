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

  $operatorHost = trim((string)($_GET['operator_host'] ?? ''));
  $externalRef = trim((string)($_GET['external_ref'] ?? ''));

  if ($operatorHost === '' || $externalRef === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_request']);
    exit;
  }

  $pdo = db();

  $st = $pdo->prepare("
    SELECT ol.application_id, a.app_number, a.operator_id
    FROM operator_links ol
    JOIN applications a ON a.id = ol.application_id
    WHERE ol.operator_host=? AND ol.external_ref=?
    LIMIT 1
  ");
  $st->execute([$operatorHost, $externalRef]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    echo json_encode(['ok' => true, 'found' => false], JSON_UNESCAPED_UNICODE);
    exit;
  }

  echo json_encode([
    'ok' => true,
    'found' => true,
    'application_id' => (int)$row['application_id'],
    'app_number' => (string)$row['app_number'],
    'operator_id' => (int)$row['operator_id'],
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}