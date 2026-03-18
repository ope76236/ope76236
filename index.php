<?php
declare(strict_types=1);

$title = 'Обзор';
require __DIR__ . '/_layout_top.php';

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';

$pdo = db();

$today = new DateTimeImmutable('today');
$todayYmd = $today->format('Y-m-d');
$plus2 = $today->modify('+2 days')->format('Y-m-d');
$plus3 = $today->modify('+3 days')->format('Y-m-d');

$monthStart = $today->modify('first day of this month')->format('Y-m-01');
$monthEnd = $today->modify('last day of this month')->format('Y-m-t');

function fmt_dmy(?string $ymd): string {
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '—';
  $ts = strtotime($ymd);
  if ($ts === false) return $ymd;
  return date('d.m.Y', $ts);
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

/**
 * KPI
 */
$cntTourists = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='tourist'")->fetchColumn();
$cntApps = (int)$pdo->query("SELECT COUNT(*) FROM applications")->fetchColumn();
$cntOps = (int)$pdo->query("SELECT COUNT(*) FROM tour_operators")->fetchColumn();

$appByStatus = $pdo->query("
  SELECT status, COUNT(*) cnt
  FROM applications
  GROUP BY status
")->fetchAll();

$appsStatusMap = [];
foreach ($appByStatus as $r) {
  $appsStatusMap[(string)$r['status']] = (int)$r['cnt'];
}
$cntInWork = (int)($appsStatusMap['in_work'] ?? 0);
$cntConfirmed = (int)($appsStatusMap['confirmed'] ?? 0);
$cntDocsIssued = (int)($appsStatusMap['docs_issued'] ?? 0);
$cntCancelled = (int)($appsStatusMap['cancelled'] ?? 0);

/**
 * Дедлайны (2 дня)
 */
$stDlTourist = $pdo->prepare("
  SELECT d.id AS deadline_id,
         d.application_id,
         d.direction,
         d.due_date,
         d.percent,

         a.app_number,
         a.currency,
         a.fx_rate_to_kzt,
         a.tourist_price_amount,
         a.operator_price_amount,

         COALESCE(NULLIF(TRIM(CONCAT(t.last_name,' ',t.first_name,' ',t.middle_name)), ''), u.name, '') AS customer_fio
  FROM payment_deadlines d
  JOIN applications a ON a.id = d.application_id
  LEFT JOIN users u ON u.id = a.customer_tourist_user_id
  LEFT JOIN tourists t ON t.user_id = u.id
  WHERE d.due_date IS NOT NULL
    AND d.due_date BETWEEN ? AND ?
    AND d.direction = 'tourist_to_agent'
  ORDER BY d.due_date ASC, d.id DESC
  LIMIT 50
");
$stDlTourist->execute([$todayYmd, $plus2]);
$deadlineTouristRows = $stDlTourist->fetchAll();

$stDlOperator = $pdo->prepare("
  SELECT d.id AS deadline_id,
         d.application_id,
         d.direction,
         d.due_date,
         d.percent,

         a.app_number,
         a.currency,
         a.fx_rate_to_kzt,
         a.tourist_price_amount,
         a.operator_price_amount,

         COALESCE(NULLIF(TRIM(CONCAT(t.last_name,' ',t.first_name,' ',t.middle_name)), ''), u.name, '') AS customer_fio
  FROM payment_deadlines d
  JOIN applications a ON a.id = d.application_id
  LEFT JOIN users u ON u.id = a.customer_tourist_user_id
  LEFT JOIN tourists t ON t.user_id = u.id
  WHERE d.due_date IS NOT NULL
    AND d.due_date BETWEEN ? AND ?
    AND d.direction = 'agent_to_operator'
  ORDER BY d.due_date ASC, d.id DESC
  LIMIT 50
");
$stDlOperator->execute([$todayYmd, $plus2]);
$deadlineOperatorRows = $stDlOperator->fetchAll();

/**
 * Ближайшие вылеты/возвраты
 */
$flights = $pdo->prepare("
  SELECT a.id,
         a.app_number,
         a.start_date,
         COALESCE(NULLIF(TRIM(CONCAT(t.last_name,' ',t.first_name,' ',t.middle_name)), ''), u.name, '') AS customer_fio
  FROM applications a
  LEFT JOIN users u ON u.id = a.customer_tourist_user_id
  LEFT JOIN tourists t ON t.user_id = u.id
  WHERE a.start_date IS NOT NULL
    AND a.start_date BETWEEN ? AND ?
  ORDER BY a.start_date ASC, a.id DESC
  LIMIT 50
");
$flights->execute([$todayYmd, $plus3]);
$flightRows = $flights->fetchAll();

$returns = $pdo->prepare("
  SELECT a.id,
         a.app_number,
         a.end_date,
         COALESCE(NULLIF(TRIM(CONCAT(t.last_name,' ',t.first_name,' ',t.middle_name)), ''), u.name, '') AS customer_fio
  FROM applications a
  LEFT JOIN users u ON u.id = a.customer_tourist_user_id
  LEFT JOIN tourists t ON t.user_id = u.id
  WHERE a.end_date IS NOT NULL
    AND a.end_date BETWEEN ? AND ?
  ORDER BY a.end_date ASC, a.id DESC
  LIMIT 50
");
$returns->execute([$todayYmd, $plus3]);
$returnRows = $returns->fetchAll();

/**
 * Доход агента (план/факт)
 */
$profitPlanSt = $pdo->prepare("
  SELECT
    COUNT(*) AS cnt,
    COALESCE(SUM(
      (COALESCE(tourist_price_amount,0) - COALESCE(operator_price_amount,0))
      * CASE
          WHEN currency='KZT' THEN 1
          ELSE COALESCE(NULLIF(fx_rate_to_kzt,0), 0)
        END
    ),0) AS profit_kzt
  FROM applications
  WHERE start_date BETWEEN ? AND ?
");
$profitPlanSt->execute([$monthStart, $monthEnd]);
$profitPlanRow = $profitPlanSt->fetch();
$cntThisMonth = (int)($profitPlanRow['cnt'] ?? 0);
$profitPlanKzt = (float)($profitPlanRow['profit_kzt'] ?? 0.0);

$profitFactSt = $pdo->prepare("
  SELECT
    COALESCE(SUM(CASE
      WHEN p.status='paid' AND p.direction='tourist_to_agent' THEN COALESCE(p.amount,0)
      ELSE 0
    END),0)
    -
    COALESCE(SUM(CASE
      WHEN p.status='paid' AND p.direction='agent_to_operator' THEN COALESCE(p.amount,0)
      ELSE 0
    END),0) AS profit_kzt
  FROM payments p
  JOIN applications a ON a.id = p.application_id
  WHERE a.start_date BETWEEN ? AND ?
");
$profitFactSt->execute([$monthStart, $monthEnd]);
$profitFactKzt = (float)($profitFactSt->fetchColumn() ?? 0.0);

/** helper: курс к KZT */
function fx_to_kzt_for_app(string $currency, float $fx): float
{
  $currency = strtoupper(trim($currency));
  if ($currency === 'KZT') return 1.0;
  if ($fx <= 0) return 0.0;
  return $fx;
}

/** helper: суммы дедлайна */
function deadline_amounts(array $row): array
{
  $currency = (string)($row['currency'] ?? 'KZT');
  if (!in_array($currency, ['KZT','USD','EUR'], true)) $currency = 'KZT';

  $fx = (float)($row['fx_rate_to_kzt'] ?? 1);
  $fxKzt = fx_to_kzt_for_app($currency, $fx);

  $percent = (float)($row['percent'] ?? 0);
  if ($percent <= 0) return [0.0, $fxKzt, 0.0];

  $dir = (string)($row['direction'] ?? '');
  $baseCur = ($dir === 'tourist_to_agent')
    ? (float)($row['tourist_price_amount'] ?? 0)
    : (float)($row['operator_price_amount'] ?? 0);

  $amtCur = round($baseCur * ($percent / 100.0), 2);
  $amtKzt = round($amtCur * $fxKzt, 2);
  return [$amtCur, $fxKzt, $amtKzt];
}
?>

<style>
  :root{
    --w-strong: 750;
    --w-normal: 600;
    --accent-red: rgba(239,68,68,.95);
    --accent-red-soft: rgba(239,68,68,.12);
  }

  /* веса шрифтов как просили */
  .kpi-card .t,
  .kpi-card .s,
  .profit-box .lbl,
  .profit-sub,
  .section-title,
  .muted,
  .row .fio,
  .row .date,
  .deadline-table td,
  .deadline-table th{
    font-weight: var(--w-normal);
  }
  .row .no,
  .deadline-no,
  .deadline-paycell .kzt,
  .kpi-card .v,
  .profit-box .val{
    font-weight: var(--w-strong);
  }

  .grid{ display:grid; grid-template-columns: 1fr; gap:14px; margin-top:14px; }
  @media (min-width: 1100px){ .grid{ grid-template-columns: 1fr 1fr; } }

  .cards{ display:grid; gap:12px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top:14px; }
  @media (min-width: 980px){ .cards{ grid-template-columns: repeat(4, minmax(0, 1fr)); } }

  .kpi-card{
    border:1px solid rgba(226,232,240,.92);
    border-radius:16px;
    background: rgba(255,255,255,.72);
    padding:12px;
    cursor:pointer;
    user-select:none;
    transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
    min-width:0;
  }
  .kpi-card:hover{
    transform: translateY(-1px);
    box-shadow: 0 14px 30px rgba(2,8,23,.06);
    border-color: rgba(14,165,233,.25);
  }
  .kpi-card .t{ color: var(--muted); font-size:12px; }
  .kpi-card .v{ font-size:18px; color:#0f172a; margin-top:6px; }
  .kpi-card .s{ color: var(--muted); font-size:12px; margin-top:6px; }

  .kpi-card.profit-wide{ grid-column: 1 / -1; cursor:pointer; }
  .profit-rows{ display:grid; grid-template-columns: 1fr; gap:10px; margin-top:10px; }
  @media (min-width: 980px){ .profit-rows{ grid-template-columns: 1fr 1fr 1fr; } }
  .profit-box{
    border:1px solid rgba(226,232,240,.85);
    border-radius:14px;
    background: rgba(255,255,255,.78);
    padding:12px;
    min-width:0;
  }
  .profit-box .lbl{ color: var(--muted); font-size:12px; }
  .profit-box .val{ color:#0f172a; margin-top:6px; font-size:18px; white-space:nowrap; }
  .profit-sub{ color: var(--muted); font-size:12px; margin-top:10px; }

  .section-title{
    margin:0;
    color:#0f172a;
    font-size:15px;
    display:flex;
    align-items:center;
    gap:10px;
  }
  /* ИКОНКА: красная для дедлайнов, синяя для остального */
  .section-title::before{
    content:"";
    width:9px; height:9px;
    border-radius:999px;
    background: rgba(14,165,233,.92);
    box-shadow: 0 8px 18px rgba(14,165,233,.18);
    display:inline-block;
  }
  .section-title.deadline::before{
    background: var(--accent-red);
    box-shadow: 0 8px 18px rgba(239,68,68,.22);
  }

  .section{
    padding:14px;
    border-radius:16px;
    border:1px solid rgba(226,232,240,.92);
    background: rgba(255,255,255,.72);
  }
  .muted{ color:var(--muted); font-size:12px; }

  .list{ display:flex; flex-direction:column; gap:8px; margin-top:10px; }
  .row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    padding:10px 12px;
    border-radius:14px;
    border:1px solid rgba(226,232,240,.85);
    background: rgba(255,255,255,.78);
    cursor:pointer;
    transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
  }
  .row:hover{
    transform: translateY(-1px);
    box-shadow: 0 12px 26px rgba(2,8,23,.06);
    border-color: rgba(14,165,233,.25);
  }
  .row .left{ display:flex; align-items:center; gap:10px; min-width:0; }
  .row .no{ white-space:nowrap; }
  .row .fio{
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    max-width: 52vw;
  }
  .row .date{ color:#0f172a; white-space:nowrap; }
  @media (min-width: 980px){ .row .fio{ max-width: 420px; } }

  /* дедлайны: теперь вертикально (оператор под туристом) */
  .deadline-stack{
    display:flex;
    flex-direction:column;
    gap:14px;
    margin-top:14px;
  }

  .deadline-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    margin-top:8px;
  }
  .deadline-hint{
    color: var(--muted);
    font-size: 12px;
    font-weight: var(--w-normal);
  }
  .deadline-hint b{ font-weight: var(--w-strong); }

  .deadline-wrap{ margin-top:10px; overflow:auto; }
  .deadline-table{ min-width: 680px; }
  .deadline-table th, .deadline-table td{ vertical-align:top; }
  .deadline-table td{ padding-top:7px; padding-bottom:7px; }
  .deadline-tr{ cursor:pointer; transition: background .12s ease; }
  .deadline-tr:hover{ background: rgba(239,68,68,.06); }

  .deadline-paycell{ white-space:nowrap; }
  .deadline-paycell .kzt{ display:block; color:#0f172a; font-size:13px; line-height:1.15; }
  .deadline-paycell .meta{
    display:block;
    color:var(--muted);
    font-size:12px;
    margin-top:1px;
    line-height:1.15;
  }
  .deadline-paycell .meta b{ font-weight: var(--w-strong); }
</style>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div>
    <h1 class="h1" style="margin-bottom:6px;">Обзор</h1>
    <div class="badge">Метрики, напоминания, ближайшие события</div>
  </div>
</div>

<!-- Доход агента -->
<div class="cards">
  <div class="kpi-card profit-wide" onclick="window.location.href='/manager/apps.php'" style="border-color: rgba(34,197,94,.30);">
    <div class="t">Доход агента (месяц)</div>
    <div class="profit-rows">
      <div class="profit-box">
        <div class="lbl">Плановый доход</div>
        <div class="val"><?= number_format($profitPlanKzt, 0, '.', ' ') ?> KZT</div>
      </div>
      <div class="profit-box">
        <div class="lbl">Фактический доход</div>
        <div class="val"><?= number_format($profitFactKzt, 0, '.', ' ') ?> KZT</div>
      </div>
      <div class="profit-box">
        <div class="lbl">Заявок в месяце</div>
        <div class="val"><?= (int)$cntThisMonth ?></div>
      </div>
    </div>
    <div class="profit-sub">План/факт рассчитаны по заявкам с датой вылета в текущем месяце</div>
  </div>
</div>

<!-- KPI -->
<div class="cards" style="margin-top:12px;">
  <div class="kpi-card" onclick="window.location.href='/manager/tourists.php'">
    <div class="t">Туристы</div>
    <div class="v"><?= $cntTourists ?></div>
    <div class="s">в базе</div>
  </div>

  <div class="kpi-card" onclick="window.location.href='/manager/operators.php'">
    <div class="t">Туроператоры</div>
    <div class="v"><?= $cntOps ?></div>
    <div class="s">в справочнике</div>
  </div>

  <div class="kpi-card" onclick="window.location.href='/manager/apps.php'">
    <div class="t">Заявки</div>
    <div class="v"><?= $cntApps ?></div>
    <div class="s">всего</div>
  </div>

  <div class="kpi-card" onclick="window.location.href='/manager/apps.php?status=in_work'">
    <div class="t">В работе</div>
    <div class="v"><?= $cntInWork ?></div>
    <div class="s">статус</div>
  </div>
</div>

<div class="cards" style="margin-top:12px;">
  <div class="kpi-card" onclick="window.location.href='/manager/apps.php?status=confirmed'">
    <div class="t">Подтверждено</div>
    <div class="v"><?= $cntConfirmed ?></div>
    <div class="s">статус</div>
  </div>
  <div class="kpi-card" onclick="window.location.href='/manager/apps.php?status=docs_issued'">
    <div class="t">Документы</div>
    <div class="v"><?= $cntDocsIssued ?></div>
    <div class="s">выданы</div>
  </div>
  <div class="kpi-card" onclick="window.location.href='/manager/apps.php?status=cancelled'">
    <div class="t">Отменено</div>
    <div class="v"><?= $cntCancelled ?></div>
    <div class="s">статус</div>
  </div>
</div>

<!-- Вылеты/возвраты -->
<div class="grid">
  <div class="section">
    <div class="section-title">Ближайшие вылеты (сегодня + 3 дня)</div>
    <?php if (!$flightRows): ?>
      <div class="muted" style="margin-top:10px;">Нет вылетов в ближайшие дни.</div>
    <?php else: ?>
      <div class="list">
        <?php foreach ($flightRows as $r): ?>
          <?php
            $appId = (int)$r['id'];
            $appNo = (int)($r['app_number'] ?? 0);
            if ($appNo <= 0) $appNo = $appId;

            $fio = fio_short((string)($r['customer_fio'] ?? ''));
            $date = fmt_dmy((string)($r['start_date'] ?? ''));
          ?>
          <div class="row" onclick="window.location.href='/manager/app_view.php?id=<?= $appId ?>';">
            <div class="left">
              <div class="no">№<?= (int)$appNo ?></div>
              <div class="fio"><?= h($fio) ?></div>
            </div>
            <div class="date"><?= h($date) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="section-title">Туристы возвращаются (сегодня + 3 дня)</div>
    <?php if (!$returnRows): ?>
      <div class="muted" style="margin-top:10px;">Нет возвратов в ближайшие дни.</div>
    <?php else: ?>
      <div class="list">
        <?php foreach ($returnRows as $r): ?>
          <?php
            $appId = (int)$r['id'];
            $appNo = (int)($r['app_number'] ?? 0);
            if ($appNo <= 0) $appNo = $appId;

            $fio = fio_short((string)($r['customer_fio'] ?? ''));
            $date = fmt_dmy((string)($r['end_date'] ?? ''));
          ?>
          <div class="row" onclick="window.location.href='/manager/app_view.php?id=<?= $appId ?>';">
            <div class="left">
              <div class="no">№<?= (int)$appNo ?></div>
              <div class="fio"><?= h($fio) ?></div>
            </div>
            <div class="date"><?= h($date) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Дедлайны (оператор под туристом) -->
<div class="deadline-stack">
  <div class="section">
    <div class="section-title deadline">Дедлайны от туриста (2 дня)</div>

    <div class="deadline-head">
      <div class="deadline-hint">
        Переход в оплаты: клик по строке.
      </div>
      <div class="deadline-hint">
        Период: <b><?= h(fmt_dmy($todayYmd)) ?></b> — <b><?= h(fmt_dmy($plus2)) ?></b>
      </div>
    </div>

    <?php if (!$deadlineTouristRows): ?>
      <div class="muted" style="margin-top:10px;">Нет дедлайнов от туристов на ближайшие 2 дня.</div>
    <?php else: ?>
      <div class="deadline-wrap">
        <table class="table compact deadline-table">
          <thead>
            <tr>
              <th style="width:90px;">Заявка</th>
              <th style="width:160px;">Турист</th>
              <th style="width:110px;">Дата</th>
              <th style="width:260px;">Сумма</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($deadlineTouristRows as $d): ?>
              <?php
                $appId = (int)($d['application_id'] ?? 0);
                $appNo = (int)($d['app_number'] ?? 0);
                if ($appNo <= 0) $appNo = $appId;

                $fio = fio_short((string)($d['customer_fio'] ?? ''));
                $date = fmt_dmy((string)($d['due_date'] ?? ''));
                [$amtCur, $fx, $amtKzt] = deadline_amounts($d);

                $cur = (string)($d['currency'] ?? 'KZT');
                if (!in_array($cur, ['KZT','USD','EUR'], true)) $cur = 'KZT';

                $href = '/manager/payments.php?app_id=' . $appId;
                $pct = (float)($d['percent'] ?? 0);
              ?>
              <tr class="deadline-tr" onclick="window.location.href='<?= h($href) ?>';">
                <td class="nowrap deadline-no">№<?= (int)$appNo ?></td>
                <td><?= h($fio) ?></td>
                <td class="nowrap"><?= h($date) ?></td>
                <td class="deadline-paycell">
                  <span class="kzt"><?= number_format((float)$amtKzt, 2, '.', ' ') ?> KZT</span>
                  <span class="meta">
                    <b><?= number_format((float)$pct, 0, '.', ' ') ?>%</b>
                    · <?= number_format((float)$amtCur, 2, '.', ' ') ?> <?= h($cur) ?>
                    · <?= number_format((float)$fx, 6, '.', ' ') ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="section">
    <div class="section-title deadline">Дедлайны туроператору (2 дня)</div>

    <?php if (!$deadlineOperatorRows): ?>
      <div class="muted" style="margin-top:10px;">Нет дедлайнов туроператорам на ближайшие 2 дня.</div>
    <?php else: ?>
      <div class="deadline-wrap">
        <table class="table compact deadline-table">
          <thead>
            <tr>
              <th style="width:90px;">Заявка</th>
              <th style="width:160px;">Турист</th>
              <th style="width:110px;">Дата</th>
              <th style="width:260px;">Сумма</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($deadlineOperatorRows as $d): ?>
              <?php
                $appId = (int)($d['application_id'] ?? 0);
                $appNo = (int)($d['app_number'] ?? 0);
                if ($appNo <= 0) $appNo = $appId;

                $fio = fio_short((string)($d['customer_fio'] ?? ''));
                $date = fmt_dmy((string)($d['due_date'] ?? ''));
                [$amtCur, $fx, $amtKzt] = deadline_amounts($d);

                $cur = (string)($d['currency'] ?? 'KZT');
                if (!in_array($cur, ['KZT','USD','EUR'], true)) $cur = 'KZT';

                $href = '/manager/payments.php?app_id=' . $appId;
                $pct = (float)($d['percent'] ?? 0);
              ?>
              <tr class="deadline-tr" onclick="window.location.href='<?= h($href) ?>';">
                <td class="nowrap deadline-no">№<?= (int)$appNo ?></td>
                <td><?= h($fio) ?></td>
                <td class="nowrap"><?= h($date) ?></td>
                <td class="deadline-paycell">
                  <span class="kzt"><?= number_format((float)$amtKzt, 2, '.', ' ') ?> KZT</span>
                  <span class="meta">
                    <b><?= number_format((float)$pct, 0, '.', ' ') ?>%</b>
                    · <?= number_format((float)$amtCur, 2, '.', ' ') ?> <?= h($cur) ?>
                    · <?= number_format((float)$fx, 6, '.', ' ') ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>