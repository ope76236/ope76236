<?php
declare(strict_types=1);

$title = 'Мои заявки';
require __DIR__ . '/_layout_top.php';

require_once __DIR__ . '/../app/db.php';
$pdo = db();

$u = current_user();
$uid = (int)($u['id'] ?? 0);

function fmt_dmy_short(?string $ymd): string {
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '—';
  $ts = strtotime($ymd);
  if ($ts === false) return $ymd;
  return date('d.m.Y', $ts);
}

function money_fmt(float $n): string {
  return number_format($n, 2, '.', ' ');
}

/** Русские подписи статусов */
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

/** Цвет статуса (chip) */
function status_chip_class(string $status): string {
  $s = trim($status);

  if (in_array($s, ['completed', 'paid', 'docs_issued'], true)) return 'chip chip-ok';
  if (in_array($s, ['cancelled'], true)) return 'chip chip-bad';
  if (in_array($s, ['confirmed', 'in_work', 'new'], true)) return 'chip chip-warn';
  if (in_array($s, ['draft'], true)) return 'chip chip-muted';

  return 'chip chip-muted';
}

$st = $pdo->prepare("
  SELECT
    a.id,
    a.title,
    a.destination,
    a.start_date,
    a.end_date,
    a.status,

    a.currency,
    a.fx_rate_to_kzt,
    a.tourist_price_amount,

    o.name AS operator_name,

    (
      SELECT COUNT(*)
      FROM application_tourists at2
      WHERE at2.application_id = a.id
    ) AS tourists_count,

    (
      SELECT COALESCE(SUM(p.amount / NULLIF(p.fx_rate_to_kzt,0)), 0)
      FROM payments p
      WHERE p.application_id = a.id
        AND p.direction = 'tourist_to_agent'
        AND p.status = 'paid'
    ) AS paid_tourist_cur_at_pay

  FROM applications a
  JOIN application_tourists at ON at.application_id = a.id AND at.tourist_user_id = ?
  LEFT JOIN tour_operators o ON o.id = a.operator_id
  ORDER BY a.id DESC
  LIMIT 200
");
$st->execute([$uid]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
  /* Chips */
  .chip{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:7px 12px;
    border-radius:999px;
    border:1px solid rgba(226,232,240,.90);
    background: rgba(255,255,255,.78);
    font-size:13px;
    font-weight: var(--w-strong);
    line-height:1;
    white-space:nowrap;

    /* IMPORTANT: chip must never overflow card */
    max-width: 100%;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  .chip-ok{
    color:#16a34a;
    border-color: rgba(34,197,94,.40);
    background: rgba(34,197,94,.12);
  }
  .chip-warn{
    color:#92400e;
    border-color: rgba(245,158,11,.42);
    background: rgba(245,158,11,.14);
  }
  .chip-bad{
    color:#ef4444;
    border-color: rgba(239,68,68,.42);
    background: rgba(239,68,68,.12);
  }
  .chip-muted{
    color: var(--muted);
    border-color: rgba(226,232,240,.90);
    background: rgba(255,255,255,.78);
    font-weight: var(--w-normal);
  }

  /* One-line application row */
  .app-row{
    display:grid;

    /* FIX: last column was too wide on some screens -> status went out of card.
       We make last column flexible but capped.
    */
    grid-template-columns:
      minmax(320px, 1fr)   /* Тур */
      180px                /* Даты */
      110px                /* Туристы */
      260px                /* Долг */
      minmax(160px, 220px) /* Статус */

    ;
    gap:12px;
    align-items:center;

    padding:14px;
    border-radius:16px;
    border:1px solid rgba(226,232,240,.90);
    background: rgba(255,255,255,.72);
    transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease, background .12s ease;
    color:inherit;
    text-decoration:none;

    /* extra safety */
    max-width:100%;
    overflow:hidden;
  }
  .app-row:hover{
    transform: translateY(-1px);
    box-shadow: 0 12px 26px rgba(2,8,23,.08);
    border-color: rgba(14,165,233,.35);
  }
  .app-row:focus{
    outline:none;
    border-color: rgba(14,165,233,.55);
    box-shadow: 0 0 0 4px rgba(14,165,233,.14);
  }

  .col{ min-width:0; }
  .ttl{
    font-weight: var(--w-strong);
    color:#0f172a;
  }
  .sub{ margin-top:4px; }
  .meta{ color: var(--muted); font-size:12px; font-weight: var(--w-normal); }
  .right{ justify-self:end; text-align:right; min-width:0; }

  .danger{ color:#ef4444; font-weight: var(--w-strong); }
  .oktxt{ color:#16a34a; font-weight: var(--w-strong); }

  @media (max-width: 980px){
    .app-row{ grid-template-columns: 1fr; align-items:start; }
    .right{ justify-self:start; text-align:left; }
  }
</style>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div style="min-width:0;">
    <h1 class="h1" style="margin-bottom:6px;">Мои заявки</h1>
    <div class="badge">Все туры и заявки, где вы добавлены в состав</div>
  </div>
</div>

<div style="margin-top:14px; display:flex; flex-direction:column; gap:12px;">
  <?php if (!$rows): ?>
    <div class="badge">Заявок нет. Обратитесь к менеджеру.</div>
  <?php else: ?>
    <?php foreach ($rows as $r): ?>
      <?php
        $id = (int)$r['id'];

        $titleTxt = trim((string)($r['title'] ?? ''));
        $tourTitle = $titleTxt !== '' ? $titleTxt : ('Заявка №' . $id);

        $destination = trim((string)($r['destination'] ?? ''));
        $operatorName = trim((string)($r['operator_name'] ?? ''));

        $dateFrom = fmt_dmy_short((string)($r['start_date'] ?? ''));
        $dateTo   = fmt_dmy_short((string)($r['end_date'] ?? ''));

        $touristsCount = (int)($r['tourists_count'] ?? 0);

        $appCurrency = strtoupper(trim((string)($r['currency'] ?? 'KZT')));
        if (!in_array($appCurrency, ['KZT','USD','EUR'], true)) $appCurrency = 'KZT';

        $touristPriceCur = (float)($r['tourist_price_amount'] ?? 0);
        $paidCurAtPay = (float)($r['paid_tourist_cur_at_pay'] ?? 0);

        // ДОЛГ В ВАЛЮТЕ ТУРА (как вы просите)
        $debtCur = round($touristPriceCur - $paidCurAtPay, 2);
        $debtIsPaid = !($debtCur > 0.009);

        $stRaw = (string)($r['status'] ?? '');
        $stTxt = status_ru($stRaw);
        $stChip = status_chip_class($stRaw);

        $tourSub = $destination !== '' ? $destination : '';
        $opSub = $operatorName !== '' ? ('Туроператор: ' . $operatorName) : '';
      ?>

      <a class="app-row" href="/tourist/tour_view.php?id=<?= $id ?>">
        <!-- Тур -->
        <div class="col">
          <div class="ttl ellipsis" title="<?= h($tourTitle) ?>"><?= h($tourTitle) ?></div>
          <?php if ($tourSub !== ''): ?>
            <div class="meta ellipsis sub" title="<?= h($tourSub) ?>"><?= h($tourSub) ?></div>
          <?php endif; ?>
          <?php if ($opSub !== ''): ?>
            <div class="meta ellipsis" title="<?= h($opSub) ?>"><?= h($opSub) ?></div>
          <?php endif; ?>
        </div>

        <!-- Даты -->
        <div class="col">
          <div class="meta">Даты тура</div>
          <div class="ttl nowrap"><?= h($dateFrom) ?> — <?= h($dateTo) ?></div>
        </div>

        <!-- Туристы -->
        <div class="col" style="text-align:center;">
          <div class="meta">Туристы</div>
          <div class="ttl"><?= (int)$touristsCount ?></div>
        </div>

        <!-- Долг (только валюта тура) -->
        <div class="col">
          <div class="meta">Долг по оплате</div>
          <?php if ($debtIsPaid): ?>
            <div class="oktxt">оплачено</div>
          <?php else: ?>
            <div class="danger nowrap"><?= h(money_fmt($debtCur)) ?> <?= h($appCurrency) ?></div>
          <?php endif; ?>
        </div>

        <!-- Статус -->
        <div class="col right">
          <span class="<?= h($stChip) ?>" title="<?= h($stRaw) ?>"><?= h($stTxt) ?></span>
        </div>
      </a>

    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>