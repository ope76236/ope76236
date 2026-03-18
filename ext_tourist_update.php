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

  $touristUserId = (int)($body['tourist_user_id'] ?? 0);
  $patch = $body['patch'] ?? null;

  if ($touristUserId <= 0 || !is_array($patch)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_request']);
    exit;
  }

  $pdo = db();

  // ensure tourist row exists
  $pdo->prepare("INSERT IGNORE INTO tourists(user_id) VALUES(?)")->execute([$touristUserId]);

  // tourists table: whitelist fields
  $mapTourists = [
    'iin' => 'iin',

    'last_name' => 'last_name',
    'first_name' => 'first_name',
    'middle_name' => 'middle_name',

    // NEW: English (passport Latin) names
    'last_name_en' => 'last_name_en',
    'first_name_en' => 'first_name_en',
    'middle_name_en' => 'middle_name_en',

    'birth_date' => 'birth_date',
    'citizenship' => 'citizenship',

    'passport_no' => 'passport_no',
    'passport_issue_date' => 'passport_issue_date',
    'passport_expiry_date' => 'passport_expiry_date',
  ];

  $set = [];
  $vals = [];

  foreach ($mapTourists as $k => $col) {
    if (!array_key_exists($k, $patch)) continue;

    $val = trim((string)$patch[$k]);

    // Normalize empty dates
    if (in_array($k, ['birth_date', 'passport_issue_date', 'passport_expiry_date'], true)) {
      if ($val === '' || $val === '0000-00-00') $val = '';
    }

    $set[] = "$col=?";
    $vals[] = $val;
  }

  if ($set) {
    $vals[] = $touristUserId;
    $sql = "UPDATE tourists SET " . implode(',', $set) . " WHERE user_id=? LIMIT 1";
    $pdo->prepare($sql)->execute($vals);
  }

  // users table: contacts
  $userSet = [];
  $userVals = [];
  if (array_key_exists('email', $patch)) { $userSet[] = "email=?"; $userVals[] = trim((string)$patch['email']); }
  if (array_key_exists('phone', $patch)) { $userSet[] = "phone=?"; $userVals[] = trim((string)$patch['phone']); }
  if ($userSet) {
    $userVals[] = $touristUserId;
    $pdo->prepare("UPDATE users SET " . implode(',', $userSet) . " WHERE id=? LIMIT 1")->execute($userVals);
  }

  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}