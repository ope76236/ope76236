<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

require_role('manager');

$pdo = db();

$appId = (int)($_GET['app_id'] ?? 0);
if ($appId <= 0) {
  http_response_code(404);
  echo "Не указан app_id";
  exit;
}

// Company
$company = $pdo->query("SELECT * FROM companies ORDER BY id ASC LIMIT 1")->fetch();
if (!$company || trim((string)$company['name']) === '') {
  // редиректим на заполнение реквизитов
  redirect('/manager/company.php');
}

// Application
$stApp = $pdo->prepare("
  SELECT a.*, 
         o.name AS operator_name, o.bin AS operator_bin, o.address AS operator_address, o.phone AS operator_phone, o.email AS operator_email
  FROM applications a
  LEFT JOIN tour_operators o ON o.id = a.operator_id
  WHERE a.id=?
  LIMIT 1
");
$stApp->execute([$appId]);
$app = $stApp->fetch();
if (!$app) {
  http_response_code(404);
  echo "Заявка не найдена";
  exit;
}

// Tourists in application
$stMembers = $pdo->prepare("
  SELECT u.id, u.email, u.phone, u.name,
         t.iin, t.last_name, t.first_name, t.middle_name, t.birth_date, t.passport_no
  FROM application_tourists at
  JOIN users u ON u.id = at.tourist_user_id
  LEFT JOIN tourists t ON t.user_id = u.id
  WHERE at.application_id=?
  ORDER BY u.id ASC
");
$stMembers->execute([$appId]);
$members = $stMembers->fetchAll();

if (!$members) {
  // вернёмся в заявку — там покажем сообщение позже (пока просто редирект)
  redirect('/manager/app_view.php?id=' . $appId);
}

$mainTouristId = (int)($app['main_tourist_user_id'] ?? 0);
$main = null;
foreach ($members as $m) {
  if ((int)$m['id'] === $mainTouristId) { $main = $m; break; }
}
if (!$main) $main = $members[0];

function fio(array $m): string {
  $s = trim(($m['last_name'] ?? '') . ' ' . ($m['first_name'] ?? '') . ' ' . ($m['middle_name'] ?? ''));
  if ($s === '') $s = trim((string)($m['name'] ?? ''));
  return $s !== '' ? $s : '—';
}

$contractNumber = 'TD-' . $appId . '-' . date('Ymd');
$contractDate = date('Y-m-d');

$total = (float)$app['total_amount'];
$cur = (string)$app['currency'];

$touristFio = fio($main);
$touristIin = (string)($main['iin'] ?? '');
$touristPhone = (string)($main['phone'] ?? '');
$touristEmail = (string)($main['email'] ?? '');
$passportNo = (string)($main['passport_no'] ?? '');

// HTML snapshot
$html = '
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <title>Договор ' . htmlspecialchars($contractNumber) . '</title>
  <style>
    body{ font-family: Arial, sans-serif; color:#0f172a; }
    .wrap{ max-width: 900px; margin: 0 auto; padding: 24px; }
    h1{ font-size:18px; margin:0 0 8px; text-align:center; }
    .meta{ text-align:center; font-size:12px; color:#334155; margin-bottom:18px; }
    .p{ font-size:14px; line-height:1.45; margin: 10px 0; }
    .tbl{ width:100%; border-collapse:collapse; margin: 10px 0; font-size:13px; }
    .tbl td, .tbl th{ border:1px solid #cbd5e1; padding:8px; vertical-align:top; }
    .muted{ color:#475569; }
    .sign{ display:grid; grid-template-columns: 1fr 1fr; gap:18px; margin-top:22px; }
    .box{ border:1px solid #cbd5e1; padding:10px; min-height:120px; }
    .small{ font-size:12px; color:#475569; }
    @media print { .noprint{ display:none; } }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="noprint" style="margin-bottom:12px;">
      <button onclick="window.print()">Печать</button>
    </div>

    <h1>ДОГОВОР на оказание туристских услуг № ' . htmlspecialchars($contractNumber) . '</h1>
    <div class="meta">г. ____________ · дата: ' . htmlspecialchars($contractDate) . '</div>

    <p class="p">
      ' . htmlspecialchars((string)$company['name']) . ', БИН ' . htmlspecialchars((string)$company['bin']) . ',
      далее «Турагент», в лице ' . htmlspecialchars((string)$company['director_name']) . ' (' . htmlspecialchars((string)$company['director_basis']) . '),
      с одной стороны, и
      ' . htmlspecialchars($touristFio) . ($touristIin ? (', ИИН ' . htmlspecialchars($touristIin)) : '') . ',
      далее «Турист», с другой стороны, заключили настоящий договор о нижеследующем:
    </p>

    <p class="p"><b>1. Предмет договора</b><br>
      Турагент оказывает содействие в бронировании туристского продукта и оформлении документов по туру:
      <b>' . htmlspecialchars((string)$app['destination']) . '</b> в период
      <b>' . htmlspecialchars((string)$app['start_date']) . '</b> — <b>' . htmlspecialchars((string)$app['end_date']) . '</b>.
    </p>

    <p class="p"><b>2. Туроператор</b><br>
      Туроператор: <b>' . htmlspecialchars((string)($app['operator_name'] ?? '—')) . '</b>.
    </p>

    <p class="p"><b>3. Стоимость и порядок оплаты</b><br>
      Общая стоимость туристского продукта: <b>' . number_format($total, 2, '.', ' ') . ' ' . htmlspecialchars($cur) . '</b>.
      Оплата производится в порядке и сроки, согласованные сторонами. Факты оплат фиксируются в CRM/квитанциях.
    </p>

    <p class="p"><b>4. Условия</b><br>
      Стороны руководствуются законодательством Республики Казахстан. Турист подтверждает достоверность предоставленных данных.
      Условия аннуляции/изменений зависят от правил туроператора и поставщиков услуг.
    </p>

    <p class="p"><b>5. Данные туриста</b></p>
    <table class="tbl">
      <tr><th>ФИО</th><td>' . htmlspecialchars($touristFio) . '</td></tr>
      <tr><th>ИИН</th><td>' . htmlspecialchars($touristIin) . '</td></tr>
      <tr><th>Паспорт</th><td>' . htmlspecialchars($passportNo) . '</td></tr>
      <tr><th>Телефон / Email</th><td>' . htmlspecialchars($touristPhone) . ' / ' . htmlspecialchars($touristEmail) . '</td></tr>
    </table>

    <p class="p"><b>6. Реквизиты и подписи</b></p>

    <div class="sign">
      <div class="box">
        <b>Турагент</b><br>
        ' . htmlspecialchars((string)$company['name']) . '<br>
        <span class="small">БИН:</span> ' . htmlspecialchars((string)$company['bin']) . '<br>
        <span class="small">Адрес:</span> ' . htmlspecialchars((string)$company['address']) . '<br>
        <span class="small">Телефон:</span> ' . htmlspecialchars((string)$company['phone']) . '<br>
        <span class="small">Email:</span> ' . htmlspecialchars((string)$company['email']) . '<br><br>
        <span class="small">Банк:</span> ' . htmlspecialchars((string)$company['bank_name']) . '<br>
        <span class="small">БИК:</span> ' . htmlspecialchars((string)$company['bik']) . '<br>
        <span class="small">IBAN:</span> ' . htmlspecialchars((string)$company['iban']) . '<br>
        <span class="small">КБЕ:</span> ' . htmlspecialchars((string)$company['kbe']) . ' · <span class="small">КНП:</span> ' . htmlspecialchars((string)$company['knp']) . '<br><br>
        _______________________ / ' . htmlspecialchars((string)$company['director_name']) . '
      </div>

      <div class="box">
        <b>Турист</b><br>
        ' . htmlspecialchars($touristFio) . '<br>
        <span class="small">ИИН:</span> ' . htmlspecialchars($touristIin) . '<br>
        <span class="small">Паспорт:</span> ' . htmlspecialchars($passportNo) . '<br>
        <span class="small">Телефон:</span> ' . htmlspecialchars($touristPhone) . '<br>
        <span class="small">Email:</span> ' . htmlspecialchars($touristEmail) . '<br><br>
        _______________________ / ' . htmlspecialchars($touristFio) . '
      </div>
    </div>

    <p class="p muted small" style="margin-top:16px;">
      Примечание: базовый шаблон договора. Далее добавим расширенные условия, приложения и PDF.
    </p>
  </div>
</body>
</html>
';

$ins = $pdo->prepare("
  INSERT INTO contracts(application_id, contract_number, contract_date, html)
  VALUES(?,?,?,?)
");
$ins->execute([$appId, $contractNumber, $contractDate, $html]);

$contractId = (int)$pdo->lastInsertId();
redirect('/manager/contract_print.php?id=' . $contractId);