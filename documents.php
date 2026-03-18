<?php
declare(strict_types=1);

/**
 * Подстановки для генерации документов + каталог переменных для редактора шаблонов.
 *
 * ВАЖНО:
 * - document_template_edit.php ожидает функцию doc_variables_catalog()
 * - contract_view.php использует doc_variables_for_app() и render_doc_template()
 */

require_once __DIR__ . '/db.php';

function _fmt_dmy(?string $ymd): string {
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '';
  $ts = strtotime($ymd);
  if ($ts === false) return $ymd;
  return date('d.m.Y', $ts);
}

function _norm_key(string $s): string {
  $s = trim($s);
  $s = preg_replace('~\s+~u', ' ', $s) ?? $s;
  $s = str_replace(['№ ', ' №'], ['№', '№'], $s);
  return $s;
}

/**
 * Каталог переменных (для UI редактора шаблонов).
 * Возвращает массив: ключ => описание.
 */
function doc_variables_catalog(): array
{
  return [
    // Заявка
    'app_id' => 'ID заявки',
    'app_number' => 'Номер заявки (app_number или id)',
    'application_created_at' => 'Дата создания заявки (дд.мм.гггг)',

    'app_country' => 'Страна/направление',
    'app_start_date' => 'Дата начала тура (дд.мм.гггг)',
    'app_end_date' => 'Дата окончания тура (дд.мм.гггг)',
    'app_hotel' => 'Отель',
    'app_meal_plan' => 'Питание',

    // Услуги / перелёты
    'trip_transfers' => 'Трансферы',
    'trip_flights' => 'Перелёты (может содержать HTML <br>)',
    'trip_insurance' => 'Страхование',
    'trip_visa_support' => 'Визовая поддержка',
    'trip_extra_services' => 'Доп. услуги',

    // Финансы
    'app_fx_rate_to_kzt' => 'Курс заявки к KZT',
    'app_tourist_price_amount' => 'Цена для туриста (в валюте заявки)',
    'price_total_kzt_today' => 'Цена в KZT (по курсу из заявки)',

    // Сроки оплат
    'deadlines_list_html' => 'HTML список сроков оплат (<li>..</li>)',

    // Туристы
    'tourists_table_html' => 'HTML таблица туристов (готовая <table>)',

    // За��азчик
    'customer_fio' => 'ФИО заказчика',
    'customer_email' => 'Email заказчика',
    'customer_phone' => 'Телефон заказчика',
    'customer_address' => 'Адрес заказчика',

    'customer_passport_no' => 'Паспорт заказчика: номер',
    'customer_passport_issue_date' => 'Паспорт заказчика: дата выдачи (дд.мм.гггг)',
    'customer_passport_issued_by' => 'Паспорт заказчика: кем выдан',

    'customer_emergency_contact_name' => 'Экстренный контакт: ФИО',
    'customer_emergency_contact_phone' => 'Экстренный контакт: телефон',

    // Туроператор
    'operator_full_name' => 'Туроператор: полное наименование',
    'operator_bin' => 'Туроператор: БИН',
    'operator_license_no' => 'Туроператор: лицензия №',
    'operator_license_issue_date' => 'Туроператор: дата лицензии (дд.мм.гггг)',
    'operator_address' => 'Туроператор: адрес',
    'operator_phone' => 'Туроператор: телефон',
    'operator_email' => 'Туроператор: email',
    'operator_agency_contract_no' => 'Договор с ТО: №',
    'operator_agency_contract_date' => 'Договор с ТО: дата (дд.мм.гггг)',

    // Алиасы из ваших шаблонов
    'emergency_contact_phone' => 'АЛИАС → customer_phone (телефон заказчика)',
    'address' => 'АЛИАС → customer_address (адрес заказчика)',
    'емейл заказчика тура' => 'АЛИАС → customer_email',
  ];
}

function _load_deadlines_for_app(\PDO $pdo, int $appId): array
{
  try {
    $st = $pdo->prepare("
      SELECT due_date
      FROM payment_deadlines
      WHERE application_id=?
        AND direction='tourist_to_agent'
      ORDER BY due_date ASC, id ASC
      LIMIT 3
    ");
    $st->execute([$appId]);
    $rows = $st->fetchAll(\PDO::FETCH_ASSOC);

    $d1 = _fmt_dmy((string)($rows[0]['due_date'] ?? ''));
    $d2 = _fmt_dmy((string)($rows[1]['due_date'] ?? ''));
    $d3 = _fmt_dmy((string)($rows[2]['due_date'] ?? ''));
    return [$d1, $d2, $d3];
  } catch (\Throwable $e) {
    return ['', '', ''];
  }
}

function doc_variables_for_app(\PDO $pdo, int $appId, int $maxTourists = 6): array
{
  $st = $pdo->prepare("
    SELECT
      a.id AS app_id,
      COALESCE(NULLIF(a.app_number, 0), a.id) AS app_number,
      a.created_at AS application_created_at,

      a.country,
      a.destination,
      a.hotel_name,
      a.meal_plan,
      a.start_date,
      a.end_date,

      a.flights_outbound,
      a.flights_return,
      a.transfers_info,
      a.insurance_info,
      a.visa_support_info,
      a.excursions_info,

      a.currency,
      a.fx_rate_to_kzt,
      a.tourist_price_amount,

      o.full_name AS operator_full_name,
      o.bin AS operator_bin,
      o.license_no AS operator_license_no,
      o.license_issue_date AS operator_license_issue_date,
      o.address AS operator_address,
      o.phone AS operator_phone,
      o.email AS operator_email,
      o.agency_contract_no AS operator_agency_contract_no,
      o.agency_contract_date AS operator_agency_contract_date,

      cu.email AS customer_email,
      cu.phone AS customer_phone,
      COALESCE(NULLIF(TRIM(CONCAT(ct.last_name,' ',ct.first_name,' ',ct.middle_name)), ''), cu.name, '') AS customer_fio,
      ct.address AS customer_address,

      ct.passport_no AS customer_passport_no,
      ct.passport_issue_date AS customer_passport_issue_date,
      ct.passport_issued_by AS customer_passport_issued_by,

      ct.emergency_contact_full_name AS customer_emergency_contact_name,
      ct.emergency_contact_phone AS customer_emergency_contact_phone

    FROM applications a
    LEFT JOIN tour_operators o ON o.id = a.operator_id
    LEFT JOIN users cu ON cu.id = a.customer_tourist_user_id
    LEFT JOIN tourists ct ON ct.user_id = cu.id
    WHERE a.id=?
    LIMIT 1
  ");
  $st->execute([$appId]);
  $r = $st->fetch(\PDO::FETCH_ASSOC);
  if (!$r) throw new \RuntimeException('Заявка не найдена.');

  $country = (string)($r['country'] ?? '');
  if ($country === '') $country = (string)($r['destination'] ?? '');

  $flOut = trim((string)($r['flights_outbound'] ?? ''));
  $flRet = trim((string)($r['flights_return'] ?? ''));
  $tripFlights = '';
  if ($flOut !== '' && $flRet !== '') $tripFlights = "Туда: {$flOut}<br>Обратно: {$flRet}";
  elseif ($flOut !== '') $tripFlights = $flOut;
  elseif ($flRet !== '') $tripFlights = $flRet;

  // дедлайны
  [$d1, $d2, $d3] = _load_deadlines_for_app($pdo, $appId);
  $deadlinesLis = '';
  foreach ([$d1, $d2, $d3] as $d) {
    $d = trim((string)$d);
    if ($d === '') continue;
    $deadlinesLis .= '<li>' . htmlspecialchars($d, \ENT_QUOTES) . '</li>' . "\n";
  }

  // туристы
  $stT = $pdo->prepare("
    SELECT
      u.phone AS user_phone,
      t.first_name_en,
      t.last_name_en,
      t.birth_date,
      t.passport_no,
      t.passport_expiry_date
    FROM application_tourists at
    JOIN users u ON u.id = at.tourist_user_id
    LEFT JOIN tourists t ON t.user_id = u.id
    WHERE at.application_id=?
    ORDER BY u.id ASC
  ");
  $stT->execute([$appId]);
  $tourists = $stT->fetchAll(\PDO::FETCH_ASSOC);

  $rows = '';
  foreach ($tourists as $tr) {
    $fio = trim((string)($tr['first_name_en'] ?? '') . ' ' . (string)($tr['last_name_en'] ?? ''));
    $birth = _fmt_dmy((string)($tr['birth_date'] ?? ''));
    $passNo = (string)($tr['passport_no'] ?? '');
    $passExp = _fmt_dmy((string)($tr['passport_expiry_date'] ?? ''));
    $phone = (string)($tr['user_phone'] ?? '');

    $rows .= "<tr>"
      . "<td>" . htmlspecialchars($fio, \ENT_QUOTES) . "</td>"
      . "<td>" . htmlspecialchars($birth, \ENT_QUOTES) . "</td>"
      . "<td>" . htmlspecialchars($passNo, \ENT_QUOTES) . "</td>"
      . "<td>" . htmlspecialchars($passExp, \ENT_QUOTES) . "</td>"
      . "<td>" . htmlspecialchars($phone, \ENT_QUOTES) . "</td>"
      . "</tr>\n";
  }
  if ($rows === '') {
    $rows = '<tr><td colspan="5">—</td></tr>';
  }

  $touristsTableHtml = '
<table>
  <tbody>
    <tr>
      <th>Фамилия и имя</th>
      <th>Дата рождения</th>
      <th>№ паспорта</th>
      <th>Действителен до</th>
      <th>Телефон</th>
    </tr>
    ' . $rows . '
  </tbody>
</table>';

  // цена в KZT
  $currency = (string)($r['currency'] ?? 'KZT');
  $fx = ($currency === 'KZT') ? 1.0 : (float)($r['fx_rate_to_kzt'] ?? 0);
  $tourPriceCur = (float)($r['tourist_price_amount'] ?? 0);
  $totalKzt = ($tourPriceCur > 0 && $fx > 0) ? $tourPriceCur * $fx : 0.0;

  return [
    'app_id' => (string)($r['app_id'] ?? ''),
    'app_number' => (string)($r['app_number'] ?? ''),
    'application_created_at' => _fmt_dmy((string)($r['application_created_at'] ?? '')),

    'app_country' => $country,
    'app_start_date' => _fmt_dmy((string)($r['start_date'] ?? '')),
    'app_end_date' => _fmt_dmy((string)($r['end_date'] ?? '')),
    'app_hotel' => (string)($r['hotel_name'] ?? ''),
    'app_meal_plan' => (string)($r['meal_plan'] ?? ''),

    'trip_transfers' => (string)($r['transfers_info'] ?? ''),
    'trip_flights' => $tripFlights,
    'trip_insurance' => (string)($r['insurance_info'] ?? ''),
    'trip_visa_support' => (string)($r['visa_support_info'] ?? ''),
    'trip_extra_services' => (string)($r['excursions_info'] ?? ''),

    'app_fx_rate_to_kzt' => number_format((float)$fx, 2, '.', ''),
    'app_tourist_price_amount' => number_format((float)$tourPriceCur, 2, '.', ''),
    'price_total_kzt_today' => ($totalKzt > 0) ? number_format($totalKzt, 2, '.', '') : '',

    'deadlines_list_html' => $deadlinesLis,
    'tourists_table_html' => $touristsTableHtml,

    'customer_fio' => (string)($r['customer_fio'] ?? ''),
    'customer_email' => (string)($r['customer_email'] ?? ''),
    'customer_phone' => (string)($r['customer_phone'] ?? ''),
    'customer_address' => (string)($r['customer_address'] ?? ''),

    'customer_passport_no' => (string)($r['customer_passport_no'] ?? ''),
    'customer_passport_issue_date' => _fmt_dmy((string)($r['customer_passport_issue_date'] ?? '')),
    'customer_passport_issued_by' => (string)($r['customer_passport_issued_by'] ?? ''),

    'customer_emergency_contact_name' => (string)($r['customer_emergency_contact_name'] ?? ''),
    'customer_emergency_contact_phone' => (string)($r['customer_emergency_contact_phone'] ?? ''),

    'operator_full_name' => (string)($r['operator_full_name'] ?? ''),
    'operator_bin' => (string)($r['operator_bin'] ?? ''),
    'operator_license_no' => (string)($r['operator_license_no'] ?? ''),
    'operator_license_issue_date' => _fmt_dmy((string)($r['operator_license_issue_date'] ?? '')),
    'operator_address' => (string)($r['operator_address'] ?? ''),
    'operator_phone' => (string)($r['operator_phone'] ?? ''),
    'operator_email' => (string)($r['operator_email'] ?? ''),
    'operator_agency_contract_no' => (string)($r['operator_agency_contract_no'] ?? ''),
    'operator_agency_contract_date' => _fmt_dmy((string)($r['operator_agency_contract_date'] ?? '')),
  ];
}

function render_doc_template(string $html, array $vars): string
{
  $safeHtml = [
    'trip_flights' => true,
    'deadlines_list_html' => true,
    'tourists_table_html' => true,
  ];

  $aliases = [
    'емейл заказчика тура' => 'customer_email',
    'email заказчика тура' => 'customer_email',
    'e-mail заказчика тура' => 'customer_email',

    // ваши теги подписи:
    'emergency_contact_phone' => 'customer_phone',
    'address' => 'customer_address',
  ];

  return preg_replace_callback('~\{([^}]+)\}~u', function($m) use ($vars, $aliases, $safeHtml) {
    $raw = (string)$m[1];
    $key = _norm_key($raw);
    $key = $aliases[$key] ?? $key;

    $val = (string)($vars[$key] ?? '');

    if (isset($safeHtml[$key])) return $val;
    return htmlspecialchars($val, \ENT_QUOTES);
  }, $html);
}