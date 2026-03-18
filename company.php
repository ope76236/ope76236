<?php
declare(strict_types=1);

$title = 'Реквизиты компании';
require __DIR__ . '/_layout_top.php';

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';

$pdo = db();

$error = null;
$saved = false;

// Берём первую запись (если нет — создадим пустую)
$company = $pdo->query("SELECT * FROM companies ORDER BY id ASC LIMIT 1")->fetch();
if (!$company) {
  $pdo->query("INSERT INTO companies(name) VALUES('')");
  $company = $pdo->query("SELECT * FROM companies ORDER BY id ASC LIMIT 1")->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $name = post('name');
    $bin = preg_replace('~\D+~', '', post('bin'));
    $address = post('address');
    $phone = post('phone');
    $email = trim(mb_strtolower(post('email')));

    $bank = post('bank_name');
    $bik = post('bik');
    $iban = post('iban');
    $kbe = post('kbe');
    $knp = post('knp');

    $director = post('director_name');
    $basis = post('director_basis', 'на основании Устава');

    if ($name === '') throw new RuntimeException('Укажите название компании.');
    if ($bin !== '' && strlen($bin) !== 12) throw new RuntimeException('БИН должен содержать 12 цифр (или оставьте пустым).');
    if ($email !== '' && !is_valid_email($email)) throw new RuntimeException('Некорректный email.');

    $st = $pdo->prepare("
      UPDATE companies
      SET name=?, bin=?, address=?, phone=?, email=?,
          bank_name=?, bik=?, iban=?, kbe=?, knp=?,
          director_name=?, director_basis=?
      WHERE id=?
      LIMIT 1
    ");
    $st->execute([
      $name, $bin, $address, $phone, $email,
      $bank, $bik, $iban, $kbe, $knp,
      $director, $basis,
      (int)$company['id']
    ]);

    $company = $pdo->query("SELECT * FROM companies ORDER BY id ASC LIMIT 1")->fetch();
    $saved = true;
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}
?>

<style>
  /* Единый стиль секций как на tourist/operator/document pages */
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
  .grid-3{ display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px; }
  @media (max-width: 980px){
    .grid-2,.grid-3{ grid-template-columns: 1fr; }
  }
  .hint{
    color: var(--muted);
    font-size:12px;
    margin-top:8px;
    line-height:1.4;
  }
  .input label{ color:#334155; font-weight:800; }
</style>

  <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <div>
      <h1 class="h1" style="margin-bottom:6px;">Реквизиты компании</h1>
      <div class="badge">Эти данные подставляются в документы/договоры</div>
    </div>
    <a class="btn" href="/manager/">← В кабинет</a>
  </div>

  <?php if ($error): ?>
    <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($saved): ?>
    <div class="badge" style="margin-top:14px;border-color:rgba(34,197,94,.35); font-weight:900;">Сохранено.</div>
  <?php endif; ?>

  <form class="form" method="post" style="margin-top:14px; max-width:1100px;">

    <div class="section-title">Основные данные</div>
    <div class="section">
      <div class="input">
        <label>Название компании</label>
        <input name="name" type="text" required value="<?= h((string)$company['name']) ?>" placeholder="ТОО ...">
      </div>

      <div class="grid-2" style="margin-top:10px;">
        <div class="input">
          <label>БИН</label>
          <input name="bin" type="text" inputmode="numeric" value="<?= h((string)($company['bin'] ?? '')) ?>">
        </div>
        <div class="input">
          <label>Телефон</label>
          <input name="phone" type="text" value="<?= h((string)($company['phone'] ?? '')) ?>">
        </div>
      </div>

      <div class="grid-2" style="margin-top:10px;">
        <div class="input">
          <label>Email</label>
          <input name="email" type="email" value="<?= h((string)($company['email'] ?? '')) ?>">
        </div>
        <div class="input">
          <label>Адрес</label>
          <input name="address" type="text" value="<?= h((string)($company['address'] ?? '')) ?>">
        </div>
      </div>
    </div>

    <div class="section-title">Банк</div>
    <div class="section">
      <div class="grid-2">
        <div class="input">
          <label>Банк</label>
          <input name="bank_name" type="text" value="<?= h((string)($company['bank_name'] ?? '')) ?>">
        </div>
        <div class="input">
          <label>БИК</label>
          <input name="bik" type="text" value="<?= h((string)($company['bik'] ?? '')) ?>">
        </div>
      </div>

      <div class="grid-3" style="margin-top:10px;">
        <div class="input">
          <label>IBAN</label>
          <input name="iban" type="text" value="<?= h((string)($company['iban'] ?? '')) ?>">
        </div>
        <div class="input">
          <label>КБЕ</label>
          <input name="kbe" type="text" value="<?= h((string)($company['kbe'] ?? '')) ?>">
        </div>
        <div class="input">
          <label>КНП</label>
          <input name="knp" type="text" value="<?= h((string)($company['knp'] ?? '')) ?>">
        </div>
      </div>
    </div>

    <div class="section-title">Подписант</div>
    <div class="section">
      <div class="grid-2">
        <div class="input">
          <label>ФИО директора/подписанта</label>
          <input name="director_name" type="text" value="<?= h((string)($company['director_name'] ?? '')) ?>" placeholder="Иванов И.И.">
        </div>
        <div class="input">
          <label>Основание</label>
          <input name="director_basis" type="text" value="<?= h((string)($company['director_basis'] ?? 'на основании Устава')) ?>">
        </div>
      </div>

      <div class="hint">
        Под РК: БИН/реквизиты/подписант нужны для корректного договора и документов.
      </div>
    </div>

    <button class="btn success" type="submit">Сохранить</button>
  </form>

<?php require __DIR__ . '/_layout_bottom.php'; ?>