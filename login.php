<?php
declare(strict_types=1);

require __DIR__ . '/app/auth.php';
require __DIR__ . '/app/helpers.php';

start_session();

$roleHint = get('role', 'tourist');
$roleHint = ($roleHint === 'manager') ? 'manager' : 'tourist';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = post('email');
  $pass  = (string)($_POST['password'] ?? '');

  if (!is_valid_email($email)) {
    $error = 'Укажите корректный email.';
  } else {
    $user = auth_attempt($email, $pass);
    if (!$user) {
      $error = 'Неверный email или пароль (либо пользователь отключён).';
    } else {
      auth_login($user);
      redirect($user['role'] === 'manager' ? '/manager/' : '/tourist/');
    }
  }
}

$adminWhatsAppPhone = '77000000000'; // TODO: заменить на номер администратора (77XXXXXXXXX)
$waText = "Здравствуйте! Нужен доступ к TurDoc CRM.\n\n"
        . "ФИО: \n"
        . "Компания/филиал: \n"
        . "Роль (менеджер/турист): \n"
        . "Телефон/Email: \n";
$waUrl = 'https://wa.me/' . rawurlencode($adminWhatsAppPhone) . '?text=' . rawurlencode($waText);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Вход — TurDoc CRM</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <meta name="description" content="Вход в TurDoc CRM. Доступ выдаётся администратором компании.">
</head>
<body class="fade-in">
  <div class="container">

    <style>
      /* Landing-style, но компактнее (страница входа) */
      .auth-wrap{ max-width: 1080px; margin: 0 auto; }
      .auth-shell{
        margin-top:16px;
        border-radius:24px;
        border:1px solid rgba(226,232,240,.92);
        background:
          radial-gradient(1200px 520px at 12% -10%, rgba(14,165,233,.18), rgba(255,255,255,0) 60%),
          radial-gradient(900px 520px at 92% 10%, rgba(34,197,94,.12), rgba(255,255,255,0) 55%),
          rgba(255,255,255,.72);
        box-shadow:
          0 36px 80px rgba(2,8,23,.08),
          0 16px 40px rgba(14,165,233,.08);
        padding:16px;
      }

      .auth-top{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
      }
      .auth-brand{
        display:flex;
        align-items:center;
        gap:10px;
        min-width:0;
      }
      .auth-title{
        font-weight:1000;
        font-size:16px;
        color:#0f172a;
        line-height:1.05;
      }
      .auth-sub{ margin-top:6px; }

      .auth-grid{
        margin-top:14px;
        display:grid;
        grid-template-columns: 1fr;
        gap:14px;
        align-items:start;
      }
      @media (min-width: 980px){
        .auth-grid{ grid-template-columns: 1.15fr 0.85fr; }
      }

      .auth-main{
        border-radius:20px;
        border:1px solid rgba(226,232,240,.92);
        background: rgba(255,255,255,.80);
        padding:16px;
        position:relative;
        overflow:hidden;
      }
      .auth-main::after{
        content:"";
        position:absolute;
        inset:-2px;
        background:
          radial-gradient(560px 280px at 18% 18%, rgba(14,165,233,.12), rgba(255,255,255,0) 62%),
          radial-gradient(560px 280px at 76% 86%, rgba(34,197,94,.10), rgba(255,255,255,0) 62%);
        pointer-events:none;
      }
      .auth-main > *{ position:relative; z-index:1; }

      .auth-h{
        margin:0;
        font-weight:1000;
        color:#0f172a;
        letter-spacing:-.02em;
        font-size:22px;
        line-height:1.2;
        max-width: 26ch;
      }
      @media (min-width: 980px){ .auth-h{ font-size:26px; } }

      .auth-p{
        margin-top:8px;
        color: rgba(15,23,42,.78);
        font-size:13px;
        line-height:1.55;
        max-width: 70ch;
      }

      .auth-form{
        margin-top:14px;
        max-width: 480px;
      }

      .auth-actions{
        display:flex;
        gap:10px;
        flex-wrap:wrap;
        align-items:center;
        margin-top:12px;
      }
      .btn.btn-lg{
        padding:12px 14px;
        border-radius:16px;
        font-size:13px;
        font-weight:1000;
        white-space:nowrap;
      }
      .btn.btn-primary{
        border-color: rgba(14,165,233,.40);
        background: rgba(14,165,233,.08);
      }
      .btn.success{
        border-color: rgba(34,197,94,.40);
        background: rgba(34,197,94,.10);
      }
      .btn.purple{
        border-color: rgba(99,102,241,.38);
        background: rgba(99,102,241,.10);
        color:#3730a3;
      }

      .auth-side{
        display:grid;
        grid-template-columns: 1fr;
        gap:10px;
      }
      .auth-card{
        border:1px solid rgba(226,232,240,.92);
        border-radius:18px;
        background: rgba(255,255,255,.80);
        padding:12px;
      }
      .auth-card .t{ color:var(--muted); font-size:12px; font-weight:900; }
      .auth-card .v{ margin-top:6px; font-weight:1000; color:#0f172a; font-size:14px; }
      .auth-card .s{ margin-top:6px; color:var(--muted); font-size:12px; line-height:1.35; }

      .auth-footer{
        margin-top:12px;
        text-align:center;
        color: var(--muted);
        font-size:12px;
        font-weight:700;
      }
      .auth-footer b{ color:#0f172a; font-weight:900; }
    </style>

    <div class="auth-wrap">
      <div class="auth-shell">

        <div class="auth-top">
          <div class="auth-brand">
            <div class="logo" aria-hidden="true"></div>
            <div style="min-width:0;">
              <div class="auth-title">TurDoc CRM</div>
              <div class="badge auth-sub">Вход в личный кабинет</div>
            </div>
          </div>

          <a class="badge" href="/">← На главную</a>
        </div>

        <div class="auth-grid">
          <div class="auth-main">
            <h1 class="auth-h">Вход</h1>
            <div class="auth-p">
              Введите данные, которые выдал администратор/менеджер компании.
            </div>

            <?php if ($error): ?>
              <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
            <?php endif; ?>

            <form class="form auth-form" method="post">
              <div class="input">
                <label>Email</label>
                <input name="email" type="email" required placeholder="name@example.com" value="<?= h((string)($_POST['email'] ?? '')) ?>">
              </div>

              <div class="input">
                <label>Пароль</label>
                <input name="password" type="password" required placeholder="••••••••">
              </div>

              <div class="auth-actions">
                <button class="btn btn-lg <?= $roleHint === 'manager' ? 'success' : 'btn-primary' ?>" type="submit">
                  Войти
                </button>

                <a class="btn btn-lg purple" href="<?= h($waUrl) ?>" target="_blank" rel="noopener">
                  Запросить доступ (WhatsApp)
                </a>
              </div>
            </form>

            <div class="hint" style="margin-top:12px;">
              Если вы забыли пароль — обратитесь к администратору (самовосстановления в этой версии нет).
            </div>
          </div>

          <aside class="auth-side">
            <div class="auth-card">
              <div class="t">Турист</div>
              <div class="v">Личный кабинет</div>
              <div class="s">Ближайший тур, договор, оплаты и история.</div>
            </div>

            <div class="auth-card">
              <div class="t">Менеджер</div>
              <div class="v">Управление</div>
              <div class="s">Заявки, туристы, туроператоры, договоры и оплаты.</div>
            </div>

            <div class="auth-card">
              <div class="t">Республика Казахстан</div>
              <div class="v">Практика и контроль</div>
              <div class="s">Процессы и документы ведутся в логике, удобной для работы на рынке РК.</div>
            </div>
          </aside>
        </div>

        <div class="auth-footer">
          Разработчик: <b>ИП «Первое турагентство»</b> · 2026 · Все права защищены
        </div>
      </div>
    </div>

  </div>

  <script src="/assets/js/app.js"></script>
</body>
</html>