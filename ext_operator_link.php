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

  $body = json_decode((string)file_get_contents('php://input'), true);
  if (!is_array($body)) $body = [];

  $appNumber = trim((string)($body['app_number'] ?? ''));
  $operatorId = (int)($body['operator_id'] ?? 0);
  $externalRef = trim((string)($body['external_ref'] ?? ''));
  $externalUrl = trim((string)($body['external_url'] ?? ''));
  $operatorHost = trim((string)($body['operator_host'] ?? ''));

  if ($appNumber === '' || $operatorId <= 0 || $externalRef === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_request']);
    exit;
  }

  $pdo = db();

  $stA = $pdo->prepare("SELECT id FROM applications WHERE app_number=? LIMIT 1");
  $stA->execute([$appNumber]);
  $app = $stA->fetch(PDO::FETCH_ASSOC);
  if (!$app) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'application_not_found']);
    exit;
  }
  $appId = (int)$app['id'];

  // upsert
  $st = $pdo->prepare("
    INSERT INTO operator_links(application_id, operator_id, external_ref, external_url, operator_host, created_by_user_id, created_at, updated_at)
    VALUES(?,?,?,?,?,?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
      external_ref=VALUES(external_ref),
      external_url=VALUES(external_url),
      operator_host=VALUES(operator_host),
      updated_at=NOW()
  ");
  $st->execute([$appId, $operatorId, $externalRef, $externalUrl, $operatorHost, (int)($u['id'] ?? 0)]);

  echo json_encode(['ok' => true, 'application_id' => $appId], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}