<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

require_role('manager');

$title = 'Оплаты по заявке';
$pdo = db();

$appId = (int)($_GET['app_id'] ?? 0);
if ($appId <= 0) {
  http_response_code(404);
  echo "Не указан app_id";
  exit;
}

$error = null;

/* ---------------- helpers ---------------- */

function money_in(string $s): float {
  $s = trim($s);
  if ($s === '') return 0.0;
  $s = str_replace([' ', ','], ['', '.'], $s);
  return (float)$s;
}

function app_cur_to_kzt_today(float $amount, string $appCurrency, float $fxRateToday): float {
  if ($appCurrency === 'KZT') return $amount;
  return round($amount * $fxRateToday, 2);
}

function kzt_to_app_cur_at_pay(float $amountKzt, string $appCurrency, float $fxRateAtPay): float {
  if ($appCurrency === 'KZT') return $amountKzt;
  if ($fxRateAtPay <= 0) return 0.0;
  return round($amountKzt / $fxRateAtPay, 2);
}

function app_cur_to_kzt_at_pay(float $amountCur, string $appCurrency, float $fxRateAtPay): float {
  if ($appCurrency === 'KZT') return $amountCur;
  if ($fxRateAtPay <= 0) return 0.0;
  return round($amountCur * $fxRateAtPay, 2);
}

function fmt_dmy(?string $ymd): string {
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '—';
  $ts = strtotime($ymd);
  if ($ts === false) return $ymd;
  return date('d.m.Y', $ts);
}

function pay_method_label(string $m): string {
  $m = trim($m);
  if ($m === 'cash') return 'наличные';
  if ($m === 'card') return 'карта';
  if ($m === 'bank') return 'банк';
  if ($m === 'other') return 'другое';
  if ($m === 'transfer') return 'перенос';
  return $m !== '' ? $m : '—';
}

function is_transfer_note(string $note): bool {
  $n = mb_strtolower(trim($note));
  return str_contains($n, 'перенос');
}

/* ---------------- ensure deadlines table ---------------- */

$pdo->exec("
CREATE TABLE IF NOT EXISTS payment_deadlines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id INT UNSIGNED NOT NULL,
  direction ENUM('tourist_to_agent','agent_to_operator') NOT NULL,
  due_date DATE NOT NULL,
  percent DECIMAL(5,2) NOT NULL DEFAULT 0,
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_app (application_id),
  INDEX idx_dir (direction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* ---------------- load app ---------------- */

$stApp = $pdo->prepare("
  SELECT a.*,
         a.operator_id
  FROM applications a
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

$appCurrency = (string)($app['currency'] ?? 'KZT');
if (!in_array($appCurrency, ['KZT','USD','EUR'], true)) $appCurrency = 'KZT';

$fxRateFromApp = (float)($app['fx_rate_to_kzt'] ?? 1);
if ($appCurrency === 'KZT') $fxRateFromApp = 1.0;

$appNo = (int)(($app['app_number'] ?? 0) ?: (int)$app['id']);

$touristPriceCur = (float)($app['tourist_price_amount'] ?? 0);
$operatorPriceCur = (float)($app['operator_price_amount'] ?? 0);

/**
 * курс туроператора "на сегодня"
 */
$operatorId = (int)($app['operator_id'] ?? 0);
$fxRateOperatorToday = $fxRateFromApp;

if ($appCurrency === 'KZT') {
  $fxRateOperatorToday = 1.0;
} elseif ($operatorId > 0) {
  try {
    $stFx = $pdo->prepare("
      SELECT rate_to_kzt
      FROM operator_fx_rates
      WHERE operator_id = ?
        AND currency = ?
      ORDER BY captured_at DESC
      LIMIT 1
    ");
    $stFx->execute([$operatorId, $appCurrency]);
    $r = $stFx->fetch(PDO::FETCH_ASSOC);
    if ($r && (float)$r['rate_to_kzt'] > 0) $fxRateOperatorToday = (float)$r['rate_to_kzt'];
  } catch (Throwable $e) {
    $fxRateOperatorToday = $fxRateFromApp;
  }
}

/**
 * “курс в заявке”
 */
$fxRateToday = $fxRateFromApp;

$planProfitCur = round($touristPriceCur - $operatorPriceCur, 2);
$planProfitKztToday = app_cur_to_kzt_today($planProfitCur, $appCurrency, $fxRateToday);

$touristPriceKztToday = app_cur_to_kzt_today($touristPriceCur, $appCurrency, $fxRateToday);
$operatorPriceKztToday = app_cur_to_kzt_today($operatorPriceCur, $appCurrency, $fxRateToday);

/* ---------------- POST actions ---------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = post('_action');

    /* ---------------- payments ---------------- */

    if ($action === 'add_payment') {
      $direction = post('direction');
      if (!in_array($direction, ['tourist_to_agent','agent_to_operator'], true)) {
        throw new RuntimeException('Неверный тип платежа.');
      }

      $payDate = post('pay_date');
      $method = post('method', 'bank');
      $note = post('note');

      if (!in_array($method, ['cash','card','bank','transfer','other'], true)) $method = 'bank';

      $inputCurrency = strtoupper(trim(post('input_currency', 'KZT')));
      if (!in_array($inputCurrency, ['KZT','USD','EUR'], true)) $inputCurrency = 'KZT';

      // по умолчанию: курс туроператора на сегодня
      $fxRateAtPay = (float)money_in(post('fx_rate_to_kzt', (string)$fxRateOperatorToday));
      if ($appCurrency === 'KZT') $fxRateAtPay = 1.0;
      if ($fxRateAtPay <= 0) throw new RuntimeException('Курс должен быть больше 0.');

      $amountKzt = 0.0;

      if ($inputCurrency === 'KZT') {
        $amountKzt = money_in(post('amount_kzt'));
        if ($amountKzt <= 0) throw new RuntimeException('Сумма должна быть больше 0.');
      } else {
        if ($inputCurrency !== $appCurrency) {
          throw new RuntimeException('Сумму в валюте можно вводить только в валюте тура (' . $appCurrency . ').');
        }
        $amountCur = money_in(post('amount_cur'));
        if ($amountCur <= 0) throw new RuntimeException('Сумма должна быть больше 0.');
        $amountKzt = app_cur_to_kzt_at_pay($amountCur, $appCurrency, $fxRateAtPay);
        if ($amountKzt <= 0) throw new RuntimeException('Некорректная сумма/курс.');
      }

      $payerType = ($direction === 'tourist_to_agent') ? 'tourist' : 'operator';
      $payerName = ($direction === 'tourist_to_agent') ? 'Турист' : 'Туроператор';

      $status = 'paid';

      $ins = $pdo->prepare("
        INSERT INTO payments(application_id, direction, payer_type, payer_name, amount, currency, fx_rate_to_kzt, pay_date, status, method, note)
        VALUES(?,?,?,?,?,?,?,?,?,?,?)
      ");
      $ins->execute([
        $appId,
        $direction,
        $payerType,
        $payerName,
        $amountKzt,
        'KZT',
        $fxRateAtPay,
        ($payDate !== '' ? $payDate : null),
        $status,
        $method,
        $note
      ]);

      redirect('/manager/payments.php?app_id=' . $appId . '#payments');
    }

    if ($action === 'edit_payment') {
      $pid = (int)post('payment_id', '0');
      if ($pid <= 0) throw new RuntimeException('Неверный платёж.');

      $direction = post('direction');
      if (!in_array($direction, ['tourist_to_agent','agent_to_operator'], true)) {
        throw new RuntimeException('Неверный тип платежа.');
      }

      $payDate = post('pay_date');
      $method = post('method', 'bank');
      $note = post('note');

      if (!in_array($method, ['cash','card','bank','transfer','other'], true)) $method = 'bank';

      $stChk = $pdo->prepare("SELECT id, note, method FROM payments WHERE id=? AND application_id=? LIMIT 1");
      $stChk->execute([$pid, $appId]);
      $cur = $stChk->fetch(PDO::FETCH_ASSOC);
      if (!$cur) throw new RuntimeException('Платёж не найден.');

      // запрет редактирования для переносов
      $curNote = (string)($cur['note'] ?? '');
      $curMethod = (string)($cur['method'] ?? '');
      if ($curMethod === 'transfer' || is_transfer_note($curNote)) {
        throw new RuntimeException('Платёж “перенос” нельзя редактировать. Можно только удалить.');
      }

      $inputCurrency = strtoupper(trim(post('input_currency', 'KZT')));
      if (!in_array($inputCurrency, ['KZT','USD','EUR'], true)) $inputCurrency = 'KZT';

      $fxRateAtPay = (float)money_in(post('fx_rate_to_kzt', (string)$fxRateToday));
      if ($appCurrency === 'KZT') $fxRateAtPay = 1.0;
      if ($fxRateAtPay <= 0) throw new RuntimeException('Курс должен быть больше 0.');

      $amountKzt = 0.0;

      if ($inputCurrency === 'KZT') {
        $amountKzt = money_in(post('amount_kzt'));
        if ($amountKzt <= 0) throw new RuntimeException('Сумма должна быть больше 0.');
      } else {
        if ($inputCurrency !== $appCurrency) {
          throw new RuntimeException('Сумму в валюте можно вводить только в валюте тура (' . $appCurrency . ').');
        }
        $amountCur = money_in(post('amount_cur'));
        if ($amountCur <= 0) throw new RuntimeException('Сумма должна быть больше 0.');
        $amountKzt = app_cur_to_kzt_at_pay($amountCur, $appCurrency, $fxRateAtPay);
        if ($amountKzt <= 0) throw new RuntimeException('Некорректная сумма/курс.');
      }

      $pdo->prepare("
        UPDATE payments
        SET direction=?,
            amount=?,
            fx_rate_to_kzt=?,
            pay_date=?,
            method=?,
            note=?
        WHERE id=? AND application_id=?
        LIMIT 1
      ")->execute([
        $direction,
        $amountKzt,
        $fxRateAtPay,
        ($payDate !== '' ? $payDate : null),
        $method,
        $note,
        $pid,
        $appId
      ]);

      redirect('/manager/payments.php?app_id=' . $appId . '#payments');
    }

    if ($action === 'delete_payment') {
      $pid = (int)($_POST['payment_id'] ?? 0);
      if ($pid <= 0) throw new RuntimeException('Неверный платёж.');

      $pdo->prepare("DELETE FROM payments WHERE id=? AND application_id=? LIMIT 1")
          ->execute([$pid, $appId]);

      redirect('/manager/payments.php?app_id=' . $appId . '#payments');
    }

    /* ---------------- expenses/transfers ---------------- */

    if ($action === 'add_expense') {
      $expenseType = trim((string)post('expense_type'));
      if (!in_array($expenseType, ['refund_tourist','refund_operator','fine','transfer'], true)) {
        throw new RuntimeException('Неверный тип расхода.');
      }

      $expenseScope = trim((string)post('expense_scope', 'tourist_minus'));
      if (!in_array($expenseScope, ['tourist_minus','agent_minus','agent_to_tourist','operator_minus'], true)) {
        throw new RuntimeException('Неверная часть расхода.');
      }

      $payDate = post('pay_date');
      $method = post('method', 'bank');
      $noteUser = trim((string)post('note'));

      if (!in_array($method, ['cash','card','bank','transfer','other'], true)) $method = 'bank';

      $inputCurrency = strtoupper(trim(post('input_currency', 'KZT')));
      if (!in_array($inputCurrency, ['KZT','USD','EUR'], true)) $inputCurrency = 'KZT';

      $fxRateAtPay = (float)money_in(post('fx_rate_to_kzt', (string)$fxRateOperatorToday));
      if ($appCurrency === 'KZT') $fxRateAtPay = 1.0;
      if ($fxRateAtPay <= 0) throw new RuntimeException('Курс должен быть больше 0.');

      $amountKzt = 0.0;
      if ($inputCurrency === 'KZT') {
        $amountKzt = money_in(post('amount_kzt'));
        if ($amountKzt <= 0) throw new RuntimeException('Сумма должна быть больше 0.');
      } else {
        if ($inputCurrency !== $appCurrency) {
          throw new RuntimeException('Сумму в валюте можно вводить только в валюте тура (' . $appCurrency . ').');
        }
        $amountCur = money_in(post('amount_cur'));
        if ($amountCur <= 0) throw new RuntimeException('Сумма должна быть больше 0.');
        $amountKzt = app_cur_to_kzt_at_pay($amountCur, $appCurrency, $fxRateAtPay);
        if ($amountKzt <= 0) throw new RuntimeException('Некорректная сумма/курс.');
      }

      $status = 'paid';

      $ins = $pdo->prepare("
        INSERT INTO payments(application_id, direction, payer_type, payer_name, amount, currency, fx_rate_to_kzt, pay_date, status, method, note)
        VALUES(?,?,?,?,?,?,?,?,?,?,?)
      ");

      $makeNote = function(string $prefix, string $noteUser): string {
        $noteUser = trim($noteUser);
        if ($noteUser === '') return $prefix;
        return $prefix . ' · ' . $noteUser;
      };

      $targetAppNoOrId = (int)post('target_app_no', '0');
      $targetAppId = 0;
      $targetApp = null;

      $runInsert = function(int $applicationId, string $direction, string $payerType, string $payerName, float $amount, string $method, string $note) use ($ins, $fxRateAtPay, $payDate, $status) {
        $ins->execute([
          $applicationId,
          $direction,
          $payerType,
          $payerName,
          $amount,
          'KZT',
          $fxRateAtPay,
          ($payDate !== '' ? $payDate : null),
          $status,
          $method,
          $note
        ]);
      };

      if ($expenseType === 'transfer') {
        if ($targetAppNoOrId <= 0) throw new RuntimeException('Укажите номер заявки для переноса.');

        $stT = $pdo->prepare("
          SELECT *
          FROM applications
          WHERE id = ?
             OR app_number = ?
          LIMIT 1
        ");
        $stT->execute([$targetAppNoOrId, $targetAppNoOrId]);
        $targetApp = $stT->fetch(PDO::FETCH_ASSOC);
        if (!$targetApp) throw new RuntimeException('Целевая заявка не найдена.');

        $targetAppId = (int)$targetApp['id'];
        if ($targetAppId <= 0) throw new RuntimeException('Некорректная целевая заявка.');
        if ($targetAppId === $appId) throw new RuntimeException('Нельзя переносить в эту же заявку.');

        $outNote = $makeNote('перенос в заявку №' . (int)($targetApp['app_number'] ?? $targetAppId), $noteUser);
        $inNote = $makeNote('перенос из заявки №' . (int)$appNo, $noteUser);
        $inNote = trim($inNote) . ' · перенос';

        $dir = ($expenseScope === 'operator_minus') ? 'agent_to_operator' : 'tourist_to_agent';

        // метод для переносов фиксируем как transfer
        $runInsert($appId, $dir, 'agent', 'Агентство', -abs($amountKzt), 'transfer', $outNote);
        $runInsert($targetAppId, $dir, 'agent', 'Агентство', abs($amountKzt), 'transfer', $inNote);

        redirect('/manager/payments.php?app_id=' . $appId . '#payments');
      }

      $expLabel = 'расход';
      if ($expenseType === 'refund_tourist') $expLabel = 'возврат туристу';
      if ($expenseType === 'refund_operator') $expLabel = 'возврат туроператору';
      if ($expenseType === 'fine') $expLabel = 'штраф';

      if ($expenseScope === 'tourist_minus') {
        $runInsert($appId, 'tourist_to_agent', 'tourist', 'Турист', -abs($amountKzt), $method, $makeNote($expLabel . ' (турист -)', $noteUser));
      } elseif ($expenseScope === 'agent_minus') {
        $runInsert($appId, 'tourist_to_agent', 'agent', 'Агентство', -abs($amountKzt), $method, $makeNote($expLabel . ' (агент -)', $noteUser));
      } elseif ($expenseScope === 'operator_minus') {
        $runInsert($appId, 'agent_to_operator', 'operator', 'Туроператор', -abs($amountKzt), $method, $makeNote($expLabel . ' (туроператор -)', $noteUser));
      } else {
        $runInsert($appId, 'tourist_to_agent', 'agent', 'Агентство', -abs($amountKzt), $method, $makeNote($expLabel . ' (агент -)', $noteUser));
        $runInsert($appId, 'tourist_to_agent', 'tourist', 'Турист', abs($amountKzt), $method, $makeNote($expLabel . ' (турист +)', $noteUser));
      }

      redirect('/manager/payments.php?app_id=' . $appId . '#payments');
    }

    /* ---------------- deadlines ---------------- */

    if ($action === 'add_deadline') {
      $direction = post('direction');
      if (!in_array($direction, ['tourist_to_agent','agent_to_operator'], true)) {
        throw new RuntimeException('Неверный тип дедлайна.');
      }

      $due = post('due_date');
      $percent = money_in(post('percent', '0'));

      if ($due === '') throw new RuntimeException('Укажите дату.');
      if ($percent <= 0 || $percent > 100) throw new RuntimeException('Процент должен быть от 0 до 100.');

      $ins = $pdo->prepare("
        INSERT INTO payment_deadlines(application_id, direction, due_date, percent, note)
        VALUES(?,?,?,?,?)
      ");
      $ins->execute([$appId, $direction, $due, $percent, '']);

      redirect('/manager/payments.php?app_id=' . $appId . '#deadlines');
    }

    if ($action === 'edit_deadline') {
      $dlId = (int)post('deadline_id', '0');
      if ($dlId <= 0) throw new RuntimeException('Некорректный дедлайн.');

      $direction = post('direction');
      if (!in_array($direction, ['tourist_to_agent','agent_to_operator'], true)) {
        throw new RuntimeException('Неверный тип дедлайна.');
      }

      $due = post('due_date');
      $percent = money_in(post('percent', '0'));

      if ($due === '') throw new RuntimeException('Укажите дату.');
      if ($percent <= 0 || $percent > 100) throw new RuntimeException('Процент должен быть от 0 до 100.');

      $stChk = $pdo->prepare("SELECT id FROM payment_deadlines WHERE id=? AND application_id=? LIMIT 1");
      $stChk->execute([$dlId, $appId]);
      if (!$stChk->fetchColumn()) throw new RuntimeException('Дедлайн не найден.');

      $pdo->prepare("
        UPDATE payment_deadlines
        SET direction=?, due_date=?, percent=?, note=''
        WHERE id=? AND application_id=?
        LIMIT 1
      ")->execute([$direction, $due, $percent, $dlId, $appId]);

      redirect('/manager/payments.php?app_id=' . $appId . '#deadlines');
    }

    if ($action === 'delete_deadline') {
      $dlId = (int)($_POST['deadline_id'] ?? 0);
      if ($dlId <= 0) throw new RuntimeException('Некорректный дедлайн.');

      $pdo->prepare("DELETE FROM payment_deadlines WHERE id=? AND application_id=? LIMIT 1")
          ->execute([$dlId, $appId]);

      redirect('/manager/payments.php?app_id=' . $appId . '#deadlines');
    }

  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

/* ---------------- load payments ---------------- */

$st = $pdo->prepare("SELECT * FROM payments WHERE application_id=? ORDER BY id DESC");
$st->execute([$appId]);
$rows = $st->fetchAll();

$rowsTourist = [];
$rowsOperator = [];
foreach ($rows as $r) {
  $dir = (string)($r['direction'] ?? '');
  if ($dir === '') $dir = 'tourist_to_agent';
  if ($dir === 'agent_to_operator') $rowsOperator[] = $r;
  else $rowsTourist[] = $r;
}

$paidTouristKzt = 0.0;
$paidOperatorKzt = 0.0;

$paidTouristCurAtPay = 0.0;
$paidOperatorCurAtPay = 0.0;

foreach ($rowsTourist as $r) {
  if ((string)$r['status'] !== 'paid') continue;
  $amtKzt = (float)$r['amount'];
  $fxPay = (float)$r['fx_rate_to_kzt'];
  $paidTouristKzt += $amtKzt;
  $paidTouristCurAtPay += kzt_to_app_cur_at_pay($amtKzt, $appCurrency, $fxPay);
}
foreach ($rowsOperator as $r) {
  if ((string)$r['status'] !== 'paid') continue;
  $amtKzt = (float)$r['amount'];
  $fxPay = (float)$r['fx_rate_to_kzt'];
  $paidOperatorKzt += $amtKzt;
  $paidOperatorCurAtPay += kzt_to_app_cur_at_pay($amtKzt, $appCurrency, $fxPay);
}

$paidTouristCurAtPay = round($paidTouristCurAtPay, 2);
$paidOperatorCurAtPay = round($paidOperatorCurAtPay, 2);

$debtTouristCur = round($touristPriceCur - $paidTouristCurAtPay, 2);
$debtOperatorCur = round($operatorPriceCur - $paidOperatorCurAtPay, 2);

$factProfitKzt = round($paidTouristKzt - $paidOperatorKzt, 2);

$debtTouristKztAtAppRate = max(0.0, round($debtTouristCur * $fxRateToday, 2));
$debtOperatorKztAtAppRate = max(0.0, round($debtOperatorCur * $fxRateToday, 2));

/* ---------------- deadlines ---------------- */

$stDl = $pdo->prepare("
  SELECT *
  FROM payment_deadlines
  WHERE application_id=?
  ORDER BY direction ASC, due_date ASC, id ASC
");
$stDl->execute([$appId]);
$deadlines = $stDl->fetchAll();

$dlTourist = [];
$dlOperator = [];
foreach ($deadlines as $d) {
  if ((string)$d['direction'] === 'agent_to_operator') $dlOperator[] = $d;
  else $dlTourist[] = $d;
}

function plan_rows_dual(array $deadlines, float $totalCur, float $fxOperatorToday): array {
  $out = [];
  $sumCur = 0.0;
  foreach ($deadlines as $d) {
    $p = (float)($d['percent'] ?? 0);
    $amtCur = round($totalCur * ($p / 100.0), 2);
    $amtKzt = round($amtCur * $fxOperatorToday, 2);
    $sumCur += $amtCur;
    $out[] = [$d, $amtCur, $amtKzt];
  }
  $restCur = round($totalCur - $sumCur, 2);
  $restKzt = round($restCur * $fxOperatorToday, 2);
  return [$out, $restCur, $restKzt];
}

[$dlTouristRows] = plan_rows_dual($dlTourist, $touristPriceCur, $fxRateOperatorToday);
[$dlOperatorRows] = plan_rows_dual($dlOperator, $operatorPriceCur, $fxRateOperatorToday);

$todayCut = date('Y-m-d');
function due_as_of_today_dual(array $dlRows, string $today, float $fxOperatorToday): array {
  $sumCur = 0.0;
  foreach ($dlRows as $triple) {
    [$d, $amtCur] = $triple;
    $dd = (string)($d['due_date'] ?? '');
    if ($dd !== '' && $dd <= $today) $sumCur += (float)$amtCur;
  }
  $sumCur = round($sumCur, 2);
  $sumKzt = round($sumCur * $fxOperatorToday, 2);
  return [$sumCur, $sumKzt];
}

[$needTouristByTodayCur, $needTouristByTodayKztOp] = due_as_of_today_dual($dlTouristRows, $todayCut, $fxRateOperatorToday);
[$needOperatorByTodayCur, $needOperatorByTodayKztOp] = due_as_of_today_dual($dlOperatorRows, $todayCut, $fxRateOperatorToday);

require __DIR__ . '/_layout_top.php';
?>

<style>
  :root{ --w-strong: 750; --w-normal: 600; }
  .muted{ color: var(--muted); font-size:12px; font-weight: var(--w-normal); }
  .nowrap{ white-space:nowrap; }
  .ellipsis{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

  /* ===== FIXES for mobile ===== */

  /* не даём модалкам/инпутам вылезать по ширине */
  .modal, .section, .kpi-card { box-sizing:border-box; }
  .modal * { box-sizing:border-box; }
  input, select, textarea { max-width:100%; }

  /* кнопка "Расход / Перенос" */
  .btn { white-space:nowrap; }
  @media (max-width: 420px){
    .btn { white-space:normal; }
    .btn.btn-danger { max-width:100%; }
  }

  /* дедлайны на мобиле: таблица скроллится */
  .dl-table-wrap{ width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; }
  .dl-table{ width:100%; table-layout:fixed; min-width:520px; }
  @media (min-width: 981px){ .dl-table{ min-width:0; } }

  /* гриды в модалках: 2 колонки -> 1 колонка на мобиле */
  .grid-2{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
  @media (max-width: 560px){ .grid-2{ grid-template-columns: 1fr; } }

  /* ===== Existing styles (from previous file) ===== */

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
    min-width:0;
    overflow:hidden;
  }

  .cards{
    display:grid;
    gap:12px;
    grid-template-columns: 1fr;
    margin-top:14px;
    align-items:stretch;
  }
  @media (min-width: 980px){
    .cards{ grid-template-columns: repeat(3, minmax(0, 1fr)); }
  }
  .kpi-card{
    border:1px solid rgba(226,232,240,.92);
    border-radius:16px;
    background: rgba(255,255,255,.72);
    padding:12px;
    min-width:0;
  }
  .kpi-card .t{ color: var(--muted); font-size:12px; font-weight: var(--w-normal); }
  .kpi-card .v{ font-size:18px; font-weight: var(--w-strong); color:#0f172a; margin-top:6px; }
  .kpi-card .s{ color: var(--muted); font-size:12px; margin-top:6px; font-weight: var(--w-normal); }
  .kpi-actions{ margin-top:10px; display:flex; gap:8px; flex-wrap:wrap; }
  .btn.btn-primary{
    border-color: rgba(14,165,233,.45);
    background: rgba(14,165,233,.08);
    color:#075985;
  }

  .panel-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    margin-top:10px;
  }

  .two-cols{
    display:grid;
    grid-template-columns: 1fr;
    gap:12px;
    margin-top:10px;
    align-items:start;
  }
  @media (min-width: 1100px){
    .two-cols{ grid-template-columns: 1fr 1fr; }
  }

  .table.dl-table th, .table.dl-table td{ padding:8px 8px; vertical-align:middle; }
  .table.dl-table th{ font-size:12px; font-weight: var(--w-normal); }
  .table.dl-table td{ font-size:13px; font-weight: var(--w-normal); }

  .dl-date{ width:92px; }
  .dl-pct{ width:54px; text-align:center; }
  .dl-cur{ width:110px; text-align:right; }
  .dl-kzt{ width:120px; text-align:right; }

  .dl-form{
    display:grid;
    grid-template-columns: 1fr 92px;
    gap:10px;
    margin-top:10px;
  }
  @media (max-width: 520px){
    .dl-form{ grid-template-columns: 1fr; }
  }

  .dl-head{
    font-weight: var(--w-strong);
    color:#0f172a;
    padding:8px 10px;
    border-radius:14px;
    border:1px solid rgba(226,232,240,.92);
    background: rgba(15,23,42,.04);
  }
  .dl-head.tourist{
    border-color: rgba(14,165,233,.35);
    background: rgba(14,165,233,.10);
    color:#075985;
  }
  .dl-head.operator{
    border-color: rgba(34,197,94,.35);
    background: rgba(34,197,94,.10);
    color:#166534;
  }

  .dl-row-click{ cursor:pointer; }
  .dl-row-click:hover{ background: rgba(14,165,233,.06); }

  .pay-grid{
    display:grid;
    grid-template-columns: 1fr;
    gap:12px;
    margin-top:10px;
    align-items:start;
  }
  @media (min-width: 1100px){
    .pay-grid{ grid-template-columns: 1fr 1fr; }
  }

  .pay-table{ width:100%; table-layout: fixed; }
  .table.pay-table th, .table.pay-table td{ padding:8px 8px; vertical-align:middle; }
  .table.pay-table th{ font-size:12px; font-weight: var(--w-normal); }
  .table.pay-table td{ font-size:13px; font-weight: var(--w-normal); }

  .p-date{ width:88px; }
  .p-meth{ width:92px; }
  .p-kzt{ width:120px; text-align:right; }
  .p-cur{ width:110px; text-align:right; }
  .p-fx{ width:92px; text-align:right; }

  .row-click{ cursor:pointer; transition: background .12s ease; }
  .row-click:hover{ background: rgba(14,165,233,.06); }
  .row-transfer{ background: rgba(15,23,42,.02); }

  .pay-desktop{ }
  .pay-mobile{ display:none; margin-top:10px; }
  @media (max-width: 980px){
    .pay-desktop{ display:none; }
    .pay-mobile{ display:block; }
  }

  .pay-card{
    border:1px solid rgba(226,232,240,.92);
    border-radius:16px;
    background: rgba(255,255,255,.72);
    padding:12px;
    cursor:pointer;
  }
  .pay-card + .pay-card{ margin-top:12px; }
  .pay-card.tourist{ border-color: rgba(14,165,233,.28); background: rgba(14,165,233,.06); }
  .pay-card.operator{ border-color: rgba(34,197,94,.28); background: rgba(34,197,94,.06); }
  .pay-amt{ font-weight: var(--w-strong); color:#0f172a; }
  .pay-sub{ margin-top:6px; color:var(--muted); font-size:12px; font-weight: var(--w-normal); }

  .modal-backdrop{
    position:fixed; inset:0;
    background:rgba(15,23,42,.55);
    display:none;
    align-items:center;
    justify-content:center;
    padding:16px;
    z-index:9999;
  }
  .modal{
    background:#fff;
    border-radius:16px;
    max-width:860px;
    width:100%;
    padding:14px;
    border:1px solid rgba(226,232,240,.9);
  }
  .modal-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:10px;
    margin-bottom:10px;
  }
  .modal-title{ font-weight: var(--w-strong); }
  .modal-hint{ color:var(--muted); font-size:12px; margin-top:2px; }

  .seg{
    display:inline-flex;
    gap:6px;
    padding:6px;
    border-radius:14px;
    border:1px solid rgba(226,232,240,.85);
    background: rgba(255,255,255,.72);
    flex-wrap:wrap;
    max-width:100%;
  }
  .seg button{
    border:1px solid rgba(226,232,240,.85);
    background:#fff;
    border-radius:12px;
    padding:8px 10px;
    font-size:12px;
    font-weight: var(--w-normal);
    cursor:pointer;
    white-space:nowrap;
    max-width:100%;
  }
  .seg button.active{
    border-color: rgba(14,165,233,.45);
    background: rgba(14,165,233,.08);
  }

  .recalc{
    margin-top:10px;
    border:1px solid rgba(226,232,240,.85);
    border-radius:14px;
    background: rgba(248,250,252,.75);
    padding:10px;
  }
  .recalc .row{ display:flex; justify-content:space-between; gap:10px; }
  .recalc .row + .row{ margin-top:6px; }
  .recalc b{ font-weight: var(--w-strong); color:#0f172a; }

  .btn.btn-danger{
    border-color:rgba(239,68,68,.45);
    color:#ef4444;
    background: rgba(239,68,68,.06);
  }
  .btn.btn-sm{
    padding:8px 10px;
    border-radius:12px;
    font-size:12px;
    font-weight: var(--w-normal);
  }
</style>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div style="min-width:0;">
    <h1 class="h1" style="margin-bottom:6px;">Оплаты</h1>
    <div class="badge ellipsis" title="Заявка №<?= (int)$appNo ?>">
      Заявка №<?= (int)$appNo ?>
      · <?= h((string)($app['country'] ?? $app['destination'] ?? '')) ?>
      · валюта тура: <b><?= h($appCurrency) ?></b>
      · курс (в заявке): <?= number_format($fxRateToday, 2, '.', ' ') ?>
      · курс (оператор сегодня): <?= number_format($fxRateOperatorToday, 2, '.', ' ') ?>
    </div>
  </div>
  <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn" href="/manager/app_view.php?id=<?= (int)$app['id'] ?>">← К заявке</a>
    <a class="btn" href="#deadlines">Дедлайны</a>
    <a class="btn" href="#payments">Оплаты</a>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
<?php endif; ?>

<!-- KPI -->
<div class="cards">
  <div class="kpi-card">
    <div class="t">Турист</div>
    <div class="s" style="margin-top:8px;">Цена</div>
    <div class="v"><?= number_format($touristPriceCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></div>
    <div class="s"><?= number_format($touristPriceKztToday, 2, '.', ' ') ?> KZT</div>

    <div class="s" style="margin-top:10px;">Оплачено</div>
    <div style="font-weight:var(--w-strong); color:#0f172a;"><?= number_format($paidTouristCurAtPay, 2, '.', ' ') ?> <?= h($appCurrency) ?></div>
    <div class="s"><?= number_format($paidTouristKzt, 2, '.', ' ') ?> KZT</div>

    <div class="s" style="margin-top:10px;">Долг</div>
    <div style="font-weight:var(--w-strong); color:<?= ($debtTouristCur > 0 ? '#ef4444' : '#16a34a') ?>;">
      <?= number_format($debtTouristCur, 2, '.', ' ') ?> <?= h($appCurrency) ?>
    </div>
    <div class="s"><?= number_format($debtTouristKztAtAppRate, 2, '.', ' ') ?> KZT</div>

    <div class="kpi-actions">
      <button class="btn btn-sm btn-primary" type="button" onclick="openPayModal('tourist_to_agent')">Оплатить</button>
    </div>
  </div>

  <div class="kpi-card">
    <div class="t">Туроператор</div>
    <div class="s" style="margin-top:8px;">Цена</div>
    <div class="v"><?= number_format($operatorPriceCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></div>
    <div class="s"><?= number_format($operatorPriceKztToday, 2, '.', ' ') ?> KZT</div>

    <div class="s" style="margin-top:10px;">Оплачено</div>
    <div style="font-weight:var(--w-strong); color:#0f172a;"><?= number_format($paidOperatorCurAtPay, 2, '.', ' ') ?> <?= h($appCurrency) ?></div>
    <div class="s"><?= number_format($paidOperatorKzt, 2, '.', ' ') ?> KZT</div>

    <div class="s" style="margin-top:10px;">Долг</div>
    <div style="font-weight:var(--w-strong); color:<?= ($debtOperatorCur > 0 ? '#ef4444' : '#16a34a') ?>;">
      <?= number_format($debtOperatorCur, 2, '.', ' ') ?> <?= h($appCurrency) ?>
    </div>
    <div class="s"><?= number_format($debtOperatorKztAtAppRate, 2, '.', ' ') ?> KZT</div>

    <div class="kpi-actions">
      <button class="btn btn-sm btn-primary" type="button" onclick="openPayModal('agent_to_operator')">Оплатить</button>
    </div>
  </div>

  <div class="kpi-card">
    <div class="t">Прибыль</div>
    <div class="s" style="margin-top:8px;">Плановая</div>
    <div style="font-weight:var(--w-strong); color:#0f172a;">
      <?= number_format($planProfitCur, 2, '.', ' ') ?> <?= h($appCurrency) ?>
    </div>
    <div class="s"><?= number_format($planProfitKztToday, 2, '.', ' ') ?> KZT</div>

    <div class="s" style="margin-top:10px;">Фактическая</div>
    <div class="v"><?= number_format($factProfitKzt, 2, '.', ' ') ?> KZT</div>
  </div>
</div>

<!-- Deadlines -->
<div id="deadlines" class="section-title">Дедлайны</div>

<div class="two-cols">
  <div class="section">
    <div class="panel-row">
      <div class="dl-head tourist">График туриста</div>
      <div class="badge muted">
        К <?= h(fmt_dmy(date('Y-m-d'))) ?>:
        <b style="color:#0f172a; font-weight:var(--w-strong);"><?= number_format($needTouristByTodayCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></b>
        (≈ <?= number_format($needTouristByTodayKztOp, 2, '.', ' ') ?> KZT)
      </div>
    </div>

    <form method="post" class="form" style="margin-top:10px;">
      <input type="hidden" name="_action" value="add_deadline">
      <input type="hidden" name="direction" value="tourist_to_agent">
      <div class="dl-form">
        <div class="input" style="margin:0;">
          <label>Дата</label>
          <input type="date" name="due_date" required>
        </div>
        <div class="input" style="margin:0;">
          <label>%</label>
          <input type="text" name="percent" required placeholder="30">
        </div>
      </div>
      <button class="btn" type="submit" style="margin-top:10px;">Добавить</button>
    </form>

    <div class="dl-table-wrap">
      <table class="table dl-table" style="margin-top:10px;">
        <thead>
          <tr>
            <th class="dl-date">Дата</th>
            <th class="dl-pct">%</th>
            <th class="dl-cur"><?= h($appCurrency) ?></th>
            <th class="dl-kzt">KZT</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$dlTouristRows): ?>
            <tr><td colspan="4" class="muted">Нет дедлайнов.</td></tr>
          <?php else: ?>
            <?php foreach ($dlTouristRows as $triple): ?>
              <?php [$d, $amtCur, $amtKzt] = $triple; ?>
              <tr class="dl-row-click" data-deadline='<?= h(json_encode([
                'id' => (int)$d['id'],
                'direction' => (string)($d['direction'] ?? 'tourist_to_agent'),
                'due_date' => (string)($d['due_date'] ?? ''),
                'percent' => (float)($d['percent'] ?? 0),
              ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                <td class="nowrap"><?= h(fmt_dmy((string)$d['due_date'])) ?></td>
                <td class="dl-pct"><?= number_format((float)$d['percent'], 0, '.', ' ') ?></td>
                <td class="dl-cur" style="font-weight:var(--w-strong); color:#0f172a;"><?= number_format((float)$amtCur, 2, '.', ' ') ?></td>
                <td class="dl-kzt" style="font-weight:var(--w-strong); color:#0f172a;"><?= number_format((float)$amtKzt, 2, '.', ' ') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="section">
    <div class="panel-row">
      <div class="dl-head operator">График туроператора</div>
      <div class="badge muted">
        К <?= h(fmt_dmy(date('Y-m-d'))) ?>:
        <b style="color:#0f172a; font-weight:var(--w-strong);"><?= number_format($needOperatorByTodayCur, 2, '.', ' ') ?> <?= h($appCurrency) ?></b>
        (≈ <?= number_format($needOperatorByTodayKztOp, 2, '.', ' ') ?> KZT)
      </div>
    </div>

    <form method="post" class="form" style="margin-top:10px;">
      <input type="hidden" name="_action" value="add_deadline">
      <input type="hidden" name="direction" value="agent_to_operator">
      <div class="dl-form">
        <div class="input" style="margin:0;">
          <label>Дата</label>
          <input type="date" name="due_date" required>
        </div>
        <div class="input" style="margin:0;">
          <label>%</label>
          <input type="text" name="percent" required placeholder="50">
        </div>
      </div>
      <button class="btn" type="submit" style="margin-top:10px;">Добавить</button>
    </form>

    <div class="dl-table-wrap">
      <table class="table dl-table" style="margin-top:10px;">
        <thead>
          <tr>
            <th class="dl-date">Дата</th>
            <th class="dl-pct">%</th>
            <th class="dl-cur"><?= h($appCurrency) ?></th>
            <th class="dl-kzt">KZT</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$dlOperatorRows): ?>
            <tr><td colspan="4" class="muted">Нет дедлайнов.</td></tr>
          <?php else: ?>
            <?php foreach ($dlOperatorRows as $triple): ?>
              <?php [$d, $amtCur, $amtKzt] = $triple; ?>
              <tr class="dl-row-click" data-deadline='<?= h(json_encode([
                'id' => (int)$d['id'],
                'direction' => (string)($d['direction'] ?? 'agent_to_operator'),
                'due_date' => (string)($d['due_date'] ?? ''),
                'percent' => (float)($d['percent'] ?? 0),
              ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                <td class="nowrap"><?= h(fmt_dmy((string)$d['due_date'])) ?></td>
                <td class="dl-pct"><?= number_format((float)$d['percent'], 0, '.', ' ') ?></td>
                <td class="dl-cur" style="font-weight:var(--w-strong); color:#0f172a;"><?= number_format((float)$amtCur, 2, '.', ' ') ?></td>
                <td class="dl-kzt" style="font-weight:var(--w-strong); color:#0f172a;"><?= number_format((float)$amtKzt, 2, '.', ' ') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Deadline modal -->
<div id="dlModal" class="modal-backdrop" onclick="if(event.target===this) closeDlModal();">
  <div class="modal">
    <div class="modal-head">
      <div style="min-width:0;">
        <div class="modal-title">Дедлайн</div>
        <div class="modal-hint" id="dlModalHint">—</div>
      </div>
      <button class="btn" type="button" onclick="closeDlModal()">Закрыть</button>
    </div>

    <form method="post" class="form">
      <input type="hidden" name="_action" value="edit_deadline">
      <input type="hidden" name="deadline_id" id="dlId" value="">
      <input type="hidden" name="direction" id="dlDir" value="tourist_to_agent">

      <div class="grid-2">
        <div class="input">
          <label>Дата</label>
          <input type="date" name="due_date" id="dlDue" required>
        </div>
        <div class="input">
          <label>%</label>
          <input type="text" name="percent" id="dlPct" required>
        </div>
      </div>

      <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
        <button class="btn" type="submit">Сохранить</button>
        <button class="btn btn-danger" type="button" onclick="submitDlDelete()">Удалить</button>
      </div>
    </form>

    <form method="post" id="dlDeleteForm" style="display:none;">
      <input type="hidden" name="_action" value="delete_deadline">
      <input type="hidden" name="deadline_id" id="dlDelId" value="">
    </form>
  </div>
</div>

<!-- Payments -->
<div id="payments" class="section-title">Оплаты</div>

<div class="panel-row">
  <div class="badge">Добавление оплаты: ввод KZT или <?= h($appCurrency) ?>. Перенос — отдельная карточка без редактирования.</div>
  <div style="display:flex; gap:8px; flex-wrap:wrap;">
    <button class="btn" type="button" onclick="openPayModal('tourist_to_agent')">+ От туриста</button>
    <button class="btn" type="button" onclick="openPayModal('agent_to_operator')">+ Туроператору</button>
    <button class="btn btn-danger" type="button" onclick="openExpenseModal()"><span>Расход / Перенос</span></button>
  </div>
</div>

<!-- Add payment modal -->
<div id="payModal" class="modal-backdrop" onclick="if(event.target===this) closePayModal();">
  <div class="modal">
    <div class="modal-head">
      <div style="min-width:0;">
        <div class="modal-title" id="payModalTitle">Добавить оплату</div>
        <div class="modal-hint">Сохранится в базе в KZT. Эквивалент — автоматически.</div>
      </div>
      <button class="btn" type="button" onclick="closePayModal()">Закрыть</button>
    </div>

    <div class="section" style="margin-top:0;">
      <div class="muted">Остаток долга</div>
      <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-top:6px;">
        <div style="font-weight:var(--w-strong); color:#0f172a;" id="addDebtCurTxt">—</div>
        <button class="btn btn-sm" type="button" onclick="fillAmountFromDebt()">Подставить</button>
      </div>
      <div class="muted" style="margin-top:6px;" id="addDebtKztTxt">—</div>
    </div>

    <form method="post" class="form" oninput="recalcAddPreview()">
      <input type="hidden" name="_action" value="add_payment">
      <input type="hidden" name="direction" id="payDirection" value="tourist_to_agent">

      <div class="input" style="margin:10px 0 10px;">
        <label>Валюта ввода</label>
        <div class="seg">
          <button type="button" id="segKzt" class="active" onclick="setAddCurrency('KZT')">KZT</button>
          <button type="button" id="segCur" onclick="setAddCurrency('CUR')"><?= h($appCurrency) ?></button>
        </div>
        <input type="hidden" name="input_currency" id="addInputCurrency" value="KZT">
      </div>

      <div class="grid-2">
        <div class="input">
          <label>Дата оплаты</label>
          <input type="date" name="pay_date" id="addPayDate" value="<?= h(date('Y-m-d')) ?>">
        </div>
        <div class="input">
          <label>Метод</label>
          <select name="method" id="addPayMethod">
            <option value="bank">банк</option>
            <option value="cash">наличные</option>
            <option value="card">карта</option>
            <option value="other">другое</option>
          </select>
        </div>
      </div>

      <div class="grid-2">
        <div class="input" id="addAmountKztBox">
          <label>Сумма (KZT)</label>
          <input type="text" name="amount_kzt" id="addAmountKzt" placeholder="100000">
        </div>

        <div class="input" id="addAmountCurBox" style="display:none;">
          <label>Сумма (<?= h($appCurrency) ?>)</label>
          <input type="text" name="amount_cur" id="addAmountCur" placeholder="100">
        </div>

        <div class="input">
          <label>Курс <?= h($appCurrency) ?>→KZT (туроператор сегодня)</label>
          <input type="text" name="fx_rate_to_kzt" id="addFxAtPay" value="<?= h(number_format((float)$fxRateOperatorToday, 6, '.', '')) ?>" required>
        </div>
      </div>

      <div class="input" style="margin-top:10px;">
        <label>Комментарий</label>
        <input type="text" name="note" id="addNote" placeholder="назначение/комментарий">
      </div>

      <div class="recalc">
        <div class="row"><div class="muted">Сохранится как</div><div><b id="addSaveKzt">—</b></div></div>
        <div class="row"><div class="muted">Эквивалент</div><div><b id="addSaveCur">—</b></div></div>
      </div>

      <button class="btn" type="submit">Сохранить</button>
    </form>
  </div>
</div>

<!-- Expense modal -->
<div id="expenseModal" class="modal-backdrop" onclick="if(event.target===this) closeExpenseModal();">
  <div class="modal">
    <div class="modal-head">
      <div style="min-width:0;">
        <div class="modal-title">Расход / Перенос</div>
        <div class="modal-hint">Операции внутри заявки (кроме переноса). Перенос создаёт 2 записи.</div>
      </div>
      <button class="btn" type="button" onclick="closeExpenseModal()">Закрыть</button>
    </div>

    <form method="post" class="form" oninput="recalcExpensePreview()">
      <input type="hidden" name="_action" value="add_expense">

      <div class="input">
        <label>Тип операции</label>
        <select name="expense_type" id="expType" onchange="toggleTransferTarget()">
          <option value="refund_tourist">Возврат туристу</option>
          <option value="refund_operator">Возврат туроператору</option>
          <option value="fine">Штраф</option>
          <option value="transfer">Перенос (в другую заявку)</option>
        </select>
      </div>

      <div class="input" style="margin-top:10px;">
        <label>В какую часть относится расход</label>
        <select name="expense_scope" id="expScope">
          <option value="tourist_minus">Турист − (минус с его счета)</option>
          <option value="agent_minus">Агент − (минус со счета агента)</option>
          <option value="agent_to_tourist">Агент → Турист (агент − и турист +)</option>
          <option value="operator_minus">Туроператор − (минус со счета туроператора)</option>
        </select>
      </div>

      <div class="input" id="transferTargetBox" style="display:none; margin-top:10px;">
        <label>Номер заявки (куда переносим)</label>
        <input type="text" name="target_app_no" id="targetAppNo" placeholder="например 1234">
        <div class="muted" style="margin-top:6px;">Можно указать app_number или id.</div>
      </div>

      <div class="input" style="margin-top:10px;">
        <label>Валюта ввода</label>
        <div class="seg">
          <button type="button" id="xsegKzt" class="active" onclick="setExpenseCurrency('KZT')">KZT</button>
          <button type="button" id="xsegCur" onclick="setExpenseCurrency('CUR')"><?= h($appCurrency) ?></button>
        </div>
        <input type="hidden" name="input_currency" id="expInputCurrency" value="KZT">
      </div>

      <div class="grid-2">
        <div class="input">
          <label>Дата</label>
          <input type="date" name="pay_date" id="expPayDate" value="<?= h(date('Y-m-d')) ?>">
        </div>
        <div class="input">
          <label>Метод</label>
          <select name="method" id="expMethod">
            <option value="bank">банк</option>
            <option value="cash">наличные</option>
            <option value="card">карта</option>
            <option value="other">другое</option>
          </select>
        </div>
      </div>

      <div class="grid-2">
        <div class="input" id="expAmountKztBox">
          <label>Сумма (KZT)</label>
          <input type="text" name="amount_kzt" id="expAmountKzt" placeholder="100000">
        </div>

        <div class="input" id="expAmountCurBox" style="display:none;">
          <label>Сумма (<?= h($appCurrency) ?>)</label>
          <input type="text" name="amount_cur" id="expAmountCur" placeholder="100">
        </div>

        <div class="input">
          <label>Курс <?= h($appCurrency) ?>→KZT</label>
          <input type="text" name="fx_rate_to_kzt" id="expFxAtPay" value="<?= h(number_format((float)$fxRateOperatorToday, 6, '.', '')) ?>" required>
        </div>
      </div>

      <div class="input" style="margin-top:10px;">
        <label>Комментарий</label>
        <input type="text" name="note" id="expNote" placeholder="причина/описание">
      </div>

      <div class="recalc">
        <div class="row"><div class="muted">Сумма (KZT)</div><div><b id="expSaveKzt">—</b></div></div>
        <div class="row"><div class="muted">Эквивалент</div><div><b id="expSaveCur">—</b></div></div>
      </div>

      <button class="btn btn-danger" type="submit">Провести операцию</button>
    </form>
  </div>
</div>

<!-- Payments tables -->
<div class="pay-desktop">
  <div class="pay-grid">
    <div class="section">
      <div class="badge" style="font-weight:var(--w-strong);">Оплаты от туриста</div>
      <table class="table pay-table" style="margin-top:10px;">
        <thead>
          <tr>
            <th class="p-date">Дата</th>
            <th class="p-meth">Метод</th>
            <th class="p-kzt">KZT</th>
            <th class="p-cur"><?= h($appCurrency) ?></th>
            <th class="p-fx">Курс</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rowsTourist): ?>
            <tr><td colspan="5" class="muted">Платежей нет.</td></tr>
          <?php else: ?>
            <?php foreach ($rowsTourist as $p): ?>
              <?php
                $pid = (int)($p['id'] ?? 0);
                $amtKzt = (float)($p['amount'] ?? 0);
                $fxPay = (float)($p['fx_rate_to_kzt'] ?? 0);
                $amtCur = kzt_to_app_cur_at_pay($amtKzt, $appCurrency, $fxPay);
                $m = (string)($p['method'] ?? '');
                $dateIso = (string)($p['pay_date'] ?? '');
                $note = (string)($p['note'] ?? '');
                $isTransfer = ($m === 'transfer') || is_transfer_note($note);
              ?>
              <tr class="row-click <?= $isTransfer ? 'row-transfer' : '' ?>"
                  data-payment='<?= h(json_encode([
                    'id' => $pid,
                    'direction' => (string)($p['direction'] ?? 'tourist_to_agent'),
                    'pay_date' => $dateIso,
                    'method' => (string)($p['method'] ?? 'bank'),
                    'note' => $note,
                    'amount_kzt' => $amtKzt,
                    'fx_rate_to_kzt' => $fxPay,
                    'is_transfer' => $isTransfer,
                  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                <td class="nowrap"><?= h(fmt_dmy($dateIso)) ?></td>
                <td><?= h(pay_method_label($m)) ?></td>
                <td class="p-kzt" style="font-weight:var(--w-strong); color:#0f172a;"><?= number_format($amtKzt, 2, '.', ' ') ?></td>
                <td class="p-cur"><?= number_format((float)$amtCur, 2, '.', ' ') ?></td>
                <td class="p-fx"><?= number_format((float)$fxPay, 2, '.', ' ') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="section">
      <div class="badge" style="font-weight:var(--w-strong);">Оплаты туроператору</div>
      <table class="table pay-table" style="margin-top:10px;">
        <thead>
          <tr>
            <th class="p-date">Дата</th>
            <th class="p-meth">Метод</th>
            <th class="p-kzt">KZT</th>
            <th class="p-cur"><?= h($appCurrency) ?></th>
            <th class="p-fx">Курс</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rowsOperator): ?>
            <tr><td colspan="5" class="muted">Платежей нет.</td></tr>
          <?php else: ?>
            <?php foreach ($rowsOperator as $p): ?>
              <?php
                $pid = (int)($p['id'] ?? 0);
                $amtKzt = (float)($p['amount'] ?? 0);
                $fxPay = (float)($p['fx_rate_to_kzt'] ?? 0);
                $amtCur = kzt_to_app_cur_at_pay($amtKzt, $appCurrency, $fxPay);
                $m = (string)($p['method'] ?? '');
                $dateIso = (string)($p['pay_date'] ?? '');
                $note = (string)($p['note'] ?? '');
                $isTransfer = ($m === 'transfer') || is_transfer_note($note);
              ?>
              <tr class="row-click <?= $isTransfer ? 'row-transfer' : '' ?>"
                  data-payment='<?= h(json_encode([
                    'id' => $pid,
                    'direction' => (string)($p['direction'] ?? 'agent_to_operator'),
                    'pay_date' => $dateIso,
                    'method' => (string)($p['method'] ?? 'bank'),
                    'note' => $note,
                    'amount_kzt' => $amtKzt,
                    'fx_rate_to_kzt' => $fxPay,
                    'is_transfer' => $isTransfer,
                  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                <td class="nowrap"><?= h(fmt_dmy($dateIso)) ?></td>
                <td><?= h(pay_method_label($m)) ?></td>
                <td class="p-kzt" style="font-weight:var(--w-strong); color:#0f172a;"><?= number_format($amtKzt, 2, '.', ' ') ?></td>
                <td class="p-cur"><?= number_format((float)$amtCur, 2, '.', ' ') ?></td>
                <td class="p-fx"><?= number_format((float)$fxPay, 2, '.', ' ') ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="pay-mobile">
  <div class="section">
    <div class="badge" style="font-weight:var(--w-strong);">Оплаты от туриста</div>
    <?php if (!$rowsTourist): ?>
      <div class="muted" style="margin-top:10px;">Платежей нет.</div>
    <?php else: ?>
      <?php foreach ($rowsTourist as $p): ?>
        <?php
          $pid = (int)($p['id'] ?? 0);
          $amtKzt = (float)($p['amount'] ?? 0);
          $fxPay = (float)($p['fx_rate_to_kzt'] ?? 0);
          $amtCur = kzt_to_app_cur_at_pay($amtKzt, $appCurrency, $fxPay);
          $m = (string)($p['method'] ?? '');
          $dateIso = (string)($p['pay_date'] ?? '');
          $note = (string)($p['note'] ?? '');
          $isTransfer = ($m === 'transfer') || is_transfer_note($note);
        ?>
        <div class="pay-card tourist row-click <?= $isTransfer ? 'row-transfer' : '' ?>" data-payment='<?= h(json_encode([
          'id' => $pid,
          'direction' => (string)($p['direction'] ?? 'tourist_to_agent'),
          'pay_date' => $dateIso,
          'method' => (string)($p['method'] ?? 'bank'),
          'note' => $note,
          'amount_kzt' => $amtKzt,
          'fx_rate_to_kzt' => $fxPay,
          'is_transfer' => $isTransfer,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
          <div class="pay-amt"><?= number_format($amtKzt, 2, '.', ' ') ?> KZT</div>
          <div class="pay-sub"><?= number_format((float)$amtCur, 2, '.', ' ') ?> <?= h($appCurrency) ?> · курс <?= number_format((float)$fxPay, 2, '.', ' ') ?></div>
          <div class="pay-sub"><?= h(fmt_dmy($dateIso)) ?> · <?= h(pay_method_label($m)) ?></div>
          <?php if (trim($note) !== ''): ?><div class="pay-sub"><?= h($note) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="section" style="margin-top:12px;">
    <div class="badge" style="font-weight:var(--w-strong);">Оплаты туроператору</div>
    <?php if (!$rowsOperator): ?>
      <div class="muted" style="margin-top:10px;">Платежей нет.</div>
    <?php else: ?>
      <?php foreach ($rowsOperator as $p): ?>
        <?php
          $pid = (int)($p['id'] ?? 0);
          $amtKzt = (float)($p['amount'] ?? 0);
          $fxPay = (float)($p['fx_rate_to_kzt'] ?? 0);
          $amtCur = kzt_to_app_cur_at_pay($amtKzt, $appCurrency, $fxPay);
          $m = (string)($p['method'] ?? '');
          $dateIso = (string)($p['pay_date'] ?? '');
          $note = (string)($p['note'] ?? '');
          $isTransfer = ($m === 'transfer') || is_transfer_note($note);
        ?>
        <div class="pay-card operator row-click <?= $isTransfer ? 'row-transfer' : '' ?>" data-payment='<?= h(json_encode([
          'id' => $pid,
          'direction' => (string)($p['direction'] ?? 'agent_to_operator'),
          'pay_date' => $dateIso,
          'method' => (string)($p['method'] ?? 'bank'),
          'note' => $note,
          'amount_kzt' => $amtKzt,
          'fx_rate_to_kzt' => $fxPay,
          'is_transfer' => $isTransfer,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
          <div class="pay-amt"><?= number_format($amtKzt, 2, '.', ' ') ?> KZT</div>
          <div class="pay-sub"><?= number_format((float)$amtCur, 2, '.', ' ') ?> <?= h($appCurrency) ?> · курс <?= number_format((float)$fxPay, 2, '.', ' ') ?></div>
          <div class="pay-sub"><?= h(fmt_dmy($dateIso)) ?> · <?= h(pay_method_label($m)) ?></div>
          <?php if (trim($note) !== ''): ?><div class="pay-sub"><?= h($note) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- View modal -->
<div id="viewPayModal" class="modal-backdrop" onclick="if(event.target===this) closeViewPayModal();">
  <div class="modal">
    <div class="modal-head">
      <div style="min-width:0;">
        <div class="modal-title">Платёж</div>
        <div class="modal-hint" id="viewPayHint">—</div>
      </div>
      <button class="btn" type="button" onclick="closeViewPayModal()">Закрыть</button>
    </div>

    <div class="section" style="margin-top:0;">
      <div class="muted">KZT</div>
      <div style="font-weight:var(--w-strong); font-size:18px; color:#0f172a;" id="viewPayKzt">—</div>

      <div style="margin-top:10px;" class="muted"><?= h($appCurrency) ?></div>
      <div style="font-weight:var(--w-strong); color:#0f172a;" id="viewPayCur">—</div>

      <div style="margin-top:10px;" class="muted">Дата · метод · курс</div>
      <div id="viewPayMeta" style="font-weight:var(--w-normal);">—</div>

      <div id="viewPayNoteBox" style="margin-top:10px; display:none;">
        <div class="muted">Комментарий</div>
        <div id="viewPayNote" style="font-weight:var(--w-normal);"></div>
      </div>
    </div>

    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
      <button class="btn" type="button" onclick="openEditFromView()">Редактировать</button>

      <form method="post" style="margin:0;" onsubmit="return confirm('Удалить платёж? Действие необратимо.');">
        <input type="hidden" name="_action" value="delete_payment">
        <input type="hidden" name="payment_id" id="viewDelPaymentId" value="">
        <button class="btn btn-danger" type="submit">Удалить</button>
      </form>
    </div>
  </div>
</div>

<!-- Edit modal -->
<div id="editPayModal" class="modal-backdrop" onclick="if(event.target===this) closeEditPayModal();">
  <div class="modal">
    <div class="modal-head">
      <div style="min-width:0;">
        <div class="modal-title">Редактировать платёж</div>
        <div class="modal-hint">Измените сумму/курс/дату/метод или комментарий.</div>
      </div>
      <button class="btn" type="button" onclick="closeEditPayModal()">Закрыть</button>
    </div>

    <form method="post" class="form" oninput="recalcEditPreview()">
      <input type="hidden" name="_action" value="edit_payment">
      <input type="hidden" name="payment_id" id="editPaymentId" value="">
      <input type="hidden" name="direction" id="editDirection" value="tourist_to_agent">

      <div class="input" style="margin:0 0 10px;">
        <label>Валюта ввода</label>
        <div class="seg">
          <button type="button" id="esegKzt" class="active" onclick="setEditCurrency('KZT')">KZT</button>
          <button type="button" id="esegCur" onclick="setEditCurrency('CUR')"><?= h($appCurrency) ?></button>
        </div>
        <input type="hidden" name="input_currency" id="editInputCurrency" value="KZT">
      </div>

      <div class="grid-2">
        <div class="input">
          <label>Дата оплаты</label>
          <input type="date" name="pay_date" id="editPayDate" value="">
        </div>
        <div class="input">
          <label>Метод</label>
          <select name="method" id="editPayMethod">
            <option value="bank">банк</option>
            <option value="cash">наличные</option>
            <option value="card">карта</option>
            <option value="other">другое</option>
          </select>
        </div>
      </div>

      <div class="grid-2">
        <div class="input" id="editAmountKztBox">
          <label>Сумма (KZT)</label>
          <input type="text" name="amount_kzt" id="editAmountKzt" placeholder="100000">
        </div>
        <div class="input" id="editAmountCurBox" style="display:none;">
          <label>Сумма (<?= h($appCurrency) ?>)</label>
          <input type="text" name="amount_cur" id="editAmountCur" placeholder="100">
        </div>
        <div class="input">
          <label>Курс <?= h($appCurrency) ?>→KZT</label>
          <input type="text" name="fx_rate_to_kzt" id="editFxAtPay" value="" required>
        </div>
      </div>

      <div class="input" style="margin-top:10px;">
        <label>Комментарий</label>
        <input type="text" name="note" id="editNote" placeholder="назначение/комментарий">
      </div>

      <div class="recalc">
        <div class="row"><div class="muted">Сохранится как</div><div><b id="editSaveKzt">—</b></div></div>
        <div class="row"><div class="muted">Эквивалент</div><div><b id="editSaveCur">—</b></div></div>
      </div>

      <button class="btn" type="submit">Сохранить изменения</button>
    </form>
  </div>
</div>

<!-- Transfer modal (no edit) -->
<div id="transferModal" class="modal-backdrop" onclick="if(event.target===this) closeTransferModal();">
  <div class="modal">
    <div class="modal-head">
      <div style="min-width:0;">
        <div class="modal-title">Перенос</div>
        <div class="modal-hint">Редактирование запрещено. Можно удалить операцию.</div>
      </div>
      <button class="btn" type="button" onclick="closeTransferModal()">Закрыть</button>
    </div>

    <div class="section" style="margin-top:0;">
      <div class="muted">Дата переноса</div>
      <div style="font-weight:var(--w-strong);" id="trDate">—</div>

      <div style="margin-top:10px;" class="muted">Сумма по курсу в заявке</div>
      <div style="font-weight:var(--w-strong);" id="trAmountAppCur">—</div>

      <div style="margin-top:10px;" class="muted">Сумма (KZT)</div>
      <div style="font-weight:var(--w-strong);" id="trAmountKzt">—</div>

      <div style="margin-top:10px;" class="muted">Заявка, из которой был осуществлен перенос</div>
      <div style="font-weight:var(--w-strong);" id="trFrom">—</div>

      <div id="trNoteBox" style="margin-top:10px; display:none;">
        <div class="muted">Комментарий</div>
        <div id="trNote"></div>
      </div>
    </div>

    <form method="post" style="margin-top:10px;" onsubmit="return confirm('Удалить перенос? Удалится только эта запись.');">
      <input type="hidden" name="_action" value="delete_payment">
      <input type="hidden" name="payment_id" id="trPaymentId" value="">
      <button class="btn btn-danger" type="submit">Удалить перенос</button>
    </form>
  </div>
</div>

<script>
  var APP_CURRENCY = <?= json_encode($appCurrency, JSON_UNESCAPED_UNICODE) ?>;
  var FX_OPERATOR_TODAY = <?= json_encode((float)$fxRateOperatorToday) ?>;
  var FX_APP_RATE = <?= json_encode((float)$fxRateToday) ?>;

  var DEBT_TOURIST_CUR = <?= json_encode((float)$debtTouristCur) ?>;
  var DEBT_OPERATOR_CUR = <?= json_encode((float)$debtOperatorCur) ?>;
  var DEBT_TOURIST_KZT = <?= json_encode((float)$debtTouristKztAtAppRate) ?>;
  var DEBT_OPERATOR_KZT = <?= json_encode((float)$debtOperatorKztAtAppRate) ?>;

  function toNum(v){
    v = (v || '').toString().trim();
    if (!v) return 0;
    v = v.replace(/\s+/g,'').replace(',', '.');
    var n = parseFloat(v);
    return isFinite(n) ? n : 0;
  }
  function fmtMoney(n){
    try { return (Number(n || 0)).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    catch(e){ return String(n); }
  }
  function fmtFx(n){
    n = Number(n || 0);
    if (!isFinite(n)) return '—';
    return n.toFixed(2);
  }

  // ---------- deadlines modal ----------
  function getDeadlineDataFromEl(el){
    if (!el) return null;
    var raw = el.getAttribute('data-deadline');
    if (!raw) return null;
    try { return JSON.parse(raw); } catch(e) { return null; }
  }
  document.addEventListener('click', function(e){
    var tr = e.target.closest('tr.dl-row-click');
    if (!tr) return;
    var d = getDeadlineDataFromEl(tr);
    if (!d || !d.id) return;
    openDlModal(d);
  });
  function openDlModal(d){
    document.getElementById('dlId').value = d.id;
    document.getElementById('dlDelId').value = d.id;
    document.getElementById('dlDir').value = d.direction || 'tourist_to_agent';
    document.getElementById('dlDue').value = (d.due_date || '').substring(0,10);
    document.getElementById('dlPct').value = String(d.percent || 0);
    document.getElementById('dlModalHint').textContent =
      (d.direction === 'agent_to_operator') ? 'График: туроператор' : 'График: турист';
    document.getElementById('dlModal').style.display = 'flex';
  }
  function closeDlModal(){ document.getElementById('dlModal').style.display = 'none'; }
  function submitDlDelete(){
    if (!confirm('Удалить дедлайн?')) return;
    document.getElementById('dlDeleteForm').submit();
  }

  // ---------- add payment modal ----------
  function openPayModal(direction){
    document.getElementById('payDirection').value = direction || 'tourist_to_agent';

    var isOp = (direction === 'agent_to_operator');
    document.getElementById('payModalTitle').textContent = isOp ? 'Добавить оплату туроператору' : 'Добавить оплату от туриста';

    var debtCur = isOp ? DEBT_OPERATOR_CUR : DEBT_TOURIST_CUR;
    var debtKzt = isOp ? DEBT_OPERATOR_KZT : DEBT_TOURIST_KZT;

    document.getElementById('addDebtCurTxt').textContent =
      fmtMoney(debtCur) + ' ' + (APP_CURRENCY === 'KZT' ? 'KZT' : APP_CURRENCY);
    document.getElementById('addDebtKztTxt').textContent =
      '≈ ' + fmtMoney(debtKzt) + ' KZT (по курсу в заявке)';

    document.getElementById('addFxAtPay').value = (APP_CURRENCY === 'KZT') ? '1' : String(FX_OPERATOR_TODAY || 0);
    document.getElementById('addAmountKzt').value = '';
    if (document.getElementById('addAmountCur')) document.getElementById('addAmountCur').value = '';
    document.getElementById('addNote').value = '';
    setAddCurrency('KZT');

    document.getElementById('payModal').style.display = 'flex';
    recalcAddPreview();
  }
  function closePayModal(){ document.getElementById('payModal').style.display = 'none'; }

  function setAddCurrency(mode){
    var segKzt = document.getElementById('segKzt');
    var segCur = document.getElementById('segCur');
    var input = document.getElementById('addInputCurrency');
    var boxK = document.getElementById('addAmountKztBox');
    var boxC = document.getElementById('addAmountCurBox');

    if (mode === 'CUR' && APP_CURRENCY === 'KZT') mode = 'KZT';

    if (mode === 'KZT') {
      input.value = 'KZT';
      segKzt.classList.add('active');
      segCur.classList.remove('active');
      boxK.style.display = '';
      boxC.style.display = 'none';
    } else {
      input.value = APP_CURRENCY;
      segKzt.classList.remove('active');
      segCur.classList.add('active');
      boxK.style.display = 'none';
      boxC.style.display = '';
    }
    recalcAddPreview();
  }

  function fillAmountFromDebt(){
    var direction = document.getElementById('payDirection').value || 'tourist_to_agent';
    var isOp = (direction === 'agent_to_operator');

    var inputCur = document.getElementById('addInputCurrency')?.value || 'KZT';
    var fx = toNum(document.getElementById('addFxAtPay')?.value || '0');
    var debtCur = isOp ? DEBT_OPERATOR_CUR : DEBT_TOURIST_CUR;

    if (APP_CURRENCY === 'KZT') {
      document.getElementById('addAmountKzt').value = String(debtCur);
    } else {
      if (inputCur === 'KZT') {
        var kzt = (fx > 0) ? (debtCur * fx) : 0;
        document.getElementById('addAmountKzt').value = String(Math.max(0, kzt.toFixed(2)));
      } else {
        if (document.getElementById('addAmountCur')) document.getElementById('addAmountCur').value = String(Math.max(0, debtCur.toFixed(2)));
      }
    }
    recalcAddPreview();
  }

  function recalcAddPreview(){
    var inputCur = document.getElementById('addInputCurrency')?.value || 'KZT';
    var fx = toNum(document.getElementById('addFxAtPay')?.value || '0');

    var amtKzt = toNum(document.getElementById('addAmountKzt')?.value || '0');
    var amtCur = toNum(document.getElementById('addAmountCur')?.value || '0');

    var saveKzt = 0, saveCur = 0;

    if (APP_CURRENCY === 'KZT') {
      saveKzt = (inputCur === 'KZT') ? amtKzt : amtCur;
      saveCur = saveKzt;
    } else {
      if (inputCur === 'KZT') { saveKzt = amtKzt; saveCur = (fx > 0) ? (saveKzt / fx) : 0; }
      else { saveCur = amtCur; saveKzt = (fx > 0) ? (saveCur * fx) : 0; }
    }

    document.getElementById('addSaveKzt').textContent = fmtMoney(saveKzt) + ' KZT';
    document.getElementById('addSaveCur').textContent =
      (APP_CURRENCY === 'KZT') ? (fmtMoney(saveCur) + ' KZT') : (fmtMoney(saveCur) + ' ' + APP_CURRENCY);
  }

  // ---------- expense modal ----------
  function openExpenseModal(){
    document.getElementById('expType').value = 'refund_tourist';
    document.getElementById('expScope').value = 'tourist_minus';
    document.getElementById('expFxAtPay').value = (APP_CURRENCY === 'KZT') ? '1' : String(FX_OPERATOR_TODAY || 0);
    document.getElementById('expAmountKzt').value = '';
    if (document.getElementById('expAmountCur')) document.getElementById('expAmountCur').value = '';
    document.getElementById('expNote').value = '';
    document.getElementById('targetAppNo').value = '';
    setExpenseCurrency('KZT');
    toggleTransferTarget();
    document.getElementById('expenseModal').style.display = 'flex';
    recalcExpensePreview();
  }
  function closeExpenseModal(){ document.getElementById('expenseModal').style.display = 'none'; }

  function toggleTransferTarget(){
    var t = document.getElementById('expType').value;
    document.getElementById('transferTargetBox').style.display = (t === 'transfer') ? '' : 'none';
  }

  function setExpenseCurrency(mode){
    var segKzt = document.getElementById('xsegKzt');
    var segCur = document.getElementById('xsegCur');
    var input = document.getElementById('expInputCurrency');
    var boxK = document.getElementById('expAmountKztBox');
    var boxC = document.getElementById('expAmountCurBox');

    if (mode === 'CUR' && APP_CURRENCY === 'KZT') mode = 'KZT';

    if (mode === 'KZT') {
      input.value = 'KZT';
      segKzt.classList.add('active');
      segCur.classList.remove('active');
      boxK.style.display = '';
      boxC.style.display = 'none';
    } else {
      input.value = APP_CURRENCY;
      segKzt.classList.remove('active');
      segCur.classList.add('active');
      boxK.style.display = 'none';
      boxC.style.display = '';
    }
    recalcExpensePreview();
  }

  function recalcExpensePreview(){
    var inputCur = document.getElementById('expInputCurrency')?.value || 'KZT';
    var fx = toNum(document.getElementById('expFxAtPay')?.value || '0');
    var amtKzt = toNum(document.getElementById('expAmountKzt')?.value || '0');
    var amtCur = toNum(document.getElementById('expAmountCur')?.value || '0');

    var saveKzt = 0, saveCur = 0;

    if (APP_CURRENCY === 'KZT') {
      saveKzt = (inputCur === 'KZT') ? amtKzt : amtCur;
      saveCur = saveKzt;
    } else {
      if (inputCur === 'KZT') { saveKzt = amtKzt; saveCur = (fx > 0) ? (saveKzt / fx) : 0; }
      else { saveCur = amtCur; saveKzt = (fx > 0) ? (saveCur * fx) : 0; }
    }

    document.getElementById('expSaveKzt').textContent = fmtMoney(saveKzt) + ' KZT';
    document.getElementById('expSaveCur').textContent =
      (APP_CURRENCY === 'KZT') ? (fmtMoney(saveCur) + ' KZT') : (fmtMoney(saveCur) + ' ' + APP_CURRENCY);
  }

  // ---------- payments view/edit/transfer ----------
  function getPaymentDataFromEl(el){
    if (!el) return null;
    var raw = el.getAttribute('data-payment');
    if (!raw) return null;
    try { return JSON.parse(raw); } catch(e) { return null; }
  }

  document.addEventListener('click', function(e){
    var row = e.target.closest('.row-click');
    if (!row) return;
    if (e.target.closest('button')) return;

    var data = getPaymentDataFromEl(row);
    if (!data || !data.id) return;

    if (data.is_transfer) {
      openTransferModal(data);
    } else {
      openViewPayModal(data);
    }
  });

  var lastViewed = null;

  function calcCurFromKzt(amountKzt, fx){
    if (APP_CURRENCY === 'KZT') return amountKzt;
    if (!fx || fx <= 0) return 0;
    return amountKzt / fx;
  }

  function openViewPayModal(data){
    lastViewed = data;

    var amtKzt = Number(data.amount_kzt || 0);
    var fx = Number(data.fx_rate_to_kzt || 0);
    var amtCur = calcCurFromKzt(amtKzt, fx);

    document.getElementById('viewPayKzt').textContent = fmtMoney(amtKzt) + ' KZT';
    document.getElementById('viewPayCur').textContent =
      (APP_CURRENCY === 'KZT') ? (fmtMoney(amtCur) + ' KZT') : (fmtMoney(amtCur) + ' ' + APP_CURRENCY);

    var dt = (data.pay_date || '').toString().substring(0,10);
    var method = (data.method || '').toString();
    document.getElementById('viewPayMeta').textContent = (dt ? dt : '—') + ' · ' + method + ' · курс ' + fmtFx(fx);

    var note = (data.note || '').toString().trim();
    var nb = document.getElementById('viewPayNoteBox');
    if (note) {
      nb.style.display = '';
      document.getElementById('viewPayNote').textContent = note;
    } else {
      nb.style.display = 'none';
      document.getElementById('viewPayNote').textContent = '';
    }

    document.getElementById('viewPayHint').textContent =
      (data.direction === 'agent_to_operator') ? 'Оплата туроператору' : 'Оплата от туриста';

    document.getElementById('viewDelPaymentId').value = data.id;
    document.getElementById('viewPayModal').style.display = 'flex';
  }

  function closeViewPayModal(){ document.getElementById('viewPayModal').style.display = 'none'; }

  function openEditFromView(){
    if (!lastViewed) return;
    closeViewPayModal();
    openEditPayModal(lastViewed);
  }

  function openEditPayModal(data){
    document.getElementById('editPaymentId').value = data.id || '';
    document.getElementById('editDirection').value = data.direction || 'tourist_to_agent';
    document.getElementById('editPayDate').value = (data.pay_date || '').toString().substring(0,10);
    document.getElementById('editPayMethod').value = data.method || 'bank';
    document.getElementById('editNote').value = data.note || '';
    document.getElementById('editFxAtPay').value = (data.fx_rate_to_kzt != null) ? String(data.fx_rate_to_kzt) : '';

    document.getElementById('editAmountKzt').value = (data.amount_kzt != null) ? String(data.amount_kzt) : '';
    if (document.getElementById('editAmountCur')) document.getElementById('editAmountCur').value = '';

    setEditCurrency('KZT');

    document.getElementById('editPayModal').style.display = 'flex';
    recalcEditPreview();
  }

  function closeEditPayModal(){ document.getElementById('editPayModal').style.display = 'none'; }

  function setEditCurrency(mode){
    var segKzt = document.getElementById('esegKzt');
    var segCur = document.getElementById('esegCur');
    var input = document.getElementById('editInputCurrency');

    var boxK = document.getElementById('editAmountKztBox');
    var boxC = document.getElementById('editAmountCurBox');

    if (mode === 'CUR' && APP_CURRENCY === 'KZT') mode = 'KZT';

    if (mode === 'KZT') {
      input.value = 'KZT';
      segKzt.classList.add('active');
      segCur.classList.remove('active');
      boxK.style.display = '';
      boxC.style.display = 'none';
    } else {
      input.value = APP_CURRENCY;
      segKzt.classList.remove('active');
      segCur.classList.add('active');
      boxK.style.display = 'none';
      boxC.style.display = '';
    }
    recalcEditPreview();
  }

  function recalcEditPreview(){
    var inputCur = document.getElementById('editInputCurrency')?.value || 'KZT';
    var fx = toNum(document.getElementById('editFxAtPay')?.value || '0');

    var amtKzt = toNum(document.getElementById('editAmountKzt')?.value || '0');
    var amtCur = toNum(document.getElementById('editAmountCur')?.value || '0');

    var saveKzt = 0, saveCur = 0;

    if (APP_CURRENCY === 'KZT') {
      saveKzt = (inputCur === 'KZT') ? amtKzt : amtCur;
      saveCur = saveKzt;
    } else {
      if (inputCur === 'KZT') { saveKzt = amtKzt; saveCur = (fx > 0) ? (saveKzt / fx) : 0; }
      else { saveCur = amtCur; saveKzt = (fx > 0) ? (saveCur * fx) : 0; }
    }

    document.getElementById('editSaveKzt').textContent = fmtMoney(saveKzt) + ' KZT';
    document.getElementById('editSaveCur').textContent =
      (APP_CURRENCY === 'KZT') ? (fmtMoney(saveCur) + ' KZT') : (fmtMoney(saveCur) + ' ' + APP_CURRENCY);
  }

  function parseTransferFromNote(note){
    note = (note || '').toString();
    var m = note.match(/заявк[аи]\s*№\s*(\d+)/i);
    return m ? m[1] : '';
  }

  function openTransferModal(p){
    document.getElementById('trPaymentId').value = p.id || '';
    document.getElementById('trDate').textContent = (p.pay_date || '—').toString().substring(0,10);

    var amtKzt = Number(p.amount_kzt || 0);
    document.getElementById('trAmountKzt').textContent = fmtMoney(amtKzt) + ' KZT';

    if (APP_CURRENCY === 'KZT' || !FX_APP_RATE || FX_APP_RATE <= 0) {
      document.getElementById('trAmountAppCur').textContent = fmtMoney(amtKzt) + ' KZT';
    } else {
      var cur = amtKzt / FX_APP_RATE;
      document.getElementById('trAmountAppCur').textContent = fmtMoney(cur) + ' ' + APP_CURRENCY + ' (курс ' + FX_APP_RATE.toFixed(2) + ')';
    }

    var from = parseTransferFromNote(p.note || '');
    document.getElementById('trFrom').textContent = from ? ('№' + from) : '—';

    var note = (p.note || '').toString().trim();
    note = note.replace(/\s*·\s*перенос\s*$/i, '').trim();

    if (note) {
      document.getElementById('trNoteBox').style.display = '';
      document.getElementById('trNote').textContent = note;
    } else {
      document.getElementById('trNoteBox').style.display = 'none';
      document.getElementById('trNote').textContent = '';
    }

    document.getElementById('transferModal').style.display = 'flex';
  }

  function closeTransferModal(){ document.getElementById('transferModal').style.display = 'none'; }
</script>

<?php require __DIR__ . '/_layout_bottom.php'; ?>