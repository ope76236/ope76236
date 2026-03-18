<?php
declare(strict_types=1);

$title = 'Смена пароля';
require __DIR__ . '/_layout_top.php';

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';

$pdo = db();
$u = current_user();
$uid = (int)($u['id'] ?? 0);

$error = null;
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $old = (string)($_POST['old_password'] ?? '');
    $new1 = (string)($_POST['new_password'] ?? '');
    $new2 = (string)($_POST['new_password2'] ?? '');

    if (mb_strlen($new1) < 6) throw new RuntimeException('Новый пароль должен быть минимум 6 символов.');
    if ($new1 !== $new2) throw new RuntimeException('Новые пароли не совпадают.');

    $st = $pdo->prepare("SELECT password_hash FROM users WHERE id=? AND role='tourist' LIMIT 1");
    $st->execute([$uid]);
    $row = $st->fetch();
    if (!$row) throw new RuntimeException('Пользователь не найден.');

    if (!password_verify($old, (string)$row['password_hash'])) {
      throw new RuntimeException('Старый пароль неверный.');
    }

    $hash = password_hash($new1, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password_hash=? WHERE id=? LIMIT 1")->execute([$hash, $uid]);

    // обновим сессию
    start_session();
    $_SESSION['user']['id'] = $uid;

    $ok = true;
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}
?>

  <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
    <div>
      <h1 class="h1" style="margin-bottom:6px;">Смена пароля</h1>
      <div class="badge">Рекомендуется изменить пароль после получения от менеджера</div>
    </div>
    <a class="btn" href="/tourist/">← В кабинет</a>
  </div>

  <?php if ($error): ?>
    <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
  <?php endif; ?>

  <?php if ($ok): ?>
    <div class="badge" style="margin-top:14px;border-color:rgba(34,197,94,.35);">
      Пароль успешно изменён.
    </div>
  <?php endif; ?>

  <form class="form" method="post" style="margin-top:14px; max-width:720px;">
    <div class="input">
      <label>Старый пароль</label>
      <input name="old_password" type="password" required placeholder="••••••••">
    </div>

    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
      <div class="input">
        <label>Новый пароль</label>
        <input name="new_password" type="password" required placeholder="Минимум 6 символов">
      </div>
      <div class="input">
        <label>Новый пароль ещё раз</label>
        <input name="new_password2" type="password" required placeholder="Повторите новый пароль">
      </div>
    </div>

    <button class="btn primary" type="submit">Сменить пароль</button>

    <div class="badge" style="margin-top:10px;">
      Совет: используйте уникальный пароль и не передавайте его третьим лицам.
    </div>
  </form>

<?php require __DIR__ . '/_layout_bottom.php'; ?>