<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();

$operatorId = (int)($_GET['operator_id'] ?? 0);
$currency = strtoupper(trim((string)($_GET['currency'] ?? 'KZT')));
$appId = (int)($_GET['app_id'] ?? 0); // для туриста

if ($operatorId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'operator_id is required'], JSON_UNESCAPED_UNICODE);
  exit;
}
if (!in_array($currency, ['KZT','USD','EUR'], true)) $currency = 'KZT';

$u = current_user();
$role = (string)($u['role'] ?? '');
$uid = (int)($u['id'] ?? 0);

if ($role === 'manager') {
  // ок
} elseif ($role === 'tourist') {
  // Туристу можно смотреть курс только для своей заявки (и только если он в составе заявки)
  if ($appId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'app_id is required for tourist'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $st = $pdo->prepare("
    SELECT 1
    FROM application_tourists at
    WHERE at.application_id = ?
      AND at.tourist_user_id = ?
    LIMIT 1
  ");
  $st->execute([$appId, $uid]);
  if (!$st->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
  }
} else {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($currency === 'KZT') {
  echo json_encode([
    'ok' => true,
    'operator_id' => $operatorId,
    'currency' => 'KZT',
    'rate_to_kzt' => 1.0,
    'captured_at' => null,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$st = $pdo->prepare("
  SELECT rate_to_kzt, captured_at
  FROM operator_fx_rates
  WHERE operator_id = ?
    AND currency = ?
  ORDER BY captured_at DESC
  LIMIT 1
");
$st->execute([$operatorId, $currency]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  echo json_encode([
    'ok' => false,
    'error' => 'rate_not_found',
    'operator_id' => $operatorId,
    'currency' => $currency,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

echo json_encode([
  'ok' => true,
  'operator_id' => $operatorId,
  'currency' => $currency,
  'rate_to_kzt' => (float)$row['rate_to_kzt'],
  'captured_at' => (string)$row['captured_at'],
], JSON_UNESCAPED_UNICODE);