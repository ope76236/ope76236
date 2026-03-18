<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

require_role('manager');

$title = 'Карточка туроператора';
$pdo = db();

// Если id не задан — возьмём первого
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  $stFirst = $pdo->query("SELECT id FROM tour_operators ORDER BY id ASC LIMIT 1");
  $first = $stFirst->fetch();
  $id = (int)($first['id'] ?? 0);
}
if ($id <= 0) {
  http_response_code(404);
  echo "Не найдено ни одного туроператора в таблице tour_operators";
  exit;
}

$error = null;
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $name = post('name');
    $fullName = post('full_name');

    $bin = post('bin');
    $address = post('address');
    $phone = post('phone');
    $email = trim(mb_strtolower(post('email')));
    $note = post('note');

    $licenseNo = post('license_no');
    $licenseIssueDate = post('license_issue_date');

    $agencyContractNo = post('agency_contract_no');
    $agencyContractDate = post('agency_contract_date');
    $agencyContractExpiryDate = post('agency_contract_expiry_date'); // NEW

    $bankName = post('bank_name');
    $bankBik = post('bank_bik');
    $bankIik = post('bank_iik');

    if (trim($name) === '') {
      throw new RuntimeException('Укажите название (name).');
    }
    if ($email !== '' && !is_valid_email($email)) {
      throw new RuntimeException('Некорректный email.');
    }

    $st = $pdo->prepare("
      UPDATE tour_operators
      SET name=?,
          full_name=?,
          bin=?,
          address=?,
          phone=?,
          email=?,
          note=?,
          license_no=?,
          license_issue_date=NULLIF(?, ''),
          agency_contract_no=?,
          agency_contract_date=NULLIF(?, ''),
          agency_contract_expiry_date=NULLIF(?, ''),
          bank_name=?,
          bank_bik=?,
          bank_iik=?
      WHERE id=?
      LIMIT 1
    ");
    $st->execute([
      $name,
      $fullName,
      $bin,
      $address,
      $phone,
      $email,
      $note,
      $licenseNo,
      $licenseIssueDate,
      $agencyContractNo,
      $agencyContractDate,
      $agencyContractExpiryDate,
      $bankName,
      $bankBik,
      $bankIik,
      $id
    ]);

    $saved = true;
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$st = $pdo->prepare("SELECT * FROM tour_operators WHERE id=? LIMIT 1");
$st->execute([$id]);
$row = $st->fetch();

if (!$row) {
  http_response_code(404);
  echo "Туроператор не найден";
  exit;
}

require __DIR__ . '/_layout_top.php';
?>

<style>
  /* Единый стиль заголовков/секций как в tourist_view.php */
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
  .grid-2{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
  .grid-2wide{ display:grid; grid-template-columns: 1fr 220px; gap:12px; }
  .grid-3wide{ display:grid; grid-template-columns: 1fr 220px 1fr; gap:12px; }
  .grid-3contract{ display:grid; grid-template-columns: 1fr 220px 220px; gap:12px; }
  @media (max-width: 980px){
    .grid-2,.grid-2wide,.grid-3wide,.grid-3contract{ grid-template-columns: 1fr; }
  }
  .actions-row{ display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; align-items:center; }
  .input label{ color:#334155; font-weight:800; }
</style>

  <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <div>
      <h1 class="h1" style="margin-bottom:6px;">
        Карточка туроператора: <?= h((string)($row['name'] ?? '')) ?>
      </h1>
      <div class="badge">ID <?= (int)$row['id'] ?></div>
    </div>

    <div style="display:flex; gap:10px; flex-wrap:wrap;">
      <a class="btn" href="/manager/operators.php">← К списку</a>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($saved): ?>
    <div class="badge" style="margin-top:14px;border-color:rgba(34,197,94,.35); font-weight:900;">Сохранено.</div>
  <?php endif; ?>

  <form class="form" method="post" style="margin-top:14px; max-width:900px;">

    <div class="section-title">Основные данные</div>
    <div class="section">
      <div class="grid-2">
        <div class="input">
          <label>Название (коротко)</label>
          <input name="name" type="text" required value="<?= h((string)($row['name'] ?? '')) ?>">
        </div>

        <div class="input">
          <label>Полное наименование</label>
          <input name="full_name" type="text" value="<?= h((string)($row['full_name'] ?? '')) ?>">
        </div>
      </div>

      <div class="grid-2" style="margin-top:10px;">
        <div class="input">
          <label>БИН</label>
          <input name="bin" type="text" value="<?= h((string)($row['bin'] ?? '')) ?>">
        </div>
        <div class="input">
          <label>Адрес</label>
          <input name="address" type="text" value="<?= h((string)($row['address'] ?? '')) ?>">
        </div>
      </div>

      <div class="grid-2" style="margin-top:10px;">
        <div class="input">
          <label>Телефон</label>
          <input name="phone" type="text" value="<?= h((string)($row['phone'] ?? '')) ?>">
        </div>
        <div class="input">
          <label>Email</label>
          <input name="email" type="email" value="<?= h((string)($row['email'] ?? '')) ?>">
        </div>
      </div>

      <div class="input" style="margin-top:10px;">
        <label>Примечание</label>
        <textarea name="note" rows="3"><?= h((string)($row['note'] ?? '')) ?></textarea>
      </div>
    </div>

    <div class="section-title">Лицензия</div>
    <div class="section">
      <div class="grid-2wide">
        <div class="input">
          <label>Гос. лицензия на туроператорскую деятельность №</label>
          <input name="license_no" type="text" value="<?= h((string)($row['license_no'] ?? '')) ?>">
        </div>
        <div class="input">
          <label>Дата выдачи</label>
          <input name="license_issue_date" type="date" value="<?= h((string)($row['license_issue_date'] ?? '')) ?>">
        </div>
      </div>
    </div>

    <div class="section-title">Договор с турагентством</div>
    <div class="section">
      <div class="grid-3contract">
        <div class="input">
          <label>Номер договора</label>
          <input name="agency_contract_no" type="text" value="<?= h((string)($row['agency_contract_no'] ?? '')) ?>">
        </div>
        <div class="input">
          <label>Дата договора</label>
          <input name="agency_contract_date" type="date" value="<?= h((string)($row['agency_contract_date'] ?? '')) ?>">
        </div>
        <div class="input">
          <label>Срок действия (до)</label>
          <input name="agency_contract_expiry_date" type="date" value="<?= h((string)($row['agency_contract_expiry_date'] ?? '')) ?>">
        </div>
      </div>
    </div>

    <div class="section-title">Банковские реквизиты</div>
    <div class="section">
      <div class="input">
        <label>Банк</label>
        <input name="bank_name" type="text" value="<?= h((string)($row['bank_name'] ?? '')) ?>">
      </div>

      <div class="grid-2" style="margin-top:10px;">
        <div class="input">
          <label>БИК</label>
          <input name="bank_bik" type="text" value="<?= h((string)($row['bank_bik'] ?? '')) ?>">
        </div>
        <div class="input">
          <label>ИИК</label>
          <input name="bank_iik" type="text" value="<?= h((string)($row['bank_iik'] ?? '')) ?>">
        </div>
      </div>
    </div>

    <div class="actions-row">
      <button class="btn success" type="submit">Сохранить</button>
      <a class="btn" href="/manager/operators.php">Отмена</a>
    </div>
  </form>

<?php require __DIR__ . '/_layout_bottom.php'; ?>