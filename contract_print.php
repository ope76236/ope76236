<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';

require_role('tourist');

$pdo = db();
$u = current_user();
$uid = (int)($u['id'] ?? 0);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(404);
  echo "Не указан ID договора";
  exit;
}

/**
 * Доступ разрешаем только если:
 * - договор принадлежит заявке
 * - турист входит в состав этой заявки (application_tourists)
 */
$st = $pdo->prepare("
  SELECT c.html
  FROM contracts c
  JOIN applications a ON a.id = c.application_id
  JOIN application_tourists at ON at.application_id = a.id AND at.tourist_user_id = ?
  WHERE c.id = ?
  LIMIT 1
");
$st->execute([$uid, $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  http_response_code(403);
  echo "Доступ запрещён";
  exit;
}

$contractHtml = (string)($row['html'] ?? '');

/**
 * ВАЖНО:
 * В contracts.html у вас может лежать только "кусок" HTML (без <html><body>),
 * и браузеры иногда печатают пусто/криво, если нет базовой структуры документа и стилей.
 *
 * Поэтому возвращаем полноценную HTML-страницу с CSS:
 * - одинаковые поля @page
 * - единый спокойный стиль шрифта
 * - печать только содержимого (и сразу автопечать при ?autoprint=1)
 */

$autoprint = (isset($_GET['autoprint']) && $_GET['autoprint'] === '1');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: text/html; charset=utf-8');

?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <title>Печать договора</title>

  <style>
    /* одинаковые поля */
    @page { size: A4 portrait; margin: 8mm 8mm; }

    html, body{
      padding:0;
      margin:0;
      background:#fff;
      color:#0f172a;
      font-family: Arial, sans-serif;
      font-size: 10.5px;
      line-height: 1.22;
      font-weight: 400;
    }

    /* единый спокойный стиль, чтобы не “пестрило” */
    b, strong{ font-weight: 600; }
    h1{ font-size: 13px; margin: 0 0 6px 0; line-height: 1.18; font-weight: 600; }
    h2{ font-size: 12px; margin: 10px 0 6px 0; line-height: 1.18; font-weight: 600; }
    h3{ font-size: 11px; margin: 10px 0 6px 0; line-height: 1.18; font-weight: 600; }
    p{ margin: 0 0 6px 0; }

    table{ width:100%; border-collapse:collapse; margin: 6px 0 8px 0; }
    th, td{ border: 1px solid #cbd5e1; padding: 3px 4px; vertical-align: top; }
    th{ background:#f1f5f9; font-weight: 600; }

    .no-border, .no-border th, .no-border td { border:none !important; }

    /* часто “шапка” сделана через align=right — приводим к общему */
    [align="right"], .right, .text-right { font-size: 10.5px; font-weight: 400; }

    /* контейнер */
    .doc{
      /* при необходимости можно “ужать” чуть-чуть, чтобы точно влезть */
      /* transform: scale(0.96); transform-origin: top left; width: calc(100% / 0.96); */
    }

    /* экран: просто красивое отображение */
    @media screen{
      body{ padding: 12px; }
      .paper{
        max-width: 860px;
        margin: 0 auto;
        border:1px solid #e2e8f0;
        border-radius: 14px;
        padding: 12px;
        box-shadow: 0 18px 40px rgba(2,8,23,.08);
      }
    }
  </style>

  <?php if ($autoprint): ?>
    <script>
      // Автопечать после загрузки, чтобы Chrome/Android успел отрисовать таблицы
      window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 80);
      });
      // Закрывать окно можно по желанию (часто браузеры блокируют close)
      window.addEventListener('afterprint', function () {
        // window.close();
      });
    </script>
  <?php endif; ?>
</head>
<body>
  <div class="paper">
    <div class="doc">
      <?= $contractHtml ?>
    </div>
  </div>
</body>
</html>