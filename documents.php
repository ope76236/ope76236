<?php
declare(strict_types=1);

if (isset($_GET['debug']) && $_GET['debug'] === '1') {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

/**
 * FIX: раньше было require_once __DIR__ . '/db.php'; (файла нет)
 * В ��енеджерских страницах у вас используется app/db.php + auth/helpers.
 */
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/documents.php';

require_role('manager');

$title = 'Документы по заявке';
$pdo = db();

$appId = (int)($_GET['app_id'] ?? 0);
if ($appId <= 0) {
  http_response_code(404);
  echo "Не указан app_id";
  exit;
}

$error = null;

/**
 * ВАЖНО:
 * Этот файл НЕ должен содержать копии функций doc_variables_catalog/doc_variables_for_app.
 * Они находятся в app/documents.php и должны быть единственным источником правды,
 * иначе вы ловите конфликт версий и 500.
 *
 * Ниже — минимальная страница со списком шаблонов + прикрепление/открепление.
 */

// --- load application for header ---
$stApp = $pdo->prepare("SELECT id, app_number, country, destination, status FROM applications WHERE id=? LIMIT 1");
$stApp->execute([$appId]);
$app = $stApp->fetch(PDO::FETCH_ASSOC);
if (!$app) {
  http_response_code(404);
  echo "Заявка не найдена";
  exit;
}

$appNo = (int)(($app['app_number'] ?? 0) ?: (int)$app['id']);
$appPlace = (string)($app['country'] ?? $app['destination'] ?? '');

// --- POST actions: attach/detach ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = (string)post('_action', '');
    $tplId = (int)post('template_id', '0');
    if ($tplId <= 0) throw new RuntimeException('Некорректный template_id.');

    if ($action === 'attach') {
      $pdo->prepare("
        INSERT IGNORE INTO application_documents(application_id, template_id, created_at)
        VALUES(?,?,NOW())
      ")->execute([$appId, $tplId]);

      redirect('/manager/documents.php?app_id=' . $appId);
    }

    if ($action === 'detach') {
      $pdo->prepare("
        DELETE FROM application_documents
        WHERE application_id=? AND template_id=?
        LIMIT 1
      ")->execute([$appId, $tplId]);

      redirect('/manager/documents.php?app_id=' . $appId);
    }

    throw new RuntimeException('Неизвестное действие.');
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

// --- templates ---
$stTpl = $pdo->query("
  SELECT id, title, description, is_active, show_in_manager, show_in_tourist
  FROM document_templates
  ORDER BY id DESC
");
$templates = $stTpl->fetchAll(PDO::FETCH_ASSOC);

// --- attached templates for app ---
$stAttached = $pdo->prepare("
  SELECT template_id
  FROM application_documents
  WHERE application_id=?
");
$stAttached->execute([$appId]);
$attachedIds = array_map('intval', array_column($stAttached->fetchAll(PDO::FETCH_ASSOC), 'template_id'));
$attachedSet = array_fill_keys($attachedIds, true);

require __DIR__ . '/_layout_top.php';
?>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div style="min-width:0;">
    <h1 class="h1" style="margin-bottom:6px;">Документы</h1>
    <div class="badge">
      Заявка №<?= (int)$appNo ?><?= $appPlace !== '' ? (' · ' . h($appPlace)) : '' ?>
    </div>
  </div>
  <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="/manager/app_view.php?id=<?= (int)$appId ?>">← К заявке</a>
    <a class="btn" href="/manager/document_templates.php">Шаблоны</a>
    <a class="btn" href="/manager/payments.php?app_id=<?= (int)$appId ?>">Оплаты</a>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="section" style="margin-top:14px;">
  <div class="section-title" style="margin-top:0;">Шаблоны документов</div>

  <table class="table" style="margin-top:10px;">
    <thead>
      <tr>
        <th style="width:70px;">ID</th>
        <th>Название</th>
        <th style="width:150px;">Состояние</th>
        <th style="width:360px;">Действия</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$templates): ?>
        <tr><td colspan="4" class="muted">Шаблонов нет.</td></tr>
      <?php else: ?>
        <?php foreach ($templates as $t): ?>
          <?php
            $tid = (int)$t['id'];
            $isActive = ((int)($t['is_active'] ?? 0) === 1);
            $isAttached = isset($attachedSet[$tid]);
          ?>
          <tr>
            <td><?= (int)$tid ?></td>
            <td>
              <div style="font-weight:900; color:#0f172a;"><?= h((string)$t['title']) ?></div>
              <?php if (trim((string)$t['description']) !== ''): ?>
                <div class="muted" style="margin-top:4px;"><?= h((string)$t['description']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <?= $isActive ? '<span class="badge">активен</span>' : '<span class="badge" style="opacity:.7;">неактивен</span>' ?>
              <div class="muted" style="margin-top:6px;"><?= $isAttached ? 'прикреплён' : 'не прикреплён' ?></div>
            </td>
            <td style="white-space:nowrap;">
              <?php if ($isAttached): ?>
                <a class="btn" href="/manager/document_render.php?app_id=<?= (int)$appId ?>&tpl_id=<?= (int)$tid ?>" target="_blank">Открыть</a>

                <form method="post" style="display:inline;">
                  <input type="hidden" name="_action" value="detach">
                  <input type="hidden" name="template_id" value="<?= (int)$tid ?>">
                  <button class="btn" type="submit">Открепить</button>
                </form>
              <?php else: ?>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="_action" value="attach">
                  <input type="hidden" name="template_id" value="<?= (int)$tid ?>">
                  <button class="btn" type="submit">Прикрепить</button>
                </form>
              <?php endif; ?>

              <a class="btn" href="/manager/document_template_edit.php?id=<?= (int)$tid ?>">Редактировать</a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>