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
if ($appId <= 0) {
  http_response_code(404);
  echo "Не у��азан app_id";
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
function pick_first_existing_template_html(PDO $pdo): string
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
  } catch (Throwable $e) {}
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
  } catch (Throwable $e) {}
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
  } catch (Throwable $e) {}
  return '';
}

$stApp = $pdo->prepare("SELECT * FROM applications WHERE id=? LIMIT 1");
$stApp->execute([$appId]);
$app = $stApp->fetch(PDO::FETCH_ASSOC);
if (!$app) {
  http_response_code(404);
  echo "Заявка не найдена";
  exit;
}

$tplHtml = pick_first_existing_template_html($pdo);
if (trim($tplHtml) === '') {
  http_response_code(500);
  echo "Шаблон договора не найден.";
  exit;
}

$appCurrency = (string)($app['currency'] ?? 'KZT');
if (!in_array($appCurrency, ['KZT','USD','EUR'], true)) $appCurrency = 'KZT';
$touristPriceCur = (float)($app['tourist_price_amount'] ?? 0.0);

// курс на сегодня
$fxInfo = fx_operator_today_for_app($pdo, $app);
$fxToday = (float)($fxInfo['rate'] ?? 0);
if ($appCurrency === 'KZT') $fxToday = 1.0;
if ($fxToday <= 0) $fxToday = (float)($app['fx_rate_to_kzt'] ?? 1.0);

$fxTodaySourceText = ($fxInfo['source'] ?? '') === 'operator_today'
  ? 'по курсу туроператора на сегодня'
  : (($fxInfo['source'] ?? '') === 'kzt' ? 'тенге' : 'по курсу в заявке');

$todayText = date('d.m.Y');

// дедлайны
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

    $txt = h($due)
      . ' — ' . h(number_format($percent, 0, '.', '')) . '%'
      . ' — ' . h(money_fmt($amtCur)) . ' ' . h($appCurrency)
      . ' — ' . h(money_fmt($amtKzt)) . ' KZT'
      . ' <span style="color:#64748b; font-weight:600;">'
      . '(курс на ' . h($todayText) . ': ' . h(money_fmt($fxToday)) . ', ' . h($fxTodaySourceText) . ')'
      . '</span>';

    if ($note !== '') {
      $txt .= ' <span style="color:#64748b; font-weight:600;">· ' . h($note) . '</span>';
    }

    $items[] = '<li>' . $txt . '</li>';
  }
  $deadlinesListHtml = implode("\n", $items);
} else {
  $deadlinesListHtml = '<li style="color:#64748b;">Дедлайны не заданы.</li>';
}

$vars = doc_variables_for_app($pdo, $appId);
$vars['deadlines_list_html'] = $deadlinesListHtml;

$rendered = render_doc_template($tplHtml, $vars);

$autoprint = (isset($_GET['autoprint']) && $_GET['autoprint'] === '1');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: text/html; charset=utf-8');

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Печать договора</title>
  <style>
    @page { size: A4 portrait; margin: 8mm 8mm; }
    html, body{ padding:0; margin:0; background:#fff; color:#0f172a; font-family: Arial, sans-serif; font-size: 10.5px; line-height: 1.22; font-weight:400; }
    b, strong{ font-weight: 600; }
    h1{ font-size:13px; margin:0 0 6px 0; font-weight:600; }
    h2{ font-size:12px; margin:10px 0 6px 0; font-weight:600; }
    h3{ font-size:11px; margin:10px 0 6px 0; font-weight:600; }
    p{ margin:0 0 6px 0; }
    table{ width:100%; border-collapse:collapse; margin: 6px 0 8px 0; }
    th, td{ border:1px solid #cbd5e1; padding:3px 4px; vertical-align:top; }
    th{ background:#f1f5f9; font-weight:600; }
    .no-border, .no-border th, .no-border td{ border:none !important; }
    [align="right"], .right, .text-right{ font-size:10.5px; font-weight:400; }

    @media screen{
      body{ padding: 12px; }
      .paper{ max-width: 860px; margin: 0 auto; border:1px solid #e2e8f0; border-radius: 14px; padding: 12px; box-shadow: 0 18px 40px rgba(2,8,23,.08); }
    }
  </style>

  <?php if ($autoprint): ?>
    <script>
      window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 80);
      });
    </script>
  <?php endif; ?>
</head>
<body>
  <div class="paper">
    <?= $rendered ?>
  </div>
</body>
</html>