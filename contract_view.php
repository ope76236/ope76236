<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/documents.php';
require_once __DIR__ . '/../app/fx.php';

require_role('tourist');

$title = 'Договор';
$pdo = db();

$u = current_user();
$uid = (int)($u['id'] ?? 0);

$appId = (int)($_GET['app_id'] ?? 0);
if ($appId <= 0) {
  http_response_code(404);
  echo "Не указан app_id";
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

function money_fmt(float $n): string { return number_format($n, 2, '.', ' '); }
function fmt_dmy(?string $ymd): string
{
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '—';
  $ts = strtotime($ymd);
  if ($ts === false) return $ymd;
  return date('d.m.Y', $ts);
}

// доступ
$stAccess = $pdo->prepare("
  SELECT a.*
  FROM applications a
  JOIN application_tourists at ON at.application_id=a.id AND at.tourist_user_id=?
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

$tplHtml = pick_first_existing_template_html($pdo);
if (trim($tplHtml) === '') {
  http_response_code(500);
  echo "Шаблон договора не найден в document_templates.";
  exit;
}

$appCurrency = (string)($app['currency'] ?? 'KZT');
if (!in_array($appCurrency, ['KZT','USD','EUR'], true)) $appCurrency = 'KZT';

$touristPriceCur = (float)($app['tourist_price_amount'] ?? 0.0);

/**
 * Курс НА СЕГОДНЯ (на день печати):
 * используем вашу функцию fx_operator_today_for_app (как в tourist/tour_view.php),
 * чтобы курс был “по курсу ТО на сегодня”.
 */
$fxInfo = fx_operator_today_for_app($pdo, $app);
$fxToday = (float)($fxInfo['rate'] ?? 0);
if ($appCurrency === 'KZT') $fxToday = 1.0;
if ($fxToday <= 0) $fxToday = (float)($app['fx_rate_to_kzt'] ?? 1.0);

$fxTodaySourceText = ($fxInfo['source'] ?? '') === 'operator_today'
  ? 'по курсу туроператора на сегодня'
  : (($fxInfo['source'] ?? '') === 'kzt' ? 'тенге' : 'по курсу в заявке');

$todayYmd = date('Y-m-d');
$todayText = date('d.m.Y');

// дедлайны: due_date, percent, note
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
} catch (\Throwable $e) {
  $deadlines = [];
}

/**
 * Формируем {deadlines_list_html}:
 * Дата — percent% — сумма в валюте тура — сумма в KZT по курсу НА СЕГОДНЯ (на день печати)
 */
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
      . ' <span style="color:var(--muted); font-weight:600;">'
      . '(курс на ' . h($todayText) . ': ' . h(money_fmt($fxToday)) . ', ' . h($fxTodaySourceText) . ')'
      . '</span>';

    if ($note !== '') {
      $txt .= ' <span style="color:var(--muted); font-weight:600;">· ' . h($note) . '</span>';
    }

    $items[] = '<li>' . $txt . '</li>';
  }

  $deadlinesListHtml = implode("\n", $items);
} else {
  $deadlinesListHtml = '<li style="color:var(--muted);">Дедлайны не заданы.</li>';
}

$vars = doc_variables_for_app($pdo, $appId);

// Подставляем в ваш шаблон ровно в {deadlines_list_html}
$vars['deadlines_list_html'] = $deadlinesListHtml;

// Доп. переменные на будущее (если захотите вывести отдельной строкой в шаблоне)
$vars['fx_today'] = (string)$fxToday;
$vars['fx_today_date'] = $todayYmd;

$rendered = render_doc_template($tplHtml, $vars);

$appNo = (int)($vars['app_number'] ?? 0);
if ($appNo <= 0) $appNo = (int)$appId;

$pdfUrl = '/tourist/contract_pdf.php?app_id=' . (int)$appId;

require __DIR__ . '/_layout_top.php';
?>

<style>
  .actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:12px;
    align-items:center;
    justify-content:space-between;
  }
  .actions .left{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    align-items:center;
  }

  .doc-wrap{
    border:1px solid rgba(226,232,240,.90);
    border-radius:18px;
    background: rgba(255,255,255,.78);
    padding:14px;
  }

  /* единый спокойный стиль в просмотре */
  .doc-wrap{
    color:#0f172a;
    font-size: 12.5px;
    line-height: 1.28;
    font-weight: 400;
  }
  .doc-wrap, .doc-wrap *{ font-family: inherit !important; }
  .doc-wrap b, .doc-wrap strong{ font-weight: 650 !important; }
  .doc-wrap h1{ font-size:15px !important; margin:0 0 8px 0; font-weight:650 !important; }
  .doc-wrap h2{ font-size:13.5px !important; margin:10px 0 6px 0; font-weight:650 !important; }
  .doc-wrap h3{ font-size:12.5px !important; margin:10px 0 6px 0; font-weight:650 !important; }
  .doc-wrap p{ margin:0 0 8px 0; }

  .doc-wrap table{ width:100%; border-collapse:collapse; margin: 8px 0; }
  .doc-wrap th, .doc-wrap td{ border:1px solid rgba(203,213,225,.85); padding:4px 5px; vertical-align:top; }
  .doc-wrap th{ background: rgba(241,245,249,.85); font-weight: 600 !important; }

  /* ---------------- PRINT (реально “только документ”) ---------------- */
  body.printing .actions,
  body.printing header,
  body.printing nav,
  body.printing aside,
  body.printing .sidebar,
  body.printing .menu,
  body.printing .topbar,
  body.printing .footer,
  body.printing .layout-left,
  body.printing .layout-right,
  body.printing .container,
  body.printing .page,
  body.printing .content
  {
    display: none !important;
  }

  body.printing #contractDoc{
    display: block !important;
  }

  @media print {
    @page { size: A4 portrait; margin: 8mm 8mm; }

    .actions{ display:none !important; }

    body *{ display: none !important; }
    #contractDoc, #contractDoc *{ display: revert !important; }

    html, body{
      background:#fff !important;
      height: auto !important;
    }

    #contractDoc{
      border: none !important;
      background: #fff !important;
      padding: 0 !important;
      margin: 0 !important;

      font-size: 10.5px !important;
      line-height: 1.22 !important;
      font-weight: 400 !important;
    }

    #contractDoc b, #contractDoc strong{ font-weight: 600 !important; }
    #contractDoc h1{ font-size: 13px !important; font-weight: 600 !important; margin:0 0 6px 0 !important; }
    #contractDoc h2{ font-size: 12px !important; font-weight: 600 !important; margin:10px 0 6px 0 !important; }
    #contractDoc h3{ font-size: 11px !important; font-weight: 600 !important; margin:10px 0 6px 0 !important; }
    #contractDoc p{ margin:0 0 6px 0 !important; }
    #contractDoc th, #contractDoc td{ padding: 3px 4px !important; }
    #contractDoc table{ width:100% !important; border-collapse: collapse !important; }
  }
</style>

<div class="actions">
  <div class="left">
    <a class="btn" href="/tourist/documents.php?app_id=<?= (int)$appId ?>">← К документам</a>
    <div class="badge">Договор · заявка №<?= (int)$appNo ?></div>
  </div>

  <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn primary" href="<?= h($pdfUrl) ?>">Скачать PDF</a>
    <button class="btn" type="button" onclick="printContractOnly();">Печать</button>
  </div>
</div>

<div class="doc-wrap" id="contractDoc">
  <?= $rendered ?>
</div>

<script>
  function printContractOnly() {
    document.body.classList.add('printing');
    setTimeout(function () {
      window.print();
      setTimeout(function () {
        document.body.classList.remove('printing');
      }, 400);
    }, 50);
  }
</script>

<?php require __DIR__ . '/_layout_bottom.php'; ?>