<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';
require_once __DIR__ . '/../app/db.php';

require_role('manager');

$title = 'Курсы туроператоров';
$pdo = db();

$error = null;

function fmt_dmy_hi(?string $dt): string {
  $dt = trim((string)$dt);
  if ($dt === '') return '—';
  $ts = strtotime($dt);
  if ($ts === false) return $dt;
  return date('d.m.Y H:i', $ts);
}

function money_in(string $s): float {
  $s = trim($s);
  if ($s === '') return 0.0;
  $s = str_replace([' ', ','], ['', '.'], $s);
  return (float)$s;
}

/**
 * Значок тренда по курсу:
 * - ↑ красный, если курс стал выше
 * - ↓ зелёный, если курс стал ниже
 * - пусто, если нет сравнения / нет изменений
 */
function trend_badge(?float $cur, ?float $prev): string
{
  if ($cur === null || $prev === null) return '';
  if (abs($cur - $prev) < 0.0000001) return '';

  if ($cur > $prev) {
    return '<span class="trend up" title="Курс вырос">↑</span>';
  }
  return '<span class="trend down" title="Курс снизился">↓</span>';
}

/**
 * Курс "на вчера" (точнее: последняя запись ДО сегодняшнего дня).
 * Если сегодня ещё нет записей — вернётся последняя за прошлые дни.
 */
function pick_yesterday_rate(array $rowsByCurrency, string $currency, string $todayYmd): ?array
{
  if (!isset($rowsByCurrency[$currency])) return null;
  foreach ($rowsByCurrency[$currency] as $r) {
    $cap = (string)($r['captured_at'] ?? '');
    if ($cap === '') continue;
    $ymd = substr($cap, 0, 10);
    if ($ymd < $todayYmd) return $r;
  }
  return null;
}

try {
  $pdo->query("SELECT 1")->fetchColumn();
} catch (Throwable $e) {
  http_response_code(500);
  echo "DB error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  exit;
}

// --- POST actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = (string)($_POST['_action'] ?? '');

    if ($action === 'save_source') {
      $opId = (int)($_POST['operator_id'] ?? 0);
      $url = trim((string)($_POST['fx_source_url'] ?? ''));

      if ($opId <= 0) throw new RuntimeException('Некорректный оператор.');
      if ($url === '') throw new RuntimeException('Укажите URL источника.');

      $pdo->prepare("UPDATE tour_operators SET fx_source_url=? WHERE id=? LIMIT 1")
          ->execute([$url, $opId]);

      header('Location: /manager/operator_fx.php');
      exit;
    }

    if ($action === 'set_manual') {
      $opId = (int)($_POST['operator_id'] ?? 0);
      $cur = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
      $rate = (float)money_in((string)($_POST['rate_to_kzt'] ?? '0'));
      $sourceUrl = trim((string)($_POST['source_url'] ?? ''));

      if ($opId <= 0) throw new RuntimeException('Некорректный оператор.');
      if (!in_array($cur, ['USD','EUR'], true)) throw new RuntimeException('Валюта должна быть USD или EUR.');
      if ($rate <= 0) throw new RuntimeException('Курс должен быть больше 0.');

      $u = current_user();
      $uid = (int)($u['id'] ?? 0);

      $st = $pdo->prepare("
        INSERT INTO operator_fx_rates(operator_id, currency, rate_to_kzt, source_url, captured_at, captured_by_user_id, captured_by_method)
        VALUES(?,?,?,?,NOW(),?, 'manual')
        ON DUPLICATE KEY UPDATE
          rate_to_kzt=VALUES(rate_to_kzt),
          source_url=VALUES(source_url),
          captured_at=NOW(),
          captured_by_user_id=VALUES(captured_by_user_id),
          captured_by_method='manual'
      ");
      $st->execute([$opId, $cur, $rate, $sourceUrl, $uid]);

      $pdo->prepare("UPDATE tour_operators SET fx_status='manual', fx_updated_at=NOW() WHERE id=? LIMIT 1")
          ->execute([$opId]);

      header('Location: /manager/operator_fx.php');
      exit;
    }

  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

// --- Load data ---
try {
  $ops = $pdo->query("
    SELECT id, name, fx_source_url, fx_updated_at, fx_status
    FROM tour_operators
    ORDER BY name ASC
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  http_response_code(500);
  echo "SQL error (tour_operators): " . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  exit;
}

/**
 * Собирае  :
 *  - последнюю запись по каждой валюте (USD/EUR)
 *  - предыдущую запись по каждой валюте (чтобы показать тренд относительно последнего обновления)
 *  - курс "на вчера" (последняя запись ДО сегодняшнего дня) по каждой валюте
 *  - фактическое обновление = max(captured_at) по оператору
 */
$ratesMap = [];      // [oid][cur] => last row
$prevRatesMap = [];  // [oid][cur] => prev row (вторая по свежести)
$ydayRatesMap = [];  // [oid][cur] => row (последняя до today)
try {
  $rates = $pdo->query("
    SELECT operator_id, currency, rate_to_kzt, source_url, captured_at, captured_by_method
    FROM operator_fx_rates
    ORDER BY operator_id ASC, currency ASC, captured_at DESC
  ")->fetchAll(PDO::FETCH_ASSOC);

  $todayYmd = date('Y-m-d');

  // сгруппуем для вычисления "на вчера"
  $rowsByOperator = []; // [oid][cur] => rows[]
  foreach ($rates as $r) {
    $oid = (int)$r['operator_id'];
    $cur = (string)$r['currency'];
    if (!in_array($cur, ['USD','EUR'], true)) continue;
    $rowsByOperator[$oid][$cur][] = $r;
  }

  // last/prev + lastCaptured
  $seen = [];         // [oid][cur] => count
  $lastCaptured = []; // operator_id => datetime string

  foreach ($rates as $r) {
    $oid = (int)$r['operator_id'];
    $cur = (string)$r['currency'];
    if (!in_array($cur, ['USD','EUR'], true)) continue;

    $seen[$oid][$cur] = (int)($seen[$oid][$cur] ?? 0) + 1;

    if (($seen[$oid][$cur] ?? 0) === 1) {
      $ratesMap[$oid][$cur] = $r;
    } elseif (($seen[$oid][$cur] ?? 0) === 2) {
      $prevRatesMap[$oid][$cur] = $r;
    }

    $cap = (string)($r['captured_at'] ?? '');
    if ($cap !== '' && (!isset($lastCaptured[$oid]) || strtotime($cap) > strtotime((string)$lastCaptured[$oid]))) {
      $lastCaptured[$oid] = $cap;
    }
  }

  foreach ($rowsByOperator as $oid => $byCur) {
    foreach (['USD','EUR'] as $cur) {
      $yr = pick_yesterday_rate($byCur, $cur, $todayYmd);
      if ($yr) $ydayRatesMap[(int)$oid][$cur] = $yr;
    }
  }

  foreach ($lastCaptured as $oid => $dt) {
    $ratesMap[(int)$oid]['_last_fx_captured_at'] = $dt;
  }

} catch (Throwable $e) {
  http_response_code(500);
  echo "SQL error (operator_fx_rates): " . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  exit;
}

require __DIR__ . '/_layout_top.php';
?>

<style>
  :root{
    --w-strong: 750;
    --w-normal: 600;
  }

  .muted{ color: var(--muted); font-weight: var(--w-normal); }
  .mini{ font-size:12px; }
  .nowrap{ white-space:nowrap; }
  .ellipsis{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }

  .ok{ color:#16a34a; font-weight: var(--w-strong); }
  .bad{ color:#ef4444; font-weight: var(--w-strong); }
  .warn{ color:#f59e0b; font-weight: var(--w-strong); }

  /* Убираем видимую полосу прокрутки (но оставляем возможность скролла) */
  .fx-wrap{
    margin-top:12px;
    overflow:auto;
    border-radius:16px;

    scrollbar-width: none;      /* Firefox */
    -ms-overflow-style: none;   /* IE/Edge legacy */
  }
  .fx-wrap::-webkit-scrollbar{ width:0; height:0; } /* WebKit */

  .fx-table{
    width:100%;
    table-layout: fixed;
    min-width: 1380px; /* + столбцы "на вчера" */
  }
  .table.fx-table th, .table.fx-table td{ padding:8px 8px; vertical-align: top; }
  .table.fx-table th{ font-size:12px; font-weight: var(--w-normal); }
  .table.fx-table td{ font-size:13px; font-weight: var(--w-normal); }

  /* курс: число + тренд рядом */
  .rate{
    display:flex;
    align-items:baseline;
    gap:8px;
    white-space:nowrap;
  }
  .rate .num{ font-weight: var(--w-strong); color:#0f172a; }

  .trend{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:18px;
    height:18px;
    border-radius:999px;
    font-size:12px;
    line-height:1;
    font-weight: var(--w-strong);
    border:1px solid rgba(226,232,240,.85);
    background: rgba(255,255,255,.78);
  }
  .trend.up{
    color:#ef4444;
    border-color: rgba(239,68,68,.35);
    background: rgba(239,68,68,.06);
  }
  .trend.down{
    color:#16a34a;
    border-color: rgba(34,197,94,.35);
    background: rgba(34,197,94,.06);
  }

  /* rowform (source url) */
  .rowform{
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
    margin:0;
  }
  .rowform input[type="text"]{
    min-width: 320px;
    flex: 1;
  }

  .btn.btn-sm{
    padding:8px 10px;
    border-radius:12px;
    font-size:12px;
    font-weight: var(--w-normal);
  }
  .btn.btn-primary{
    border-color: rgba(14,165,233,.40);
    background: rgba(14,165,233,.08);
  }
  .btn.btn-primary:hover{
    border-color: rgba(14,165,233,.55);
    box-shadow: 0 12px 26px rgba(2,8,23,.06);
  }

  details.fx-details{
    margin-top:8px;
    border:1px solid rgba(226,232,240,.85);
    border-radius:14px;
    background: rgba(255,255,255,.72);
    padding:10px;
  }
  details.fx-details > summary{
    cursor:pointer;
    color: var(--muted);
    font-size:12px;
    font-weight: var(--w-normal);
    list-style: none;
  }
  details.fx-details > summary::-webkit-details-marker{ display:none; }

  .manual-form{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    align-items:center;
    margin-top:10px;
  }
  .manual-form input[type="text"]{ min-width: 160px; }

  /* MOBILE: cards */
  .fx-cards{ display:none; margin-top:12px; }
  .fx-card{
    border:1px solid rgba(226,232,240,.92);
    border-radius:16px;
    background: rgba(255,255,255,.72);
    padding:12px;
    box-shadow: var(--shadow);
  }
  .fx-card + .fx-card{ margin-top:12px; }
  .fx-head{ display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
  .fx-name{ font-weight: var(--w-strong); }
  .fx-status{ margin-left:auto; }
  .fx-grid{
    display:grid;
    grid-template-columns: 1fr 1fr;
    gap:10px;
    margin-top:10px;
  }
  @media (max-width: 420px){
    .fx-grid{ grid-template-columns: 1fr; }
  }
  .box{
    border:1px solid rgba(226,232,240,.85);
    border-radius:14px;
    background: rgba(255,255,255,.78);
    padding:10px;
    min-width:0;
  }
  .box .ttl{ color:var(--muted); font-size:12px; font-weight: var(--w-normal); }
  .box .val{ margin-top:6px; font-size:13px; font-weight: var(--w-normal); }
  .box .val b{ font-weight: var(--w-strong); }

  @media (max-width: 980px){
    .fx-wrap{ display:none; }
    .fx-cards{ display:block; }
  }
</style>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div>
    <h1 class="h1" style="margin-bottom:6px;">Курсы туроператоров (KZT)</h1>
    <div class="badge">
      Подсказка: на десктопе можно перемещать таблицу влево/вправо колесом мыши (Shift+колесо) или удерживая ЛКМ и перетаскивая.
    </div>
  </div>
  <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <a class="btn btn-sm btn-primary" href="/manager/apps.php">← В заявки</a>
  </div>
</div>

<?php if ($error): ?>
  <div class="alert" style="margin-top:14px;"><?= h($error) ?></div>
<?php endif; ?>

<!-- DESKTOP/TABLET -->
<div id="fxWrap" class="fx-wrap">
  <table class="table fx-table">
    <thead>
      <tr>
        <th style="width:240px;">Туроператор</th>

        <th style="width:120px;">USD→KZT</th>
        <th style="width:120px;">USD вчера</th>
        <th style="width:150px;">Обновлено</th>

        <th style="width:120px;">EUR→KZT</th>
        <th style="width:120px;">EUR вчера</th>
        <th style="width:150px;">Обновлено</th>

        <th style="width:150px;">Факт</th>
        <th style="width:150px;">Попытка</th>

        <th style="width:120px;">Статус</th>
        <th style="width:520px;">Источник / ручной ввод</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$ops): ?>
        <tr><td colspan="11" class="muted">Нет туроператоров.</td></tr>
      <?php else: ?>
        <?php foreach ($ops as $o): ?>
          <?php
            $oid = (int)$o['id'];

            $usd = isset($ratesMap[$oid]['USD']['rate_to_kzt']) ? (float)$ratesMap[$oid]['USD']['rate_to_kzt'] : null;
            $eur = isset($ratesMap[$oid]['EUR']['rate_to_kzt']) ? (float)$ratesMap[$oid]['EUR']['rate_to_kzt'] : null;

            // тренд относительно предыдущей записи (по времени)
            $usdPrev = isset($prevRatesMap[$oid]['USD']['rate_to_kzt']) ? (float)$prevRatesMap[$oid]['USD']['rate_to_kzt'] : null;
            $eurPrev = isset($prevRatesMap[$oid]['EUR']['rate_to_kzt']) ? (float)$prevRatesMap[$oid]['EUR']['rate_to_kzt'] : null;

            // "курс на вчера" = последняя запись ДО сегодняшнего дня
            $usdY = isset($ydayRatesMap[$oid]['USD']['rate_to_kzt']) ? (float)$ydayRatesMap[$oid]['USD']['rate_to_kzt'] : null;
            $eurY = isset($ydayRatesMap[$oid]['EUR']['rate_to_kzt']) ? (float)$ydayRatesMap[$oid]['EUR']['rate_to_kzt'] : null;

            $usdAt = (string)($ratesMap[$oid]['USD']['captured_at'] ?? '');
            $eurAt = (string)($ratesMap[$oid]['EUR']['captured_at'] ?? '');

            $usdMethod = (string)($ratesMap[$oid]['USD']['captured_by_method'] ?? '');
            $eurMethod = (string)($ratesMap[$oid]['EUR']['captured_by_method'] ?? '');

            $factAt = (string)($ratesMap[$oid]['_last_fx_captured_at'] ?? '');
            $tryAt = (string)($o['fx_updated_at'] ?? '');

            $status = (string)($o['fx_status'] ?? '');
            $statusText = $status !== '' ? $status : '—';
            $statusClass = 'warn';
            if ($status === 'ok') $statusClass = 'ok';
            if (in_array($status, ['fetch_failed','parse_failed','db_failed'], true)) $statusClass = 'bad';

            $sourceUrl = trim((string)($o['fx_source_url'] ?? ''));
          ?>
          <tr>
            <td>
              <div style="font-weight:var(--w-strong); color:#0f172a;" class="ellipsis" title="<?= h((string)$o['name']) ?>">
                <?= h((string)$o['name']) ?>
              </div>
              <div class="muted mini">ID <?= (int)$oid ?></div>
            </td>

            <td>
              <div class="rate">
                <span class="num"><?= $usd !== null ? number_format($usd, 2, '.', ' ') : '—' ?></span>
                <?= trend_badge($usd, $usdPrev) ?>
              </div>
              <?php if ($usdPrev !== null): ?>
                <div class="muted mini">было: <?= number_format($usdPrev, 2, '.', ' ') ?></div>
              <?php endif; ?>
            </td>

            <td class="muted mini">
              <?= $usdY !== null ? number_format($usdY, 2, '.', ' ') : '—' ?>
            </td>

            <td class="muted mini">
              <?= h(fmt_dmy_hi($usdAt)) ?>
              <?php if ($usdMethod !== ''): ?><div class="muted mini"><?= h($usdMethod) ?></div><?php endif; ?>
            </td>

            <td>
              <div class="rate">
                <span class="num"><?= $eur !== null ? number_format($eur, 2, '.', ' ') : '—' ?></span>
                <?= trend_badge($eur, $eurPrev) ?>
              </div>
              <?php if ($eurPrev !== null): ?>
                <div class="muted mini">было: <?= number_format($eurPrev, 2, '.', ' ') ?></div>
              <?php endif; ?>
            </td>

            <td class="muted mini">
              <?= $eurY !== null ? number_format($eurY, 2, '.', ' ') : '—' ?>
            </td>

            <td class="muted mini">
              <?= h(fmt_dmy_hi($eurAt)) ?>
              <?php if ($eurMethod !== ''): ?><div class="muted mini"><?= h($eurMethod) ?></div><?php endif; ?>
            </td>

            <td class="muted mini"><?= h(fmt_dmy_hi($factAt)) ?></td>
            <td class="muted mini"><?= h(fmt_dmy_hi($tryAt)) ?></td>

            <td class="<?= h($statusClass) ?> nowrap"><?= h($statusText) ?></td>

            <td>
              <form method="post" class="rowform">
                <input type="hidden" name="_action" value="save_source">
                <input type="hidden" name="operator_id" value="<?= (int)$oid ?>">
                <input name="fx_source_url" type="text" value="<?= h($sourceUrl) ?>" placeholder="https://b2b.selfietravel.kz/search_tour">
                <button class="btn btn-sm btn-primary" type="submit">Сохранить</button>
                <?php if ($sourceUrl !== ''): ?>
                  <a class="btn btn-sm" target="_blank" href="<?= h($sourceUrl) ?>">Открыть</a>
                <?php endif; ?>
              </form>

              <details class="fx-details">
                <summary>Ручная установка курса</summary>
                <form method="post" class="manual-form">
                  <input type="hidden" name="_action" value="set_manual">
                  <input type="hidden" name="operator_id" value="<?= (int)$oid ?>">
                  <select name="currency">
                    <option value="USD">USD</option>
                    <option value="EUR">EUR</option>
                  </select>
                  <input name="rate_to_kzt" type="text" placeholder="Напр. 510">
                  <input name="source_url" type="text" placeholder="URL (опционально)">
                  <button class="btn btn-sm btn-primary" type="submit">Применить</button>
                </form>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- MOBILE: cards -->
<div class="fx-cards">
  <?php if (!$ops): ?>
    <div class="muted">Нет туроператоров.</div>
  <?php else: ?>
    <?php foreach ($ops as $o): ?>
      <?php
        $oid = (int)$o['id'];

        $usd = isset($ratesMap[$oid]['USD']['rate_to_kzt']) ? (float)$ratesMap[$oid]['USD']['rate_to_kzt'] : null;
        $eur = isset($ratesMap[$oid]['EUR']['rate_to_kzt']) ? (float)$ratesMap[$oid]['EUR']['rate_to_kzt'] : null;

        $usdPrev = isset($prevRatesMap[$oid]['USD']['rate_to_kzt']) ? (float)$prevRatesMap[$oid]['USD']['rate_to_kzt'] : null;
        $eurPrev = isset($prevRatesMap[$oid]['EUR']['rate_to_kzt']) ? (float)$prevRatesMap[$oid]['EUR']['rate_to_kzt'] : null;

        $usdY = isset($ydayRatesMap[$oid]['USD']['rate_to_kzt']) ? (float)$ydayRatesMap[$oid]['USD']['rate_to_kzt'] : null;
        $eurY = isset($ydayRatesMap[$oid]['EUR']['rate_to_kzt']) ? (float)$ydayRatesMap[$oid]['EUR']['rate_to_kzt'] : null;

        $usdAt = (string)($ratesMap[$oid]['USD']['captured_at'] ?? '');
        $eurAt = (string)($ratesMap[$oid]['EUR']['captured_at'] ?? '');

        $factAt = (string)($ratesMap[$oid]['_last_fx_captured_at'] ?? '');
        $tryAt = (string)($o['fx_updated_at'] ?? '');

        $status = (string)($o['fx_status'] ?? '');
        $statusText = $status !== '' ? $status : '—';
        $statusClass = 'warn';
        if ($status === 'ok') $statusClass = 'ok';
        if (in_array($status, ['fetch_failed','parse_failed','db_failed'], true)) $statusClass = 'bad';

        $sourceUrl = trim((string)($o['fx_source_url'] ?? ''));
      ?>

      <div class="fx-card">
        <div class="fx-head">
          <div style="min-width:0;">
            <div class="fx-name ellipsis" title="<?= h((string)$o['name']) ?>"><?= h((string)$o['name']) ?></div>
            <div class="muted mini">ID <?= (int)$oid ?> · факт: <?= h(fmt_dmy_hi($factAt)) ?> · попытка: <?= h(fmt_dmy_hi($tryAt)) ?></div>
          </div>
          <div class="fx-status">
            <span class="<?= h($statusClass) ?>"><?= h($statusText) ?></span>
          </div>
        </div>

        <div class="fx-grid">
          <div class="box">
            <div class="ttl">USD→KZT</div>
            <div class="val">
              <b><?= $usd !== null ? number_format($usd, 2, '.', ' ') : '—' ?></b>
              <?= trend_badge($usd, $usdPrev) ?><br>
              <span class="muted mini">вчера: <?= $usdY !== null ? number_format($usdY, 2, '.', ' ') : '—' ?></span><br>
              <span class="muted mini">обновлено: <?= h(fmt_dmy_hi($usdAt)) ?></span>
            </div>
          </div>
          <div class="box">
            <div class="ttl">EUR→KZT</div>
            <div class="val">
              <b><?= $eur !== null ? number_format($eur, 2, '.', ' ') : '—' ?></b>
              <?= trend_badge($eur, $eurPrev) ?><br>
              <span class="muted mini">вчера: <?= $eurY !== null ? number_format($eurY, 2, '.', ' ') : '—' ?></span><br>
              <span class="muted mini">обновлено: <?= h(fmt_dmy_hi($eurAt)) ?></span>
            </div>
          </div>
        </div>

        <details class="fx-details" style="margin-top:10px;">
          <summary>Источник и ручная установка</summary>

          <form method="post" class="rowform" style="margin-top:10px;">
            <input type="hidden" name="_action" value="save_source">
            <input type="hidden" name="operator_id" value="<?= (int)$oid ?>">
            <input name="fx_source_url" type="text" value="<?= h($sourceUrl) ?>" placeholder="https://b2b.selfietravel.kz/search_tour">
            <button class="btn btn-sm btn-primary" type="submit">Сохранить</button>
            <?php if ($sourceUrl !== ''): ?>
              <a class="btn btn-sm" target="_blank" href="<?= h($sourceUrl) ?>">Открыть</a>
            <?php endif; ?>
          </form>

          <form method="post" class="manual-form">
            <input type="hidden" name="_action" value="set_manual">
            <input type="hidden" name="operator_id" value="<?= (int)$oid ?>">
            <select name="currency">
              <option value="USD">USD</option>
              <option value="EUR">EUR</option>
            </select>
            <input name="rate_to_kzt" type="text" placeholder="Напр. 510">
            <input name="source_url" type="text" placeholder="URL (опционально)">
            <button class="btn btn-sm btn-primary" type="submit">Применить</button>
          </form>
        </details>
      </div>

    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
/**
 * Desktop UX:
 * 1) Drag-to-scroll horizontally with mouse (no visible scrollbar)
 * 2) Shift+Wheel => horizontal scroll
 * 3) Trackpads already work natively
 */
(function () {
  var wrap = document.getElementById('fxWrap');
  if (!wrap) return;

  var isDown = false;
  var startX = 0;
  var scrollLeft = 0;

  wrap.addEventListener('mousedown', function (e) {
    // не начинаем drag если кликнули по input/button/select/a/details/summary
    var t = e.target;
    if (t && (t.closest('input,textarea,select,button,a,details,summary,label') || t.isContentEditable)) return;

    isDown = true;
    startX = e.pageX;
    scrollLeft = wrap.scrollLeft;
    wrap.style.cursor = 'grabbing';
    wrap.style.userSelect = 'none';
  });

  window.addEventListener('mouseup', function () {
    if (!isDown) return;
    isDown = false;
    wrap.style.cursor = '';
    wrap.style.userSelect = '';
  });

  window.addEventListener('mousemove', function (e) {
    if (!isDown) return;
    var dx = e.pageX - startX;
    wrap.scrollLeft = scrollLeft - dx;
  });

  // Shift + wheel => horizontal
  wrap.addEventListener('wheel', function (e) {
    if (!e.shiftKey) return;
    e.preventDefault();
    wrap.scrollLeft += e.deltaY;
  }, { passive: false });
})();
</script>

<div class="badge" style="margin-top:12px;">
  Для SelfieTravel укажите источник: <b>https://b2b.selfietravel.kz/search_tour</b>.
  Cron читает <span class="mono">samo.CROSS_RATES</span> и берёт поля USD/EUR с ключом <span class="mono">"4"</span> (курс к KZT).
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>