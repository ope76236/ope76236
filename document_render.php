<?php
declare(strict_types=1);

$debug = (isset($_GET['debug']) && $_GET['debug'] === '1');
if ($debug) {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

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

$stChk = $pdo->prepare("
  SELECT 1
  FROM application_documents
  WHERE application_id=? AND template_id=?
  LIMIT 1
");
$stChk->execute([$appId, $tplId]);
if (!$stChk->fetch()) {
  http_response_code(403);
  echo "Документ не прикреплён к заявке";
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
  echo "Заявка не найдена";
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

$tplType = strtolower(trim((string)($tpl['type'] ?? '')));
$tplCode = strtolower(trim((string)($tpl['code'] ?? '')));
$isContract = ($tplType === 'contract' || $tplCode === 'contract');

if ($isContract) {
  $appCurrency = (string)($app['currency'] ?? 'KZT');
  if (!in_array($appCurrency, ['KZT', 'USD', 'EUR'], true)) $appCurrency = 'KZT';

  $touristPriceCur = (float)($app['tourist_price_amount'] ?? 0.0);

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

$htmlBody = render_doc_template((string)$tpl['body_html'], $vars);

if ($debug) {
  $htmlBody .= "<hr><h3>DEBUG vars</h3><pre style='white-space:pre-wrap;font-size:12px;'>"
    . htmlspecialchars(print_r($vars, true), ENT_QUOTES)
    . "</pre>";
}

$docTitle = (string)($tpl['title'] ?? 'Документ');
$docTitleSafe = htmlspecialchars($docTitle, ENT_QUOTES);

$pdfUrl = "/manager/contract_pdf.php?app_id=" . (int)$appId . "&tpl_id=" . (int)$tplId;

$html = "<!doctype html>
<html lang='ru'>
<head>
  <meta charset='utf-8'>
  <meta name='viewport' content='width=device-width, initial-scale=1'>
  <title>{$docTitleSafe}</title>
  <style>
    body{font-family:'Times New Roman',Times,serif;font-size:14px;color:#0f172a;line-height:1.35;padding:24px;background:#fff}
    .toolbar{position:sticky;top:0;background:#fff;padding:10px 0 14px;border-bottom:1px solid #e2e8f0;margin-bottom:18px;z-index:10}
    .btn{display:inline-block;padding:7px 10px;border:1px solid #cbd5e1;border-radius:10px;text-decoration:none;color:#0f172a;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:13px;font-weight:600;background:#fff}
    .btn + .btn{margin-left:8px}
    table{border-collapse:collapse;width:100%;margin:10px 0;table-layout:fixed}
    th,td{border:1px solid #111;padding:6px 8px;vertical-align:top;word-break:break-word}
    th{background:#f3f4f6;font-weight:700}
    @media print {.toolbar{display:none} body{padding:0}}
  </style>
</head>
<body>
  <div class='toolbar'>
    <a class='btn' href='/manager/documents.php?app_id=" . (int)$appId . "'>← Назад</a>
    <a class='btn' href='" . htmlspecialchars($pdfUrl, ENT_QUOTES) . "'>Скачать PDF</a>
    <a class='btn' href='javascript:window.print()'>Печать</a>
  </div>
  {$htmlBody}
</body>
</html>";

header('Content-Type: text/html; charset=utf-8');
echo $html;
exit;