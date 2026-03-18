<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/helpers.php';

header('Content-Type: application/json; charset=utf-8');

function fmt_ymd(?string $d): string {
  $d = trim((string)$d);
  if ($d === '' || $d === '0000-00-00') return '';
  return $d;
}

try {
  $u = current_user();
  if (!$u || ($u['role'] ?? '') !== 'manager') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
  }

  $pdo = db();

  $appId = (int)($_GET['id'] ?? 0);
  $appNumber = trim((string)($_GET['app_number'] ?? ''));

  if ($appId <= 0 && $appNumber === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'id_or_app_number_required']);
    exit;
  }

  if ($appId > 0) {
    $st = $pdo->prepare("SELECT * FROM applications WHERE id=? LIMIT 1");
    $st->execute([$appId]);
  } else {
    $st = $pdo->prepare("SELECT * FROM applications WHERE app_number=? LIMIT 1");
    $st->execute([$appNumber]);
  }

  $app = $st->fetch(PDO::FETCH_ASSOC);
  if (!$app) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'application_not_found']);
    exit;
  }

  $appId = (int)$app['id'];

  $stT = $pdo->prepare("
    SELECT
      u.id AS tourist_user_id,
      u.email,
      u.phone,
      u.name,
      t.iin,
      t.last_name, t.first_name, t.middle_name,
      t.last_name_en, t.first_name_en, t.middle_name_en,
      t.birth_date,
      t.citizenship,
      t.passport_no,
      t.passport_issue_date,
      t.passport_expiry_date
    FROM application_tourists at
    JOIN users u ON u.id = at.tourist_user_id
    LEFT JOIN tourists t ON t.user_id = u.id
    WHERE at.application_id=?
    ORDER BY t.last_name ASC, t.first_name ASC, u.id ASC
  ");
  $stT->execute([$appId]);
  $tourists = $stT->fetchAll(PDO::FETCH_ASSOC);

  foreach ($tourists as &$t) {
    $t['birth_date'] = fmt_ymd($t['birth_date'] ?? '');
    $t['passport_issue_date'] = fmt_ymd($t['passport_issue_date'] ?? '');
    $t['passport_expiry_date'] = fmt_ymd($t['passport_expiry_date'] ?? '');
  }
  unset($t);

  echo json_encode([
    'ok' => true,
    'application' => [
      'id' => (int)$app['id'],
      'app_number' => (string)$app['app_number'],
      'operator_id' => (int)($app['operator_id'] ?? 0),
      'title' => (string)($app['title'] ?? ''),
      'country' => (string)($app['country'] ?? ''),
      'destination' => (string)($app['destination'] ?? ''),
    ],
    'tourists' => $tourists,
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}