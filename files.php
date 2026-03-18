<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

require_role('manager');

$title = 'Файлы по заявке';
$pdo = db();

$appId = (int)($_GET['app_id'] ?? 0);
if ($appId <= 0) {
  http_response_code(404);
  echo "Не указан app_id";
  exit;
}

$error = null;

/**
 * Папка загрузок: turdoc.kz/public_html/uploads
 * В коде проекта это: /uploads (рядом с manager/, tourist/)
 */
$uploadDir = __DIR__ . '/../uploads';
if (!is_dir($uploadDir)) $uploadDir = dirname(__DIR__) . '/uploads';

function safe_ext(string $name): string {
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $ext = preg_replace('~[^a-z0-9]+~', '', $ext);
  return $ext;
}
function uid16(): string {
  return bin2hex(random_bytes(16));
}

$typeName = [
  'passport' => 'Паспорт',
  'voucher' => 'Ваучер',
  'ticket' => 'Билет',
  'insurance' => 'Страховка',
  'receipt' => 'Чек/квитанция',
  'other' => 'Другое',
];

$stApp = $pdo->prepare("SELECT * FROM applications WHERE id=? LIMIT 1");
$stApp->execute([$appId]);
$app = $stApp->fetch();
if (!$app) {
  http_response_code(404);
  echo "Заявка не найдена";
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = post('_action');

    if ($action === 'upload_file') {
      if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        throw new RuntimeException('Папка uploads недоступна для записи.');
      }

      if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
        throw new RuntimeException('Файл не выбран.');
      }

      $f = $_FILES['file'];
      if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Ошибка загрузки файла (код ' . (int)$f['error'] . ').');
      }

      $origName = (string)($f['name'] ?? 'file');
      $tmp = (string)($f['tmp_name'] ?? '');
      $size = (int)($f['size'] ?? 0);

      if ($size <= 0) throw new RuntimeException('Пустой файл.');
      if ($size > 50 * 1024 * 1024) throw new RuntimeException('Файл слишком большой (лимит 50 МБ).');

      $docType = post('doc_type', 'other');
      if (!array_key_exists($docType, $typeName)) $docType = 'other';

      $note = post('file_note', '');

      $ext = safe_ext($origName);
      $stored = uid16() . ($ext ? ('.' . $ext) : '');
      $dest = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $stored;

      $mime = '';
      if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
          $mime = (string)finfo_file($fi, $tmp);
          finfo_close($fi);
        }
      }

      if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Не удалось сохранить файл на сервер.');
      }

      $u = current_user();
      $uid = (int)($u['id'] ?? 0);

      $ins = $pdo->prepare("
        INSERT INTO documents(application_id, file_name, stored_name, mime_type, file_size, doc_type, note, uploaded_by_role, uploaded_by_user_id)
        VALUES(?,?,?,?,?,?,?,?,?)
      ");
      $ins->execute([$appId, $origName, $stored, $mime, $size, $docType, $note, 'manager', $uid]);

      redirect('/manager/files.php?app_id=' . $appId);
    }

    if ($action === 'delete_file') {
      $docId = (int)($_POST['doc_id'] ?? 0);
      if ($docId <= 0) throw new RuntimeException('Некорректный документ.');

      $stD = $pdo->prepare("
        SELECT stored_name
        FROM documents
        WHERE id=? AND application_id=?
        LIMIT 1
      ");
      $stD->execute([$docId, $appId]);
      $row = $stD->fetch();
      if (!$row) throw new RuntimeException('Документ не найден.');

      $pdo->prepare("DELETE FROM documents WHERE id=? LIMIT 1")->execute([$docId]);

      $path = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . (string)$row['stored_name'];
      if (is_file($path)) @unlink($path);

      redirect('/manager/files.php?app_id=' . $appId);
    }

  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$stDocs = $pdo->prepare("
  SELECT id, file_name, mime_type, file_size, doc_type, note, created_at, uploaded_by_role
  FROM documents
  WHERE application_id=?
  ORDER BY id DESC
");
$stDocs->execute([$appId]);
$docs = $stDocs->fetchAll();

$appNoText = (int)($app['app_number'] ?? 0);
$appNoText = $appNoText > 0 ? $appNoText : (int)$app['id'];

require __DIR__ . '/_layout_top.php';
?>

<style>
  /* наследуем шрифт сайта во всех контролах */
  .btn, .tabbtn, button, input, select, textarea { font-family: inherit; }

  /* “гипержирный” стиль как в меню: без изменения шрифта, только weight */
  .superbold { font-weight: 1000; color:#0f172a; }

  /* заголовки секций — единообразно */
  .block-title{
    margin-top:18px;
    font-weight:1000;
    color:#0f172a;
    font-size:18px;
  }

  /* кликабельные строки */
  tr.row-link { cursor: pointer; }
  tr.row-link:hover { background: rgba(2,132,199,.06); }

  a.file-link { color: inherit; text-decoration: none; }
  a.file-link:hover { text-decoration: underline; }

  .note {
    color: var(--muted);
    font-size: 12px;
    margin-top: 4px;
    font-weight: 600;
    line-height: 1.25;
  }

  /* === Красивый file input (как кнопки в UI) === */
  .filepicker{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    padding:12px;
    border:1px dashed rgba(148,163,184,.55);
    border-radius:16px;
    background: rgba(255,255,255,.72);
  }
  .filepicker .meta{ min-width:0; }
  .filepicker .meta .label{ font-size:12px; color:var(--muted); font-weight:700; }
  .filepicker .meta .name{
    margin-top:4px;
    font-weight:900;
    color:#0f172a;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    max-width: 680px;
  }
  .filepicker .meta .hint{ margin-top:4px; font-size:12px; color:var(--muted); font-weight:600; }

  .filepicker input[type="file"]{
    position:absolute;
    opacity:0;
    width:1px;
    height:1px;
    pointer-events:none;
  }

  /* Кнопка “Выбрать файл” — в стиле как на примере (image3) */
  .btn-soft{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:10px 14px;
    border-radius:14px;
    border:1px solid rgba(14,165,233,.40);
    background: rgba(14,165,233,.08);
    color:#0f172a;
    text-decoration:none;
    font-weight:800;
    cursor:pointer;
    white-space:nowrap;
    transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
  }
  .btn-soft:hover{
    transform: translateY(-1px);
    box-shadow: 0 12px 26px rgba(2,8,23,.06);
    border-color: rgba(14,165,233,.55);
  }

  /* Подсвеченная кнопка “Загрузить” */
  .btn-accent{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:12px 16px;
    border-radius:14px;
    border:1px solid rgba(34,197,94,.55);
    background: rgba(34,197,94,.14);
    color:#0f172a;
    text-decoration:none;
    font-weight:900;
    cursor:pointer;
    transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
  }
  .btn-accent:hover{
    transform: translateY(-1px);
    box-shadow: 0 12px 26px rgba(2,8,23,.08);
    border-color: rgba(34,197,94,.72);
  }

  /* маленькая кнопка “Открыть” в таблице */
  .btn-open{
    border:1px solid rgba(14,165,233,.40);
    background: rgba(14,165,233,.08);
    border-radius:14px;
    padding:8px 12px;
    font-weight:800;
    cursor:pointer;
    text-decoration:none;
    color:inherit;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    white-space:nowrap;
  }

  /* кнопка удаления — как и было, но тоже в “pill” стиле */
  .btn-del{
    border:1px solid rgba(239,68,68,.40);
    background: rgba(239,68,68,.06);
    border-radius:14px;
    padding:8px 12px;
    font-weight:800;
    cursor:pointer;
    color:#ef4444;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    white-space:nowrap;
  }

  @media (max-width: 720px){
    .filepicker{ flex-direction:column; align-items:stretch; }
    .filepicker .meta .name{ max-width: 100%; }
    .filepicker .actions{ display:flex; justify-content:flex-end; }
  }
</style>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div>
    <h1 class="h1 superbold" style="margin-bottom:6px;">Файлы · заявка №<?= (int)$appNoText ?></h1>
    <div class="badge">
      <?= h((string)($app['country'] ?? $app['destination'] ?? '')) ?>
      · <?= h((string)$app['start_date']) ?> — <?= h((string)$app['end_date']) ?>
    </div>
  </div>
  <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="/manager/app_view.php?id=<?= (int)$app['id'] ?>">← К заявке</a>
    <a class="btn" href="/manager/documents.php?app_id=<?= (int)$app['id'] ?>">Документы</a>
    <a class="btn" href="/manager/payments.php?app_id=<?= (int)$app['id'] ?>">Оплаты</a>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="block-title">Добавить файл</div>

<form method="post" enctype="multipart/form-data" class="form" style="margin-top:10px; max-width:1100px;">
  <input type="hidden" name="_action" value="upload_file">

  <div style="display:grid; grid-template-columns: 220px 1fr; gap:12px;">
    <div class="input">
      <label>Тип</label>
      <select name="doc_type">
        <?php foreach ($typeName as $k => $v): ?>
          <option value="<?= h($k) ?>"><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="input">
      <label>Комментарий</label>
      <input name="file_note" type="text" placeholder="Например: договор, чек, фото паспорта">
    </div>
  </div>

  <div class="input">
    <label>Файл (до 50 МБ)</label>

    <div class="filepicker" onclick="document.getElementById('fileInput').click();">
      <input id="fileInput" name="file" type="file" required>
      <div class="meta">
        <div class="label">Выбранный файл</div>
        <div class="name" id="pickedFileName">Файл не выбран</div>
        <div class="hint">Нажмите “Выбрать файл” или кликните по этой области</div>
      </div>
      <div class="actions">
        <button class="btn-soft" type="button" onclick="event.stopPropagation(); document.getElementById('fileInput').click();">
          Выбрать файл
        </button>
      </div>
    </div>
  </div>

  <button class="btn-accent" type="submit">Загрузить</button>
</form>

<div class="block-title">Файлы</div>

<table class="table" style="margin-top:10px;">
  <thead>
    <tr>
      <th style="width:70px;">ID</th>
      <th>Файл</th>
      <th style="width:160px;">Тип</th>
      <th style="width:140px;">Размер</th>
      <th style="width:160px;">Кем загружен</th>
      <th style="width:160px;">Дата</th>
      <th style="width:220px;">Действия</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$docs): ?>
      <tr><td colspan="7" style="color:var(--muted);">Файлов пока нет.</td></tr>
    <?php else: ?>
      <?php foreach ($docs as $d): ?>
        <?php
          $sizeKb = ((int)$d['file_size'] / 1024);
          $who = ((string)$d['uploaded_by_role'] === 'tourist') ? 'турист' : 'менеджер';
          $downloadUrl = "/tourist/document_download.php?id=" . (int)$d['id'];
        ?>
        <tr class="row-link" onclick="window.open('<?= h($downloadUrl) ?>', '_blank');">
          <td><?= (int)$d['id'] ?></td>
          <td>
            <div class="superbold" style="font-size:14px;">
              <a class="file-link" target="_blank" href="<?= h($downloadUrl) ?>" onclick="event.stopPropagation();">
                <?= h((string)$d['file_name']) ?>
              </a>
            </div>
            <div class="note"><?= h((string)($d['note'] ?? '')) ?></div>
          </td>
          <td><?= h($typeName[(string)$d['doc_type']] ?? (string)$d['doc_type']) ?></td>
          <td style="color:var(--muted);"><?= number_format($sizeKb, 1, '.', ' ') ?> KB</td>
          <td style="color:var(--muted);"><?= h($who) ?></td>
          <td style="color:var(--muted);"><?= h((string)substr((string)$d['created_at'], 0, 16)) ?></td>
          <td onclick="event.stopPropagation();">
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
              <a class="btn-open" target="_blank" href="<?= h($downloadUrl) ?>">Открыть</a>

              <form method="post" style="margin:0;" onsubmit="return confirm('Удалить файл?');">
                <input type="hidden" name="_action" value="delete_file">
                <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                <button class="btn-del" type="submit">Удалить</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>

<script>
  (function () {
    var inp = document.getElementById('fileInput');
    var out = document.getElementById('pickedFileName');
    if (!inp || !out) return;

    function update() {
      var f = inp.files && inp.files[0];
      out.textContent = f ? (f.name + ' (' + Math.round(f.size/1024) + ' KB)') : 'Файл не выбран';
    }
    inp.addEventListener('change', update);
    update();
  })();
</script>

<?php require __DIR__ . '/_layout_bottom.php'; ?>