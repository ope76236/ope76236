<?php
declare(strict_types=1);

$title = 'Заявка';
require __DIR__ . '/_layout_top.php';

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/fx.php';

$pdo = db();

$u = current_user();
$uid = (int)($u['id'] ?? 0);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(404);
  echo "Не указан ID заявки";
  exit;
}

$st = $pdo->prepare("
  SELECT a.*,
         o.name AS operator_name,
         mu.name AS manager_name_user,
         mu.email AS manager_email_user,
         mu.phone AS manager_phone_user
  FROM applications a
  JOIN application_tourists at ON at.application_id = a.id AND at.tourist_user_id = ?
  LEFT JOIN tour_operators o ON o.id = a.operator_id
  LEFT JOIN users mu ON mu.id = a.manager_user_id
  WHERE a.id=?
  LIMIT 1
");
$st->execute([$uid, $id]);
$app = $st->fetch(PDO::FETCH_ASSOC);
if (!$app) {
  http_response_code(403);
  echo "Доступ запрещён";
  exit;
}

/** Company card (table: companies) */
$company = null;
try {
  $company = $pdo->query("SELECT name, director_name, phone, email FROM companies ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $company = null;
}

function status_ru(string $status): string {
  $status = trim($status);
  if ($status === '') return '—';

  $map = [
    'draft'        => 'черновик',
    'confirmed'    => 'подтверждено',
    'completed'    => 'завершено',
    'cancelled'    => 'отменено',
    'docs_issued'  => 'документы выданы',
    'paid'         => 'оплачено',
    'in_work'      => 'в работе',
    'new'          => 'новая',
  ];
  if (isset($map[$status])) return $map[$status];

  $status = str_replace(['_', '-'], ' ', $status);
  $status = mb_strtolower($status);
  $status = mb_convert_case($status, MB_CASE_TITLE, 'UTF-8');
  return $status;
}
function status_chip_class(string $status): string {
  $s = trim($status);
  if (in_array($s, ['completed', 'paid', 'docs_issued'], true)) return 'chip chip-ok';
  if (in_array($s, ['cancelled'], true)) return 'chip chip-bad';
  if (in_array($s, ['confirmed', 'in_work', 'new'], true)) return 'chip chip-warn';
  if (in_array($s, ['draft'], true)) return 'chip chip-muted';
  return 'chip chip-muted';
}

function kzt_to_app_cur_at_pay(float $kzt, string $appCurrency, float $fxAtPay): float
{
  if ($appCurrency === 'KZT') return $kzt;
  if ($fxAtPay <= 0) return 0.0;
  return round($kzt / $fxAtPay, 2);
}

function years_old(?string $birthDate): string
{
  $birthDate = trim((string)$birthDate);
  if ($birthDate === '') return '—';
  $ts = strtotime($birthDate);
  if ($ts === false) return '—';
  $d = new DateTime(date('Y-m-d', $ts));
  $now = new DateTime('today');
  return (string)$d->diff($now)->y;
}

function fmt_dmy(?string $ymd): string
{
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '—';
  $ts = strtotime($ymd);
  if ($ts === false) return $ymd;
  return date('d.m.Y', $ts);
}

function fio_row(array $r): string {
  $fio = trim(($r['last_name'] ?? '') . ' ' . ($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? ''));
  if ($fio === '') $fio = trim((string)($r['name'] ?? ''));
  return $fio !== '' ? $fio : '—';
}

function money_fmt(float $n): string {
  return number_format($n, 2, '.', ' ');
}

$appCurrency = (string)($app['currency'] ?? 'KZT');
if (!in_array($appCurrency, ['KZT','USD','EUR'], true)) $appCurrency = 'KZT';

$fxDateText = date('d.m.Y');

$fxInfo = fx_operator_today_for_app($pdo, $app);
$fxRateToday = (float)$fxInfo['rate'];
$fxSourceText = ($fxInfo['source'] === 'operator_today')
  ? 'по курсу туроператора на сегодня'
  : (($fxInfo['source'] === 'kzt') ? 'тенге' : 'по курсу в заявке');

$touristPriceCur = (float)($app['tourist_price_amount'] ?? 0);

// Payments -> debt
$stPay = $pdo->prepare("
  SELECT direction, amount, fx_rate_to_kzt, status
  FROM payments
  WHERE application_id=?
");
$stPay->execute([$id]);
$payments = $stPay->fetchAll(PDO::FETCH_ASSOC);

$paidTouristCurAtPay = 0.0;
$paidTouristKzt = 0.0;

foreach ($payments as $p) {
  if ((string)($p['status'] ?? '') !== 'paid') continue;

  $dir = (string)($p['direction'] ?? '');
  if ($dir === '') $dir = 'tourist_to_agent';
  if ($dir !== 'tourist_to_agent') continue;

  $amtKzt = (float)$p['amount'];
  $paidTouristKzt += $amtKzt;

  $fxPay = (float)($p['fx_rate_to_kzt'] ?? $fxRateToday);
  $paidTouristCurAtPay += kzt_to_app_cur_at_pay($amtKzt, $appCurrency, $fxPay);
}
$paidTouristCurAtPay = round($paidTouristCurAtPay, 2);
$paidTouristKzt = round($paidTouristKzt, 2);

$debtTouristCur = round($touristPriceCur - $paidTouristCurAtPay, 2);

// Стоимость тура в KZT на сегодня (по курсу оператора сегодня)
$tourPriceKztToday = fx_cur_to_kzt($touristPriceCur, $appCurrency, $fxRateToday);

// KZT-долг/переплата = abs(долг в валюте) * курс сегодня
$debtAbsCur = abs($debtTouristCur);
$debtAbsKztToday = fx_cur_to_kzt($debtAbsCur, $appCurrency, $fxRateToday);

$isOverpay = ($debtTouristCur < -0.009);
$isDebt = ($debtTouristCur > 0.009);

$curLabel = $isOverpay ? 'Переплата' : 'Долг';
$kztLabel = $isOverpay ? 'Переплата' : 'Долг';

$curValueToShow = $isOverpay ? $debtAbsCur : max(0.0, $debtTouristCur);

$curClass = $isOverpay ? 'money-overpay' : ($isDebt ? 'money-debt' : 'money-paid');

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
$members = $stMembers->fetchAll(PDO::FETCH_ASSOC);

$appNo = (int)($app['app_number'] ?? 0);
$appNo = $appNo > 0 ? $appNo : (int)$app['id'];

$docsUrl = "/tourist/documents.php?app_id=" . (int)$app['id'];

$product = [
  'Направление' => trim((string)($app['country'] ?? $app['destination'] ?? '')),
  'Отель' => trim((string)($app['hotel_name'] ?? '')),
  'Категория номера' => trim((string)($app['room_category'] ?? '')),
  'Тип питания' => trim((string)($app['meal_plan'] ?? '')),
  'Перелёты (туда)' => trim((string)($app['flights_outbound'] ?? '')),
  'Перелёты (обратно)' => trim((string)($app['flights_return'] ?? '')),
  'Трансферы' => trim((string)($app['transfers_info'] ?? '')),
  'Страхование' => trim((string)($app['insurance_info'] ?? '')),
  'Доп. услуги' => trim((string)($app['excursions_info'] ?? '')),
];
$product = array_filter($product, fn($v) => $v !== '');

$stStatus = (string)($app['status'] ?? 'in_work');
$statusText = status_ru($stStatus);
$statusCls = status_chip_class($stStatus);

$companyDirector = trim((string)($company['director_name'] ?? ''));
$companyPhone = trim((string)($company['phone'] ?? ''));
$companyEmail = trim((string)($company['email'] ?? ''));

$managerName = $companyDirector !== '' ? $companyDirector : trim((string)($app['manager_name_user'] ?? ''));
$managerPhone = $companyPhone !== '' ? $companyPhone : trim((string)($app['manager_phone_user'] ?? ''));
$managerEmail = $companyEmail !== '' ? $companyEmail : trim((string)($app['manager_email_user'] ?? ''));

if ($managerName === '') $managerName = '—';

function phone_href(string $phone): string {
  $p = preg_replace('/[^\d\+]/', '', $phone);
  return $p ?: $phone;
}
?>

<style>
  :root{ --w-strong: 750; --w-normal: 600; }

  .muted{ color: var(--muted); font-weight: var(--w-normal); }
  .mini{ font-size:12px; }
  .nowrap{ white-space:nowrap; }
  .ellipsis{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

  .chip{
    display:inline-flex; align-items:center; justify-content:center;
    padding:7px 12px; border-radius:999px;
    border:1px solid rgba(226,232,240,.90);
    background: rgba(255,255,255,.78);
    font-size:13px; font-weight: var(--w-normal);
    line-height:1; white-space:nowrap;
    max-width:100%; overflow:hidden; text-overflow:ellipsis;
  }
  .chip-ok{ color:#16a34a; border-color: rgba(34,197,94,.40); background: rgba(34,197,94,.12); }
  .chip-warn{ color:#92400e; border-color: rgba(245,158,11,.42); background: rgba(245,158,11,.14); }
  .chip-bad{ color:#ef4444; border-color: rgba(239,68,68,.42); background: rgba(239,68,68,.12); }
  .chip-muted{ color: var(--muted); border-color: rgba(226,232,240,.90); background: rgba(255,255,255,.78); }

  .section-title{
    margin-top:18px;
    font-weight: var(--w-strong);
    color:#0f172a;
    font-size:15px;
    display:flex;
    align-items:center;
    gap:10px;
  }
  .section-title::before{
    content:"";
    width:9px; height:9px; border-radius:999px;
    background: rgba(14,165,233,.92);
    box-shadow: 0 8px 18px rgba(14,165,233,.18);
    display:inline-block;
  }

  .cards-2{
    display:grid;
    grid-template-columns: 1fr;
    gap:12px;
    margin-top:12px;
    min-width:0;
  }
  @media (min-width: 980px){
    .cards-2{ grid-template-columns: 1fr 1fr; }
  }

  .box{
    border:1px solid rgba(226,232,240,.90);
    border-radius:16px;
    background: rgba(255,255,255,.72);
    padding:14px;
    min-width:0;
    overflow:hidden;
  }
  .box .h{ font-weight: var(--w-strong); color:#0f172a; margin-bottom:10px; }

  .calc-stack .row{ margin-top:12px; }
  .calc-stack .row:first-child{ margin-top:0; }
  .calc-stack .k{ color:var(--muted); font-size:12px; font-weight: var(--w-normal); }
  .calc-stack .v{
    margin-top:6px;
    font-size:15px;
    font-weight: var(--w-strong);
    line-height:1.25;
  }
  .calc-stack .sub{
    margin-top:6px;
    color:var(--muted);
    font-size:12px;
    font-weight: var(--w-normal);
  }

  .money-price{ color:#0f172a; }
  .money-paid{ color:#16a34a; }
  .money-debt{ color:#ef4444; }
  .money-overpay{ color:#f59e0b; } /* оранжевый: переплата */

  .table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    overflow:hidden;
    border-radius:16px;
    border:1px solid rgba(226,232,240,.90);
    background: rgba(255,255,255,.72);
    table-layout: fixed;
    margin-top:12px;
  }
  .table th, .table td{
    padding:10px 10px;
    border-bottom:1px solid rgba(226,232,240,.75);
    text-align:left;
    font-size:13px;
    vertical-align:top;
    font-weight: var(--w-normal);
  }
  .table th{
    font-size:12px;
    color:var(--muted);
    font-weight: var(--w-normal);
    background: rgba(248,250,252,.7);
  }
  .table tr:last-child td{ border-bottom:none; }

  .table-wrap{ width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
  .table-wrap .table{ min-width: 760px; }
  @media (min-width: 981px){
    .table-wrap .table{ min-width:0; }
  }

  .kv{
    display:grid;
    grid-template-columns: 1fr 1fr;
    gap:10px;
  }
  @media (max-width: 520px){
    .kv{ grid-template-columns: 1fr; }
  }
  .kv .item{
    border:1px solid rgba(226,232,240,.90);
    border-radius:14px;
    background: rgba(255,255,255,.78);
    padding:10px;
    min-width:0;
  }

  .prod-dir .k{
    font-weight: var(--w-strong);
    color:#0f172a;
    font-size:13px;
  }
  .prod-dir .v{
    font-weight: var(--w-normal);
    color: var(--muted);
    margin-top:6px;
  }

  .kv .k{ color:var(--muted); font-size:12px; font-weight: var(--w-normal); }
  .kv .v{ margin-top:6px; font-weight: var(--w-strong); color:#0f172a; }

  .contact-link{
    color:inherit;
    text-decoration:none;
    border-bottom: 1px dashed rgba(148,163,184,.9);
  }
  .contact-link:hover{ border-bottom-color: rgba(14,165,233,.55); }
</style>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div style="min-width:0;">
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <h1 class="h1" style="margin-bottom:0;">Заявка №<?= (int)$appNo ?></h1>
      <span class="<?= h($statusCls) ?>"><?= h($statusText) ?></span>
    </div>

    <div class="badge" style="margin-top:8px;">
      <?= h(fmt_dmy((string)($app['start_date'] ?? ''))) ?> — <?= h(fmt_dmy((string)($app['end_date'] ?? ''))) ?> ·
      <?= h((string)($app['operator_name'] ?? '—')) ?> ·
      <?= h((string)($app['country'] ?? $app['destination'] ?? '—')) ?>
    </div>
  </div>

  <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="/tourist/tours.php">← К списку</a>
    <a class="btn primary" href="<?= h($docsUrl) ?>">Документы</a>
  </div>
</div>

<div class="section-title">Расчёты и менеджер</div>

<div class="cards-2">
  <div class="box">
    <div class="h">Расчёты с туристом</div>

    <div class="calc-stack">
      <div class="row">
        <div class="k">Стоимость тура</div>
        <div class="v money-price"><?= money_fmt($touristPriceCur) ?> <?= h($appCurrency) ?></div>
        <div class="sub"><?= money_fmt($tourPriceKztToday) ?> KZT (<?= h($fxSourceText) ?>)</div>
      </div>

      <div class="row">
        <div class="k">Оплачено</div>
        <div class="v money-paid"><?= money_fmt($paidTouristCurAtPay) ?> <?= h($appCurrency) ?></div>
        <div class="sub"><?= money_fmt($paidTouristKzt) ?> KZT (фактически оплачено)</div>
      </div>

      <div class="row">
        <div class="k"><?= h($curLabel) ?></div>
        <div class="v <?= h($curClass) ?>"><?= money_fmt($curValueToShow) ?> <?= h($appCurrency) ?></div>
        <div class="sub"><?= money_fmt($debtAbsKztToday) ?> KZT (<?= h($kztLabel) ?>, <?= h($fxSourceText) ?>)</div>
      </div>

      <div class="row">
        <div class="k">Курс на <?= h($fxDateText) ?></div>
        <div class="v money-price"><?= money_fmt($fxRateToday) ?> (<?= h($appCurrency) ?>→KZT)</div>
      </div>
    </div>
  </div>

  <div class="box">
    <div class="h">Менеджер</div>

    <div class="kv">
      <div class="item" style="grid-column: 1 / -1;">
        <div class="k">ФИО</div>
        <div class="v"><?= h($managerName) ?></div>
      </div>

      <?php if (trim($managerPhone) !== ''): ?>
        <div class="item">
          <div class="k">Телефон</div>
          <div class="v">
            <a class="contact-link" href="tel:<?= h(phone_href($managerPhone)) ?>">
              <?= h($managerPhone) ?>
            </a>
          </div>
        </div>
      <?php endif; ?>

      <?php if (trim($managerEmail) !== ''): ?>
        <div class="item">
          <div class="k">Email</div>
          <div class="v">
            <a class="contact-link" href="mailto:<?= h($managerEmail) ?>"><?= h($managerEmail) ?></a>
          </div>
        </div>
      <?php endif; ?>

      <?php if (trim($managerEmail) === '' && trim($managerPhone) === ''): ?>
        <div class="item" style="grid-column: 1 / -1;">
          <div class="k">Контакты</div>
          <div class="v muted">—</div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="section-title">Туристы в заявке</div>

<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>ФИО</th>
        <th style="width:160px;">Дата рождения (лет)</th>
        <th style="width:160px;">Паспорт №</th>
        <th style="width:220px;">Срок действия</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$members): ?>
        <tr><td colspan="4" class="muted">Туристы ещё не добавлены.</td></tr>
      <?php else: ?>
        <?php foreach ($members as $m): ?>
          <?php $fio = fio_row($m); ?>
          <tr>
            <td style="font-weight:var(--w-strong); color:#0f172a;"><?= h($fio) ?></td>
            <td class="muted">
              <?= h(fmt_dmy((string)($m['birth_date'] ?? ''))) ?>
              <div class="mini muted"><?= h(years_old((string)($m['birth_date'] ?? ''))) ?> лет</div>
            </td>
            <td><?= h((string)($m['passport_no'] ?? '')) ?></td>
            <td class="muted">
              <?= h(fmt_dmy((string)($m['passport_issue_date'] ?? ''))) ?> — <?= h(fmt_dmy((string)($m['passport_expiry_date'] ?? ''))) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($product): ?>
  <div class="section-title">Состав туристского продукта</div>

  <div class="box" style="margin-top:12px;">
    <div class="kv">
      <?php foreach ($product as $k => $v): ?>
        <?php
          $isDirection = ($k === 'Направление');
          $itemCls = $isDirection ? 'prod-dir' : '';
          $wide = (mb_strlen((string)$v) > 60) ? 'grid-column:1 / -1;' : '';
        ?>
        <div class="item <?= h($itemCls) ?>" style="<?= h($wide) ?>">
          <div class="k"><?= h($k) ?></div>
          <div class="v" style="white-space:pre-wrap;"><?= h($v) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="section-title">Документы</div>

<div class="box" style="margin-top:12px;">
  <div class="muted">Прикрепление и скачивание документов доступны в разделе “Документы”.</div>
  <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn primary" href="<?= h($docsUrl) ?>">Перейти в документы заявки</a>
  </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>