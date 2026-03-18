<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

require_role('manager');

$title = 'Шаблоны документов';
$pdo = db();

$rows = $pdo->query("SELECT * FROM document_templates ORDER BY id DESC LIMIT 500")->fetchAll();

require __DIR__ . '/_layout_top.php';
?>

  <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <div>
      <h1 class="h1" style="margin-bottom:6px;">Шаблоны документов</h1>
      <div class="badge">Создание и редактирование шаблонов с переменными</div>
    </div>
    <a class="btn success" href="/manager/document_template_edit.php">+ Новый шаблон</a>
  </div>

  <div class="badge" style="margin-top:18px;font-weight:900;">Шаблоны</div>

  <table class="table" style="margin-top:10px;">
    <thead>
      <tr>
        <th style="width:70px;">ID</th>
        <th>Название</th>
        <th style="width:120px;">Активен</th>
        <th style="width:140px;">Менеджер</th>
        <th style="width:140px;">Турист</th>
        <th style="width:180px;">Обновлён</th>
        <th style="width:160px;">Действия</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" style="color:var(--muted);">Шаблонов пока нет.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php $id = (int)$r['id']; ?>
          <tr onclick="window.location.href='/manager/document_template_edit.php?id=<?= $id ?>';" style="cursor:pointer;">
            <td><?= $id ?></td>
            <td style="font-weight:900;"><?= h((string)$r['title']) ?></td>
            <td><?= ((int)$r['is_active'] === 1) ? 'да' : 'нет' ?></td>
            <td><?= ((int)$r['show_in_manager'] === 1) ? 'да' : 'нет' ?></td>
            <td><?= ((int)$r['show_in_tourist'] === 1) ? 'да' : 'нет' ?></td>
            <td style="color:var(--muted);"><?= h((string)$r['updated_at']) ?></td>
            <td onclick="event.stopPropagation();">
              <a class="btn" href="/manager/document_template_edit.php?id=<?= $id ?>">Редактировать</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

<?php require __DIR__ . '/_layout_bottom.php'; ?>