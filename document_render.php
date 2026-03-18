<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/documents.php';

require_role('tourist');

$pdo = db();

$u = current_user();
$uid = (int)($u['id'] ?? 0);

$appId = (int)($_GET['app_id'] ?? 0);
$tplId = (int)($_GET['tpl_id'] ?? 0);

if ($appId <= 0 || $tplId <= 0) {
  http_response_code(404);
  echo "Не указан app_id или tpl_id";
  exit;
}

// доступ туриста к заявке
$stAccess = $pdo->prepare("
  SELECT 1
  FROM application_tourists
  WHERE application_id=? AND tourist_user_id=?
  LIMIT 1
");
$stAccess->execute([$appId, $uid]);
if (!$stAccess->fetch()) {
  http_response_code(403);
  echo "Доступ запрещён";
  exit;
}

// документ должен быть прикреплён к заявке и разрешён для туриста
$stDoc = $pdo->prepare("
  SELECT 1
  FROM application_documents
  WHERE application_id=? AND template_id=? AND show_in_tourist=1
  LIMIT 1
");
$stDoc->execute([$appId, $tplId]);
if (!$stDoc->fetch()) {
  http_response_code(403);
  echo "Документ недоступен";
  exit;
}

$stTpl = $pdo->prepare("SELECT * FROM document_templates WHERE id=? AND is_active=1 LIMIT 1");
$stTpl->execute([$tplId]);
$tpl = $stTpl->fetch();
if (!$tpl) {
  http_response_code(404);
  echo "Шаблон не найден";
  exit;
}

$vars = doc_variables_for_app($pdo, $appId);
$htmlBody = render_doc_template((string)$tpl['body_html'], $vars);

$docTitle = (string)($tpl['title'] ?? 'Документ');
$docTitleSafe = htmlspecialchars($docTitle, ENT_QUOTES);

$html = "<!doctype html>
<html lang='ru'>
<head>
  <meta charset='utf-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1'>
  <title>{$docTitleSafe}</title>
  <style>
    body{font-family:Arial,sans-serif;color:#0f172a;line-height:1.45;padding:24px;background:#fff}
    .toolbar{position:sticky;top:0;background:#fff;padding:10px 0 14px;border-bottom:1px solid #e2e8f0;margin-bottom:18px}
    .btn{display:inline-block;padding:8px 12px;border:1px solid #cbd5e1;border-radius:10px;text-decoration:none;color:#0f172a}
    .btn + .btn{margin-left:8px}
    @media print {.toolbar{display:none} body{padding:0}}
  </style>
</head>
<body>
  <div class='toolbar'>
    <a class='btn' href='/tourist/documents.php?app_id=" . (int)$appId . "'>← Назад</a>
    <a class='btn' href='javascript:window.print()'>Печать</a>
  </div>
  {$htmlBody}
</body>
</html>";

header('Content-Type: text/html; charset=utf-8');
echo $html;