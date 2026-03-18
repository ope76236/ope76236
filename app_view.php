<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/fx.php';

require_role('manager');

$title = 'Карточка заявки';
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(404);
  echo "Не указан ID";
  exit;
}

$error = null;

$statusLabel = [
  'in_work' => 'в работе',
  'confirmed' => 'подтверждено',
  'docs_issued' => 'документы выданы',
  'cancelled' => 'отменено',
];

$statusStyle = [
  'in_work' => 'border-color: rgba(14,165,233,.45); color: rgba(14,165,233,1);',
  'confirmed' => 'border-color: rgba(34,197,94,.35); color: rgba(34,197,94,1);',
  'docs_issued' => 'border-color: rgba(22,163,74,.45); color: rgba(22,163,74,1);',
  'cancelled' => 'border-color: rgba(239,68,68,.45); color: rgba(239,68,68,1);',
];

function money_in(string $s): float {
  $s = trim($s);
  if ($s === '') return 0.0;
  $s = str_replace([' ', ','], ['', '.'], $s);
  return (float)$s;
}

function fio_row(array $r): string {
  $fio = trim(($r['last_name'] ?? '') . ' ' . ($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? ''));
  if ($fio === '') $fio = trim((string)($r['name'] ?? ''));
  return $fio !== '' ? $fio : '—';
}

/** Фамилия И.О. */
function fio_short(?string $fio): string
{
  $fio = trim((string)$fio);
  if ($fio === '') return '—';
  $fio = preg_replace('/\s+/u', ' ', $fio) ?? $fio;
  $parts = preg_split('/\s+/u', $fio) ?: [];

  $last = trim((string)($parts[0] ?? ''));
  $first = trim((string)($parts[1] ?? ''));
  $mid = trim((string)($parts[2] ?? ''));

  $ini1 = ($first !== '') ? mb_substr($first, 0, 1, 'UTF-8') . '.' : '';
  $ini2 = ($mid !== '') ? mb_substr($mid, 0, 1, 'UTF-8') . '.' : '';

  $out = trim($last . ' ' . $ini1 . $ini2);
  return $out !== '' ? $out : $fio;
}

function kzt_to_app_cur_at_pay(float $kzt, string $appCurrency, float $fxAtPay): float
{
  if ($appCurrency === 'KZT') return $kzt;
  if ($fxAtPay <= 0) return 0.0;
  return round($kzt / $fxAtPay, 2);
}

function app_cur_to_kzt_today(float $amount, string $appCurrency, float $fxRateToday): float
{
  if ($appCurrency === 'KZT') return $amount;
  return round($amount * $fxRateToday, 2);
}

function years_old(?string $birthDate): string
{
  $birthDate = trim((string)$birthDate);
  if ($birthDate === '') return '—';
  $ts = strtotime($birthDate);
  if ($ts === false) return '—';
  $d = new DateTime(date('Y-m-d', $ts));
  $now = new DateTime('today');
  $age = $d->diff($now)->y;
  return (string)$age;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = post('_action');

    if ($action === 'set_status') {
      $status = post('status', 'in_work');

      $allowed = ['in_work','confirmed','docs_issued','cancelled'];
      if (!in_array($status, $allowed, true)) {
        throw new RuntimeException('Некорректный статус.');
      }

      $pdo->prepare("UPDATE applications SET status=? WHERE id=? LIMIT 1")->execute([$status, $id]);

      $stChk = $pdo->prepare("SELECT status FROM applications WHERE id=? LIMIT 1");
      $stChk->execute([$id]);
      $savedStatus = (string)($stChk->fetchColumn() ?: '');
      if ($savedStatus !== $status) {
        throw new RuntimeException(
          "Статус не сохранился в базе. В БД сейчас: '{$savedStatus}', пытались поставить: '{$status}'. " .
          "Проверьте тип поля applications.status (ENUM/ограничения)."
        );
      }

      redirect('/manager/app_view.php?id=' . $id);
    }

    if ($action === 'save_and_close') {
      $appNumberRaw = trim((string)($_POST['app_number'] ?? ''));
      $appNumber = $appNumberRaw !== '' ? (int)$appNumberRaw : null;
      if ($appNumber !== null && $appNumber <= 0) $appNumber = null;

      $customerTouristUserId = (int)($_POST['customer_tourist_user_id'] ?? 0);
      if ($customerTouristUserId <= 0) $customerTouristUserId = null;

      $country = post('country');
      $start = post('start_date');
      $end = post('end_date');
      $operatorId = (int)($_POST['operator_id'] ?? 0);

      $hotelName = post('hotel_name');
      $roomCategory = post('room_category');
      $mealPlan = post('meal_plan');

      $flightsOutbound = post('flights_outbound');
      $flightsReturn = post('flights_return');
      $transfersInfo = post('transfers_info');
      $insuranceInfo = post('insurance_info');
      $visaSupportInfo = post('visa_support_info');
      $excursionsInfo = post('excursions_info');

      $currency = post('currency', 'KZT');
      if (!in_array($currency, ['KZT','USD','EUR'], true)) $currency = 'KZT';

      $fxRate = (float)money_in(post('fx_rate_to_kzt', '1'));
      if ($currency === 'KZT') $fxRate = 1.0;
      if ($fxRate <= 0) throw new RuntimeException('Курс к тенге должен быть больше 0.');

      $touristPrice = (float)money_in(post('tourist_price_amount', '0'));
      $operatorPrice = (float)money_in(post('operator_price_amount', '0'));

      $note = post('note');

      if ($country === '') throw new RuntimeException('Страна обязательна.');
      if ($start === '' || $end === '') throw new RuntimeException('Даты обязательны.');
      if ($operatorId <= 0) throw new RuntimeException('Выберите туроператора.');

      $numText = $appNumber ?: $id;
      $titleIn = 'Заявка №' . $numText . ' — ' . $country;

      $st = $pdo->prepare("
        UPDATE applications
        SET app_number=?,
            customer_tourist_user_id=?,
            title=?,
            country=?,
            destination=?,
            start_date=?,
            end_date=?,
            operator_id=?,
            currency=?,
            fx_rate_to_kzt=?,
            tourist_price_amount=?,
            operator_price_amount=?,
            hotel_name=?,
            room_category=?,
            meal_plan=?,
            flights_outbound=?,
            flights_return=?,
            transfers_info=?,
            insurance_info=?,
            visa_support_info=?,
            excursions_info=?,
            note=?
        WHERE id=?
        LIMIT 1
      ");
      $st->execute([
        $appNumber,
        $customerTouristUserId,
        $titleIn,
        $country,
        $country,
        $start,
        $end,
        $operatorId,
        $currency,
        $fxRate,
        $touristPrice,
        $operatorPrice,
        $hotelName,
        $roomCategory,
        $mealPlan,
        ($flightsOutbound !== '' ? $flightsOutbound : null),
        ($flightsReturn !== '' ? $flightsReturn : null),
        ($transfersInfo !== '' ? $transfersInfo : null),
        ($insuranceInfo !== '' ? $insuranceInfo : null),
        ($visaSupportInfo !== '' ? $visaSupportInfo : null),
        ($excursionsInfo !== '' ? $excursionsInfo : null),
        $note,
        $id
      ]);

      redirect('/manager/apps.php');
    }

    if ($action === 'add_tourist') {
      $touristUserId = (int)($_POST['tourist_user_id'] ?? 0);
      if ($touristUserId <= 0) {
        $pick = trim((string)($_POST['tourist_pick_label'] ?? ''));
        if (preg_match('~\(#(\d+)\)\s*$~u', $pick, $m)) $touristUserId = (int)$m[1];
      }
      if ($touristUserId <= 0) throw new RuntimeException('Выберите туриста из списка (выпадающего).');

      $stT = $pdo->prepare("SELECT id FROM users WHERE id=? AND role='tourist' LIMIT 1");
      $stT->execute([$touristUserId]);
      if (!$stT->fetch()) throw new RuntimeException('Выбранный пользователь не является туристом.');

      $pdo->prepare("INSERT IGNORE INTO application_tourists(application_id, tourist_user_id) VALUES(?,?)")
          ->execute([$id, $touristUserId]);

      $pdo->prepare("
        UPDATE applications
        SET main_tourist_user_id = IFNULL(main_tourist_user_id, ?)
        WHERE id=?
        LIMIT 1
      ")->execute([$touristUserId, $id]);

      redirect('/manager/app_view.php?id=' . $id . '#tourists');
    }

    if ($action === 'remove_tourist') {
      $touristUserId = (int)($_POST['tourist_user_id'] ?? 0);
      if ($touristUserId <= 0) throw new RuntimeException('Некорректный турист.');

      $pdo->prepare("DELETE FROM application_tourists WHERE application_id=? AND tourist_user_id=? LIMIT 1")
          ->execute([$id, $touristUserId]);

      $pdo->prepare("
        UPDATE applications
        SET main_tourist_user_id = CASE
          WHEN main_tourist_user_id = ? THEN NULL
          ELSE main_tourist_user_id
        END
        WHERE id=?
        LIMIT 1
      ")->execute([$touristUserId, $id]);

      redirect('/manager/app_view.php?id=' . $id . '#tourists');
    }

  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$ops = $pdo->query("SELECT id, name FROM tour_operators ORDER BY name ASC")->fetchAll();

$st = $pdo->prepare("
  SELECT a.*,
         o.name AS operator_name
  FROM applications a
  LEFT JOIN tour_operators o ON o.id = a.operator_id
  WHERE a.id=?
  LIMIT 1
");
$st->execute([$id]);
$app = $st->fetch();
if (!$app) {
  http_response_code(404);
  echo "Заявка не найдена";
  exit;
}

$appCurrency = (string)($app['currency'] ?? 'KZT');
if (!in_array($appCurrency, ['KZT','USD','EUR'], true)) $appCurrency = 'KZT';

// курс в заявке (для платежей fallback)
$fxRateApp = (float)($app['fx_rate_to_kzt'] ?? 1);
if ($appCurrency === 'KZT') $fxRateApp = 1.0;

$fxInfo = fx_operator_today_for_app($pdo, $app);
$fxRateToday = (float)$fxInfo['rate'];
$fxSourceText = ($fxInfo['source'] === 'operator_today')
  ? 'по курсу туоператора на сегодня'
  : (($fxInfo['source'] === 'kzt') ? 'тенге' : 'по курсу в заявке');

$touristPriceCur = (float)($app['tourist_price_amount'] ?? 0);
$operatorPriceCur = (float)($app['operator_price_amount'] ?? 0);

$planProfitCur = round($touristPriceCur - $operatorPriceCur, 2);
$planProfitKztToday = app_cur_to_kzt_today($planProfitCur, $appCurrency, $fxRateToday);

$stPay = $pdo->prepare("
  SELECT direction, amount, fx_rate_to_kzt, status
  FROM payments
  WHERE application_id=?
");
$stPay->execute([$id]);
$payments = $stPay->fetchAll();

$paidTouristKzt = 0.0;
$paidOperatorKzt = 0.0;
$paidTouristCurAtPay = 0.0;
$paidOperatorCurAtPay = 0.0;

foreach ($payments as $p) {
  if ((string)($p['status'] ?? '') !== 'paid') continue;

  $dir = (string)($p['direction'] ?? '');
  if ($dir === '') $dir = 'tourist_to_agent';

  $amtKzt = (float)$p['amount'];
  $fxPay = (float)($p['fx_rate_to_kzt'] ?? $fxRateApp);

  if ($dir === 'tourist_to_agent') {
    $paidTouristKzt += $amtKzt;
    $paidTouristCurAtPay += kzt_to_app_cur_at_pay($amtKzt, $appCurrency, $fxPay);
  }
  if ($dir === 'agent_to_operator') {
    $paidOperatorKzt += $amtKzt;
    $paidOperatorCurAtPay += kzt_to_app_cur_at_pay($amtKzt, $appCurrency, $fxPay);
  }
}

$paidTouristCurAtPay = round($paidTouristCurAtPay, 2);
$paidOperatorCurAtPay = round($paidOperatorCurAtPay, 2);

$debtTouristCur = round($touristPriceCur - $paidTouristCurAtPay, 2);
$debtOperatorCur = round($operatorPriceCur - $paidOperatorCurAtPay, 2);

$factProfitKzt = round($paidTouristKzt - $paidOperatorKzt, 2);

// KZT долг/переплата: abs(debtCur) * fxToday
$debtTouristAbsCur = abs($debtTouristCur);
$debtOperatorAbsCur = abs($debtOperatorCur);

$debtTouristAbsKztToday = app_cur_to_kzt_today($debtTouristAbsCur, $appCurrency, $fxRateToday);
$debtOperatorAbsKztToday = app_cur_to_kzt_today($debtOperatorAbsCur, $appCurrency, $fxRateToday);

$isTouristOverpay = ($debtTouristCur < -0.009);
$isOperatorOverpay = ($debtOperatorCur < -0.009);

$touristCurLabel = $isTouristOverpay ? 'Переплата' : 'Долг';
$operatorCurLabel = $isOperatorOverpay ? 'Переплата' : 'Долг';

$touristCurValueShow = $isTouristOverpay ? $debtTouristAbsCur : max(0.0, $debtTouristCur);
$operatorCurValueShow = $isOperatorOverpay ? $debtOperatorAbsCur : max(0.0, $debtOperatorCur);

$touristCurCls = $isTouristOverpay ? 'warn' : (($debtTouristCur > 0.009) ? 'bad' : 'good');
$operatorCurCls = $isOperatorOverpay ? 'warn' : (($debtOperatorCur > 0.009) ? 'bad' : 'good');

$touristKztLabel = $isTouristOverpay ? 'Переплата' : 'Долг';
$operatorKztLabel = $isOperatorOverpay ? 'Переплата' : 'Долг';

$touristKztCls = $isTouristOverpay ? 'warn' : 's';
$operatorKztCls = $isOperatorOverpay ? 'warn' : 's';

$tourists = $pdo->query("
  SELECT u.id, u.email, u.name,
         t.last_name, t.first_name, t.middle_name,
         t.birth_date, t.passport_no
  FROM users u
  LEFT JOIN tourists t ON t.user_id = u.id
  WHERE u.role='tourist' AND u.active=1
  ORDER BY t.last_name ASC, t.first_name ASC, u.id DESC
  LIMIT 5000
")->fetchAll();

$customerLabel = '';
if (!empty($app['customer_tourist_user_id'])) {
  foreach ($tourists as $t) {
    if ((int)$t['id'] === (int)$app['customer_tourist_user_id']) {
      $customerLabel = fio_row($t) . ' · ' . (string)($t['birth_date'] ?? '') . ' · ' . (string)($t['passport_no'] ?? '') . ' (#' . (int)$t['id'] . ')';
      break;
    }
  }
}

$stMembers = $pdo->prepare("
  SELECT u.id, u.email, u.phone, u.active, u.name,
         t.last_name, t.first_name, t.middle_name,
         t.birth_date,
         t.passport_no, t.passport_issue_date, t.passport_expiry_date
  FROM application_tourists at
  JOIN users u ON u.id = at.tourist_user_id
  LEFT JOIN tourists t ON t.user_id = u.id
  WHERE at.application_id=?
  ORDER BY t.last_name ASC, t.first_name ASC, u.id DESC
");
$stMembers->execute([$id]);
$members = $stMembers->fetchAll();

$stStatus = (string)($app['status'] ?? 'in_work');
if (!in_array($stStatus, ['in_work','confirmed','docs_issued','cancelled'], true)) $stStatus = 'in_work';

$appNoText = (int)($app['app_number'] ?? 0);
$appNoText = $appNoText > 0 ? $appNoText : (int)$app['id'];

require __DIR__ . '/_layout_top.php';
?>

<style>
  :root{
    --w-strong: 750;
    --w-normal: 600;
  }

  .block-title{
    margin-top:18px;
    font-weight: var(--w-strong);
    color:#0f172a;
    font-size:15px;
    display:flex;
    align-items:center;
    gap:10px;
  }
  .block-title::before{
    content:"";
    width:9px; height:9px;
    border-radius:999px;
    background: rgba(14,165,233,.92);
    box-shadow: 0 8px 18px rgba(14,165,233,.18);
    display:inline-block;
  }
  .sub-title{
    margin-top:12px;
    font-weight: var(--w-strong);
    color:#0f172a;
    font-size:14px;
  }
  .hint{color:var(--muted);font-size:12px;margin-top:6px;font-weight: var(--w-normal);line-height:1.35}

  .status-wrap{display:flex;gap:10px;align-items:center;flex-wrap:wrap}

  .kpi{ display:grid; gap:12px; grid-template-columns: 1fr; margin-top:10px; }
  @media (min-width: 980px){ .kpi{ grid-template-columns: repeat(3, minmax(0, 1fr)); } }

  .kpi-card{
    border:1px solid rgba(226,232,240,.92);
    border-radius:16px;
    background: rgba(255,255,255,.72);
    padding:12px;
    min-width:0;
  }
  .kpi-card .t{ color:var(--muted); font-size:12px; font-weight: var(--w-normal); }
  .kpi-card .v{ font-size:18px; font-weight: var(--w-strong); color:#0f172a; margin-top:6px; }
  .kpi-card .s{ color:var(--muted); font-size:12px; font-weight: var(--w-normal); margin-top:6px; }
  .kpi-card .line{ margin-top:8px; }
  .kpi-card .val{ font-weight: var(--w-strong); }
  .kpi-card .good{ color:#16a34a; }
  .kpi-card .bad{ color:#ef4444; }
  .kpi-card .warn{ color:#f59e0b; } /* оранжевый для переплаты */

  .tabs{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:12px;
  }
  .tabbtn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:10px 12px;
    border-radius: 12px;
    border:1px solid rgba(226,232,240,.85);
    background: rgba(255,255,255,.78);
    text-decoration:none;
    color:inherit;
    font-size:13px;
    font-weight: var(--w-normal);
    transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
    white-space:nowrap;
  }
  .tabbtn:hover{
    transform: translateY(-1px);
    box-shadow: 0 12px 26px rgba(2,8,23,.06);
    border-color: rgba(14,165,233,.25);
  }
  .tabbtn.primary{
    border-color: rgba(14,165,233,.40);
    background: rgba(14,165,233,.08);
  }

  /* NEW: кнопка договора */
  .tabbtn.contract{
    border-color: rgba(99,102,241,.35);
    background: rgba(99,102,241,.08);
  }

  .wrap{ max-width: 1100px; }

  .grid-2{ display:grid; grid-template-columns: 1fr; gap:12px; }
  @media (min-width: 980px){ .grid-2{ grid-template-columns: 1fr 1fr; } }

  .grid-3{ display:grid; grid-template-columns: 1fr; gap:12px; }
  @media (min-width: 980px){ .grid-3{ grid-template-columns: 1fr 1fr 1fr; } }

  .grid-4{ display:grid; grid-template-columns: 1fr; gap:12px; }
  @media (min-width: 980px){ .grid-4{ grid-template-columns: 1fr 220px 1fr 1fr; } }

  .members-wrap{ margin-top:12px; overflow:auto; border-radius: 16px; }
  .members-table{ min-width: 860px; }

  tr.clickable{cursor:pointer}
  tr.clickable:hover{background:rgba(2,132,199,.06)}

  .btn.btn-sm{
    padding:8px 10px;
    border-radius:12px;
    font-size:12px;
    font-weight: var(--w-normal);
  }
  .btn.btn-primary{
    border-color: rgba(14,165,233,.40);
    background: rgba(14,165,233,.08);
  }
  .btn.btn-danger{
    border-color: rgba(239,68,68,.40);
    background: rgba(239,68,68,.06);
    color: rgba(239,68,68,1);
  }

  .fxhint{ margin-top:6px; color:var(--muted); font-size:12px; font-weight: var(--w-normal); }
</style>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div>
    <div class="status-wrap">
      <h1 class="h1" style="margin-bottom:0;">Заявка №<?= (int)$appNoText ?></h1>

      <form method="post" style="margin:0;">
        <input type="hidden" name="_action" value="set_status">
        <select
          name="status"
          onchange="this.form.submit()"
          class="pill"
          style="<?= $statusStyle[$stStatus] ?? '' ?>; font-weight:<?= (int)750 ?>; padding:8px 12px; border-radius:999px; background:#fff; cursor:pointer;"
        >
          <?php foreach ($statusLabel as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= ($stStatus === $k ? 'selected' : '') ?>><?= h($v) ?></option>
          <?php endforeach; ?>
        </select>
      </form>

      <button class="btn success" type="button" onclick="document.getElementById('saveCloseBtn').click();">
        Сохранить и закрыть
      </button>
    </div>

    <div class="badge" style="margin-top:8px;">
      <?= h((string)$app['start_date']) ?> — <?= h((string)$app['end_date']) ?> ·
      <?= h((string)($app['operator_name'] ?? '—')) ?> ·
      <?= h((string)($app['country'] ?? $app['destination'] ?? '—')) ?>
    </div>

    <div class="tabs">
      <a class="tabbtn" href="/manager/apps.php">← К списку</a>
      <a class="tabbtn primary" href="/manager/payments.php?app_id=<?= (int)$app['id'] ?>">Оплаты</a>
      <a class="tabbtn" href="/manager/documents.php?app_id=<?= (int)$app['id'] ?>">Документы</a>
      <a class="tabbtn contract" href="/manager/contract_view.php?app_id=<?= (int)$app['id'] ?>">Договор</a>
      <a class="tabbtn" href="/manager/files.php?app_id=<?= (int)$app['id'] ?>">Файлы</a>
    </div>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="block-title">Информация о заявке</div>

<div class="kpi">
  <div class="kpi-card">
    <div class="t">Расчёты с туристом</div>

    <div class="line s">Цена</div>
    <div class="v"><?= number_format($touristPriceCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></div>

    <div class="line s">Оплачено</div>
    <div class="val"><?= number_format($paidTouristCurAtPay, 2, '.', ' ') ?> <?= h($appCurrency) ?></div>

    <div class="line s"><?= h($touristCurLabel) ?></div>
    <div class="val <?= h($touristCurCls) ?>">
      <?= number_format($touristCurValueShow, 2, '.', ' ') ?> <?= h($appCurrency) ?>
    </div>
    <div class="<?= h($touristKztCls) ?>">
      <?= h($touristKztLabel) ?>: <?= number_format($debtTouristAbsKztToday, 2, '.', ' ') ?> KZT (<?= h($fxSourceText) ?>)
    </div>
    <div class="fxhint">Курс сегодня: <?= number_format($fxRateToday, 2, '.', ' ') ?> (<?= h($appCurrency) ?>→KZT)</div>
  </div>

  <div class="kpi-card">
    <div class="t">Расчёты с туроператором</div>

    <div class="line s">Цена</div>
    <div class="v"><?= number_format($operatorPriceCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></div>

    <div class="line s">Оплачено</div>
    <div class="val"><?= number_format($paidOperatorCurAtPay, 2, '.', ' ') ?> <?= h($appCurrency) ?></div>

    <div class="line s"><?= h($operatorCurLabel) ?></div>
    <div class="val <?= h($operatorCurCls) ?>">
      <?= number_format($operatorCurValueShow, 2, '.', ' ') ?> <?= h($appCurrency) ?>
    </div>
    <div class="<?= h($operatorKztCls) ?>">
      <?= h($operatorKztLabel) ?>: <?= number_format($debtOperatorAbsKztToday, 2, '.', ' ') ?> KZT (<?= h($fxSourceText) ?>)
    </div>
    <div class="fxhint">Курс сегодня: <?= number_format($fxRateToday, 2, '.', ' ') ?> (<?= h($appCurrency) ?>→KZT)</div>
  </div>

  <div class="kpi-card">
    <div class="t">Прибыль турагента</div>

    <div class="line s">Плановая</div>
    <div class="val"><?= number_format($planProfitCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></div>
    <div class="s"><?= number_format($planProfitKztToday, 2, '.', ' ') ?> KZT (<?= h($fxSourceText) ?>)</div>

    <div class="line s">Фактическая</div>
    <div class="v"><?= number_format($factProfitKzt, 2, '.', ' ') ?> KZT</div>
  </div>
</div>

<div class="wrap">
  <form id="appForm" class="form" method="post" style="margin-top:18px;" onsubmit="syncCustomerPick(); syncTouristPick();">
    <input type="hidden" name="_action" value="save_and_close">

    <div class="block-title">Данные заявки</div>

    <div class="grid-2">
      <div class="input">
        <label>Номер заявки</label>
        <input name="app_number" type="number" min="1" value="<?= h((string)($app['app_number'] ?? '')) ?>">
      </div>

      <div class="input">
        <label>Туроператор</label>
        <select name="operator_id" required>
          <option value="">— выберите —</option>
          <?php foreach ($ops as $o): ?>
            <option value="<?= (int)$o['id'] ?>" <?= ((int)$app['operator_id'] === (int)$o['id']) ? 'selected' : '' ?>>
              <?= h((string)$o['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid-3">
      <div class="input">
        <label>Страна</label>
        <input name="country" type="text" required value="<?= h((string)($app['country'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Дата начала</label>
        <input name="start_date" type="date" required value="<?= h((string)$app['start_date']) ?>">
      </div>
      <div class="input">
        <label>Дата окончания</label>
        <input name="end_date" type="date" required value="<?= h((string)$app['end_date']) ?>">
      </div>
    </div>

    <div class="block-title">Заказчик тура</div>

    <div class="input">
      <label>Поиск и выбор заказчика (ФИО · дата рождения · паспорт)</label>
      <input id="customerPick" type="text" list="tourists_list" placeholder="Начните вводить ФИО..." value="<?= h($customerLabel) ?>">
      <div class="hint">Выберите вариант из выпадающего списка.</div>
    </div>
    <input type="hidden" id="customerUserId" name="customer_tourist_user_id" value="<?= h((string)($app['customer_tourist_user_id'] ?? '')) ?>">

    <div class="block-title" id="tourists">Туристы в заявке</div>

    <div class="grid-2">
      <div class="input">
        <label>Поиск туриста для добавления (ФИО · дата рождения · паспорт)</label>
        <input id="touristPick" name="tourist_pick_label" type="text" list="tourists_list" placeholder="Начните вводить ФИО...">
        <div class="hint">Выберите вариант из выпадающего списка.</div>
      </div>

      <div style="display:flex; align-items:flex-end;">
        <button class="btn btn-primary" type="submit" name="_action" value="add_tourist" onclick="return ensureTouristSelected();" style="width:100%;">
          Добавить туриста
        </button>
      </div>
    </div>
    <input type="hidden" id="touristUserId" name="tourist_user_id" value="">

    <datalist id="tourists_list">
      <?php foreach ($tourists as $t): ?>
        <?php
          $fio = fio_row($t);
          $bd = (string)($t['birth_date'] ?? '');
          $pp = (string)($t['passport_no'] ?? '');
          $label = $fio . ' · ' . $bd . ' · ' . $pp . ' (#' . (int)$t['id'] . ')';
        ?>
        <option value="<?= h($label) ?>" data-id="<?= (int)$t['id'] ?>"></option>
      <?php endforeach; ?>
    </datalist>

    <div class="members-wrap">
      <table class="table members-table">
        <thead>
          <tr>
            <th>ФИО</th>
            <th style="width:160px;">Дата рождения (лет)</th>
            <th style="width:160px;">Паспорт №</th>
            <th style="width:220px;">Срок действия</th>
            <th style="width:120px;">—</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$members): ?>
            <tr><td colspan="5" style="color:var(--muted);">Туристы ещё не добавлены.</td></tr>
          <?php else: ?>
            <?php foreach ($members as $m): ?>
              <?php $fio = fio_row($m); ?>
              <tr class="clickable" onclick="window.location.href='/manager/tourist_view.php?id=<?= (int)$m['id'] ?>';">
                <td><?= h($fio) ?></td>
                <td class="muted">
                  <?= h((string)($m['birth_date'] ?? '')) ?>
                  <div class="muted"><?= h(years_old((string)($m['birth_date'] ?? ''))) ?> лет</div>
                </td>
                <td><?= h((string)($m['passport_no'] ?? '')) ?></td>
                <td class="muted">
                  <?= h((string)($m['passport_issue_date'] ?? '')) ?> — <?= h((string)($m['passport_expiry_date'] ?? '')) ?>
                </td>
                <td onclick="event.stopPropagation();">
                  <button class="btn btn-sm btn-danger" type="submit" name="_action" value="remove_tourist"
                          onclick="document.getElementById('removeTouristId').value='<?= (int)$m['id'] ?>'; return confirm('Удалить туриста из заявки?');">
                    Удалить
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <input type="hidden" id="removeTouristId" name="tourist_user_id" value="">

    <div class="block-title">Туристский продукт</div>

    <div class="grid-3">
      <div class="input">
        <label>Отель</label>
        <input name="hotel_name" type="text" value="<?= h((string)($app['hotel_name'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Категория номера</label>
        <input name="room_category" type="text" value="<?= h((string)($app['room_category'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Питание</label>
        <input name="meal_plan" type="text" placeholder="BB/HB/AI..." value="<?= h((string)($app['meal_plan'] ?? '')) ?>">
      </div>
    </div>

    <div class="sub-title">Перелёты</div>
    <div class="grid-2">
      <div class="input">
        <label>Туда</label>
        <textarea name="flights_outbound" rows="3"><?= h((string)($app['flights_outbound'] ?? '')) ?></textarea>
      </div>
      <div class="input">
        <label>Обратно</label>
        <textarea name="flights_return" rows="3"><?= h((string)($app['flights_return'] ?? '')) ?></textarea>
      </div>
    </div>

    <div class="sub-title">Услуги</div>
    <div class="grid-2">
      <div class="input">
        <label>Трансферы</label>
        <textarea name="transfers_info" rows="3"><?= h((string)($app['transfers_info'] ?? '')) ?></textarea>
      </div>
      <div class="input">
        <label>Страхование</label>
        <textarea name="insurance_info" rows="3"><?= h((string)($app['insurance_info'] ?? '')) ?></textarea>
      </div>
    </div>

    <div class="grid-2">
      <div class="input">
        <label>Визовая поддержка</label>
        <textarea name="visa_support_info" rows="3"><?= h((string)($app['visa_support_info'] ?? '')) ?></textarea>
      </div>
      <div class="input">
        <label>Экскурсии</label>
        <textarea name="excursions_info" rows="3"><?= h((string)($app['excursions_info'] ?? '')) ?></textarea>
      </div>
    </div>

    <div class="block-title">Финансы</div>

    <div class="grid-4">
      <div class="input">
        <label>Валюта тура</label>
        <select name="currency">
          <?php foreach (['KZT','USD','EUR'] as $c): ?>
            <option value="<?= h($c) ?>" <?= ($appCurrency === $c) ? 'selected' : '' ?>><?= h($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="input">
        <label>Курс к тенге (на сегодня)</label>
        <input name="fx_rate_to_kzt" type="text" value="<?= h((string)($app['fx_rate_to_kzt'] ?? '1')) ?>">
        <div class="fxhint">Для расчётов: <?= number_format($fxRateToday, 2, '.', ' ') ?> (<?= h($appCurrency) ?>→KZT, <?= h($fxSourceText) ?>)</div>
      </div>

      <div class="input">
        <label>Цена для туриста (<?= h($appCurrency) ?>)</label>
        <input name="tourist_price_amount" type="text" value="<?= h((string)($app['tourist_price_amount'] ?? '0')) ?>">
      </div>

      <div class="input">
        <label>Цена туроператора (<?= h($appCurrency) ?>)</label>
        <input name="operator_price_amount" type="text" value="<?= h((string)($app['operator_price_amount'] ?? '0')) ?>">
      </div>
    </div>

    <div class="input" style="margin-top:10px;">
      <label>Примечание</label>
      <input name="note" type="text" value="<?= h((string)($app['note'] ?? '')) ?>">
    </div>

    <button id="saveCloseBtn" type="submit" name="_action" value="save_and_close" style="display:none;">submit</button>
  </form>
</div>

<script>
  var touristMap = {};
  (function buildMap() {
    var dl = document.getElementById('tourists_list');
    if (!dl) return;
    var opts = dl.querySelectorAll('option');
    opts.forEach(function(o) {
      touristMap[o.value] = o.getAttribute('data-id');
    });
  })();

  function getIdFromLabel(v) {
    v = (v || '').toString().trim();
    var id = touristMap[v];
    if (id) return id;
    var m = v.match(/\(#(\d+)\)\s*$/);
    return m ? m[1] : '';
  }

  function syncCustomerPick() {
    var pick = document.getElementById('customerPick');
    var hid = document.getElementById('customerUserId');
    if (!pick || !hid) return;
    hid.value = getIdFromLabel(pick.value);
  }

  function syncTouristPick() {
    var pick = document.getElementById('touristPick');
    var hid = document.getElementById('touristUserId');
    if (!pick || !hid) return;
    hid.value = getIdFromLabel(pick.value);
  }

  document.getElementById('customerPick')?.addEventListener('input', syncCustomerPick);
  document.getElementById('customerPick')?.addEventListener('change', syncCustomerPick);

  document.getElementById('touristPick')?.addEventListener('input', syncTouristPick);
  document.getElementById('touristPick')?.addEventListener('change', syncTouristPick);

  function ensureTouristSelected() {
    syncTouristPick();
    var hid = document.getElementById('touristUserId');
    if (!hid || !hid.value) { alert('Выберите туриста из выпадающего списка.'); return false; }
    return true;
  }
</script>

<script>
(function () {
  function q(sel) { return document.querySelector(sel); }

  var operatorSel = q('select[name="operator_id"]');
  var currencySel = q('select[name="currency"]');
  var fxInput = q('input[name="fx_rate_to_kzt"]');

  if (!operatorSel || !currencySel || !fxInput) return;

  // добавим подсказку под курсом
  var fxHint = document.createElement('div');
  fxHint.className = 'fxhint';
  fxHint.textContent = '';
  fxInput.parentNode.appendChild(fxHint);

  async function fetchFx(operatorId, currency) {
    var url = '/api/operator_fx_rate.php?operator_id=' + encodeURIComponent(operatorId) +
              '&currency=' + encodeURIComponent(currency);
    var res = await fetch(url, { credentials: 'same-origin' });
    return await res.json();
  }

  var lastKey = '';

  async function applyAutoFx() {
    var operatorId = parseInt(operatorSel.value || '0', 10);
    var cur = (currencySel.value || 'KZT').toUpperCase();

    if (!operatorId) return;

    if (cur === 'KZT') {
      fxInput.value = '1';
      fxHint.textContent = 'Авто-курс: 1 (KZT)';
      fxInput.dispatchEvent(new Event('input', { bubbles: true }));
      fxInput.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }

    var key = operatorId + ':' + cur;
    lastKey = key;

    fxHint.textContent = 'Загружаю курс...';

    try {
      var data = await fetchFx(operatorId, cur);
      if (lastKey !== key) return;

      if (!data.ok) {
        fxHint.textContent = 'Курс не найден для оператора (' + cur + '). Укажите вручную.';
        return;
      }

      var rate = Number(data.rate_to_kzt || 0);
      fxInput.value = rate ? rate.toFixed(2) : '';
      fxHint.textContent =
        'Авто-курс ' + cur + '→KZT: ' + (rate ? rate.toFixed(2) : '—') +
        (data.captured_at ? (' (обновлён: ' + data.captured_at + ')') : '');

      fxInput.dispatchEvent(new Event('input', { bubbles: true }));
      fxInput.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (e) {
      fxHint.textContent = 'Ошибка получения курса. Укажите вручную.';
      console.error(e);
    }
  }

  operatorSel.addEventListener('change', applyAutoFx);
  currencySel.addEventListener('change', applyAutoFx);
  applyAutoFx();
})();
</script>

<?php require __DIR__ . '/_layout_bottom.php'; ?>