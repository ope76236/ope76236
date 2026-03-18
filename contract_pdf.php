<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/documents.php';
require_once __DIR__ . '/../app/fx.php';

require_role('manager');

$pdo = db();

$appId = (int)($_GET['app_id'] ?? 0);
$tplId = (int)($_GET['tpl_id'] ?? 0);

if ($appId <= 0 || $tplId <= 0) {
  http_response_code(404);
  echo "Не указан app_id или tpl_id";
  exit;
}

$stTpl = $pdo->prepare("SELECT * FROM document_templates WHERE id=? AND is_active=1 LIMIT 1");
$stTpl->execute([$tplId]);
$tpl = $stTpl->fetch(PDO::FETCH_ASSOC);
if (!$tpl) {
  http_response_code(404);
  echo "Шаблон не найден";
  exit;
}

$stApp = $pdo->prepare("SELECT * FROM applications WHERE id=? LIMIT 1");
$stApp->execute([$appId]);
$app = $stApp->fetch(PDO::FETCH_ASSOC);
if (!$app) {
  http_response_code(404);
  echo "Заявка ��е найдена";
  exit;
}

function money_fmt(float $n): string { return number_format($n, 2, '.', ' '); }
function fmt_dmy(?string $ymd): string
{
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '—';
  $ts = strtotime($ymd);
  if ($ts === false) return $ymd;
  return date('d.m.Y', $ts);
}

$vars = doc_variables_for_app($pdo, $appId);

/**
 * Дедлайны подмешиваем если в шаблоне есть {deadlines_list_html}
 */
$templateHasDeadlines = (strpos((string)$tpl['body_html'], '{deadlines_list_html}') !== false);

if ($templateHasDeadlines) {
  $appCurrency = (string)($app['currency'] ?? 'KZT');
  if (!in_array($appCurrency, ['KZT', 'USD', 'EUR'], true)) $appCurrency = 'KZT';

  $touristPriceCur = (float)($app['tourist_price_amount'] ?? 0.0);

  // курс на сегодня (на день генерации PDF)
  $fxInfo = fx_operator_today_for_app($pdo, $app);
  $fxToday = (float)($fxInfo['rate'] ?? 0);

  if ($appCurrency === 'KZT') $fxToday = 1.0;
  if ($fxToday <= 0) $fxToday = (float)($app['fx_rate_to_kzt'] ?? 1.0);
  if ($fxToday <= 0) $fxToday = 1.0;

  $fxTodaySourceText = (($fxInfo['source'] ?? '') === 'operator_today')
    ? 'по курсу туроператора на сегодня'
    : ((($fxInfo['source'] ?? '') === 'kzt') ? 'тенге' : 'по курсу в заявке');

  $todayText = date('d.m.Y');

  $deadlines = [];
  try {
    $stDl = $pdo->prepare("
      SELECT due_date, percent, note
      FROM payment_deadlines
      WHERE application_id=?
        AND direction='tourist_to_agent'
      ORDER BY due_date ASC, id ASC
    ");
    $stDl->execute([$appId]);
    $deadlines = $stDl->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $deadlines = [];
  }

  $deadlinesListHtml = '';
  if ($deadlines) {
    $items = [];
    foreach ($deadlines as $dl) {
      $due = fmt_dmy((string)($dl['due_date'] ?? ''));
      $percent = (float)($dl['percent'] ?? 0);
      if ($percent < 0) $percent = 0;
      if ($percent > 100) $percent = 100;

      $amtCur = round($touristPriceCur * ($percent / 100.0), 2);
      $amtKzt = ($appCurrency === 'KZT')
        ? $amtCur
        : round($amtCur * $fxToday, 2);

      $note = trim((string)($dl['note'] ?? ''));

      $txt = htmlspecialchars($due, ENT_QUOTES)
        . ' — ' . htmlspecialchars(number_format($percent, 0, '.', ''), ENT_QUOTES) . '%'
        . ' — ' . htmlspecialchars(money_fmt($amtCur), ENT_QUOTES) . ' ' . htmlspecialchars($appCurrency, ENT_QUOTES)
        . ' — ' . htmlspecialchars(money_fmt($amtKzt), ENT_QUOTES) . ' KZT'
        . ' <span style="color:#64748b; font-weight:600;">'
        . '(курс на ' . htmlspecialchars($todayText, ENT_QUOTES) . ': ' . htmlspecialchars(money_fmt($fxToday), ENT_QUOTES) . ', ' . htmlspecialchars($fxTodaySourceText, ENT_QUOTES) . ')'
        . '</span>';

      if ($note !== '') {
        $txt .= ' <span style="color:#64748b; font-weight:600;">· ' . htmlspecialchars($note, ENT_QUOTES) . '</span>';
      }

      $items[] = '<li>' . $txt . '</li>';
    }
    $deadlinesListHtml = implode("\n", $items);
  } else {
    $deadlinesListHtml = '<li style="color:#64748b;">Дедлайны не заданы.</li>';
  }

  $vars['deadlines_list_html'] = $deadlinesListHtml;
}

$renderedHtml = render_doc_template((string)$tpl['body_html'], $vars);

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

$scale = 0.90;

$pdfCss = <<<CSS
  @page { margin: 8mm 8mm; }
  body{
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 10.5px;
    line-height: 1.22;
    color:#0f172a;
    font-weight: 400;
  }
  b, strong{ font-weight: 600; }
  h1{ font-size: 13px; margin: 0 0 6px 0; line-height: 1.18; font-weight: 600; }
  h2{ font-size: 12px; margin: 10px 0 6px 0; line-height: 1.18; font-weight: 600; }
  h3{ font-size: 11px; margin: 10px 0 6px 0; line-height: 1.18; font-weight: 600; }
  p{ margin: 0 0 6px 0; }
  table{ width: 100%; border-collapse: collapse; margin: 6px 0 8px 0; table-layout: fixed; }
  th, td{ border: 1px solid #cbd5e1; padding: 3px 4px; vertical-align: top; word-break: break-word; }
  th{ background: #f1f5f9; font-weight: 600; }
  .no-border, .no-border th, .no-border td{ border: none !important; }
  [align="right"], .right, .text-right { font-size: 10.5px; font-weight: 400; }
  .page-fit{ transform: scale({$scale}); transform-origin: top left; width: calc(100% / {$scale}); }
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

$docTitle = (string)($tpl['title'] ?? 'document');
$docTitle = preg_replace('/[^\p{L}\p{N}\-\_\.\s]+/u', '', $docTitle);
$docTitle = trim($docTitle);
if ($docTitle === '') $docTitle = 'document';

$filename = $docTitle . '_app_' . (int)$appId . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $dompdf->output();
exit;