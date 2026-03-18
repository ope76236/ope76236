<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

require_role('manager');

$title = 'Туроператоры';
$pdo = db();

$error = null;

// удаление (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = post('_action', '');
    if ($action === 'delete_operator') {
      $id = (int)post('id', '0');
      if ($id <= 0) throw new RuntimeException('Некорректный ID.');

      $stDel = $pdo->prepare("DELETE FROM tour_operators WHERE id=? LIMIT 1");
      $stDel->execute([$id]);

      header('Location: /manager/operators.php');
      exit;
    }
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$q = trim((string)($_GET['q'] ?? ''));
$qLike = '%' . $q . '%';

$sql = "
  SELECT id,
         name,
         bin,
         agency_contract_no,
         agency_contract_expiry_date,
         phone,
         email,
         created_at
  FROM tour_operators
";

if ($q !== '') {
  $st = $pdo->prepare($sql . "
    WHERE name LIKE ? OR bin LIKE ? OR phone LIKE ? OR email LIKE ? OR agency_contract_no LIKE ?
    ORDER BY id DESC
    LIMIT 300
  ");
  $st->execute([$qLike, $qLike, $qLike, $qLike, $qLike]);
  $rows = $st->fetchAll();
} else {
  $rows = $pdo->query($sql . "
    ORDER BY id DESC
    LIMIT 300
  ")->fetchAll();
}

require __DIR__ . '/_layout_top.php';
?>

<style>
  :root{
    --w-strong: 750;
    --w-normal: 600;
  }

  .muted{ color: var(--muted); font-size:12px; font-weight: var(--w-normal); }
  .nowrap{ white-space:nowrap; }
  .ellipsis{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

  .t-right{ text-align:right; }

  /* table wrapper like apps/tourists */
  .ops-wrap{
    margin-top:12px;
    overflow:auto;
    border-radius:16px;
  }
  .ops-table{
    table-layout:fixed;
    width:100%;
    min-width: 980px;
  }
  .ops-table th, .ops-table td{ vertical-align:middle; }
  .table.ops-table th, .table.ops-table td{ padding:8px 8px; }
  .table.ops-table th{ font-size:12px; font-weight: var(--w-normal); }
  .table.ops-table td{ font-size:13px; font-weight: var(--w-normal); }

  .opcell{ display:flex; flex-direction:column; gap:4px; min-width:0; }
  .opcell .name{ font-weight: var(--w-normal); color:#0f172a; }
  .opcell .sub{ color:var(--muted); font-size:12px; font-weight: var(--w-normal); }

  /* buttons */
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

  /* mobile cards */
  .ops-cards{ display:none; margin-top:12px; }
  .op-card{
    border:1px solid rgba(226,232,240,.92);
    border-radius:16px;
    background: rgba(255,255,255,.72);
    padding:12px;
    box-shadow: var(--shadow);
  }
  .op-card + .op-card{ margin-top:12px; }
  .op-head{ display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
  .op-title{ font-weight: var(--w-strong); }
  .op-sub{ margin-top:6px; color:var(--muted); font-size:12px; font-weight: var(--w-normal); }
  .op-grid{
    display:grid;
    grid-template-columns: 1fr 1fr;
    gap:10px;
    margin-top:10px;
  }
  @media (max-width: 420px){
    .op-grid{ grid-template-columns: 1fr; }
  }
  .box{
    border:1px solid rgba(226,232,240,.85);
    border-radius:14px;
    background: rgba(255,255,255,.78);
    padding:10px;
    min-width:0;
  }
  .box .ttl{ color:var(--muted); font-size:12px; font-weight: var(--w-normal); }
  .box .val{ margin-top:6px; font-size:13px; font-weight: var(--w-normal); }
  .op-actions{ display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }

  @media (max-width: 980px){
    .ops-wrap{ display:none; }
    .ops-cards{ display:block; }
  }
</style>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div>
    <h1 class="h1" style="margin-bottom:6px;">Туроператоры</h1>
    <div class="badge">Справочник: договоры, БИН, контакты</div>
  </div>
  <a class="btn success" href="/manager/operator_create.php">+ Добавить туроператора</a>
</div>

<?php if ($error): ?>
  <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="toolbar">
  <form class="search" method="get" action="/manager/operators.php">
    <div class="input" style="flex:1; margin:0;">
      <label>Поиск (название, БИН, договор №, телефон, email)</label>
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Например: БИН, название, № договора, mail@...">
    </div>
    <button class="btn btn-sm btn-primary" type="submit">Найти</button>
    <?php if ($q !== ''): ?>
      <a class="btn btn-sm" href="/manager/operators.php">Сброс</a>
    <?php endif; ?>
  </form>
</div>

<!-- DESKTOP: table -->
<div class="ops-wrap">
  <table class="table ops-table">
    <thead>
      <tr>
        <th>Туроператор</th>
        <th style="width:160px;">БИН</th>
        <th style="width:190px;">Договор №</th>
        <th style="width:180px;">Срок действия</th>
        <th style="width:150px;" class="t-right">Действия</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="muted">Пока нет туроператоров. Нажмите “Добавить туроператора”.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php
            $opId = (int)$r['id'];
            $href = '/manager/operator_view.php?id=' . $opId;

            $name = (string)($r['name'] ?? '');
            $bin = (string)($r['bin'] ?? '');
            $contractNo = (string)($r['agency_contract_no'] ?? '');
            $contractExpiry = (string)($r['agency_contract_expiry_date'] ?? '');

            $phone = (string)($r['phone'] ?? '');
            $email = (string)($r['email'] ?? '');

            $expiryText = $contractExpiry !== '' ? substr($contractExpiry, 0, 10) : '—';
          ?>
          <tr onclick="window.location.href='<?= h($href) ?>';" style="cursor:pointer;">
            <td>
              <div class="opcell">
                <div class="name ellipsis" title="<?= h($name) ?>"><?= h($name !== '' ? $name : '—') ?></div>
                <div class="sub ellipsis" title="<?= h(trim($phone . ' ' . $email)) ?>">
                  <?= h($phone) ?><?= ($phone !== '' && $email !== '' ? ' · ' : '') ?><?= h($email) ?>
                </div>
              </div>
            </td>

            <td class="nowrap"><?= h($bin !== '' ? $bin : '—') ?></td>

            <td class="nowrap"><?= h($contractNo !== '' ? $contractNo : '—') ?></td>

            <td class="nowrap muted"><?= h($expiryText) ?></td>

            <td class="t-right nowrap" onclick="event.stopPropagation();">
              <div style="display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap;">
                <a class="btn btn-sm btn-primary" href="<?= h($href) ?>">Открыть</a>

                <form method="post" style="margin:0;" onsubmit="return confirm('Удалить туроператора? Действие необратимо.');">
                  <input type="hidden" name="_action" value="delete_operator">
                  <input type="hidden" name="id" value="<?= $opId ?>">
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

<!-- MOBILE: cards -->
<div class="ops-cards">
  <?php if (!$rows): ?>
    <div class="muted">Туроператоров не найдено.</div>
  <?php else: ?>
    <?php foreach ($rows as $r): ?>
      <?php
        $opId = (int)$r['id'];
        $href = '/manager/operator_view.php?id=' . $opId;

        $name = (string)($r['name'] ?? '');
        $bin = (string)($r['bin'] ?? '');
        $contractNo = (string)($r['agency_contract_no'] ?? '');
        $contractExpiry = (string)($r['agency_contract_expiry_date'] ?? '');
        $expiryText = $contractExpiry !== '' ? substr($contractExpiry, 0, 10) : '—';

        $phone = (string)($r['phone'] ?? '');
        $email = (string)($r['email'] ?? '');
      ?>

      <div class="op-card" onclick="window.location.href='<?= h($href) ?>';" style="cursor:pointer;">
        <div class="op-head">
          <div style="min-width:0;">
            <div class="op-title ellipsis" title="<?= h($name) ?>"><?= h($name !== '' ? $name : '—') ?></div>
            <div class="op-sub ellipsis" title="<?= h(trim($phone . ' ' . $email)) ?>">
              <?= h($phone) ?><?= ($phone !== '' && $email !== '' ? ' · ' : '') ?><?= h($email) ?>
            </div>
          </div>
        </div>

        <div class="op-grid">
          <div class="box">
            <div class="ttl">БИН</div>
            <div class="val"><?= h($bin !== '' ? $bin : '—') ?></div>
          </div>
          <div class="box">
            <div class="ttl">Договор</div>
            <div class="val">
              <?= h($contractNo !== '' ? $contractNo : '—') ?><br>
              <span class="muted">до <?= h($expiryText) ?></span>
            </div>
          </div>
        </div>

        <div class="op-actions" onclick="event.stopPropagation();">
          <a class="btn btn-sm btn-primary" href="<?= h($href) ?>">Открыть</a>
          <form method="post" style="margin:0;" onsubmit="return confirm('Удалить туроператора? Действие необратимо.');">
            <input type="hidden" name="_action" value="delete_operator">
            <input type="hidden" name="id" value="<?= $opId ?>">
            <button class="btn btn-sm btn-danger" type="submit">Удалить</button>
          </form>
        </div>
      </div>

    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>