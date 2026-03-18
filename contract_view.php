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

$title = 'Договор';
$pdo = db();

$appId = (int)($_GET['app_id'] ?? 0);
if ($appId <= 0) {
  http_response_code(404);
  echo "Не указан app_id";
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

/**
 * ВАЖНО:
 * В вашей БД, судя по симптомам, в document_templates нет/не заполнены поля type/code.
 * Поэтому выбираем шаблон договора так:
 * 1) сначала ищем активный шаблон по title LIKE '%договор%' или '%contract%'
 * 2) если не нашли — берём самый последний активный шаблон
 *
 * Если хотите 100% стабильность — скажите tpl_id договора, и сделаем WHERE id=...
 */
function pick_contract_template(PDO $pdo): array
{
  try {
    $st = $pdo->prepare("
      SELECT *
      FROM document_templates
      WHERE is_active=1
        AND (LOWER(title) LIKE '%договор%' OR LOWER(title) LIKE '%contract%')
      ORDER BY id DESC
      LIMIT 1
    ");
    $st->execute();
    $tpl = $st->fetch(PDO::FETCH_ASSOC);
    if ($tpl) return $tpl;
  } catch (Throwable $e) {}

  try {
    $st = $pdo->query("
      SELECT *
      FROM document_templates
      WHERE is_active=1
      ORDER BY id DESC
      LIMIT 1
    ");
    $tpl = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if ($tpl) return $tpl;
  } catch (Throwable $e) {}

  return [];
}

// заявка
$stApp = $pdo->prepare("SELECT * FROM applications WHERE id=? LIMIT 1");
$stApp->execute([$appId]);
$app = $stApp->fetch(PDO::FETCH_ASSOC);
if (!$app) {
  http_response_code(404);
  echo "Заявка не найдена";
  exit;
}

// шаблон договора
$tpl = pick_contract_template($pdo);
if (!$tpl) {
  http_response_code(500);
  echo "Шаблон договора не найден (document_templates). Добавьте активный шаблон (is_active=1) с title содержащим 'договор'.";
  exit;
}

$tplId = (int)($tpl['id'] ?? 0);
if ($tplId <= 0) {
  http_response_code(500);
  echo "У шаблона договора нет корректного id.";
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
if ($fxToday <= 0) $fxToday = 1.0;

$fxTodaySourceText = (($fxInfo['source'] ?? '') === 'operator_today')
  ? 'по курсу туроператора на сегодня'
  : ((($fxInfo['source'] ?? '') === 'kzt') ? 'тенге' : 'по курсу в заявке');

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

$vars = doc_variables_for_app($pdo, $appId);
$vars['deadlines_list_html'] = $deadlinesListHtml;

$htmlBody = render_doc_template((string)($tpl['body_html'] ?? ''), $vars);

$appNo = (int)($vars['app_number'] ?? 0);
$appNo = $appNo > 0 ? $appNo : (int)$appId;

// ВАЖНО: менеджерский PDF endpoint (ваш файл называется contract_pdf.php)
$pdfUrl = '/manager/contract_pdf.php?app_id=' . (int)$appId . '&tpl_id=' . (int)$tplId;

require __DIR__ . '/_layout_top.php';
?>

<style>
  .actions{
    display:flex; gap:10px; flex-wrap:wrap;
    margin-bottom:12px; align-items:center; justify-content:space-between;
  }
  .actions .left{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; }

  .doc-wrap{
    border:1px solid rgba(226,232,240,.90);
    border-radius:18px;
    background: rgba(255,255,255,.78);
    padding:14px;
  }

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

  @media print{
    .actions{display:none !important;}
    body{padding:0 !important;}
    @page{ size:A4 portrait; margin:8mm 8mm; }
  }
</style>

<div class="actions">
  <div class="left">
    <a class="btn" href="/manager/app_view.php?id=<?= (int)$appId ?>">← Назад</a>
    <div class="badge">Договор · заявка №<?= (int)$appNo ?></div>
  </div>

  <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn primary" href="<?= h($pdfUrl) ?>">Скачать PDF</a>
    <button class="btn" type="button" onclick="window.print();">Печать</button>
  </div>
</div>

<div class="doc-wrap" id="contractDoc">
  <?= $htmlBody ?>
</div>

<?php if ($debug): ?>
  <hr>
  <h3>DEBUG tpl</h3>
  <pre style="white-space:pre-wrap;font-size:12px;"><?= h(print_r($tpl, true)) ?></pre>
<?php endif; ?>

<?php require __DIR__ . '/_layout_bottom.php'; ?>