<?php
declare(strict_types=1);

$title = 'Оплаты';
require __DIR__ . '/_layout_top.php';

require_once __DIR__ . '/../app/db.php';
$pdo = db();

$q = trim((string)($_GET['q'] ?? ''));
$qLike = '%' . $q . '%';

if ($q !== '') {
  $st = $pdo->prepare("
    SELECT p.*, a.title AS app_title
    FROM payments p
    LEFT JOIN applications a ON a.id = p.application_id
    WHERE p.payer_name LIKE ?
       OR p.note LIKE ?
       OR a.title LIKE ?
       OR p.status LIKE ?
    ORDER BY p.id DESC
    LIMIT 300
  ");
  $st->execute([$qLike,$qLike,$qLike,$qLike]);
  $rows = $st->fetchAll();
} else {
  $rows = $pdo->query("
    SELECT p.*, a.title AS app_title
    FROM payments p
    LEFT JOIN applications a ON a.id = p.application_id
    ORDER BY p.id DESC
    LIMIT 300
  ")->fetchAll();
}

$statusName = ['planned'=>'план','paid'=>'оплачено','cancelled'=>'отменено'];
$payerName = ['tourist'=>'турист','partner'=>'партнёр','operator'=>'туроператор'];
?>

  <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <div>
      <h1 class="h1" style="margin-bottom:6px;">Оплаты</h1>
      <div class="badge">Все платежи по всем заявкам</div>
    </div>
  </div>

  <div class="toolbar">
    <form class="search" method="get" action="/manager/payments_all.php">
      <div class="input" style="flex:1; margin:0;">
        <label>Поиск (плательщик / комментарий / заявка / статус)</label>
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Иванов, предоплата, план, заявка...">
      </div>
      <button class="btn" type="submit">Найти</button>
      <?php if ($q !== ''): ?>
        <a class="btn" href="/manager/payments_all.php">Сброс</a>
      <?php endif; ?>
    </form>
  </div>

  <table class="table">
    <thead>
      <tr>
        <th style="width:70px;">ID</th>
        <th>Заявка</th>
        <th>Кто</th>
        <th>Плательщик</th>
        <th style="width:140px;">Сумма</th>
        <th style="width:140px;">Дата</th>
        <th style="width:140px;">Статус</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" style="color:var(--muted);">Платежей пока нет.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td>
              <a href="/manager/app_view.php?id=<?= (int)$r['application_id'] ?>"><?= h((string)($r['app_title'] ?: ('Заявка #' . (int)$r['application_id']))) ?></a><br>
              <a class="pill" href="/manager/payments.php?app_id=<?= (int)$r['application_id'] ?>">оплаты по заявке</a>
            </td>
            <td><?= h($payerName[(string)$r['payer_type']] ?? (string)$r['payer_type']) ?></td>
            <td>
              <div style="font-weight:900;"><?= h((string)$r['payer_name']) ?></div>
              <div style="color:var(--muted); font-size:12px;"><?= h((string)($r['note'] ?? '')) ?></div>
            </td>
            <td style="font-weight:900;"><?= number_format((float)$r['amount'], 2, '.', ' ') ?> <?= h((string)$r['currency']) ?></td>
            <td style="color:var(--muted);"><?= h((string)($r['pay_date'] ?? '')) ?></td>
            <td><span class="pill"><?= h($statusName[(string)$r['status']] ?? (string)$r['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

<?php require __DIR__ . '/_layout_bottom.php'; ?>