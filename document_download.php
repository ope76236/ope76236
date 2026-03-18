<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';

/**
 * FIX: ошибка 500, скорее всего из-за отсутствия функции require_roles().
 * Делаем совместимо: разрешаем доступ "tourist" и "manager" через ручную проверку роли.
 */

// Если в проекте есть require_login() — используем его, иначе просто берём current_user()
if (function_exists('require_login')) {
  require_login();
}

$pdo = db();
$u = current_user();
$uid = (int)($u['id'] ?? 0);
$role = (string)($u['role'] ?? '');

if (!in_array($role, ['tourist','manager'], true)) {
  http_response_code(403);
  echo "Доступ запрещён";
  exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(404);
  echo "Не указан id";
  exit;
}

if ($role === 'manager') {
  $st = $pdo->prepare("
    SELECT d.*
    FROM documents d
    WHERE d.id=?
    LIMIT 1
  ");
  $st->execute([$id]);
  $d = $st->fetch();
} else {
  // Доступ туристу только если документ принадлежит заявке, где турист в составе
  $st = $pdo->prepare("
    SELECT d.*
    FROM documents d
    JOIN application_tourists at ON at.application_id = d.application_id AND at.tourist_user_id = ?
    WHERE d.id=?
    LIMIT 1
  ");
  $st->execute([$uid, $id]);
  $d = $st->fetch();
}

if (!$d) {
  http_response_code(403);
  echo "Доступ запрещён";
  exit;
}

$uploadDir = __DIR__ . '/../uploads';
if (!is_dir($uploadDir)) $uploadDir = dirname(__DIR__) . '/uploads';

$path = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . (string)$d['stored_name'];
if (!is_file($path)) {
  http_response_code(404);
  echo "Файл отсутствует на диске";
  exit;
}

$mime = (string)($d['mime_type'] ?? '');
if ($mime === '') $mime = 'application/octet-stream';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode((string)$d['file_name']) . '"');

readfile($path);
exit;