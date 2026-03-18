<?php
declare(strict_types=1);

if (isset($_GET['debug']) && $_GET['debug'] === '1') {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/documents.php';

require_role('manager');

$title = 'Шаблон документа';
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$error = null;
$saved = false;

// нужно 6 туристов максимум
$maxTourists = 6;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $titleIn = post('title');
    $desc = post('description');
    $body = post('body_html');

    $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
    $showM = (int)($_POST['show_in_manager'] ?? 0) === 1 ? 1 : 0;
    $showT = (int)($_POST['show_in_tourist'] ?? 0) === 1 ? 1 : 0;

    if (trim($titleIn) === '') throw new RuntimeException('Укажите название шаблона.');
    if (trim($body) === '') throw new RuntimeException('Тело документа пустое.');

    if ($id > 0) {
      $st = $pdo->prepare("
        UPDATE document_templates
        SET title=?, description=?, body_html=?, is_active=?, show_in_manager=?, show_in_tourist=?
        WHERE id=?
        LIMIT 1
      ");
      $st->execute([$titleIn, $desc, $body, $isActive, $showM, $showT, $id]);
    } else {
      $st = $pdo->prepare("
        INSERT INTO document_templates(title, description, body_html, is_active, show_in_manager, show_in_tourist)
        VALUES(?,?,?,?,?,?)
      ");
      $st->execute([$titleIn, $desc, $body, $isActive, $showM, $showT]);
      $id = (int)$pdo->lastInsertId();
    }

    $saved = true;
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$row = null;
if ($id > 0) {
  $st = $pdo->prepare("SELECT * FROM document_templates WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $row = $st->fetch();
  if (!$row) {
    http_response_code(404);
    echo "Шаблон не найден";
    exit;
  }
}

/**
 * Каталог переменных
 */
$catalogAll = doc_variables_catalog($maxTourists);

/**
 * Доп. поля туроператора/догово��а
 */
$extraOperator = [
  'operator_agency_contract_no' => 'Номер договора с турагентством',
  'operator_agency_contract_date' => 'Дата договора с турагентством',
  'operator_agency_contract_expiry_date' => 'Срок действия договора (до)',
];
foreach ($extraOperator as $k => $desc) {
  if (!isset($catalogAll[$k])) $catalogAll[$k] = $desc;
}

/**
 * Заказчик тура + дата создания заявки
 */
$extraCustomer = [
  'application_created_at' => 'Заявка: дата создания',

  'customer_passport_no' => 'Заказчик: паспорт (серия и номер)',
  'customer_passport_issue_date' => 'Заказчик: дата выдачи паспорта',
  'customer_passport_expiry_date' => 'Заказчик: паспорт действителен до',
  'customer_passport_issued_by' => 'Заказчик: кем выдан паспорт',

  'customer_idcard_no' => 'Заказчик: удостоверение №',
  'customer_idcard_issue_date' => 'Заказчик: удостоверение выдано',
  'customer_idcard_expiry_date' => 'Заказчик: удостоверение действительно до',
  'customer_idcard_issued_by' => 'Заказчик: удостоверение кем выдано',

  'customer_emergency_contact_name' => 'Заказчик: экстренный контакт — ФИО',
  'customer_emergency_contact_phone' => 'Заказчик: экстренный контакт — телефон',
  'customer_emergency_contact_relation' => 'Заказчик: экстренный контакт — кем приходится',
];
foreach ($extraCustomer as $k => $desc) {
  if (!isset($catalogAll[$k])) $catalogAll[$k] = $desc;
}

/**
 * Туристы: базовые + карточка HTML
 */
for ($i = 1; $i <= $maxTourists; $i++) {
  $add = [
    "tourist_{$i}_birth_date" => "Турист {$i}: дата рождения",
    "tourist_{$i}_phone" => "Турист {$i}: телефон",
    "tourist_{$i}_full_name_ru" => "Турист {$i}: ФИО (рус)",
    "tourist_{$i}_card_html" => "Турист {$i}: карточка (EN ФИО / RU ФИО / ДР / паспорт / срок / телефон) — HTML",
  ];
  foreach ($add as $k => $desc) {
    if (!isset($catalogAll[$k])) $catalogAll[$k] = $desc;
  }
}

/**
 * Параметры тура: услуги + дедлайны
 */
$extraTrip = [
  'trip_flights' => 'Перелёты',
  'trip_insurance' => 'Страхование',
  'trip_transfers' => 'Трансферы',
  'trip_extra_services' => 'Дополнительные услуги',

  'payment_deadline_1' => 'Оплата: дедлайн #1 (по заявке)',
  'payment_deadline_2' => 'Оплата: дедлайн #2 (по заявке)',
  'payment_deadline_3' => 'Оплата: дедлайн #3 (по заявке)',
];
foreach ($extraTrip as $k => $desc) {
  if (!isset($catalogAll[$k])) $catalogAll[$k] = $desc;
}
for ($i = 1; $i <= $maxTourists; $i++) {
  $add = [
    "tourist_{$i}_payment_deadline_1" => "Турист {$i}: дедлайн оплаты #1",
    "tourist_{$i}_payment_deadline_2" => "Турист {$i}: дедлайн оплаты #2",
    "tourist_{$i}_payment_deadline_3" => "Турист {$i}: дедлайн оплаты #3",
  ];
  foreach ($add as $k => $desc) {
    if (!isset($catalogAll[$k])) $catalogAll[$k] = $desc;
  }
}

/**
 * Группировка по колонкам
 */
$groups = [
  'agency' => ['title' => 'Турагент', 'items' => []],
  'operator' => ['title' => 'Туроператор', 'items' => []],
  'customer' => ['title' => 'Заказчик тура', 'items' => []],
  'tourists' => ['title' => 'Туристы', 'items' => []],
  'trip' => ['title' => 'Параметры тура', 'items' => []],
];

foreach ($catalogAll as $k => $desc) {
  $key = (string)$k;

  if ($key === 'application_created_at' || preg_match('~^(customer|buyer)_~', $key)) {
    $groups['customer']['items'][$key] = $desc;
    continue;
  }

  if ($key === 'tourists_count' || preg_match('~^tourist_\d+_~', $key)) {
    $groups['tourists']['items'][$key] = $desc;
    continue;
  }

  if (preg_match('~^(agency|company)_~', $key)) {
    $groups['agency']['items'][$key] = $desc;
    continue;
  }

  if (preg_match('~^(operator|tour_operator)_~', $key)) {
    $groups['operator']['items'][$key] = $desc;
    continue;
  }

  $groups['trip']['items'][$key] = $desc;
}

require __DIR__ . '/_layout_top.php';
?>

<style>
  .section-title{
    margin-top:18px;
    font-weight:1000;
    color:#0f172a;
    font-size:16px;
    display:flex;
    align-items:center;
    gap:10px;
  }
  .section-title::before{
    content:"";
    width:10px;
    height:10px;
    border-radius:999px;
    background: rgba(14,165,233,.9);
    box-shadow: 0 8px 18px rgba(14,165,233,.25);
    display:inline-block;
  }
  .section{
    margin-top:10px;
    padding:12px;
    border-radius:14px;
    border:1px solid rgba(226,232,240,.9);
    background: rgba(255,255,255,.55);
  }

  .vars-grid{
    display:grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap:12px;
    margin-top:10px;
  }
  @media (max-width: 1400px){ .vars-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); } }
  @media (max-width: 900px){ .vars-grid{ grid-template-columns: 1fr; } }

  .vars-box{
    border:1px solid rgba(226,232,240,.9);
    background: rgba(255,255,255,.62);
    border-radius:14px;
    padding:10px;
    min-width:0;
  }
  .vars-box h3{
    margin:0 0 10px;
    font-size:13px;
    font-weight:1000;
    color:#0f172a;
    display:flex;
    align-items:center;
    gap:10px;
  }
  .vars-box h3::before{
    content:"";
    width:8px; height:8px; border-radius:999px;
    background: rgba(14,165,233,.85);
    display:inline-block;
  }

  .vars-list{ display:grid; grid-template-columns: 1fr; gap:8px; }

  .var-card{
    display:block;
    padding:10px;
    border-radius:12px;
    border:1px solid rgba(226,232,240,.75);
    background: rgba(248,250,252,.6);
    cursor:pointer;
    user-select:none;
    min-width:0;
    transition: transform .08s ease, border-color .12s ease, box-shadow .12s ease;
  }
  .var-card:hover{
    transform: translateY(-1px);
    border-color: rgba(14,165,233,.30);
    box-shadow: 0 10px 22px rgba(2,8,23,.06);
  }
  .var-key{
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-size:12px;
    font-weight:1000;
    color:#0f172a;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  .var-desc{
    margin-top:6px;
    color: var(--muted);
    font-size:12px;
    line-height:1.35;
    overflow:hidden;
    text-overflow:ellipsis;
  }

  .quick-help{
    margin-top: 10px;
    padding: 10px;
    border-radius: 12px;
    border: 1px solid rgba(226,232,240,.9);
    background: rgba(255,255,255,.65);
    font-size: 12px;
    color: #64748b;
    line-height: 1.35;
  }
  .kbd{
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
    font-weight: 900;
    color:#0f172a;
    background: rgba(241,245,249,.9);
    border: 1px solid rgba(226,232,240,.9);
    border-bottom-width: 2px;
    padding: 0 6px;
    border-radius: 8px;
  }
</style>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div>
    <h1 class="h1" style="margin-bottom:6px;"><?= $id > 0 ? 'Редактирование шаблона' : 'Новый шаблон' ?></h1>
    <div class="badge">Переменные вставляйте как {variable_name}</div>
  </div>
  <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="/manager/document_templates.php">← К списку шаблонов</a>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($saved): ?>
  <div class="badge" style="margin-top:14px;border-color:rgba(34,197,94,.35);">Сохранено.</div>
<?php endif; ?>

<form method="post" class="form" style="margin-top:14px; max-width:1100px;" onsubmit="return beforeSubmit();">
  <div class="section-title">Параметры шаблона</div>
  <div class="section">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <div class="input">
        <label>Название</label>
        <input name="title" type="text" required value="<?= h((string)($row['title'] ?? ($_POST['title'] ?? ''))) ?>">
      </div>
      <div class="input">
        <label>Описание</label>
        <input name="description" type="text" value="<?= h((string)($row['description'] ?? ($_POST['description'] ?? ''))) ?>">
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:10px;">
      <div class="input">
        <label style="display:flex; gap:10px; align-items:center; margin-top:22px;">
          <input type="checkbox" name="is_active" value="1" <?= ((int)($row['is_active'] ?? 1) === 1 ? 'checked' : '') ?>>
          Активен
        </label>
      </div>
      <div class="input">
        <label style="display:flex; gap:10px; align-items:center; margin-top:22px;">
          <input type="checkbox" name="show_in_manager" value="1" <?= ((int)($row['show_in_manager'] ?? 1) === 1 ? 'checked' : '') ?>>
          По умолчанию показывать менеджеру
        </label>
      </div>
      <div class="input">
        <label style="display:flex; gap:10px; align-items:center; margin-top:22px;">
          <input type="checkbox" name="show_in_tourist" value="1" <?= ((int)($row['show_in_tourist'] ?? 0) === 1 ? 'checked' : '') ?>>
          По умолчанию показывать туристу
        </label>
      </div>
    </div>
  </div>

  <div class="section-title">Документ (TinyMCE 3.x)</div>
  <div class="section">
    <div class="input">
      <label>Тело документа</label>

      <textarea id="body_html" name="body_html" rows="18" required><?= h((string)($row['body_html'] ?? ($_POST['body_html'] ?? ''))) ?></textarea>

      <div class="quick-help">
        Быстрая вставка: печатайте <span class="kbd">\</span> и слово, например
        <span class="kbd">\названиетуроператора</span> → <span class="kbd">{operator_name}</span>.
      </div>

      <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
        <button type="button" class="btn" onclick="insertTouristsTable()">Вставить таблицу туристов</button>
      </div>
    </div>

    <button class="btn success" type="submit">Сохранить</button>
  </div>
</form>

<div class="section-title">Переменные (кликните чтобы вставить)</div>
<div class="vars-grid" style="max-width:1100px;">
  <?php foreach ($groups as $g): ?>
    <?php if (!$g['items']) continue; ?>
    <div class="vars-box">
      <h3><?= h($g['title']) ?></h3>
      <div class="vars-list">
        <?php foreach ($g['items'] as $k => $desc): ?>
          <?php $token = '{' . (string)$k . '}'; ?>
          <div class="var-card" role="button" tabindex="0"
               onclick="insertVar('<?= h($token) ?>')"
               onkeydown="if(event.key==='Enter'){ insertVar('<?= h($token) ?>'); }"
               title="Вставить <?= h($token) ?>">
            <div class="var-key"><?= h($token) ?></div>
            <div class="var-desc"><?= h((string)$desc) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<script src="/assets/vendor/tinymce/jscripts/tiny_mce/tiny_mce.js"></script>

<script>
  tinyMCE.init({
    mode: "exact",
    elements: "body_html",
    theme: "advanced",
    plugins: "table,inlinepopups,paste,searchreplace,fullscreen",
    theme_advanced_buttons1: "bold,italic,underline,|,formatselect,fontsizeselect,|,bullist,numlist,|,justifyleft,justifycenter,justifyright,|,link,unlink,|,table,|,code,fullscreen",
    theme_advanced_buttons2: "",
    theme_advanced_buttons3: "",
    theme_advanced_toolbar_location: "top",
    theme_advanced_toolbar_align: "left",
    theme_advanced_statusbar_location: "bottom",
    theme_advanced_resizing: true,
    content_style: "body{font-family:Arial, sans-serif; font-size:14px;} table{border-collapse:collapse;width:100%;} td,th{border:1px solid #94a3b8;padding:6px 8px;} th{background:#f1f5f9;}"
  });

  function insertVar(token) {
    var ed = tinyMCE.get('body_html');
    if (ed) {
      ed.focus();
      ed.execCommand('mceInsertContent', false, token);
      return;
    }
    var ta = document.getElementById('body_html');
    if (!ta) return;
    ta.value = (ta.value || '') + token;
  }

  function insertTouristsTable() {
    var rows = <?= (int)$maxTourists ?>;

    var html = '<table><thead><tr><th style="width:40px;">№</th><th>Турист</th></tr></thead><tbody>';
    for (var i = 1; i <= rows; i++) {
      html += '<tr><td>' + i + '</td><td>{tourist_' + i + '_card_html}</td></tr>';
    }
    html += '</tbody></table>';

    insertVar(html);
  }

  function beforeSubmit() {
    try { tinyMCE.triggerSave(); } catch (e) {}
    return true;
  }
</script>

<?php require __DIR__ . '/_layout_bottom.php'; ?>