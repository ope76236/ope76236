<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';

require_role('manager');

$pdo = db();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(404);
  echo "Не указан id";
  exit;
}

$st = $pdo->prepare("
  SELECT d.*
  FROM documents d
  WHERE d.id=?
  LIMIT 1
");
$st->execute([$id]);
$d = $st->fetch();

if (!$d) {
  http_response_code(404);
  echo "Файл не найден";
  exit;
}

// uploads path (как в documents.php)
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