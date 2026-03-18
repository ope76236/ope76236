<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/debug_manager.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('manager');
$u = current_user();

$title = $title ?? 'Кабинет менеджера';
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($title) ?> — TurDoc CRM</title>
  <link rel="stylesheet" href="/assets/css/style.css">
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body { width: 100%; overflow-x: hidden; }

    .container { width: 100%; max-width: 1600px; margin: 0 auto; }
    @media (max-width: 1640px){ .container{ padding-left: 12px; padding-right: 12px; } }

    .mgr-layout{
      display:grid;
      grid-template-columns: 280px 1fr;
      gap:16px;
      padding-bottom:24px;
      margin-top:14px;
      align-items:start;
    }
    .mgr-aside{ position: sticky; top: 14px; height: fit-content; padding: 16px; }
    .mgr-main{ padding: 18px; min-width: 0; }

    .mgr-aside h3{
      margin:0;
      font-size: 13px;
      font-weight: 650;
      letter-spacing: .2px;
      color: rgba(15,23,42,.86);
    }

    .navlink{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      padding:10px 12px;
      border-radius:12px;
      border:1px solid rgba(226,232,240,.85);
      background: rgba(255,255,255,.72);
      transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
      font-weight: 650;
      font-size: 13px;
      white-space: nowrap;
      text-decoration:none;
      color: inherit;
      user-select:none;
    }
    .navlink:hover{ transform: translateY(-1px); box-shadow: 0 12px 26px rgba(2,8,23,.08); border-color: rgba(14,165,233,.35); }
    .navlink.active{ border-color: rgba(14,165,233,.55); background: linear-gradient(135deg, rgba(14,165,233,.14), rgba(255,255,255,1)); }

    .pill{
      font-size:12px;
      padding:6px 10px;
      border-radius:999px;
      border:1px solid var(--border);
      color:var(--muted);
      background: rgba(255,255,255,.75);
      white-space:nowrap;
    }

    .table{
      width:100%;
      border-collapse:separate;
      border-spacing:0;
      overflow:hidden;
      border-radius:14px;
      border:1px solid rgba(226,232,240,.85);
      background: rgba(255,255,255,.72);
    }
    .table th, .table td{ padding:10px 10px; border-bottom:1px solid rgba(226,232,240,.75); text-align:left; font-size:14px; vertical-align:top; }
    .table th{ font-size:12px; color:var(--muted); font-weight:650; background: rgba(248,250,252,.7); white-space:nowrap; }
    .table tr:last-child td{ border-bottom:none; }

    .toolbar{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:space-between; margin:14px 0; }
    .search{ display:flex; gap:10px; align-items:flex-end; flex:1; min-width:260px; }
    .search input{ width:100%; }

    /* --- Простая кнопка "Закрыть меню" в моб.версии (без overlay) --- */
    .mgr-menu-toggle{
      display:none;
      border:1px solid rgba(226,232,240,.85);
      background: rgba(255,255,255,.72);
      border-radius: 12px;
      padding: 9px 12px;
      font-weight: 650;
      cursor:pointer;
      user-select:none;
      white-space:nowrap;
    }

    @media (max-width: 980px){
      .mgr-layout{ grid-template-columns: 1fr; }
      .mgr-aside{ position: relative; top:auto; padding: 14px; }
    }

    @media (max-width: 760px){
      .mgr-menu-toggle{ display:inline-flex; align-items:center; justify-content:center; gap:8px; }

      /* по умолчанию меню скрыто */
      body.mgr-menu-collapsed .mgr-aside{ display:none; }

      /* контент всегда на месте */
      .mgr-layout{ margin-top: 10px; }
      .mgr-main{ padding: 14px; }
    }
  </style>
</head>
<body class="fade-in mgr-menu-collapsed">
  <div class="container">
    <div class="nav">
      <div class="brand">
        <div class="logo" aria-hidden="true"></div>
        <div>
          <div style="font-weight:750;">Кабинет менеджера</div>
          <div class="badge"><?= h((string)($u['email'] ?? '')) ?></div>
        </div>
      </div>

      <div style="display:flex; gap:10px; align-items:center;">
        <a class="badge" href="/">Главная</a>
        <a class="badge" href="/logout.php">Выйти</a>
      </div>
    </div>

    <?php
      $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
      $isAny = function(array $paths) use ($path): string {
        foreach ($paths as $p) if ($path === $p) return 'active';
        return '';
      };
    ?>

    <!-- Панель для мобилки: кнопка открыть/закрыть меню -->
    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:14px;">
      <button
        class="mgr-menu-toggle"
        type="button"
        onclick="document.body.classList.toggle('mgr-menu-collapsed')"
      >
        ≡ Меню
      </button>
      <div class="badge" style="flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
        <?= h($title) ?>
      </div>
      <div class="badge" style="white-space:nowrap;">TurDoc CRM</div>
    </div>

    <div class="mgr-layout">
      <aside class="card mgr-aside slide-up" aria-label="Меню менеджера">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
          <h3>Меню</h3>
          <!-- На мобиле это явная кнопка "закрыть" -->
          <button class="mgr-menu-toggle" type="button" onclick="document.body.classList.add('mgr-menu-collapsed')">×</button>
        </div>
        <div style="height:10px"></div>

        <div style="display:flex; flex-direction:column; gap:10px;">
          <a class="navlink <?= ($path === '/manager/' ? 'active' : '') ?>" href="/manager/">
            <span>Обзор</span><span class="pill">метрики</span>
          </a>

          <a class="navlink <?= $isAny(['/manager/apps.php','/manager/app_create.php','/manager/app_view.php']) ?>" href="/manager/apps.php">
            <span>Заявки</span><span class="pill">список</span>
          </a>

          <a class="navlink <?= $isAny(['/manager/tourists.php','/manager/tourist_create.php','/manager/tourist_view.php']) ?>" href="/manager/tourists.php">
            <span>Туристы</span><span class="pill">база</span>
          </a>

          <a class="navlink <?= $isAny(['/manager/operators.php','/manager/operator_create.php','/manager/operator_view.php']) ?>" href="/manager/operators.php">
            <span>Туроператоры</span><span class="pill">справочник</span>
          </a>

          <a class="navlink <?= $isAny(['/manager/operator_fx.php']) ?>" href="/manager/operator_fx.php">
            <span>Курсы ТО</span><span class="pill">валюта</span>
          </a>

          <a class="navlink <?= $isAny(['/manager/document_templates.php']) ?>" href="/manager/document_templates.php">
            <span>Шаблоны документов</span><span class="pill">файлы</span>
          </a>

          <a class="navlink <?= $isAny(['/manager/company.php']) ?>" href="/manager/company.php">
            <span>Моя компания</span><span class="pill">настройки</span>
          </a>
        </div>

        <div style="height:14px"></div>
        <div class="badge">
          Управление заявками, туристами, операторами и документами.
        </div>
      </aside>

      <main class="card mgr-main slide-up" style="animation-delay:.05s">