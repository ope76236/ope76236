<?php
declare(strict_types=1);

$title = 'Создать заявку';
require __DIR__ . '/_layout_top.php';

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';

$pdo = db();

$ops = $pdo->query("SELECT id, name FROM tour_operators ORDER BY name ASC LIMIT 500")->fetchAll();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $titleIn = post('title');
    $destination = post('destination');
    $start = post('start_date');
    $end = post('end_date');
    $operatorId = (int)($_POST['operator_id'] ?? 0);

    $currency = post('currency', 'KZT');
    $total = (float)str_replace([' ', ','], ['', '.'], post('total_amount', '0'));
    $partner = (float)str_replace([' ', ','], ['', '.'], post('partner_amount', '0'));
    $tourist = (float)str_replace([' ', ','], ['', '.'], post('tourist_amount', '0'));

    $adults = (int)($_POST['adults'] ?? 0);
    $children = (int)($_POST['children'] ?? 0);
    $note = post('note');

    if ($destination === '') throw new RuntimeException('Укажите направление (страна/город/отель).');
    if ($start === '' || $end === '') throw new RuntimeException('Укажите даты тура.');
    if ($operatorId <= 0) throw new RuntimeException('Выберите туроператора.');
    if ($total < 0 || $partner < 0 || $tourist < 0) throw new RuntimeException('Суммы не могут быть отрицательными.');

    if ($titleIn === '') $titleIn = 'Тур: ' . $destination;

    $manager = current_user();
    $managerId = (int)($manager['id'] ?? 0);

    $st = $pdo->prepare("
      INSERT INTO applications(
        manager_user_id, title, destination,
        start_date, end_date,
        adults, children,
        operator_id,
        currency, total_amount, partner_amount, tourist_amount,
        status, note
      ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'draft', ?)
    ");
    $st->execute([
      $managerId ?: null,
      $titleIn, $destination,
      $start, $end,
      $adults, $children,
      $operatorId,
      $currency, $total, $partner, $tourist,
      $note
    ]);

    $id = (int)$pdo->lastInsertId();
    redirect('/manager/app_view.php?id=' . $id);
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}
?>

<style>
  :root{
    --w-strong: 750;
    --w-normal: 600;
  }

  /* общая типографика как в других файлах */
  .muted{ color:var(--muted); font-size:12px; font-weight: var(--w-normal); }

  /* раскладка */
  .create-wrap{
    display:grid;
    grid-template-columns: 1fr;
    gap:14px;
    margin-top:14px;
    max-width: 1060px;
  }

  .panel{
    border:1px solid rgba(226,232,240,.92);
    border-radius:16px;
    background: rgba(255,255,255,.72);
    padding:14px;
    box-shadow: var(--shadow);
  }

  .panel-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:10px;
  }
  .panel-title h2{
    margin:0;
    font-size:15px;
    font-weight: var(--w-strong);
    color:#0f172a;
  }

  /* красная точка как “важный блок” (по аналогии с дедлайнами) */
  .panel-title .dot{
    width:9px;
    height:9px;
    border-radius:999px;
    background: rgba(239,68,68,.95);
    box-shadow: 0 8px 18px rgba(239,68,68,.20);
    display:inline-block;
    margin-right:8px;
    flex:0 0 auto;
  }

  .grid-3{
    display:grid;
    grid-template-columns: 1fr;
    gap:12px;
  }
  @media (min-width: 900px){
    .grid-3{ grid-template-columns: 1fr 1fr 1fr; }
  }

  .grid-2{
    display:grid;
    grid-template-columns: 1fr;
    gap:12px;
  }
  @media (min-width: 900px){
    .grid-2{ grid-template-columns: 1fr 1fr; }
  }

  /* “Расчёты” — компактный блок */
  .calc-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    margin-top:4px;
  }
  .calc-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:7px 10px;
    border-radius:999px;
    border: 1px solid var(--border);
    background: rgba(255,255,255,.78);
    color: rgba(15,23,42,.65);
    font-size: 12px;
    font-weight: var(--w-normal);
    text-decoration:none;
  }

  /* кнопки: как в apps.php */
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
  .btn.btn-primary:hover{
    border-color: rgba(14,165,233,.55);
    box-shadow: 0 12px 26px rgba(2,8,23,.06);
  }

  /* подсказка под формой */
  .hint{
    color: var(--muted);
    font-size:12px;
    margin-top:10px;
    font-weight: var(--w-normal);
    line-height:1.4;
  }
</style>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div>
    <h1 class="h1" style="margin-bottom:6px;">Создать заявку</h1>
    <div class="badge">Сначала создаём заявку, затем добавляем туристов</div>
  </div>
  <a class="btn btn-sm btn-primary" href="/manager/apps.php">← К списку</a>
</div>

<?php if ($error): ?>
  <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
<?php endif; ?>

<?php if (!$ops): ?>
  <div class="alert" style="margin-top:14px;">
    Сначала добавьте хотя бы одного туроператора в справочник.
    <div style="margin-top:10px;">
      <a class="btn success" href="/manager/operator_create.php">+ Добавить туроператора</a>
    </div>
  </div>
<?php else: ?>

  <form method="post" class="create-wrap">
    <!-- Основное -->
    <div class="panel">
      <div class="panel-title">
        <div style="display:flex; align-items:center; gap:0;">
          <span class="dot" aria-hidden="true"></span>
          <h2>Основная информация</h2>
        </div>
        <div class="muted">Статус после создания: <b style="font-weight:var(--w-strong); color:#0f172a;">черновик</b></div>
      </div>

      <div class="input">
        <label>Название заявки (можно оставить пустым — заполнится автоматически)</label>
        <input name="title" type="text" value="<?= h((string)($_POST['title'] ?? '')) ?>" placeholder="Например: Тур в Анталию, семья Ивановых">
      </div>

      <div class="input">
        <label>Направление / тур</label>
        <input name="destination" type="text" required value="<?= h((string)($_POST['destination'] ?? '')) ?>" placeholder="Страна, город, отель, программа...">
      </div>

      <div class="grid-3">
        <div class="input">
          <label>Дата начала</label>
          <input name="start_date" type="date" required value="<?= h((string)($_POST['start_date'] ?? '')) ?>">
        </div>

        <div class="input">
          <label>Дата окончания</label>
          <input name="end_date" type="date" required value="<?= h((string)($_POST['end_date'] ?? '')) ?>">
        </div>

        <div class="input">
          <label>Туроператор</label>
          <select name="operator_id" required>
            <option value="">— выберите —</option>
            <?php $sel = (string)($_POST['operator_id'] ?? ''); ?>
            <?php foreach ($ops as $o): ?>
              <option value="<?= (int)$o['id'] ?>" <?= ($sel !== '' && (int)$sel === (int)$o['id']) ? 'selected' : '' ?>>
                <?= h((string)$o['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="grid-3" style="margin-top:12px;">
        <div class="input">
          <label>Взрослые</label>
          <input name="adults" type="number" min="0" value="<?= h((string)($_POST['adults'] ?? '0')) ?>">
        </div>

        <div class="input">
          <label>Дети</label>
          <input name="children" type="number" min="0" value="<?= h((string)($_POST['children'] ?? '0')) ?>">
        </div>

        <div class="input">
          <label>Валюта</label>
          <select name="currency">
            <?php $cur = (string)($_POST['currency'] ?? 'KZT'); ?>
            <?php foreach (['KZT','USD','EUR'] as $c): ?>
              <option value="<?= h($c) ?>" <?= $cur === $c ? 'selected' : '' ?>><?= h($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="hint">
        После создания вы попадёте в карточку заявки, где можно выбрать заказчика тура и добавить туристов (по базе <b style="font-weight:var(--w-strong); color:#0f172a;">tourists</b>).
      </div>
    </div>

    <!-- Расчёты -->
    <div class="panel">
      <div class="panel-title">
        <div style="display:flex; align-items:center; gap:0;">
          <span class="dot" aria-hidden="true" style="background:rgba(14,165,233,.92); box-shadow:0 8px 18px rgba(14,165,233,.18);"></span>
          <h2>Расчёты (можно заполнить позже)</h2>
        </div>
        <div class="muted">Валюта: <b style="font-weight:var(--w-strong); color:#0f172a;"><?= h((string)($_POST['currency'] ?? 'KZT')) ?></b></div>
      </div>

      <div class="calc-head">
        <div class="calc-badge">Подсказка: суммы можно вносить с пробелами и запятыми</div>
      </div>

      <div class="grid-3" style="margin-top:12px;">
        <div class="input">
          <label>Общая сумма</label>
          <input name="total_amount" type="text" value="<?= h((string)($_POST['total_amount'] ?? '0')) ?>" placeholder="0">
        </div>

        <div class="input">
          <label>Партнёры</label>
          <input name="partner_amount" type="text" value="<?= h((string)($_POST['partner_amount'] ?? '0')) ?>" placeholder="0">
        </div>

        <div class="input">
          <label>Турист</label>
          <input name="tourist_amount" type="text" value="<?= h((string)($_POST['tourist_amount'] ?? '0')) ?>" placeholder="0">
        </div>
      </div>

      <div class="hint">
        Эти поля не обязательны при создании. Финансы можно заполнить уже в карточке заявки (как на скрине с готовой заявкой).
      </div>
    </div>

    <!-- Примечание + сохранение -->
    <div class="panel">
      <div class="panel-title">
        <div style="display:flex; align-items:center; gap:0;">
          <span class="dot" aria-hidden="true" style="background:rgba(148,163,184,.9); box-shadow:0 8px 18px rgba(100,116,139,.16);"></span>
          <h2>Комментарий</h2>
        </div>
      </div>

      <div class="input">
        <label>Примечание</label>
        <input name="note" type="text" value="<?= h((string)($_POST['note'] ?? '')) ?>" placeholder="Комментарий менеджера">
      </div>

      <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;">
        <button class="btn success" type="submit">Создать</button>
        <a class="btn btn-sm btn-primary" href="/manager/apps.php">Отмена</a>
      </div>

      <div class="hint">
        Нажмите «Создать» — система сохранит заявку и перенаправит в карточку заявки для дальнейшей работы.
      </div>
    </div>
  </form>

<?php endif; ?>

<?php require __DIR__ . '/_layout_bottom.php'; ?>