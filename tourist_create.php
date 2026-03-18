<?php
declare(strict_types=1);

/**
 * DEBUG режим:
 * /manager/tourist_create.php?debug=1
 */
$debug = (isset($_GET['debug']) && $_GET['debug'] === '1');
if ($debug) {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/mailer.php';

require_role('manager');

$title = 'Добавить туриста';

$pdo = db();

$config = require __DIR__ . '/../app/config.php';
$baseUrl = (string)($config['urls']['base'] ?? '');
$mailFrom = (string)($config['mail']['from_email'] ?? '');
$mailFromName = (string)($config['mail']['from_name'] ?? ($config['app']['name'] ?? 'TurDoc CRM'));

$error = null;
$created = null;
$mailSent = null; // null/true/false

$debugDump = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $email = trim(mb_strtolower(post('email')));
    $phone = post('phone');
    $last = post('last_name');
    $first = post('first_name');
    $middle = post('middle_name');
    $iin = preg_replace('~\D+~', '', post('iin'));

    if ($debug) {
      $debugDump['post'] = [
        'email' => $email,
        'phone' => $phone,
        'last_name' => $last,
        'first_name' => $first,
        'middle_name' => $middle,
        'iin' => $iin,
      ];
      $debugDump['config'] = [
        'baseUrl' => $baseUrl,
        'mailFrom' => $mailFrom,
        'mailFromName' => $mailFromName,
        'has_send_tourist_welcome_email' => function_exists('send_tourist_welcome_email') ? 'yes' : 'no',
        'has_send_mail' => function_exists('send_mail') ? 'yes' : 'no',
      ];
    }

    if (!is_valid_email($email)) {
      throw new RuntimeException('Укажите корректный email туриста.');
    }
    if ($iin !== '' && strlen($iin) !== 12) {
      throw new RuntimeException('ИИН должен содержать 12 цифр (или оставьте пустым).');
    }
    if ($last === '' || $first === '') {
      throw new RuntimeException('Фамилия и имя обязательны.');
    }

    $lastEn = translit_to_en($last);
    $firstEn = translit_to_en($first);
    $middleEn = translit_to_en($middle);

    if ($debug) {
      $debugDump['translit'] = [
        'last_en' => $lastEn,
        'first_en' => $firstEn,
        'middle_en' => $middleEn,
      ];
    }

    $st = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    $st->execute([$email]);
    if ($st->fetch()) {
      throw new RuntimeException('Пользователь с таким email уже существует.');
    }

    $plainPass = gen_password(10);
    $hash = password_hash($plainPass, PASSWORD_DEFAULT);

    if ($debug) {
      $debugDump['generated'] = [
        'plainPass_len' => mb_strlen($plainPass),
        'hash_prefix' => substr($hash, 0, 20) . '...',
      ];
    }

    $pdo->beginTransaction();

    $insU = $pdo->prepare("INSERT INTO users(role,email,password_hash,name,phone,active) VALUES('tourist',?,?,?,?,1)");
    $insU->execute([$email, $hash, trim($last.' '.$first.' '.$middle), $phone]);
    $userId = (int)$pdo->lastInsertId();

    $insT = $pdo->prepare("
      INSERT INTO tourists(
        user_id, iin,
        last_name, first_name, middle_name,
        last_name_en, first_name_en, middle_name_en
      )
      VALUES(?,?,?,?,?,?,?,?)
    ");
    $insT->execute([$userId, $iin, $last, $first, $middle, $lastEn, $firstEn, $middleEn]);

    $pdo->commit();

    $created = [
      'user_id' => $userId,
      'email' => $email,
      'password' => $plainPass, // показываем один раз
      'fio' => trim($last.' '.$first.' '.$middle),
      'fio_en' => trim($lastEn.' '.$firstEn.' '.$middleEn),
    ];

    // Письмо туристу (НЕ должно ломать создание)
    $mailSent = false;
    if ($baseUrl !== '' && $mailFrom !== '' && function_exists('send_tourist_welcome_email')) {
      try {
        // ваш текущий формат вызова
        $mailSent = (bool)send_tourist_welcome_email($email, $email, $plainPass, $baseUrl, $mailFrom);
      } catch (Throwable $e) {
        $mailSent = false;
        if ($debug) $debugDump['mail_exception'] = $e->getMessage();
      }
    } else {
      if ($debug) {
        $debugDump['mail_skip_reason'] = [
          'baseUrl_empty' => ($baseUrl === '') ? 'yes' : 'no',
          'mailFrom_empty' => ($mailFrom === '') ? 'yes' : 'no',
          'function_missing' => function_exists('send_tourist_welcome_email') ? 'no' : 'yes',
        ];
      }
    }

    if ($debug) $debugDump['mailSent'] = $mailSent ? 'true' : 'false';

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
    if ($debug) {
      $debugDump['exception'] = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
      ];
    }
  }
}

require __DIR__ . '/_layout_top.php';
?>

  <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <div>
      <h1 class="h1" style="margin-bottom:6px;">Добавить туриста</h1>
      <div class="badge">Система создаст логин (email), сгенерирует пароль и заполнит ФИО латиницей</div>
    </div>
    <a class="btn" href="/manager/tourists.php">← К списку</a>
  </div>

  <?php if ($debug): ?>
    <div class="badge" style="margin-top:12px;border-color:rgba(14,165,233,.35);">
      <b>DEBUG включён</b> (добавьте/уберите <code>?debug=1</code>)
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($created): ?>
    <div class="badge" style="margin-top:14px;border-color:rgba(34,197,94,.35);">
      <div style="font-weight:900; margin-bottom:6px;">Турист создан</div>
      <div>ФИО: <b><?= h($created['fio']) ?></b></div>
      <div>FIO (EN): <b><?= h($created['fio_en']) ?></b></div>
      <div>Логин: <b><?= h($created['email']) ?></b></div>
      <div>Пароль (показывается один раз): <b><?= h($created['password']) ?></b></div>

      <?php if ($mailSent === true): ?>
        <div style="margin-top:8px;">Письмо с доступом отправлено на email туриста.</div>
      <?php elseif ($mailSent === false): ?>
        <div style="margin-top:8px;color:#b45309;">Письмо не отправлено (проверьте app/config.php mail/urls или mailer).</div>
      <?php endif; ?>

      <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
        <a class="btn success" href="/manager/tourist_view.php?id=<?= (int)$created['user_id'] ?>">Открыть карточку</a>
        <a class="btn" href="/manager/tourists.php">К списку туристов</a>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($debug && $debugDump): ?>
    <h3 style="margin-top:16px;">DEBUG dump</h3>
    <pre style="white-space:pre-wrap; font-size:12px; background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:10px;"><?= h(print_r($debugDump, true)) ?></pre>
  <?php endif; ?>

  <form class="form" method="post" style="margin-top:14px; max-width:720px;">
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
      <div class="input">
        <label>Email (логин)</label>
        <input name="email" type="email" required placeholder="tourist@example.com" value="<?= h((string)($_POST['email'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Телефон</label>
        <input name="phone" type="text" placeholder="+7 ..." value="<?= h((string)($_POST['phone'] ?? '')) ?>">
      </div>
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px;">
      <div class="input">
        <label>Фамилия</label>
        <input name="last_name" type="text" required value="<?= h((string)($_POST['last_name'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Имя</label>
        <input name="first_name" type="text" required value="<?= h((string)($_POST['first_name'] ?? '')) ?>">
      </div>
      <div class="input">
        <label>Отчество</label>
        <input name="middle_name" type="text" value="<?= h((string)($_POST['middle_name'] ?? '')) ?>">
      </div>
    </div>

    <div class="input">
      <label>ИИН (12 цифр, можно оставить пустым)</label>
      <input name="iin" type="text" inputmode="numeric" placeholder="ИИН" value="<?= h((string)($_POST['iin'] ?? '')) ?>">
    </div>

    <button class="btn success" type="submit">Создать туриста</button>

    <div class="badge" style="margin-top:10px;">
      Пароль будет сгенерирован автоматически. ФИО (EN) заполнится автоматически.
    </div>
  </form>

<?php require __DIR__ . '/_layout_bottom.php'; ?>