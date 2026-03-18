<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('manager');

$title = 'Туристы';
$pdo = db();

$error = null;

/**
 * ВАЖНО: любые редиректы/headers должны быть ДО подключения layout,
 * иначе будет "headers already sent".
 */

// удаление (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = post('_action', '');
    if ($action === 'delete_tourist') {
      $id = (int)post('id', '0');
      if ($id <= 0) throw new RuntimeException('Некорректный ID.');

      $stDel = $pdo->prepare("DELETE FROM users WHERE id=? AND role='tourist' LIMIT 1");
      $stDel->execute([$id]);

      redirect('/manager/tourists.php'); // вместо header('Location: ...')
    }
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$q = trim((string)($_GET['q'] ?? ''));
$qLike = '%' . $q . '%';

if ($q !== '') {
  $st = $pdo->prepare("
    SELECT u.id, u.email, u.name, u.phone, u.active, u.created_at,
           t.iin, t.last_name, t.first_name, t.middle_name
    FROM users u
    LEFT JOIN tourists t ON t.user_id = u.id
    WHERE u.role='tourist'
      AND (
        u.email LIKE ?
        OR u.name LIKE ?
        OR u.phone LIKE ?
        OR t.iin LIKE ?
        OR t.last_name LIKE ?
        OR t.first_name LIKE ?
        OR t.middle_name LIKE ?
      )
    ORDER BY u.id DESC
    LIMIT 300
  ");
  $st->execute([$qLike,$qLike,$qLike,$qLike,$qLike,$qLike,$qLike]);
  $rows = $st->fetchAll();
} else {
  $rows = $pdo->query("
    SELECT u.id, u.email, u.name, u.phone, u.active, u.created_at,
           t.iin, t.last_name, t.first_name, t.middle_name
    FROM users u
    LEFT JOIN tourists t ON t.user_id = u.id
    WHERE u.role='tourist'
    ORDER BY u.id DESC
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

  .muted{ color:var(--muted); font-size:12px; font-weight: var(--w-normal); }
  .nowrap{ white-space:nowrap; }
  .ellipsis{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

  /* таблица + прокрутка как в apps.php */
  .tbl-wrap{
    margin-top:12px;
    overflow:auto;
    border-radius:16px;
  }
  .tourists-table{ width:100%; table-layout: fixed; min-width: 980px; }
  .table.tourists-table th, .table.tourists-table td{ padding: 8px 8px; }
  .table.tourists-table th{ font-size:12px; font-weight: var(--w-normal); }
  .table.tourists-table td{ font-size:13px; font-weight: var(--w-normal); vertical-align: middle; }

  .fio{ font-weight: var(--w-normal); }
  .small{ font-size:12px; color:var(--muted); font-weight: var(--w-normal); }

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
    background: rgba(255,255,255,.78);
  }

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
  .tourists-cards{ display:none; margin-top:12px; }
  .tcard{
    border:1px solid rgba(226,232,240,.92);
    border-radius:16px;
    background: rgba(255,255,255,.72);
    padding:12px;
    box-shadow: var(--shadow);
  }
  .tcard + .tcard{ margin-top:12px; }
  .t-head{ display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
  .t-name{ font-weight: var(--w-strong); }
  .t-sub{ margin-top:6px; color:var(--muted); font-size:12px; font-weight: var(--w-normal); }
  .t-grid{
    display:grid;
    grid-template-columns: 1fr 1fr;
    gap:10px;
    margin-top:10px;
  }
  @media (max-width: 420px){
    .t-grid{ grid-template-columns: 1fr; }
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
  .t-actions{ display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }

  /* switch */
  @media (max-width: 980px){
    .tbl-wrap{ display:none; }
    .tourists-cards{ display:block; }
  }
</style>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div>
    <h1 class="h1" style="margin-bottom:6px;">Туристы</h1>
    <div class="badge">База туристов: поиск, карточка, контакты</div>
  </div>
  <a class="btn success" href="/manager/tourist_create.php">+ Добавить туриста</a>
</div>

<?php if ($error): ?>
  <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="toolbar">
  <form class="search" method="get" action="/manager/tourists.php">
    <div class="input" style="flex:1; margin:0;">
      <label>Поиск (email, ФИО, ИИН, телефон)</label>
      <input type="text" name="q" value="<?= h($q) ?>" placeholder="Например: 8701, ИИН, Иванов, mail@...">
    </div>
    <button class="btn btn-sm btn-primary" type="submit">Найти</button>
    <?php if ($q !== ''): ?>
      <a class="btn btn-sm" href="/manager/tourists.php">Сброс</a>
    <?php endif; ?>
  </form>
</div>

<!-- DESKTOP: table -->
<div class="tbl-wrap">
  <table class="table tourists-table">
    <thead>
      <tr>
        <th style="width:70px;">ID</th>
        <th style="width:260px;">Турист</th>
        <th>Email / Телефон</th>
        <th style="width:140px;">ИИН</th>
        <th style="width:120px;">Статус</th>
        <th style="width:140px;">Создан</th>
        <th style="width:220px; text-align:right;">Действия</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="muted">Пока нет туристов. Нажмите “Добавить туриста”.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php
            $idRow = (int)$r['id'];
            $fioFull = trim(($r['last_name'] ?? '') . ' ' . ($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? ''));
            if ($fioFull === '') $fioFull = (string)($r['name'] ?? '');
            if ($fioFull === '') $fioFull = '—';

            $active = ((int)$r['active'] === 1);
            $href = '/manager/tourist_view.php?id=' . $idRow;

            $iin = (string)($r['iin'] ?? '');
            $email = (string)($r['email'] ?? '');
            $phone = (string)($r['phone'] ?? '');
            $created = (string)substr((string)($r['created_at'] ?? ''), 0, 10);
          ?>
          <tr onclick="window.location.href='<?= h($href) ?>';" style="cursor:pointer;">
            <td><?= $idRow ?></td>

            <td>
              <div class="fio ellipsis" title="<?= h($fioFull) ?>"><?= h($fioFull) ?></div>
              <div class="small">Карточка туриста</div>
            </td>

            <td>
              <div class="ellipsis" title="<?= h($email) ?>"><?= h($email) ?></div>
              <div class="small ellipsis" title="<?= h($phone) ?>"><?= h($phone) ?></div>
            </td>

            <td class="ellipsis" title="<?= h($iin) ?>"><?= h($iin) ?></td>

            <td>
              <?php if ($active): ?>
                <span class="status-pill" style="border-color:rgba(34,197,94,.35); color:rgba(22,163,74,1); background:rgba(34,197,94,.08);">активен</span>
              <?php else: ?>
                <span class="status-pill" style="border-color:rgba(239,68,68,.35); color:rgba(239,68,68,1); background:rgba(239,68,68,.06);">выкл</span>
              <?php endif; ?>
            </td>

            <td class="muted nowrap"><?= h($created) ?></td>

            <td style="white-space:nowrap; text-align:right;" onclick="event.stopPropagation();">
              <div style="display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap;">
                <a class="btn btn-sm btn-primary" href="<?= h($href) ?>">Открыть</a>

                <form method="post" style="margin:0;" onsubmit="return confirm('Удалить туриста? Действие необратимо.');">
                  <input type="hidden" name="_action" value="delete_tourist">
                  <input type="hidden" name="id" value="<?= $idRow ?>">
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
<div class="tourists-cards">
  <?php if (!$rows): ?>
    <div class="muted">Туристов не найдено.</div>
  <?php else: ?>
    <?php foreach ($rows as $r): ?>
      <?php
        $idRow = (int)$r['id'];
        $fioFull = trim(($r['last_name'] ?? '') . ' ' . ($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? ''));
        if ($fioFull === '') $fioFull = (string)($r['name'] ?? '');
        if ($fioFull === '') $fioFull = '—';

        $active = ((int)$r['active'] === 1);
        $href = '/manager/tourist_view.php?id=' . $idRow;

        $iin = (string)($r['iin'] ?? '');
        $email = (string)($r['email'] ?? '');
        $phone = (string)($r['phone'] ?? '');
        $created = (string)substr((string)($r['created_at'] ?? ''), 0, 10);
      ?>

      <div class="tcard" onclick="window.location.href='<?= h($href) ?>';" style="cursor:pointer;">
        <div class="t-head">
          <div>
            <div class="t-name"><?= h($fioFull) ?></div>
            <div class="t-sub">ID <?= (int)$idRow ?><?= ($created !== '' ? ' · ' . h($created) : '') ?></div>
          </div>

          <div onclick="event.stopPropagation();">
            <?php if ($active): ?>
              <span class="status-pill" style="border-color:rgba(34,197,94,.35); color:rgba(22,163,74,1); background:rgba(34,197,94,.08);">активен</span>
            <?php else: ?>
              <span class="status-pill" style="border-color:rgba(239,68,68,.35); color:rgba(239,68,68,1); background:rgba(239,68,68,.06);">выкл</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="t-grid">
          <div class="box">
            <div class="ttl">Контакты</div>
            <div class="val">
              <?= h($email !== '' ? $email : '—') ?><br>
              <span class="muted"><?= h($phone !== '' ? $phone : '—') ?></span>
            </div>
          </div>

          <div class="box">
            <div class="ttl">ИИН</div>
            <div class="val"><?= h($iin !== '' ? $iin : '—') ?></div>
          </div>
        </div>

        <div class="t-actions" onclick="event.stopPropagation();">
          <a class="btn btn-sm btn-primary" href="<?= h($href) ?>">Открыть</a>
          <form method="post" style="margin:0;" onsubmit="return confirm('Удалить туриста? Действие необратимо.');">
            <input type="hidden" name="_action" value="delete_tourist">
            <input type="hidden" name="id" value="<?= $idRow ?>">
            <button class="btn btn-sm btn-danger" type="submit">Удалить</button>
          </form>
        </div>
      </div>

    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>