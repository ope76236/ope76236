<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

require_role('manager');

$title = 'Заявки';
$pdo = db();

$error = null;

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

/** деньги: KZT -> валюта заявки по курсу платежа */
function kzt_to_app_cur_at_pay(float $amountKzt, string $appCurrency, float $fxAtPay): float
{
  if ($appCurrency === 'KZT') return $amountKzt;
  if ($fxAtPay <= 0) return 0.0;
  return round($amountKzt / $fxAtPay, 2);
}

function fmt_dmy(?string $ymd): string
{
  $ymd = trim((string)$ymd);
  if ($ymd === '') return '';
  $ts = strtotime($ymd);
  if ($ts === false) return $ymd;
  return date('d.m.Y', $ts);
}

// Удаление заявки (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = post('_action', '');
    if ($action === 'delete_app') {
      $appId = (int)post('id', '0');
      if ($appId <= 0) throw new RuntimeException('Некорректный ID заявки.');

      $stDel = $pdo->prepare("DELETE FROM applications WHERE id=? LIMIT 1");
      $stDel->execute([$appId]);

      header('Location: /manager/apps.php');
      exit;
    }
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$q = trim((string)($_GET['q'] ?? ''));
$qLike = '%' . $q . '%';
$qDigits = preg_replace('~\D+~', '', $q);
$isNumericQuery = ($qDigits !== '' && mb_strlen($qDigits) === mb_strlen($q));

// фильтр по статусу
$statusFilter = trim((string)($_GET['status'] ?? ''));
$allowedStatuses = ['in_work','confirmed','docs_issued','draft','completed','cancelled','paid'];
if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = '';

$sqlBase = "
  SELECT a.id,
         a.app_number,
         a.status,
         a.start_date,
         a.end_date,
         a.currency,
         a.country,
         a.destination,
         a.hotel_name,
         a.tourist_price_amount,
         a.operator_price_amount,
         u.email AS customer_email,
         COALESCE(NULLIF(TRIM(CONCAT(t.last_name,' ',t.first_name,' ',t.middle_name)), ''), u.name, '') AS customer_fio
  FROM applications a
  LEFT JOIN users u ON u.id = a.customer_tourist_user_id
  LEFT JOIN tourists t ON t.user_id = u.id
";

// WHERE (поиск/фильтр статуса)
$where = [];
$params = [];

if ($q !== '') {
  if ($isNumericQuery) {
    $where[] = "(a.app_number = ? OR a.id = ?)";
    $n = (int)$qDigits;
    $params[] = $n;
    $params[] = $n;
  } else {
    $where[] = "(" .
      "COALESCE(NULLIF(TRIM(CONCAT(t.last_name,' ',t.first_name,' ',t.middle_name)), ''), u.name, '') LIKE ? " .
      "OR u.email LIKE ? " .
      "OR t.last_name LIKE ? " .
      "OR t.first_name LIKE ? " .
      "OR t.middle_name LIKE ?" .
    ")";
    $params[] = $qLike;
    $params[] = $qLike;
    $params[] = $qLike;
    $params[] = $qLike;
    $params[] = $qLike;
  }
}

if ($statusFilter !== '' && $statusFilter !== 'paid') {
  $where[] = "a.status = ?";
  $params[] = $statusFilter;
}

$sql = $sqlBase;
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY a.id DESC LIMIT 200";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$appIds = array_map(static fn($r) => (int)$r['id'], $rows);
$paidCurByApp = []; // [appId => ['tourist_to_agent'=>sumCur, 'agent_to_operator'=>sumCur]]

if ($appIds) {
  $in = implode(',', array_fill(0, count($appIds), '?'));
  $stPay = $pdo->prepare("
    SELECT application_id, direction, amount, fx_rate_to_kzt
    FROM payments
    WHERE application_id IN ($in) AND status='paid'
  ");
  $stPay->execute($appIds);
  $payRows = $stPay->fetchAll();

  $rowsMap = [];
  foreach ($rows as $r) $rowsMap[(int)$r['id']] = $r;

  foreach ($payRows as $p) {
    $appId = (int)$p['application_id'];
    $dir = (string)($p['direction'] ?? '');
    if ($dir === '') $dir = 'tourist_to_agent';

    if (!isset($paidCurByApp[$appId])) {
      $paidCurByApp[$appId] = ['tourist_to_agent' => 0.0, 'agent_to_operator' => 0.0];
    }

    $r = $rowsMap[$appId] ?? null;
    if (!$r) continue;

    $appCurrency = (string)($r['currency'] ?? 'KZT');
    if (!in_array($appCurrency, ['KZT','USD','EUR'], true)) $appCurrency = 'KZT';

    $amtKzt = (float)$p['amount'];
    $fxAtPay = (float)($p['fx_rate_to_kzt'] ?? 1);
    if ($appCurrency === 'KZT') $fxAtPay = 1.0;

    $amtCur = kzt_to_app_cur_at_pay($amtKzt, $appCurrency, $fxAtPay);
    $paidCurByApp[$appId][$dir] = ($paidCurByApp[$appId][$dir] ?? 0) + $amtCur;
  }
}

$statusLabel = [
  'in_work' => 'в работе',
  'confirmed' => 'подтверждено',
  'docs_issued' => 'документы выданы',
  'draft' => 'черновик',
  'completed' => 'завершено',
  'cancelled' => 'отменено',
  'paid' => 'оплачено',
];

function status_pill_style(string $st): string
{
  $b = 'rgba(226,232,240,.90)';
  $c = 'rgba(15,23,42,1)';
  $bg = 'rgba(148,163,184,.10)';

  if ($st === 'in_work') {
    $b = 'rgba(14,165,233,.40)'; $c = 'rgba(14,165,233,1)'; $bg = 'rgba(14,165,233,.08)';
  } elseif ($st === 'confirmed') {
    $b = 'rgba(34,197,94,.40)'; $c = 'rgba(22,163,74,1)'; $bg = 'rgba(34,197,94,.10)';
  } elseif ($st === 'docs_issued') {
    $b = 'rgba(22,163,74,.40)'; $c = 'rgba(22,163,74,1)'; $bg = 'rgba(22,163,74,.10)';
  } elseif ($st === 'cancelled') {
    $b = 'rgba(239,68,68,.45)'; $c = 'rgba(239,68,68,1)'; $bg = 'rgba(239,68,68,.08)';
  } elseif ($st === 'completed') {
    $b = 'rgba(100,116,139,.45)'; $c = 'rgba(51,65,85,1)'; $bg = 'rgba(100,116,139,.10)';
  } elseif ($st === 'draft') {
    $b = 'rgba(148,163,184,.55)'; $c = 'rgba(71,85,105,1)'; $bg = 'rgba(148,163,184,.12)';
  } elseif ($st === 'paid') {
    $b = 'rgba(34,197,94,.45)'; $c = 'rgba(22,163,74,1)'; $bg = 'rgba(34,197,94,.10)';
  }

  return "border-color:$b; color:$c; background:$bg;";
}

require __DIR__ . '/_layout_top.php';
?>

<style>
  :root{
    --w-strong: 750;
    --w-normal: 600;
  }

  /* типографика + размер как на главной */
  .muted{ color: var(--muted); font-size:12px; font-weight: var(--w-normal); }
  .nowrap{ white-space:nowrap; }
  .ellipsis{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .txt{ font-size:13px; font-weight: var(--w-normal); }

  /* toolbar */
  .filters{
    display:flex;
    gap:12px;
    align-items:flex-end;
    flex-wrap:wrap;
  }

  /* кнопки (десктоп): аккуратнее и единый стиль */
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
  .btn.btn-primary:hover{
    border-color: rgba(14,165,233,.55);
    box-shadow: 0 12px 26px rgba(2,8,23,.06);
  }
  .btn.btn-danger{
    border-color: rgba(239,68,68,.40);
    background: rgba(239,68,68,.06);
    color: rgba(239,68,68,1);
  }
  .btn.btn-danger:hover{
    border-color: rgba(239,68,68,.55);
    box-shadow: 0 12px 26px rgba(2,8,23,.06);
  }

  /* таблица: раз есть горизонтальный скролл — показываем все поля полностью */
  .apps-wrap{ margin-top:12px; overflow:auto; border-radius: 16px; }
  .apps-table{ width:100%; min-width: 1400px; table-layout:auto; }
  .apps-table th, .apps-table td{ vertical-align:middle; }

  .table.apps-table th, .table.apps-table td{ padding: 9px 10px; }
  .table.apps-table th{
    font-size: 12px;
    font-weight: var(--w-normal);
  }
  .table.apps-table td{
    font-size: 13px;
    font-weight: var(--w-normal);
  }

  /* центр/право */
  .t-center{ text-align:center; }
  .t-right{ text-align:right; }

  /* ячейки: убираем лишнюю "жирность" */
  .numcell{ display:flex; flex-direction:column; gap:4px; }
  .numcell .n{ font-weight: var(--w-strong); }
  .numcell .id{ font-size:12px; color:var(--muted); font-weight: var(--w-normal); }

  .custcell{ display:flex; flex-direction:column; gap:4px; }
  .custcell .fio{ font-weight: var(--w-normal); }
  .custcell .email{ font-size:12px; color:var(--muted); font-weight: var(--w-normal); }

  .tourcell{ display:flex; flex-direction:column; gap:3px; }
  .tourcell .country{ font-weight: var(--w-normal); }
  .tourcell .hotel{ font-size:12px; color:var(--muted); font-weight: var(--w-normal); }

  /* расчёты */
  .moneygrid{
    display:grid;
    grid-template-columns: 34px 1fr;
    column-gap: 8px;
    row-gap: 2px;
    align-items: baseline;
    min-width: 160px;
  }
  .moneygrid .k{ color:var(--muted); font-size:11px; line-height:1.1; font-weight: var(--w-normal); }
  .moneygrid .v{ text-align:right; white-space:nowrap; font-size:12px; line-height:1.1; font-weight: var(--w-normal); }

  .ok{ color: rgba(22,163,74,1); font-weight: var(--w-strong); }
  .bad{ color: rgba(239,68,68,1); font-weight: var(--w-strong); }

  .status-pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:6px 10px;
    border-radius:999px;
    border:1px solid rgba(226,232,240,.9);
    font-weight: var(--w-normal);
    font-size:12px;
    white-space:nowrap;
  }

  /* actions: компактная группа */
  .actions{
    display:flex;
    gap:8px;
    justify-content:flex-end;
    flex-wrap:wrap;
  }

  /* MOBILE: карточки */
  .apps-cards{ display:none; margin-top:12px; }
  .app-card{
    border:1px solid rgba(226,232,240,.92);
    border-radius:16px;
    background: rgba(255,255,255,.72);
    padding:12px;
    box-shadow: var(--shadow);
  }
  .app-card + .app-card{ margin-top:12px; }
  .app-head{ display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
  .app-no{ font-weight: var(--w-strong); white-space:nowrap; }
  .app-status{ margin-left:auto; }
  .app-fio{ margin-top:6px; font-weight: var(--w-normal); }
  .app-sub{ margin-top:6px; color:var(--muted); font-size:12px; font-weight: var(--w-normal); }

  .app-grid{
    display:grid;
    grid-template-columns: 1fr 1fr;
    gap:10px;
    margin-top:10px;
  }
  @media (max-width: 420px){ .app-grid{ grid-template-columns: 1fr; } }

  .box{
    border:1px solid rgba(226,232,240,.85);
    border-radius:14px;
    background: rgba(255,255,255,.78);
    padding:10px;
    min-width:0;
  }
  .box .ttl{ color:var(--muted); font-size:12px; font-weight: var(--w-normal); }
  .box .val{ margin-top:6px; font-size:13px; font-weight: var(--w-normal); }
  .box .val b{ font-weight: var(--w-strong); }

  .app-actions{ display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }

  @media (max-width: 980px){
    .apps-wrap{ display:none; }
    .apps-cards{ display:block; }
  }
</style>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div>
    <h1 class="h1" style="margin-bottom:6px;">Заявки</h1>
    <div class="badge">Поиск, фильтр по статусу, расчёты оплат</div>
  </div>
  <a class="btn success" href="/manager/app_create.php">+ Создать заявку</a>
</div>

<?php if ($error): ?>
  <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="toolbar">
  <form class="filters" method="get" action="/manager/apps.php">
    <div class="input" style="flex:1; margin:0; min-width:260px;">
      <label>Поиск по № заявки или ФИО заказчика</label>
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Например: 1203 или Иванов">
    </div>

    <div class="input" style="margin:0; min-width:220px;">
      <label>Статус</label>
      <select name="status">
        <option value="" <?= ($statusFilter === '' ? 'selected' : '') ?>>Все</option>
        <?php foreach (['in_work','confirmed','docs_issued','cancelled','draft','completed','paid'] as $stOpt): ?>
          <option value="<?= h($stOpt) ?>" <?= ($statusFilter === $stOpt ? 'selected' : '') ?>>
            <?= h($statusLabel[$stOpt] ?? $stOpt) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <button class="btn" type="submit">Показать</button>
    <?php if ($q !== '' || $statusFilter !== ''): ?>
      <a class="btn" href="/manager/apps.php">Сброс</a>
    <?php endif; ?>
  </form>
</div>

<!-- DESKTOP/TABLET: таблица -->
<div class="apps-wrap">
  <table class="table apps-table">
    <thead>
      <tr>
        <th class="t-center">№</th>
        <th>Заказчик</th>
        <th>Тур</th>
        <th class="t-center">Даты</th>
        <th>Турист</th>
        <th>Оператор</th>
        <th class="t-center">Статус</th>
        <th class="t-right">Действия</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" style="color:var(--muted);">Заявок не найдено.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php
            $appId = (int)$r['id'];
            $appNo = (int)($r['app_number'] ?? 0);
            if ($appNo <= 0) $appNo = $appId;

            $country = (string)($r['country'] ?? '');
            if ($country === '') $country = (string)($r['destination'] ?? '');
            $hotel = trim((string)($r['hotel_name'] ?? ''));

            $customerFio = trim((string)($r['customer_fio'] ?? ''));
            $customerEmail = trim((string)($r['customer_email'] ?? ''));

            $appCurrency = (string)($r['currency'] ?? 'KZT');
            if (!in_array($appCurrency, ['KZT','USD','EUR'], true)) $appCurrency = 'KZT';

            $touristPriceCur = (float)($r['tourist_price_amount'] ?? 0);
            $operatorPriceCur = (float)($r['operator_price_amount'] ?? 0);

            $paidTouristCur = (float)($paidCurByApp[$appId]['tourist_to_agent'] ?? 0);
            $paidOperatorCur = (float)($paidCurByApp[$appId]['agent_to_operator'] ?? 0);

            $debtTouristCur = round($touristPriceCur - $paidTouristCur, 2);
            $debtOperatorCur = round($operatorPriceCur - $paidOperatorCur, 2);

            $touristPaidOk = $debtTouristCur <= 0.00001;
            $operatorPaidOk = $debtOperatorCur <= 0.00001;
            $allPaid = $touristPaidOk && $operatorPaidOk;

            $stRow = (string)($r['status'] ?? '');
            $stShow = $allPaid ? 'paid' : $stRow;

            if ($statusFilter === 'paid' && !$allPaid) continue;

            $rowHref = '/manager/app_view.php?id=' . $appId;

            $touristDebtClass = $touristPaidOk ? 'ok' : 'bad';
            $operatorDebtClass = $operatorPaidOk ? 'ok' : 'bad';

            $d1 = fmt_dmy((string)($r['start_date'] ?? ''));
            $d2 = fmt_dmy((string)($r['end_date'] ?? ''));
          ?>
          <tr onclick="window.location.href='<?= h($rowHref) ?>';" style="cursor:pointer;">
            <td class="t-center">
              <div class="numcell">
                <div class="n">№<?= (int)$appNo ?></div>
                <div class="id">ID <?= (int)$appId ?></div>
              </div>
            </td>

            <td>
              <div class="custcell">
                <div class="fio" title="<?= h($customerFio !== '' ? $customerFio : '—') ?>">
                  <?= h($customerFio !== '' ? $customerFio : '—') ?>
                </div>
                <div class="email" title="<?= h($customerEmail) ?>"><?= h($customerEmail) ?></div>
              </div>
            </td>

            <td>
              <div class="tourcell">
                <div class="country" title="<?= h($country ?: '—') ?>"><?= h($country ?: '—') ?></div>
                <div class="hotel" title="<?= h($hotel !== '' ? $hotel : '—') ?>"><?= h($hotel !== '' ? $hotel : '—') ?></div>
              </div>
            </td>

            <td class="t-center">
              <div class="nowrap muted"><?= h($d1) ?></div>
              <div class="nowrap muted"><?= h($d2) ?></div>
            </td>

            <td>
              <div class="moneygrid">
                <div class="k">цена</div>
                <div class="v"><?= number_format($touristPriceCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></div>

                <div class="k">опл.</div>
                <div class="v"><?= number_format($paidTouristCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></div>

                <div class="k <?= $touristDebtClass ?>">долг</div>
                <div class="v <?= $touristDebtClass ?>"><?= number_format($debtTouristCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></div>
              </div>
            </td>

            <td>
              <div class="moneygrid">
                <div class="k">цена</div>
                <div class="v"><?= number_format($operatorPriceCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></div>

                <div class="k">опл.</div>
                <div class="v"><?= number_format($paidOperatorCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></div>

                <div class="k <?= $operatorDebtClass ?>">долг</div>
                <div class="v <?= $operatorDebtClass ?>"><?= number_format($debtOperatorCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></div>
              </div>
            </td>

            <td class="t-center" onclick="event.stopPropagation();">
              <span class="status-pill" style="<?= h(status_pill_style($stShow)) ?>">
                <?= h($statusLabel[$stShow] ?? ($stShow !== '' ? $stShow : '—')) ?>
              </span>
            </td>

            <td class="t-right" onclick="event.stopPropagation();">
              <div class="actions">
                <a class="btn btn-sm btn-primary" href="/manager/app_view.php?id=<?= (int)$appId ?>">Открыть</a>
                <form method="post" style="margin:0;" onsubmit="return confirm('Удалить заявку? Действие необратимо.');">
                  <input type="hidden" name="_action" value="delete_app">
                  <input type="hidden" name="id" value="<?= (int)$appId ?>">
                  <button class="btn btn-sm btn-danger" type="submit">Удалить</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- MOBILE: карточки -->
<div class="apps-cards">
  <?php if (!$rows): ?>
    <div class="muted">Заявок не найдено.</div>
  <?php else: ?>
    <?php foreach ($rows as $r): ?>
      <?php
        $appId = (int)$r['id'];
        $appNo = (int)($r['app_number'] ?? 0);
        if ($appNo <= 0) $appNo = $appId;

        $country = (string)($r['country'] ?? '');
        if ($country === '') $country = (string)($r['destination'] ?? '');
        $hotel = trim((string)($r['hotel_name'] ?? ''));

        $customerFioFull = trim((string)($r['customer_fio'] ?? ''));
        $customerFio = fio_short($customerFioFull);
        $customerEmail = trim((string)($r['customer_email'] ?? ''));

        $appCurrency = (string)($r['currency'] ?? 'KZT');
        if (!in_array($appCurrency, ['KZT','USD','EUR'], true)) $appCurrency = 'KZT';

        $touristPriceCur = (float)($r['tourist_price_amount'] ?? 0);
        $operatorPriceCur = (float)($r['operator_price_amount'] ?? 0);

        $paidTouristCur = (float)($paidCurByApp[$appId]['tourist_to_agent'] ?? 0);
        $paidOperatorCur = (float)($paidCurByApp[$appId]['agent_to_operator'] ?? 0);

        $debtTouristCur = round($touristPriceCur - $paidTouristCur, 2);
        $debtOperatorCur = round($operatorPriceCur - $paidOperatorCur, 2);

        $touristPaidOk = $debtTouristCur <= 0.00001;
        $operatorPaidOk = $debtOperatorCur <= 0.00001;
        $allPaid = $touristPaidOk && $operatorPaidOk;

        $stRow = (string)($r['status'] ?? '');
        $stShow = $allPaid ? 'paid' : $stRow;

        if ($statusFilter === 'paid' && !$allPaid) continue;

        $rowHref = '/manager/app_view.php?id=' . $appId;

        $d1 = fmt_dmy((string)($r['start_date'] ?? ''));
        $d2 = fmt_dmy((string)($r['end_date'] ?? ''));

        $touristDebtClass = $touristPaidOk ? 'ok' : 'bad';
        $operatorDebtClass = $operatorPaidOk ? 'ok' : 'bad';
      ?>

      <div class="app-card" onclick="window.location.href='<?= h($rowHref) ?>';" style="cursor:pointer;">
        <div class="app-head">
          <div>
            <div class="app-no">№<?= (int)$appNo ?></div>
            <div class="app-fio"><?= h($customerFio) ?></div>
            <?php if ($customerEmail !== ''): ?>
              <div class="app-sub ellipsis" title="<?= h($customerEmail) ?>"><?= h($customerEmail) ?></div>
            <?php endif; ?>
          </div>
          <div class="app-status" onclick="event.stopPropagation();">
            <span class="status-pill" style="<?= h(status_pill_style($stShow)) ?>">
              <?= h($statusLabel[$stShow] ?? ($stShow !== '' ? $stShow : '—')) ?>
            </span>
          </div>
        </div>

        <div class="app-sub">
          <?= h($country ?: '—') ?>
          <?= ($hotel !== '' ? ' · ' . h($hotel) : '') ?>
        </div>

        <div class="app-sub">
          Даты: <?= h($d1 ?: '—') ?> — <?= h($d2 ?: '—') ?>
        </div>

        <div class="app-grid">
          <div class="box">
            <div class="ttl">Турист</div>
            <div class="val">
              цена: <b><?= number_format($touristPriceCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></b><br>
              опл.: <b><?= number_format($paidTouristCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></b><br>
              долг: <b class="<?= $touristDebtClass ?>"><?= number_format($debtTouristCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></b>
            </div>
          </div>

          <div class="box">
            <div class="ttl">Оператор</div>
            <div class="val">
              цена: <b><?= number_format($operatorPriceCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></b><br>
              опл.: <b><?= number_format($paidOperatorCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></b><br>
              долг: <b class="<?= $operatorDebtClass ?>"><?= number_format($debtOperatorCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></b>
            </div>
          </div>
        </div>

        <div class="app-actions" onclick="event.stopPropagation();">
          <a class="btn btn-sm btn-primary" href="/manager/app_view.php?id=<?= (int)$appId ?>">Открыть</a>
          <form method="post" style="margin:0;" onsubmit="return confirm('Удалить заявку? Действие необратимо.');">
            <input type="hidden" name="_action" value="delete_app">
            <input type="hidden" name="id" value="<?= (int)$appId ?>">
            <button class="btn btn-sm btn-danger" type="submit">Удалить</button>
          </form>
        </div>
      </div>

    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>