<?php
declare(strict_types=1);

$title = 'Документы';
require __DIR__ . '/_layout_top.php';

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/documents.php';

$pdo = db();

$u = current_user();
$uid = (int)($u['id'] ?? 0);

$appId = (int)($_GET['app_id'] ?? 0);
if ($appId <= 0) {
  http_response_code(404);
  echo "Не указан app_id";
  exit;
}

$role = (string)($u['role'] ?? '');

$app = null;
if ($role === 'manager') {
  $stA = $pdo->prepare("
    SELECT a.id, a.title, a.destination, a.country, a.app_number
    FROM applications a
    WHERE a.id=?
    LIMIT 1
  ");
  $stA->execute([$appId]);
  $app = $stA->fetch(PDO::FETCH_ASSOC);
  if (!$app) {
    http_response_code(404);
    echo "Заявка не найдена";
    exit;
  }
} else {
  $stA = $pdo->prepare("
    SELECT a.id, a.title, a.destination, a.country, a.app_number
    FROM applications a
    LEFT JOIN application_tourists at
      ON at.application_id = a.id AND at.tourist_user_id = ?
    WHERE a.id=?
      AND (
        at.tourist_user_id IS NOT NULL
        OR a.customer_tourist_user_id = ?
      )
    LIMIT 1
  ");
  $stA->execute([$uid, $appId, $uid]);
  $app = $stA->fetch(PDO::FETCH_ASSOC);
  if (!$app) {
    http_response_code(403);
    echo "Доступ запрещён";
    exit;
  }
}

$error = null;

$uploadDir = __DIR__ . '/../uploads';
if (!is_dir($uploadDir)) $uploadDir = dirname(__DIR__) . '/uploads';

function safe_ext(string $name): string {
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $ext = preg_replace('~[^a-z0-9]+~', '', $ext);
  return $ext;
}
function uuid_like(): string {
  return bin2hex(random_bytes(16));
}
function size_human(int $bytes): string {
  if ($bytes <= 0) return '—';
  $kb = $bytes / 1024;
  if ($kb < 1024) return number_format($kb, 1, '.', ' ') . ' KB';
  $mb = $kb / 1024;
  return number_format($mb, 1, '.', ' ') . ' MB';
}

$typeName = [
  'passport' => 'Паспорт',
  'voucher' => 'Ваучер',
  'ticket' => 'Билет',
  'insurance' => 'Страховка',
  'receipt' => 'Чек/квитанция',
  'other' => 'Другое',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = post('_action');

    if ($action === 'upload') {
      if (!is_dir($uploadDir) || !is_writable($uploadDir)) {
        throw new RuntimeException('Папка uploads недоступна для записи. Обратитесь к менеджеру.');
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
      if ($size > 20 * 1024 * 1024) throw new RuntimeException('Файл слишком большой (лимит 20 МБ).');

      $docType = post('doc_type', 'other');
      if (!array_key_exists($docType, $typeName)) $docType = 'other';

      $note = post('note');

      $ext = safe_ext($origName);
      $stored = uuid_like() . ($ext ? ('.' . $ext) : '');
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

      $uploadedRole = ($role === 'manager') ? 'manager' : 'tourist';

      $ins = $pdo->prepare("
        INSERT INTO documents(application_id, file_name, stored_name, mime_type, file_size, doc_type, note, uploaded_by_role, uploaded_by_user_id)
        VALUES(?,?,?,?,?,?,?,?,?)
      ");
      $ins->execute([$appId, $origName, $stored, $mime, $size, $docType, $note, $uploadedRole, $uid]);

      redirect('/tourist/documents.php?app_id=' . $appId);
    }

    if ($action === 'delete') {
      $docId = (int)($_POST['doc_id'] ?? 0);
      if ($docId <= 0) throw new RuntimeException('Некорректный документ.');

      if ($role === 'manager') {
        $st = $pdo->prepare("
          SELECT stored_name
          FROM documents
          WHERE id=? AND application_id=?
          LIMIT 1
        ");
        $st->execute([$docId, $appId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Документ не найден.');

        $pdo->prepare("DELETE FROM documents WHERE id=? LIMIT 1")->execute([$docId]);

        $path = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . (string)$row['stored_name'];
        if (is_file($path)) @unlink($path);

        redirect('/tourist/documents.php?app_id=' . $appId);
      }

      $st = $pdo->prepare("
        SELECT stored_name
        FROM documents
        WHERE id=? AND application_id=? AND uploaded_by_role='tourist' AND uploaded_by_user_id=?
        LIMIT 1
      ");
      $st->execute([$docId, $appId, $uid]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new RuntimeException('Нельзя удалить этот документ (нет прав).');

      $pdo->prepare("DELETE FROM documents WHERE id=? LIMIT 1")->execute([$docId]);

      $path = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . (string)$row['stored_name'];
      if (is_file($path)) @unlink($path);

      redirect('/tourist/documents.php?app_id=' . $appId);
    }

  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$st = $pdo->prepare("
  SELECT id, file_name, mime_type, file_size, doc_type, note, created_at, uploaded_by_role, uploaded_by_user_id
  FROM documents
  WHERE application_id=?
  ORDER BY id DESC
");
$st->execute([$appId]);
$docs = $st->fetchAll(PDO::FETCH_ASSOC);

$stTplDocs = $pdo->prepare("
  SELECT ad.template_id,
         COALESCE(NULLIF(ad.title_override,''), dt.title) AS title
  FROM application_documents ad
  JOIN document_templates dt ON dt.id = ad.template_id
  WHERE ad.application_id=?
    AND ad.show_in_tourist=1
    AND dt.is_active=1
  ORDER BY ad.sort_order ASC, ad.id DESC
");
$stTplDocs->execute([$appId]);
$tplDocs = $stTplDocs->fetchAll(PDO::FETCH_ASSOC);

$appNo = (int)($app['app_number'] ?? 0);
$appNo = $appNo > 0 ? $appNo : (int)$app['id'];
$appCountry = (string)($app['country'] ?? '');
if ($appCountry === '') $appCountry = (string)($app['destination'] ?? '');
?>

<style>
  :root{ --w-strong: 750; --w-normal: 600; }

  .muted{ color: var(--muted); font-weight: var(--w-normal); }
  .mini{ font-size:12px; }
  .ellipsis{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

  .sec-title{
    margin-top:18px;
    font-weight: var(--w-strong);
    color:#0f172a;
    font-size:15px;
    display:flex;
    align-items:center;
    gap:10px;
  }
  .sec-title::before{
    content:"";
    width:9px;
    height:9px;
    border-radius:999px;
    background: rgba(14,165,233,.92);
    box-shadow: 0 8px 18px rgba(14,165,233,.18);
    display:inline-block;
  }

  .cardbox{
    margin-top:12px;
    padding:14px;
    border-radius:16px;
    border:1px solid rgba(226,232,240,.90);
    background: rgba(255,255,255,.72);
    min-width:0;
    overflow:hidden;
  }

  .btn{
    border-color: rgba(14,165,233,.25);
    background: rgba(14,165,233,.06);
    color:#0f172a;
  }
  .btn:hover{
    border-color: rgba(14,165,233,.40);
    background: rgba(14,165,233,.10);
  }
  .btn.primary{
    background: linear-gradient(135deg, rgba(14,165,233,.90), rgba(59,130,246,.90));
    border-color: rgba(14,165,233,.55);
  }
  .btn.success{
    background: rgba(34,197,94,.12);
    border-color: rgba(34,197,94,.35);
    color:#166534;
  }
  .btn.success:hover{
    background: rgba(34,197,94,.16);
    border-color: rgba(34,197,94,.45);
  }

  .doc-row{
    display:grid;
    grid-template-columns: 1fr auto;
    gap:12px;
    align-items:center;

    padding:12px 12px;
    border-radius:14px;
    border:1px solid rgba(226,232,240,.90);
    background: rgba(255,255,255,.78);

    color:inherit;
    text-decoration:none;

    transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
    min-width:0;
  }
  .doc-row:hover{
    transform: translateY(-1px);
    box-shadow: 0 12px 26px rgba(2,8,23,.08);
    border-color: rgba(14,165,233,.35);
  }

  .doc-name{
    font-weight: var(--w-strong);
    color:#0f172a;
    min-width:0;
  }
  .doc-meta{
    margin-top:4px;
    font-size:12px;
    color: var(--muted);
    font-weight: var(--w-normal);
  }

  .doc-actions{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    justify-content:flex-end;
  }

  .tag{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:6px 10px;
    border-radius:999px;
    border:1px solid rgba(226,232,240,.90);
    background: rgba(255,255,255,.78);
    font-size:12px;
    color: var(--muted);
    font-weight: var(--w-normal);
    white-space:nowrap;
    max-width:100%;
  }
  .tag-ok{
    color:#16a34a;
    border-color: rgba(34,197,94,.40);
    background: rgba(34,197,94,.10);
    font-weight: var(--w-strong);
  }
  .tag-warn{
    color:#0ea5e9;
    border-color: rgba(14,165,233,.40);
    background: rgba(14,165,233,.10);
    font-weight: var(--w-strong);
  }

  .grid2{
    display:grid;
    grid-template-columns: 220px 1fr;
    gap:12px;
  }

  .file-input{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
  }
  .file-input input[type="file"]{
    position:absolute;
    left:-9999px;
    width:1px;
    height:1px;
    opacity:0;
  }
  .file-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:10px 14px;
    border-radius:14px;
    border:1px solid rgba(14,165,233,.25);
    background: rgba(14,165,233,.06);
    color:#0f172a;
    font-weight: var(--w-strong);
    cursor:pointer;
    user-select:none;
    transition: background .12s ease, border-color .12s ease, box-shadow .12s ease, transform .12s ease;
  }
  .file-btn:hover{
    background: rgba(14,165,233,.10);
    border-color: rgba(14,165,233,.40);
    transform: translateY(-1px);
    box-shadow: 0 12px 26px rgba(2,8,23,.08);
  }
  .file-name{
    font-size:12px;
    color: var(--muted);
    font-weight: var(--w-normal);
    max-width: 520px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
  }

  @media (max-width: 760px){
    .grid2{ grid-template-columns: 1fr; }
    .doc-row{ grid-template-columns: 1fr; }
    .doc-actions{ justify-content:flex-start; }
    .ellipsis{ white-space:normal; }
    .file-name{ max-width: 100%; }
  }
</style>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div style="min-width:0;">
    <h1 class="h1" style="margin-bottom:6px;">Документы</h1>
    <div class="badge">
      Заявка №<?= (int)$appNo ?> · <?= h((string)$app['title']) ?> · <?= h($appCountry) ?>
      <?php if ($role === 'manager'): ?>
        · <span class="tag tag-warn">режим менеджера</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($role === 'manager'): ?>
    <a class="btn" href="/manager/app_view.php?id=<?= (int)$app['id'] ?>">← К заявке</a>
  <?php else: ?>
    <a class="btn" href="/tourist/tour_view.php?id=<?= (int)$app['id'] ?>">← К заявке</a>
  <?php endif; ?>
</div>

<?php if ($error): ?>
  <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
<?php endif; ?>

<!-- ОДИН кликабельный блок договора -->
<div class="sec-title">Договор</div>
<div class="cardbox">
  <a class="doc-row" target="_blank" href="/tourist/contract_view.php?app_id=<?= (int)$appId ?>">
    <div style="min-width:0;">
      <div class="doc-name ellipsis">Договор (оферта)</div>
      <div class="doc-meta">Открыть договор в новом окне</div>
    </div>
    <div class="doc-actions">
      <span class="tag tag-ok">Открыть</span>
    </div>
  </a>
</div>

<div class="sec-title">Файлы по заявке</div>
<div class="cardbox">
  <?php if (!$docs): ?>
    <div class="badge">Файлов пока нет.</div>
  <?php else: ?>
    <div style="display:flex; flex-direction:column; gap:10px;">
      <?php foreach ($docs as $d): ?>
        <?php
          $who = ((string)$d['uploaded_by_role'] === 'tourist') ? 'турист' : 'менеджер';
          $canDelete = ($role === 'manager') || ((string)$d['uploaded_by_role'] === 'tourist' && (int)$d['uploaded_by_user_id'] === $uid);

          $typeTxt = $typeName[(string)($d['doc_type'] ?? '')] ?? (string)($d['doc_type'] ?? '—');
          $sizeTxt = size_human((int)($d['file_size'] ?? 0));
          $createdTxt = (string)substr((string)($d['created_at'] ?? ''), 0, 16);
          $noteTxt = trim((string)($d['note'] ?? ''));
        ?>

        <a class="doc-row" target="_blank" href="/tourist/document_download.php?id=<?= (int)$d['id'] ?>">
          <div style="min-width:0;">
            <div class="doc-name ellipsis" title="<?= h((string)$d['file_name']) ?>">
              <?= h((string)$d['file_name']) ?>
            </div>
            <div class="doc-meta">
              <?= h($typeTxt) ?> · <?= h($sizeTxt) ?> · <?= h($who) ?><?= $createdTxt !== '' ? (' · ' . h($createdTxt)) : '' ?>
            </div>
            <?php if ($noteTxt !== ''): ?>
              <div class="doc-meta ellipsis" title="<?= h($noteTxt) ?>">
                <?= h($noteTxt) ?>
              </div>
            <?php endif; ?>
          </div>

          <div class="doc-actions">
            <?php if ($canDelete): ?>
              <form method="post" style="margin:0;" onsubmit="return confirm('Удалить документ?');" onclick="event.preventDefault(); event.stopPropagation(); this.submit();">
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                <button class="btn" type="submit">Удалить</button>
              </form>
            <?php else: ?>
              <span class="tag">—</span>
            <?php endif; ?>
          </div>
        </a>

      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="sec-title">Сгенерированные документы</div>
<div class="cardbox">
  <?php if (!$tplDocs): ?>
    <div class="badge">Пока нет документов для просмотра.</div>
  <?php else: ?>
    <div style="display:flex; flex-direction:column; gap:10px;">
      <?php foreach ($tplDocs as $d): ?>
        <a class="doc-row" target="_blank"
           href="/tourist/document_render.php?app_id=<?= (int)$appId ?>&tpl_id=<?= (int)$d['template_id'] ?>">
          <div style="min-width:0;">
            <div class="doc-name ellipsis" title="<?= h((string)$d['title']) ?>">
              <?= h((string)$d['title']) ?>
            </div>
            <div class="doc-meta">Открыть документ в новом окне</div>
          </div>
          <div class="doc-actions">
            <span class="tag tag-ok">Открыть</span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="sec-title">Загрузить документ</div>

<form method="post" enctype="multipart/form-data" class="form cardbox">
  <input type="hidden" name="_action" value="upload">

  <div class="grid2">
    <div class="input">
      <label>Тип документа</label>
      <select name="doc_type">
        <?php foreach ($typeName as $k => $v): ?>
          <option value="<?= h($k) ?>"><?= h($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="input">
      <label>Комментарий</label>
      <input name="note" type="text" placeholder="Например: билет туда, паспорт (1 страница)">
    </div>
  </div>

  <div class="input">
    <label>Файл (до 20 МБ)</label>

    <div class="file-input">
      <label class="file-btn" for="fileInput">Выбрать файл</label>
      <input id="fileInput" name="file" type="file" required>
      <div id="fileName" class="file-name">Файл не выбран</div>
    </div>
  </div>

  <button class="btn success" type="submit">Загрузить документ</button>

  <div class="badge" style="margin-top:10px;">
    Доступ: файлы видны по заявке. Турист может удалять только свои файлы, менеджер — в режиме менеджера.
  </div>
</form>

<script>
(function(){
  var inp = document.getElementById('fileInput');
  var out = document.getElementById('fileName');
  if (!inp || !out) return;

  inp.addEventListener('change', function(){
    var f = inp.files && inp.files[0];
    out.textContent = f ? f.name : 'Файл не выбран';
  });
})();
</script>

<?php require __DIR__ . '/_layout_bottom.php'; ?>