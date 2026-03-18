<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

chdir(__DIR__ . '/..');

require_once __DIR__ . '/../app/db.php';

$pdo = db();

function fetch_url(string $url): string
{
  $ctx = stream_context_create([
    'http' => [
      'method' => 'GET',
      'timeout' => 30,
      'header' => "User-Agent: turdoc-bot/1.0\r\nAccept: text/html,application/json\r\n",
      'follow_location' => 1,
      'max_redirects' => 5,
    ],
  ]);

  $body = @file_get_contents($url, false, $ctx);
  return is_string($body) ? $body : '';
}

function strip_utf8_bom(string $s): string
{
  // BOM: EF BB BF
  if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
    return substr($s, 3);
  }
  return $s;
}

function upsert_rate(PDO $pdo, int $operatorId, string $currency, float $rateToKzt, string $sourceUrl): void
{
  $st = $pdo->prepare("
    INSERT INTO operator_fx_rates(operator_id, currency, rate_to_kzt, source_url, captured_at)
    VALUES(?,?,?,?,NOW())
    ON DUPLICATE KEY UPDATE
      rate_to_kzt=VALUES(rate_to_kzt),
      source_url=VALUES(source_url),
      captured_at=NOW()
  ");
  $st->execute([$operatorId, $currency, $rateToKzt, $sourceUrl]);
}

function norm_float(string $s): float
{
  $s = trim($s);
  $s = str_replace(["\xC2\xA0", ' '], '', $s);
  $s = str_replace(',', '.', $s);
  return (float)$s;
}

/**
 * -------- Parsers ----------
 */

function parse_selfie_cross_rates(string $html): array
{
  if (!preg_match('~samo\.CROSS_RATES\s*=\s*(\{.*?\})\s*;~su', $html, $m)) return [];
  $data = json_decode((string)$m[1], true);
  if (!is_array($data)) return [];

  $out = [];
  foreach ($data as $row) {
    if (!is_array($row)) continue;
    $name = (string)($row['Name'] ?? '');
    if (!in_array($name, ['USD', 'EUR'], true)) continue;
    $rate = (float)($row['4'] ?? 0); // KZT
    if ($rate > 0) $out[$name] = $rate;
  }
  return $out;
}

function parse_anex_table(string $html): array
{
  // Anex: 1-я колонка EUR (€), 2-я колонка USD ($)
  if (!preg_match_all(
    '~<tr[^>]*>\s*<td[^>]*>\s*(\d{2}\.\d{2}\.\d{4})\s*</td>\s*<td[^>]*>\s*([0-9]+(?:\s*[0-9]+)?)\s*KZT\s*</td>\s*<td[^>]*>\s*([0-9]+(?:\s*[0-9]+)?)\s*KZT\s*</td>~isu',
    $html,
    $mm,
    PREG_SET_ORDER
  )) return [];

  $bestTs = 0;
  $bestEur = 0.0;
  $bestUsd = 0.0;

  foreach ($mm as $m) {
    $d = $m[1];
    $eur = (float)str_replace(' ', '', $m[2]);
    $usd = (float)str_replace(' ', '', $m[3]);
    if ($eur <= 0 || $usd <= 0) continue;

    $ts = strtotime(str_replace('.', '-', $d));
    if ($ts === false) continue;

    if ($ts >= $bestTs) {
      $bestTs = $ts;
      $bestEur = $eur;
      $bestUsd = $usd;
    }
  }

  if ($bestTs <= 0) return [];
  return ['EUR' => $bestEur, 'USD' => $bestUsd];
}

function parse_joinup_table(string $html): array
{
  // JoinUp: после даты EUR (€), затем USD ($)
  if (!preg_match_all(
    '~<tr[^>]*>\s*<td[^>]*class="date"[^>]*>\s*(\d{2}\.\d{2}\.\d{4})\s*</td>\s*<td[^>]*class="rate"[^>]*>\s*([0-9]+(?:[\.,][0-9]+)?)\s*.*?</td>\s*<td[^>]*class="rate"[^>]*>\s*([0-9]+(?:[\.,][0-9]+)?)~isu',
    $html,
    $mm,
    PREG_SET_ORDER
  )) return [];

  $bestTs = 0;
  $bestEur = 0.0;
  $bestUsd = 0.0;

  foreach ($mm as $m) {
    $d = $m[1];
    $eur = norm_float($m[2]);
    $usd = norm_float($m[3]);
    if ($eur <= 0 || $usd <= 0) continue;

    $ts = strtotime(str_replace('.', '-', $d));
    if ($ts === false) continue;

    if ($ts >= $bestTs) {
      $bestTs = $ts;
      $bestEur = $eur;
      $bestUsd = $usd;
    }
  }

  if ($bestTs <= 0) return [];
  return ['EUR' => $bestEur, 'USD' => $bestUsd];
}

function parse_kazunion_online_currency_table(string $html): array
{
  libxml_use_internal_errors(true);
  $dom = new DOMDocument();
  if (!@$dom->loadHTML($html)) return [];
  $xp = new DOMXPath($dom);

  $ths = $xp->query('//table[@id="currency"]//thead//th');
  if (!$ths || $ths->length === 0) return [];

  $colIndexByName = [];
  $i = 0;
  foreach ($ths as $th) {
    $name = strtoupper(trim(preg_replace('~\s+~u', ' ', $th->textContent ?? '')));
    if ($name !== '') $colIndexByName[$name] = $i;
    $i++;
  }

  if (!isset($colIndexByName['EUR']) || !isset($colIndexByName['USD'])) return [];
  $eurIdx = (int)$colIndexByName['EUR'];
  $usdIdx = (int)$colIndexByName['USD'];

  $trs = $xp->query('//table[@id="currency"]//tbody//tr');
  if (!$trs || $trs->length === 0) return [];

  $bestTs = 0;
  $bestEur = 0.0;
  $bestUsd = 0.0;

  foreach ($trs as $tr) {
    $tds = $xp->query('./td', $tr);
    if (!$tds || $tds->length === 0) continue;

    $dateText = trim($tds->item(0)?->textContent ?? '');
    if (!preg_match('~^\d{2}\.\d{2}\.\d{4}$~', $dateText)) continue;

    $eur = norm_float((string)($tds->item($eurIdx)?->textContent ?? ''));
    $usd = norm_float((string)($tds->item($usdIdx)?->textContent ?? ''));
    if ($eur <= 0 || $usd <= 0) continue;

    $ts = strtotime(str_replace('.', '-', $dateText));
    if ($ts === false) continue;

    if ($ts >= $bestTs) {
      $bestTs = $ts;
      $bestEur = $eur;
      $bestUsd = $usd;
    }
  }

  if ($bestTs <= 0) return [];
  return ['EUR' => $bestEur, 'USD' => $bestUsd];
}

function parse_abk(string $html): array
{
  $out = [];
  if (preg_match('~1\s*\$\s*=\s*<b>\s*([0-9]+(?:[\.,][0-9]+)?)\s*</b>\s*KZT~iu', $html, $m)) $out['USD'] = norm_float($m[1]);
  if (preg_match('~1\s*(?:€|&euro;)\s*=\s*<b>\s*([0-9]+(?:[\.,][0-9]+)?)\s*</b>\s*KZT~iu', $html, $m)) $out['EUR'] = norm_float($m[1]);
  return $out;
}

function parse_fun_sun(string $html): array
{
  $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $text = preg_replace('~\s+~u', ' ', (string)$text);

  $out = [];
  if (preg_match('~1\s*USD[^0-9]{0,40}([0-9]+(?:[\.,][0-9]+)?)\s*KZT~iu', $text, $m)) $out['USD'] = norm_float($m[1]);
  if (preg_match('~1\s*EUR[^0-9]{0,40}([0-9]+(?:[\.,][0-9]+)?)\s*KZT~iu', $text, $m)) $out['EUR'] = norm_float($m[1]);
  return $out;
}

function parse_crystalbay_usd_only(string $html): array
{
  if (!preg_match_all(
    '~<tr[^>]*>\s*<td[^>]*class="date"[^>]*>\s*(\d{2}\.\d{2}\.\d{4})\s*</td>\s*<td[^>]*class="rate"[^>]*>\s*([0-9]+(?:[\.,][0-9]+)?)~isu',
    $html,
    $mm,
    PREG_SET_ORDER
  )) return [];

  $bestTs = 0;
  $bestUsd = 0.0;

  foreach ($mm as $m) {
    $d = $m[1];
    $usd = norm_float($m[2]);
    if ($usd <= 0) continue;

    $ts = strtotime(str_replace('.', '-', $d));
    if ($ts === false) continue;

    if ($ts >= $bestTs) {
      $bestTs = $ts;
      $bestUsd = $usd;
    }
  }

  if ($bestTs <= 0 || $bestUsd <= 0) return [];
  return ['USD' => $bestUsd];
}

function parse_pegas_exchange_rates(string $html): array
{
  if (!preg_match('~data-layout-model="([^"]+)"~isu', $html, $m)) return [];

  $jsonStr = html_entity_decode((string)$m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $data = json_decode($jsonStr, true);
  if (!is_array($data)) return [];

  $info = $data['currencyRateInfo'] ?? null;
  if (!is_array($info)) return [];

  $rates = $info['rates'] ?? null;
  if (!is_array($rates)) return [];

  $out = [];
  foreach ($rates as $r) {
    if (!is_array($r)) continue;
    $code = (string)($r['sourceCode'] ?? '');
    $rate = (float)($r['rate'] ?? 0);
    if ($rate > 0 && ($code === 'USD' || $code === 'EUR')) $out[$code] = $rate;
  }
  return $out;
}

function parse_sanat_currency_json(): array
{
  $json = fetch_url('https://online.sanat.kz/TourSearchClient/currency.json');
  if ($json === '') return [];

  $json = strip_utf8_bom($json);

  $data = json_decode($json, true);
  if (!is_array($data)) return [];

  $out = [];
  foreach ($data as $row) {
    if (!is_array($row)) continue;

    $id = (int)($row['CurrencyId'] ?? 0);

    // Rate приходит числом с 6 знаками, но пусть будет универсально
    $rateRaw = $row['Rate'] ?? 0;
    $rate = is_string($rateRaw) ? norm_float($rateRaw) : (float)$rateRaw;

    if ($rate <= 0) continue;
    if ($id === 1) $out['USD'] = $rate;
    if ($id === 2) $out['EUR'] = $rate;
  }

  return $out;
}

function parse_kompas_currency_arhiv(string $html): array
{
  $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $text = preg_replace('~\s+~u', ' ', (string)$text);

  $out = [];
  if (preg_match('~USD[^0-9]{0,80}([0-9]+(?:[\.,][0-9]+)?)~iu', $text, $m)) $out['USD'] = norm_float($m[1]);
  if (preg_match('~EUR[^0-9]{0,80}([0-9]+(?:[\.,][0-9]+)?)~iu', $text, $m)) $out['EUR'] = norm_float($m[1]);
  return $out;
}

function parse_rates_by_source(string $url, string $html): array
{
  $host = strtolower((string)parse_url($url, PHP_URL_HOST));

  if (str_contains($host, 'selfietravel')) return parse_selfie_cross_rates($html);
  if (str_contains($host, 'anextour')) return parse_anex_table($html);
  if (str_contains($host, 'joinup.kz')) return parse_joinup_table($html);

  if (str_contains($host, 'online.kazunion.com')) return parse_kazunion_online_currency_table($html);

  if (str_contains($host, 'abktourism.kz')) return parse_abk($html);
  if (str_contains($host, 'fstravel.asia')) return parse_fun_sun($html);

  if (str_contains($host, 'crystalbay.com')) return parse_crystalbay_usd_only($html);
  if (str_contains($host, 'pegast.asia')) return parse_pegas_exchange_rates($html);

  if (str_contains($host, 'online.sanat.kz')) return parse_sanat_currency_json();

  if (str_contains($host, 'kompastour')) return parse_kompas_currency_arhiv($html);

  return [];
}

/**
 * -------- Main ----------
 */
$ops = $pdo->query("
  SELECT id, name, fx_source_url
  FROM tour_operators
  WHERE fx_source_url IS NOT NULL AND fx_source_url <> ''
  ORDER BY id ASC
")->fetchAll();

$ok = 0;
$bad = 0;

foreach ($ops as $op) {
  $opId = (int)$op['id'];
  $url = trim((string)$op['fx_source_url']);
  $host = strtolower((string)parse_url($url, PHP_URL_HOST));

  $isSanat = str_contains($host, 'online.sanat.kz');
  $isCrystalBay = str_contains($host, 'crystalbay.com');

  $html = '';
  if (!$isSanat) {
    $html = fetch_url($url);
    if ($html === '') {
      $pdo->prepare("UPDATE tour_operators SET fx_status='fetch_failed', fx_updated_at=NOW() WHERE id=? LIMIT 1")->execute([$opId]);
      $bad++;
      continue;
    }
  }

  $rates = parse_rates_by_source($url, $html);

  // Для CrystalBay достаточно USD, для остальных нужны USD+EUR
  $needBoth = !$isCrystalBay;
  if (
    !$rates ||
    ($needBoth && (empty($rates['USD']) || empty($rates['EUR']))) ||
    (!$needBoth && empty($rates['USD']))
  ) {
    $pdo->prepare("UPDATE tour_operators SET fx_status='parse_failed', fx_updated_at=NOW() WHERE id=? LIMIT 1")->execute([$opId]);
    $bad++;
    continue;
  }

  try {
    if (!empty($rates['USD'])) upsert_rate($pdo, $opId, 'USD', (float)$rates['USD'], $url);
    if (!empty($rates['EUR'])) upsert_rate($pdo, $opId, 'EUR', (float)$rates['EUR'], $url);

    $status = ($isCrystalBay && empty($rates['EUR'])) ? 'ok_partial' : 'ok';
    $pdo->prepare("UPDATE tour_operators SET fx_status=?, fx_updated_at=NOW() WHERE id=? LIMIT 1")->execute([$status, $opId]);

    $ok++;
  } catch (Throwable $e) {
    $pdo->prepare("UPDATE tour_operators SET fx_status='db_failed', fx_updated_at=NOW() WHERE id=? LIMIT 1")->execute([$opId]);
    $bad++;
  }
}

echo "operator fx updated: ok={$ok} bad={$bad}\n";