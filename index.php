<?php
declare(strict_types=1);

require __DIR__ . '/app/auth.php';

$u = current_user();

$userLabel = $u
  ? (string)($u['name'] ?? $u['email'] ?? 'Пользователь')
  : '';

// ВАЖНО: замените номер на номер администратора в формате 77XXXXXXXXX (без + и пробелов)
$adminWhatsAppPhone = '77029407740';
$waText = "Здравствуйте! Хочу получить доступ к TurDoc CRM.\n\n"
        . "Компания/филиал: \n"
        . "ФИО: \n"
        . "Роль (менеджер/турист): \n"
        . "Телефон/Email: \n";

$waUrl = 'https://wa.me/' . rawurlencode($adminWhatsAppPhone) . '?text=' . rawurlencode($waText);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>TurDoc CRM — управление турами</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <meta name="description" content="TurDoc CRM — деловая CRM для турагентств и туроператоров. Заявки, документы, оплаты и дедлайны. Учёт процессов с учетом требований законодательства Республики Казахстан.">
</head>
<body class="fade-in">
  <div class="container">
    <style>
      .lp-wrap{ max-width: 1120px; margin: 0 auto; }
      .lp-shell{
        margin-top:16px;
        border-radius:24px;
        border:1px solid rgba(226,232,240,.92);
        background:
          radial-gradient(1200px 520px at 12% -10%, rgba(14,165,233,.20), rgba(255,255,255,0) 60%),
          radial-gradient(900px 520px at 92% 12%, rgba(34,197,94,.14), rgba(255,255,255,0) 55%),
          radial-gradient(900px 520px at 92% 100%, rgba(99,102,241,.10), rgba(255,255,255,0) 55%),
          rgba(255,255,255,.72);
        box-shadow:
          0 40px 90px rgba(2,8,23,.08),
          0 18px 45px rgba(14,165,233,.08);
        padding:16px;
      }

      .lp-top{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
      }
      .lp-brand{
        display:flex;
        align-items:center;
        gap:10px;
        min-width:0;
      }
      .lp-title{
        font-weight:1000;
        font-size:16px;
        color:#0f172a;
        line-height:1.05;
      }
      .lp-sub{ margin-top:6px; }

      .lp-hero{
        margin-top:14px;
        border-radius:20px;
        border:1px solid rgba(226,232,240,.92);
        background: rgba(255,255,255,.78);
        padding:16px;
        position:relative;
        overflow:hidden;
      }
      .lp-hero::after{
        content:"";
        position:absolute;
        inset:-2px;
        background:
          radial-gradient(560px 280px at 18% 18%, rgba(14,165,233,.12), rgba(255,255,255,0) 62%),
          radial-gradient(560px 280px at 76% 86%, rgba(34,197,94,.10), rgba(255,255,255,0) 62%);
        pointer-events:none;
      }
      .lp-hero > *{ position:relative; z-index:1; }

      .lp-grid{
        display:grid;
        grid-template-columns: 1fr;
        gap:14px;
        align-items:start;
      }
      @media (min-width: 980px){
        .lp-grid{ grid-template-columns: 1.35fr 0.65fr; }
      }

      .lp-h{
        margin:0;
        color:#0f172a;
        font-weight:1000;
        letter-spacing:-.02em;
        font-size:22px;
        line-height:1.18;
        max-width: 32ch;
      }
      @media (min-width: 980px){ .lp-h{ font-size:30px; } }

      .lp-p{
        margin-top:10px;
        color: rgba(15,23,42,.78);
        font-size:13px;
        line-height:1.55;
        max-width: 72ch;
      }

      .lp-tags{
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        margin-top:12px;
      }
      .lp-tag{
        display:inline-flex;
        align-items:center;
        gap:8px;
        padding:8px 10px;
        border-radius:999px;
        border:1px solid rgba(226,232,240,.92);
        background: rgba(255,255,255,.80);
        font-size:12px;
        font-weight:900;
        color: rgba(15,23,42,.75);
        white-space:nowrap;
      }
      .lp-tag::before{
        content:"";
        width:8px; height:8px;
        border-radius:999px;
        background: rgba(14,165,233,.92);
        box-shadow: 0 10px 22px rgba(14,165,233,.18);
        display:inline-block;
      }
      .lp-tag.green::before{
        background: rgba(34,197,94,.92);
        box-shadow: 0 10px 22px rgba(34,197,94,.18);
      }
      .lp-tag.purple::before{
        background: rgba(99,102,241,.92);
        box-shadow: 0 10px 22px rgba(99,102,241,.18);
      }

      .lp-cta{
        display:flex;
        gap:10px;
        flex-wrap:wrap;
        margin-top:14px;
        align-items:center;
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

      .lp-side{
        display:grid;
        grid-template-columns: 1fr;
        gap:10px;
      }
      .lp-card{
        border:1px solid rgba(226,232,240,.92);
        border-radius:18px;
        background: rgba(255,255,255,.78);
        padding:12px;
      }
      .lp-card .t{
        color: var(--muted);
        font-size:12px;
        font-weight:900;
      }
      .lp-card .v{
        margin-top:6px;
        font-weight:1000;
        color:#0f172a;
        font-size:14px;
      }
      .lp-card .s{
        margin-top:6px;
        color: var(--muted);
        font-size:12px;
        line-height:1.35;
      }

      .lp-focus{
        margin-top:12px;
        border-radius:18px;
        border:1px solid rgba(14,165,233,.28);
        background: rgba(14,165,233,.06);
        padding:12px;
      }
      .lp-focus .h{
        font-weight:1000;
        color:#0f172a;
      }
      .lp-focus .txt{
        margin-top:6px;
        color: rgba(15,23,42,.78);
        font-size:12px;
        line-height:1.5;
        max-width: 78ch;
      }

      .lp-footer{
        margin-top:12px;
        text-align:center;
        color: var(--muted);
        font-size:12px;
        font-weight:700;
      }
      .lp-footer b{ color:#0f172a; font-weight:900; }
    </style>

    <div class="lp-wrap">
      <div class="lp-shell">

        <div class="lp-top">
          <div class="lp-brand">
            <div class="logo" aria-hidden="true"></div>
            <div style="min-width:0;">
              <div class="lp-title">TurDoc CRM</div>
              <div class="badge lp-sub">Деловая система для турбизнеса (РК)</div>
            </div>
          </div>

          <div class="badge" style="white-space:nowrap;">
            <?php if ($u): ?>
              Вы вошли как: <b><?= htmlspecialchars($userLabel) ?></b>
              · <a href="/logout.php">Выйти</a>
            <?php else: ?>
              Доступ по приглашению администратора
            <?php endif; ?>
          </div>
        </div>

        <div class="lp-hero">
          <div class="lp-grid">

            <div>
              <h1 class="lp-h">CRM, которая наводит порядок в заявках и оплатах — и подходит под практику РК</h1>

              <div class="lp-p">
                TurDoc CRM создана специально для турагентств и туроператоров: заявки, туристы, туроператоры,
                договоры, оплаты и дедлайны — в одном месте. Без перегруженности и “таблиц на три экрана”.
              </div>

              <div class="lp-tags">
                <span class="lp-tag">Заявки и статусы</span>
                <span class="lp-tag green">Оплаты и дедлайны</span>
                <span class="lp-tag purple">Документы и история</span>
              </div>

              <div class="lp-cta">
                <!-- ✅ WhatsApp -->
                <a class="btn btn-lg purple" href="<?= htmlspecialchars($waUrl) ?>" target="_blank" rel="noopener">
                  Запросить доступ (WhatsApp)
                </a>
                <a class="btn btn-lg btn-primary" href="/login.php?role=tourist">Войти как турист</a>
                <a class="btn btn-lg success" href="/login.php?role=manager">Войти как менеджер</a>
              </div>

              <div class="lp-focus">
                <div class="h">Сделано под требования и процессы Республики Казахстан</div>
                <div class="txt">
                  Продукт учитывает местную практику: договор туристских услуг в письменной форме и требования к
                  существенным условиям (типовой договор), а для выездного туроператора — обязательность страхования туриста.
                </div>
                <div class="txt" style="margin-top:6px;">
                  Чтобы получить доступ — нажмите «Запросить доступ (WhatsApp)» и отправьте сообщение администратору.
                </div>
              </div>
            </div>

            <aside class="lp-side">
              <div class="lp-card">
                <div class="t">Для руководителя</div>
                <div class="v">Контроль финансов и ответственности</div>
                <div class="s">Кто, сколько и кого должен оплатить — видно сразу (план/факт/долги).</div>
              </div>

              <div class="lp-card">
                <div class="t">Для менеджера</div>
                <div class="v">Ежедневная работа без хаоса</div>
                <div class="s">Карточка заявки, документы и оплаты — без разрозненных таблиц и переписок.</div>
              </div>

              <div class="lp-card">
                <div class="t">Для туриста</div>
                <div class="v">Прозрачность по туру</div>
                <div class="s">Договор, платежи, статус — в личном кабинете (если включено компанией).</div>
              </div>

              <div class="lp-card">
                <div class="t">Технологии</div>
                <div class="v">Быстро, просто, надёжно</div>
                <div class="s">PHP 8.2 · MySQL · без тяжёлых фреймворков — меньше зависимостей, больше стабильности.</div>
              </div>
            </aside>

          </div>
        </div>

        <div class="lp-footer">
          Разработчик: <b>ИП «Первое турагентство»</b> · 2026 · Все права защищены
        </div>
      </div>
    </div>

  </div>

  <script src="/assets/js/app.js"></script>
</body>
</html>