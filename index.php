<?php
declare(strict_types=1);

$title = 'Мой тур';
require __DIR__ . '/_layout_top.php';

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';

$pdo = db();

$u = current_user();
$uid = (int)($u['id'] ?? 0);

// Берём ближайшую не отменённую заявку по датам (или последнюю созданную)
$st = $pdo->prepare("
  SELECT a.*, o.name AS operator_name
  FROM applications a
  JOIN application_tourists at ON at.application_id = a.id AND at.tourist_user_id = ?
  LEFT JOIN tour_operators o ON o.id = a.operator_id
  WHERE a.status <> 'cancelled'
  ORDER BY (a.start_date IS NULL) ASC, a.start_date ASC, a.id DESC
  LIMIT 1
");
$st->execute([$uid]);
$app = $st->fetch();

// Кол-во туристов в заявке
$cntTourists = 0;
if ($app) {
  $stCnt = $pdo->prepare("SELECT COUNT(*) FROM application_tourists WHERE application_id=?");
  $stCnt->execute([(int)$app['id']]);
  $cntTourists = (int)$stCnt->fetchColumn();
}

$statusLabel = [
  'draft' => 'черновик',
  'in_work' => 'в работе',
  'confirmed' => 'подтверждено',
  'docs_issued' => 'документы выданы',
  'completed' => 'завершено',
  'cancelled' => 'отменено',
];

function fmt_dmy2(?string $ymd): string {
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '—';
  $ts = strtotime($ymd);
  if ($ts === false) return $ymd;
  return date('d.m.Y', $ts);
}
?>

<style>
  .cards{ display:grid; gap:12px; grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top:14px; }
  @media (min-width: 1100px){ .cards{ grid-template-columns: repeat(4, minmax(0, 1fr)); } }
  .kpi-card{
    border:1px solid rgba(226,232,240,.85);
    border-radius:14px;
    background: rgba(255,255,255,.55);
    padding:12px;
  }
  .kpi-card .t{ color: var(--muted); font-size:12px; font-weight:900; }
  .kpi-card .v{ font-size:16px; font-weight:1000; color:#0f172a; margin-top:6px; line-height:1.25; }
  .kpi-card .s{ color: var(--muted); font-size:12px; margin-top:6px; }

  .pill{
    display:inline-flex; align-items:center; justify-content:center;
    padding:6px 10px; border-radius:999px;
    border:1px solid rgba(226,232,240,.85);
    background: rgba(255,255,255,.72);
    font-weight:1000;
    font-size:12px;
    white-space:nowrap;
  }
  .pill.ok{ border-color: rgba(34,197,94,.45); background: rgba(34,197,94,.10); color: rgba(22,163,74,1); }
  .pill.warn{ border-color: rgba(245,158,11,.45); background: rgba(245,158,11,.10); color: rgba(180,83,9,1); }
  .pill.muted{ color: var(--muted); }
</style>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div>
    <h1 class="h1" style="margin-bottom:6px;">Мой тур</h1>
    <div class="badge">Здесь отображается ближайшая заявка. Полный список — в разделе “Мои заявки”.</div>
  </div>
</div>

<?php if (!$app): ?>
  <div class="badge" style="margin-top:14px;">
    У вас пока нет заявок. Обратитесь к менеджеру.
  </div>
<?php else: ?>
  <?php
    $appId = (int)$app['id'];
    $appNo = (int)($app['app_number'] ?? 0);
    if ($appNo <= 0) $appNo = $appId;

    $country = (string)($app['country'] ?? $app['destination'] ?? '');
    $hotel = (string)($app['hotel_name'] ?? '');
    $dateRange = fmt_dmy2((string)$app['start_date']) . ' — ' . fmt_dmy2((string)$app['end_date']);

    $statusKey = (string)($app['status'] ?? '');
    $statusText = $statusLabel[$statusKey] ?? $statusKey;

    $pillClass = 'muted';
    if (in_array($statusKey, ['confirmed','docs_issued','completed'], true)) $pillClass = 'ok';
    if (in_array($statusKey, ['draft','in_work'], true)) $pillClass = 'warn';
  ?>

  <div class="cards">
    <div class="kpi-card">
      <div class="t">Заявка</div>
      <div class="v">№<?= $appNo ?></div>
      <div class="s"><?= h($country ?: '—') ?><?= $hotel !== '' ? ' · ' . h($hotel) : '' ?></div>
    </div>

    <div class="kpi-card">
      <div class="t">Даты тура</div>
      <div class="v"><?= h($dateRange) ?></div>
    </div>

    <div class="kpi-card">
      <div class="t">Туристы</div>
      <div class="v"><?= (int)$cntTourists ?></div>
      <div class="s">в составе заявки</div>
    </div>

    <div class="kpi-card">
      <div class="t">Статус</div>
      <div class="v"><span class="pill <?= h($pillClass) ?>"><?= h($statusText) ?></span></div>
      <div class="s">Туроператор: <?= h((string)($app['operator_name'] ?? '—')) ?></div>
    </div>
  </div>

  <div class="toolbar" style="margin-top:14px;">
    <div class="badge">Открыть детали</div>
    <a class="btn primary" href="/tourist/tour_view.php?id=<?= $appId ?>">Перейти к заявке</a>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/_layout_bottom.php'; ?>