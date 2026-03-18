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
if ($appId <= 0) {
  http_response_code(404);
  echo "Не указан app_id";
  exit;
}

// доступ
$stAccess = $pdo->prepare("
  SELECT a.*
  FROM applications a
  JOIN application_tourists at ON at.application_id = a.id AND at.tourist_user_id = ?
  WHERE a.id=?
  LIMIT 1
");
$stAccess->execute([$uid, $appId]);
$app = $stAccess->fetch(PDO::FETCH_ASSOC);
if (!$app) {
  http_response_code(403);
  echo "Доступ запрещён";
  exit;
}

function pick_first_existing_template_html(\PDO $pdo): string
{
  try {
    $st = $pdo->prepare("
      SELECT body_html
      FROM document_templates
      WHERE type='contract'
      ORDER BY id DESC
      LIMIT 1
    ");
    $st->execute();
    $html = (string)($st->fetchColumn() ?: '');
    if (trim($html) !== '') return $html;
  } catch (\Throwable $e) {}

  try {
    $st = $pdo->prepare("
      SELECT body_html
      FROM document_templates
      WHERE code='contract'
      ORDER BY id DESC
      LIMIT 1
    ");
    $st->execute();
    $html = (string)($st->fetchColumn() ?: '');
    if (trim($html) !== '') return $html;
  } catch (\Throwable $e) {}

  try {
    $st = $pdo->prepare("
      SELECT body_html
      FROM document_templates
      ORDER BY id DESC
      LIMIT 1
    ");
    $st->execute();
    $html = (string)($st->fetchColumn() ?: '');
    if (trim($html) !== '') return $html;
  } catch (\Throwable $e) {}

  return '';
}

$tplHtml = pick_first_existing_template_html($pdo);
if (trim($tplHtml) === '') {
  http_response_code(500);
  echo "Шаблон договора не найден в document_templates.";
  exit;
}

$vars = doc_variables_for_app($pdo, $appId);
$renderedHtml = render_doc_template($tplHtml, $vars);

$appNo = (int)($vars['app_number'] ?? 0);
if ($appNo <= 0) $appNo = (int)$appId;

$filename = 'contract_' . $appNo . '.pdf';

/**
 * dompdf autoload (у вас: public_html/lib/dompdf/vendor/autoload.php)
 */
$autoloadCandidates = [
  dirname(__DIR__) . '/lib/dompdf/vendor/autoload.php',
  dirname(__DIR__) . '/lib/dompdf/autoload.inc.php',
  dirname(__DIR__) . '/../vendor/autoload.php',
];

$autoload = '';
foreach ($autoloadCandidates as $p) {
  if (is_file($p)) { $autoload = $p; break; }
}
if ($autoload === '') {
  http_response_code(500);
  echo "Dompdf не найден. Проверьте public_html/lib/dompdf/vendor/autoload.php";
  exit;
}

require_once $autoload;

if (!class_exists(\Dompdf\Dompdf::class)) {
  http_response_code(500);
  echo "Dompdf autoload подключен, но класс Dompdf\\Dompdf не найден.";
  exit;
}

/**
 * Единый “спокойный” стиль:
 * - один базовый кегль
 * - заголовки чуть больше, но не “кричат”
 * - b/strong не 900, а 600
 * - одинаковые поля слева/справа
 * - масштабирование page-fit чтобы влезло на 1 страницу
 */
$scale = 0.90;

$pdfCss = <<<CSS
  @page { margin: 8mm 8mm; }

  body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 10.5px;
    line-height: 1.22;
    color:#0f172a;
    font-weight: 400;
  }

  b, strong { font-weight: 600; }

  h1 { font-size: 13px; margin: 0 0 6px 0; line-height: 1.18; font-weight: 600; }
  h2 { font-size: 12px; margin: 10px 0 6px 0; line-height: 1.18; font-weight: 600; }
  h3 { font-size: 11px; margin: 10px 0 6px 0; line-height: 1.18; font-weight: 600; }

  p { margin: 0 0 6px 0; }

  table { width: 100%; border-collapse: collapse; margin: 6px 0 8px 0; }
  th, td { border: 1px solid #cbd5e1; padding: 3px 4px; vertical-align: top; }
  th { background: #f1f5f9; font-weight: 600; }

  .no-border, .no-border th, .no-border td { border: none !important; }

  /* IMPORTANT: унифицируем шапку “Руководителю…” (обычно это просто <p> или <div align=right>) */
  [align="right"], .right, .text-right { font-size: 10.5px; font-weight: 400; }

  /* Сжимаем весь документ, чтобы влезал */
  .page-fit {
    transform: scale({$scale});
    transform-origin: top left;
    width: calc(100% / {$scale});
  }
CSS;

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new \Dompdf\Dompdf($options);

$html = '<!doctype html><html><head><meta charset="utf-8"><style>' . $pdfCss . '</style></head><body>'
  . '<div class="page-fit">' . $renderedHtml . '</div>'
  . '</body></html>';

$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// если всё равно 2 страницы — уменьшаем масштаб чуть сильнее
$pageCount = (int)$dompdf->getCanvas()->get_page_count();
if ($pageCount > 1) {
  $scale2 = 0.86;
  $pdfCss2 = str_replace("scale({$scale})", "scale({$scale2})", $pdfCss);
  $pdfCss2 = str_replace("100% / {$scale}", "100% / {$scale2}", $pdfCss2);

  $dompdf = new \Dompdf\Dompdf($options);
  $html2 = '<!doctype html><html><head><meta charset="utf-8"><style>' . $pdfCss2 . '</style></head><body>'
    . '<div class="page-fit">' . $renderedHtml . '</div>'
    . '</body></html>';

  $dompdf->loadHtml($html2, 'UTF-8');
  $dompdf->setPaper('A4', 'portrait');
  $dompdf->render();
}

// скачать
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $dompdf->output();