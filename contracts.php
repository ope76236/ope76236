<?php
declare(strict_types=1);

$title = 'Договоры';
require __DIR__ . '/_layout_top.php';

require_once __DIR__ . '/../app/db.php';
$pdo = db();

$q = trim((string)($_GET['q'] ?? ''));
$qLike = '%' . $q . '%';

if ($q !== '') {
  $st = $pdo->prepare("
    SELECT c.id, c.application_id, c.contract_number, c.contract_date, c.created_at,
           a.title AS app_title, a.destination, a.start_date, a.end_date
    FROM contracts c
    LEFT JOIN applications a ON a.id = c.application_id
    WHERE c.contract_number LIKE ?
       OR a.title LIKE ?
       OR a.destination LIKE ?
    ORDER BY c.id DESC
    LIMIT 200
  ");
  $st->execute([$qLike,$qLike,$qLike]);
  $rows = $st->fetchAll();
} else {
  $rows = $pdo->query("
    SELECT c.id, c.application_id, c.contract_number, c.contract_date, c.created_at,
           a.title AS app_title, a.destination, a.start_date, a.end_date
    FROM contracts c
    LEFT JOIN applications a ON a.id = c.application_id
    ORDER BY c.id DESC
    LIMIT 200
  ")->fetchAll();
}
?>

  <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <div>
      <h1 class="h1" style="margin-bottom:6px;">Договоры</h1>
      <div class="badge">Список сохранённых договоров (HTML-снимки)</div>
    </div>
    <a class="btn" href="/manager/apps.php">К заявкам</a>
  </div>

  <div class="toolbar">
    <form class="search" method="get" action="/manager/contracts.php">
      <div class="input" style="flex:1; margin:0;">
        <label>Поиск (номер договора / заявка / направление)</label>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="TD-..., Тур: ..., Анталия...">
      </div>
      <button class="btn" type="submit">Найти</button>
      <?php if ($q !== ''): ?>
        <a class="btn" href="/manager/contracts.php">Сброс</a>
      <?php endif; ?>
    </form>
  </div>

  <table class="table">
    <thead>
      <tr>
        <th style="width:70px;">ID</th>
        <th>Договор</th>
        <th>Заявка</th>
        <th style="width:160px;">Даты тура</th>
        <th style="width:150px;">Создан</th>
        <th style="width:180px;">Действия</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" style="color:var(--muted);">Пока нет договоров. Откройте заявку и нажмите “Сгенерировать договор”.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td style="font-weight:900;">
              <a href="/manager/contract_view.php?id=<?= (int)$r['id'] ?>"><?= h((string)$r['contract_number']) ?></a><br>
              <span style="color:var(--muted); font-size:12px;">дата: <?= h((string)($r['contract_date'] ?? '')) ?></span>
            </td>
            <td>
              <a href="/manager/app_view.php?id=<?= (int)$r['application_id'] ?>"><?= h((string)($r['app_title'] ?: ('Заявка #' . (int)$r['application_id']))) ?></a><br>
              <span style="color:var(--muted); font-size:12px;"><?= h((string)($r['destination'] ?? '')) ?></span>
            </td>
            <td style="color:var(--muted); font-size:13px;">
              <?= h((string)($r['start_date'] ?? '')) ?> — <?= h((string)($r['end_date'] ?? '')) ?>
            </td>
            <td style="color:var(--muted); font-size:13px;">
              <?= h((string)substr((string)$r['created_at'], 0, 16)) ?>
            </td>
            <td>
              <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a class="btn" href="/manager/contract_view.php?id=<?= (int)$r['id'] ?>">Открыть</a>
                <a class="btn success" target="_blank" href="/manager/contract_print.php?id=<?= (int)$r['id'] ?>">Печать</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

<?php require __DIR__ . '/_layout_bottom.php'; ?>