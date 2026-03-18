<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('tourist');
$u = current_user();

$title = $title ?? 'Кабинет туриста';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
$isAny = function(array $paths) use ($path): string {
  foreach ($paths as $p) if ($path === $p) return 'active';
  return '';
};

// --- Display name (ФИО) ---
$fullName = trim((string)($u['full_name'] ?? $u['fio'] ?? $u['name'] ?? ''));
if ($fullName === '') {
  $first = trim((string)($u['first_name'] ?? ''));
  $last  = trim((string)($u['last_name'] ?? ''));
  $middle = trim((string)($u['middle_name'] ?? ''));
  $fullName = trim($last . ' ' . $first . ' ' . $middle);
}
if ($fullName === '') $fullName = 'Турист';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($title) ?> — TurDoc CRM</title>
  <link rel="stylesheet" href="/assets/css/style.css">

  <style>
    :root{
      --w-strong: 750;
      --w-normal: 600;
    }

    /* ====== Global "safety" rules (fix overflow everywhere) ====== */
    * { box-sizing: border-box; }
    img, svg, video, canvas { max-width: 100%; height: auto; }
    input, select, textarea, button { max-width: 100%; }
    .card, .section, .badge, .nav, .brand, .container { min-width: 0; }

    .muted{ color: var(--muted); font-weight: var(--w-normal); }
    .mini{ font-size:12px; }
    .nowrap{ white-space:nowrap; }
    .ellipsis{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    /* IMPORTANT:
       In your global style.css .badge is likely nowrap.
       We override ONLY inside tourist cabinet wrapper to prevent "badge" overflow like in your screenshot (image1).
    */
    .t-wrap .badge,
    .nav .badge{
      white-space: normal !important;
      overflow-wrap: anywhere;
      word-break: break-word;
      max-width: 100%;
      line-height: 1.35;
    }

    /* Layout */
    .t-wrap{
      display:grid;
      grid-template-columns: 280px 1fr;
      gap:16px;
      padding-bottom:24px;
      align-items:start;
      min-width:0;
    }
    .t-aside{
      padding:16px;
      position:sticky;
      top:14px;
      height: fit-content;
      min-width:0;
      overflow:hidden; /* extra safety: nothing can "paint" outside card */
    }
    .t-main{
      padding:18px;
      min-width:0;
      overflow:hidden;
    }
    @media (max-width: 980px){
      .t-wrap{ grid-template-columns: 1fr; }
      .t-aside{ position:relative; top:auto; }
    }

    /* Tourist name highlight in header */
    .t-user{
      font-weight: var(--w-strong);
      color:#0f172a;
      font-size:13px;
      padding:6px 10px;
      border-radius:999px;
      border:1px solid rgba(14,165,233,.35);
      background: rgba(14,165,233,.10);
      display:inline-block;

      /* safety */
      max-width: 460px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    @media (max-width: 520px){
      .t-user{ max-width: 100%; }
    }

    /* Menu item: clickable panel */
    .navitem{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      padding:12px 14px;
      border-radius:16px;
      border:1px solid rgba(226,232,240,.90);
      background: rgba(255,255,255,.72);
      transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease, background .12s ease;
      font-weight: var(--w-strong);
      color:#0f172a;
      cursor:pointer;
      user-select:none;
      text-decoration:none;
      min-width:0;
    }
    .navitem .label{
      min-width:0;
      overflow:hidden;
      text-overflow:ellipsis;
      white-space:nowrap;
    }
    .navitem:hover{
      transform: translateY(-1px);
      box-shadow: 0 12px 26px rgba(2,8,23,.08);
      border-color: rgba(14,165,233,.35);
    }
    .navitem.active{
      border-color: rgba(14,165,233,.55);
      background: linear-gradient(135deg, rgba(14,165,233,.16), rgba(255,255,255,1));
    }

    /* Tables */
    .table{
      width:100%;
      border-collapse:separate;
      border-spacing:0;
      overflow:hidden;
      border-radius:16px;
      border:1px solid rgba(226,232,240,.90);
      background: rgba(255,255,255,.72);
      table-layout: fixed;
    }
    .table th, .table td{
      padding:12px 12px;
      border-bottom:1px solid rgba(226,232,240,.75);
      text-align:left;
      font-size:14px;
      vertical-align:top;
      font-weight: var(--w-normal);
    }
    .table th{
      font-size:12px;
      color:var(--muted);
      font-weight: var(--w-normal);
      background: rgba(248,250,252,.7);
    }
    .table tr:last-child td{ border-bottom:none; }

    .table-wrap{
      width:100%;
      overflow-x:auto;
      -webkit-overflow-scrolling: touch;
    }
    .table-wrap .table{ min-width: 720px; }
    @media (min-width: 981px){
      .table-wrap .table{ min-width:0; }
    }
  </style>
</head>

<body class="fade-in">
  <div class="container">
    <div class="nav">
      <div class="brand">
        <div class="logo" aria-hidden="true"></div>
        <div style="min-width:0;">
          <div>Кабинет туриста</div>
          <div class="t-user" title="<?= h($fullName) ?>">
            <?= h($fullName) ?>
          </div>
        </div>
      </div>

      <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; min-width:0;">
        <a class="badge" href="/">Главная</a>
        <a class="badge" href="/logout.php">Выйти</a>
      </div>
    </div>

    <div class="t-wrap">
      <aside class="card t-aside slide-up">
        <div style="font-weight:var(--w-strong);">Меню</div>
        <div style="height:10px"></div>

        <div style="display:flex; flex-direction:column; gap:10px;">
          <a class="navitem <?= ($path === '/tourist/' ? 'active' : '') ?>" href="/tourist/">
            <span class="label">Мой тур</span>
          </a>

          <a class="navitem <?= $isAny(['/tourist/tours.php','/tourist/tour_view.php']) ?>" href="/tourist/tours.php">
            <span class="label">Мои заявки</span>
          </a>

          <a class="navitem <?= ($path === '/tourist/profile.php' ? 'active' : '') ?>" href="/tourist/profile.php">
            <span class="label">Мой профиль</span>
          </a>

          <a class="navitem <?= ($path === '/tourist/password.php' ? 'active' : '') ?>" href="/tourist/password.php">
            <span class="label">Смена пароля</span>
          </a>
        </div>

        <div style="height:14px"></div>

        <div class="badge">
          Ваши данные хранятся в соответствии с законодательством РК
        </div>
      </aside>

      <main class="card t-main slide-up" style="animation-delay:.05s">