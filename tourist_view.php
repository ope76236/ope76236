<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/mailer.php';

require_role('manager');

$pdo = db();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(404);
  echo "Не указан ID";
  exit;
}

$config = require __DIR__ . '/../app/config.php';
$baseUrl = (string)($config['urls']['base'] ?? '');
$mailFrom = (string)($config['mail']['from_email'] ?? '');
$mailFromName = (string)($config['mail']['from_name'] ?? ($config['app']['name'] ?? 'TurDoc CRM'));

$title = 'Карточка туриста';

$error = null;
$saved = false;
$newPasswordShown = null;
$mailSent = null; // null/true/false
$mailError = null;

function notify_tourist_access(PDO $pdo, int $touristUserId, string $plainPassword, string $baseUrl, string $mailFrom, string $mailFromName, ?string &$mailError = null): bool
{
  $mailError = null;

  if ($plainPassword === '') { $mailError = 'Пустой пароль'; return false; }
  if ($baseUrl === '') { $mailError = 'Не задан urls.base в app/config.php'; return false; }
  if ($mailFrom === '') { $mailError = 'Не задан mail.from_email в app/config.php'; return false; }

  $st = $pdo->prepare("SELECT email FROM users WHERE id=? AND role='tourist' LIMIT 1");
  $st->execute([$touristUserId]);
  $uRow = $st->fetch();
  $toEmail = (string)($uRow['email'] ?? '');
  if ($toEmail === '') { $mailError = 'У туриста пустой email'; return false; }

  $cabinetUrl = rtrim($baseUrl, '/') . '/tourist/';
  $changePassUrl = rtrim($baseUrl, '/') . '/tourist/password.php';

  $subject = "Доступ в личный кабинет туриста";

  $safePass = htmlspecialchars($plainPassword, ENT_QUOTES);
  $safeName = htmlspecialchars($mailFromName, ENT_QUOTES);

  $html = "
  <div style='font-family:Arial,sans-serif; color:#0f172a; line-height:1.5'>
    <h2 style='margin:0 0 10px'>Доступ в личный кабинет</h2>
    <p style='margin:0 0 12px'>
      Для вас создан (или обновлён) доступ в личный кабинет туриста в {$safeName}.
    </p>

    <p style='margin:0 0 8px'><b>Ссылка:</b> <a href='{$cabinetUrl}'>{$cabinetUrl}</a></p>
    <p style='margin:0 0 8px'><b>Логин:</b> {$toEmail}</p>
    <p style='margin:0 0 12px'><b>Пароль:</b> {$safePass}</p>

    <p style='margin:0 0 12px'>
      После входа рекомендуем сменить пароль:
      <a href='{$changePassUrl}'>{$changePassUrl}</a>
    </p>

    <hr style='border:none; border-top:1px solid #e2e8f0; margin:16px 0'>
    <p style='margin:0; color:#475569; font-size:12px'>
      Если вы не ожидали это письмо — просто проигнорируйте его.
    </p>
  </div>
  ";

  $ok = send_mail($toEmail, $subject, $html, $mailFrom, $mailFromName);

  if (!$ok) {
    $mailError = 'mail() вернул false (проверьте логи почты/настройки домена)';
  }

  return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = post('_action', 'save_profile');

    if ($action === 'save_profile') {
      $email = trim(mb_strtolower(post('email')));
      $name = post('name');
      $phone = post('phone');
      $active = (int)($_POST['active'] ?? 0) === 1 ? 1 : 0;

      $iin = preg_replace('~\D+~', '', post('iin'));
      $last = post('last_name');
      $first = post('first_name');
      $middle = post('middle_name');

      // EN: всегда автотранслитерация (без галочки)
      $lastEn = translit_to_en($last);
      $firstEn = translit_to_en($first);
      $middleEn = translit_to_en($middle);

      $birth = post('birth_date');

      $passportNo = post('passport_no');
      $pIssue = post('passport_issue_date');
      $passportIssuedBy = post('passport_issued_by');
      $pExp = post('passport_expiry_date');

      $idCardNo = post('id_card_no');
      $idCardIssueDate = post('id_card_issue_date');
      $idCardIssuedBy = post('id_card_issued_by');

      $cit = post('citizenship');
      $addr = post('address');

      $emgName = post('emergency_contact_full_name');
      $emgPhone = post('emergency_contact_phone');
      $emgRel = post('emergency_contact_relation');

      $bcNo = post('birth_certificate_no');
      $bcIssueDate = post('birth_certificate_issue_date');
      $bcIssuedBy = post('birth_certificate_issued_by');

      if (!is_valid_email($email)) throw new RuntimeException('Некорректный email.');
      if ($iin !== '' && strlen($iin) !== 12) throw new RuntimeException('ИИН должен быть 12 цифр (или пусто).');
      if ($last === '' || $first === '') throw new RuntimeException('Фамилия и имя обязательны.');

      $stE = $pdo->prepare("SELECT id FROM users WHERE email=? AND id<>? LIMIT 1");
      $stE->execute([$email, $id]);
      if ($stE->fetch()) throw new RuntimeException('Такой email уже используется другим пользователем.');

      $pdo->beginTransaction();

      $upU = $pdo->prepare("UPDATE users SET email=?, name=?, phone=?, active=? WHERE id=? AND role='tourist'");
      $upU->execute([$email, $name, $phone, $active, $id]);

      $upT = $pdo->prepare("
        UPDATE tourists
        SET iin=?,
            last_name=?, first_name=?, middle_name=?,
            last_name_en=?, first_name_en=?, middle_name_en=?,
            birth_date=NULLIF(?, ''),
            passport_no=?,
            passport_issue_date=NULLIF(?, ''),
            passport_issued_by=?,
            passport_expiry_date=NULLIF(?, ''),
            id_card_no=?,
            id_card_issue_date=NULLIF(?, ''),
            id_card_issued_by=?,
            citizenship=?,
            address=?,
            emergency_contact_full_name=?,
            emergency_contact_phone=?,
            emergency_contact_relation=?,
            birth_certificate_no=?,
            birth_certificate_issue_date=NULLIF(?, ''),
            birth_certificate_issued_by=?
        WHERE user_id=?
      ");
      $upT->execute([
        $iin,
        $last, $first, $middle,
        $lastEn, $firstEn, $middleEn,
        $birth,
        $passportNo,
        $pIssue,
        $passportIssuedBy,
        $pExp,
        $idCardNo,
        $idCardIssueDate,
        $idCardIssuedBy,
        $cit,
        $addr,
        $emgName,
        $emgPhone,
        $emgRel,
        $bcNo,
        $bcIssueDate,
        $bcIssuedBy,
        $id
      ]);

      $pdo->commit();
      $saved = true;
    }

    if ($action === 'reset_password') {
      $plain = gen_password(10);
      $hash = password_hash($plain, PASSWORD_DEFAULT);

      $st = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=? AND role='tourist' LIMIT 1");
      $st->execute([$hash, $id]);

      $newPasswordShown = $plain;
      $saved = true;

      $mailSent = notify_tourist_access($pdo, $id, $plain, $baseUrl, $mailFrom, $mailFromName, $mailError);
    }

    if ($action === 'set_password') {
      $new = (string)($_POST['new_password'] ?? '');
      $new2 = (string)($_POST['new_password2'] ?? '');

      if (mb_strlen($new) < 6) throw new RuntimeException('Пароль минимум 6 символов.');
      if ($new !== $new2) throw new RuntimeException('Пароли не совпадают.');

      $hash = password_hash($new, PASSWORD_DEFAULT);
      $st = $pdo->prepare("UPDATE users SET password_hash=? WHERE id=? AND role='tourist' LIMIT 1");
      $st->execute([$hash, $id]);

      $newPasswordShown = $new;
      $saved = true;

      $mailSent = notify_tourist_access($pdo, $id, $new, $baseUrl, $mailFrom, $mailFromName, $mailError);
    }

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
  }
}

$st = $pdo->prepare("
  SELECT u.id, u.email, u.name, u.phone, u.active, u.created_at,
         t.iin, t.last_name, t.first_name, t.middle_name,
         t.last_name_en, t.first_name_en, t.middle_name_en,
         t.birth_date,
         t.passport_no, t.passport_issue_date, t.passport_issued_by, t.passport_expiry_date,
         t.id_card_no, t.id_card_issue_date, t.id_card_issued_by,
         t.citizenship, t.address,
         t.emergency_contact_full_name, t.emergency_contact_phone, t.emergency_contact_relation,
         t.birth_certificate_no, t.birth_certificate_issue_date, t.birth_certificate_issued_by
  FROM users u
  LEFT JOIN tourists t ON t.user_id = u.id
  WHERE u.id=? AND u.role='tourist'
  LIMIT 1
");
$st->execute([$id]);
$row = $st->fetch();

if (!$row) {
  http_response_code(404);
  echo "Турист не найден";
  exit;
}

$fio = trim(($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''));

require __DIR__ . '/_layout_top.php';
?>

<style>
  :root{ --w-strong: 750; --w-normal: 600; }
  .muted{ color:var(--muted); font-size:12px; font-weight: var(--w-normal); }
  .nowrap{ white-space:nowrap; }
  .ellipsis{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

  .section-title{
    margin-top:18px;
    font-weight: var(--w-strong);
    color:#0f172a;
    font-size:15px;
    display:flex;
    align-items:center;
    gap:10px;
  }
  .section-title::before{
    content:"";
    width:9px; height:9px;
    border-radius:999px;
    background: rgba(14,165,233,.92);
    box-shadow: 0 8px 18px rgba(14,165,233,.18);
    display:inline-block;
  }
  .section{
    margin-top:10px;
    padding:14px;
    border-radius:16px;
    border:1px solid rgba(226,232,240,.92);
    background: rgba(255,255,255,.72);
  }
  .hint{ color: var(--muted); font-size:12px; margin-top:6px; font-weight: var(--w-normal); line-height:1.35; }
  .actions-row{ display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; align-items:center; }

  .grid-2{ display:grid; grid-template-columns: 1fr; gap:12px; }
  @media (min-width: 980px){ .grid-2{ grid-template-columns: 1fr 1fr; } }

  .grid-3{ display:grid; grid-template-columns: 1fr; gap:12px; }
  @media (min-width: 980px){ .grid-3{ grid-template-columns: 1fr 1fr 1fr; } }

  .grid-3wide{ display:grid; grid-template-columns: 1fr; gap:12px; }
  @media (min-width: 980px){ .grid-3wide{ grid-template-columns: 1fr 220px 1fr; } }

  .grid-2wide{ display:grid; grid-template-columns: 1fr; gap:12px; }
  @media (min-width: 980px){ .grid-2wide{ grid-template-columns: 1fr 220px; } }

  .grid-1wide{ display:grid; grid-template-columns: 1fr; gap:12px; }
  @media (min-width: 980px){ .grid-1wide{ grid-template-columns: 1fr 160px; } }

  .grid-contacts{ display:grid; grid-template-columns: 1fr; gap:12px; }
  @media (min-width: 980px){ .grid-contacts{ grid-template-columns: 1fr 220px 220px; } }

  .btn.btn-sm{ padding:8px 10px; border-radius:12px; font-size:12px; font-weight: var(--w-normal); }
  .btn.btn-primary{ border-color: rgba(14,165,233,.40); background: rgba(14,165,233,.08); }
  .btn.btn-primary:hover{ border-color: rgba(14,165,233,.55); box-shadow: 0 12px 26px rgba(2,8,23,.06); }

  .ok-badge{
    margin-top:14px;
    border-color: rgba(34,197,94,.35);
    color: rgba(22,163,74,1);
    background: rgba(34,197,94,.06);
    font-weight: var(--w-normal);
  }
  .warn-badge{
    margin-top:6px;
    border-color: rgba(245,158,11,.35);
    color:#b45309;
    background: rgba(245,158,11,.08);
  }
  .page-title{ margin:0; font-size: 22px; font-weight: var(--w-strong); letter-spacing:-.01em; }
</style>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div style="min-width:0;">
    <h1 class="page-title ellipsis" title="<?= h($fio ?: (string)$row['name']) ?>">
      <?= h($fio ?: (string)$row['name']) ?>
    </h1>
    <div class="badge">
      Турист · ID <?= (int)$row['id'] ?> · <?= h((string)$row['email']) ?>
    </div>
  </div>
  <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn btn-sm btn-primary" href="/manager/tourists.php">← К списку</a>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($saved): ?>
  <div class="badge ok-badge">
    Сохранено.
    <?php if ($newPasswordShown !== null): ?>
      <div class="muted" style="margin-top:6px;">
        Новый пароль (показывается один раз): <b style="font-weight:var(--w-strong); color:#0f172a;"><?= h($newPasswordShown) ?></b>
      </div>
    <?php endif; ?>
    <?php if ($mailSent === true): ?>
      <div class="muted" style="margin-top:6px;">Письмо с доступом отправлено на email туриста.</div>
    <?php elseif ($mailSent === false && ($newPasswordShown !== null)): ?>
      <div class="badge warn-badge">
        Письмо не отправлено<?= $mailError ? (': ' . h($mailError)) : '' ?>.
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="section-title">Пароль туриста</div>
<div class="section">
  <div class="actions-row">
    <form method="post" style="margin:0;">
      <input type="hidden" name="_action" value="reset_password">
      <button class="btn success" type="submit" onclick="return confirm('Сгенерировать новый пароль? Старый перестанет работать.');">
        Сгенерировать новый пароль
      </button>
    </form>
  </div>

  <form method="post" class="form" style="margin-top:10px; max-width:720px;">
    <input type="hidden" name="_action" value="set_password">
    <div class="grid-2">
      <div class="input">
        <label>Установить пароль вручную</label>
        <input name="new_password" type="password" placeholder="Минимум 6 символов">
      </div>
      <div class="input">
        <label>Повторите пароль</label>
        <input name="new_password2" type="password" placeholder="Повтор">
      </div>
    </div>
    <button class="btn btn-primary" type="submit" style="margin-top:10px;">Установить пароль</button>
    <div class="hint">После смены пароля система попытается отправить письмо туристу с доступом.</div>
  </form>
</div>

<div class="section-title">Данные туриста</div>
<form class="form" method="post" style="margin-top:10px;">
  <input type="hidden" name="_action" value="save_profile">

  <div class="section">
    <div class="grid-2">
      <div class="input">
        <label>Email (логин)</label>
        <input name="email" type="email" required value="<?= h((string)$row['email']) ?>">
      </div>
      <div class="input">
        <label>Телефон</label>
        <input name="phone" type="text" value="<?= h((string)($row['phone'] ?? '')) ?>">
      </div>
    </div>

    <div class="grid-1wide" style="margin-top:10px;">
      <div class="input">
        <label>Отображаемое имя (для интерфейса)</label>
        <input name="name" type="text" value="<?= h((string)($row['name'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Активен</label>
        <select name="active">
          <option value="1" <?= ((int)$row['active'] === 1 ? 'selected' : '') ?>>Да</option>
          <option value="0" <?= ((int)$row['active'] === 0 ? 'selected' : '') ?>>Нет</option>
        </select>
      </div>
    </div>
  </div>

  <div class="section-title">ФИО (RU/KZ)</div>
  <div class="section">
    <div class="grid-3">
      <div class="input">
        <label>Фамилия</label>
        <input name="last_name" type="text" required value="<?= h((string)($row['last_name'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Имя</label>
        <input name="first_name" type="text" required value="<?= h((string)($row['first_name'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Отчество</label>
        <input name="middle_name" type="text" value="<?= h((string)($row['middle_name'] ?? '')) ?>">
      </div>
    </div>
  </div>

  <div class="section-title">ФИО латиницей (EN, для документов)</div>
  <div class="section">
    <div class="grid-3">
      <div class="input">
        <label>Фамилия (EN)</label>
        <input name="last_name_en" type="text" value="<?= h((string)($row['last_name_en'] ?? '')) ?>" readonly>
      </div>
      <div class="input">
        <label>Имя (EN)</label>
        <input name="first_name_en" type="text" value="<?= h((string)($row['first_name_en'] ?? '')) ?>" readonly>
      </div>
      <div class="input">
        <label>Отчество (EN)</label>
        <input name="middle_name_en" type="text" value="<?= h((string)($row['middle_name_en'] ?? '')) ?>" readonly>
      </div>
    </div>

    <div class="hint">
      Латиница заполняется автоматически при сохранении (по ФИО RU/KZ).
    </div>
  </div>

  <div class="section-title">Идентификация</div>
  <div class="section">
    <div class="grid-2wide">
      <div class="input">
        <label>ИИН (12 цифр)</label>
        <input name="iin" type="text" inputmode="numeric" value="<?= h((string)($row['iin'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Дата рождения</label>
        <input name="birth_date" type="date" value="<?= h((string)($row['birth_date'] ?? '')) ?>">
      </div>
    </div>
  </div>

  <div class="section-title">Паспорт</div>
  <div class="section">
    <div class="grid-3wide">
      <div class="input">
        <label>Номер паспорта</label>
        <input name="passport_no" type="text" value="<?= h((string)($row['passport_no'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Дата выдачи</label>
        <input name="passport_issue_date" type="date" value="<?= h((string)($row['passport_issue_date'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Кем выдан</label>
        <input name="passport_issued_by" type="text" value="<?= h((string)($row['passport_issued_by'] ?? '')) ?>">
      </div>
    </div>

    <div class="grid-2wide" style="margin-top:10px;">
      <div class="input">
        <label>Срок действия</label>
        <input name="passport_expiry_date" type="date" value="<?= h((string)($row['passport_expiry_date'] ?? '')) ?>">
      </div>
      <div></div>
    </div>
  </div>

  <div class="section-title">Удостоверение личности</div>
  <div class="section">
    <div class="grid-3wide">
      <div class="input">
        <label>Номер удостоверения</label>
        <input name="id_card_no" type="text" value="<?= h((string)($row['id_card_no'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Дата выдачи</label>
        <input name="id_card_issue_date" type="date" value="<?= h((string)($row['id_card_issue_date'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Кем выдано</label>
        <input name="id_card_issued_by" type="text" value="<?= h((string)($row['id_card_issued_by'] ?? '')) ?>">
      </div>
    </div>
  </div>

  <div class="section-title">Экстренная связь</div>
  <div class="section">
    <div class="grid-contacts">
      <div class="input">
        <label>ФИО</label>
        <input name="emergency_contact_full_name" type="text" value="<?= h((string)($row['emergency_contact_full_name'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Телефон</label>
        <input name="emergency_contact_phone" type="text" value="<?= h((string)($row['emergency_contact_phone'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Степень родства</label>
        <input name="emergency_contact_relation" type="text" value="<?= h((string)($row['emergency_contact_relation'] ?? '')) ?>">
      </div>
    </div>
  </div>

  <div class="section-title">Свидетельство о рождении</div>
  <div class="section">
    <div class="grid-3wide">
      <div class="input">
        <label>Номер</label>
        <input name="birth_certificate_no" type="text" value="<?= h((string)($row['birth_certificate_no'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Дата выдачи</label>
        <input name="birth_certificate_issue_date" type="date" value="<?= h((string)($row['birth_certificate_issue_date'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Кем выдано</label>
        <input name="birth_certificate_issued_by" type="text" value="<?= h((string)($row['birth_certificate_issued_by'] ?? '')) ?>">
      </div>
    </div>
  </div>

  <div class="section-title">Адрес</div>
  <div class="section">
    <div class="grid-2">
      <div class="input">
        <label>Гражданство</label>
        <input name="citizenship" type="text" value="<?= h((string)($row['citizenship'] ?? 'Казахстан')) ?>">
      </div>
      <div class="input">
        <label>Адрес</label>
        <input name="address" type="text" value="<?= h((string)($row['address'] ?? '')) ?>">
      </div>
    </div>
  </div>

  <div class="actions-row">
    <button class="btn success" type="submit">Сохранить</button>
    <a class="btn btn-sm btn-primary" href="/manager/tourists.php">Отмена</a>
  </div>
</form>

<?php require __DIR__ . '/_layout_bottom.php'; ?>