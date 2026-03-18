<?php
declare(strict_types=1);

/**
 * Единая точка получения "курса туроператора на сегодня".
 *
 * Правило:
 * - KZT => 1
 * - иначе берём последний курс из operator_fx_rates (по operator_id + currency)
 * - если курс не найден => fallback на applications.fx_rate_to_kzt
 *
 * Возвращает:
 * - rate: float
 * - source: kzt|operator_today|app_fallback
 */

function fx_operator_today_for_app(\PDO $pdo, array $app): array
{
  $currency = (string)($app['currency'] ?? 'KZT');
  if (!in_array($currency, ['KZT','USD','EUR'], true)) $currency = 'KZT';

  $fxApp = (float)($app['fx_rate_to_kzt'] ?? 1.0);
  if ($currency === 'KZT') $fxApp = 1.0;
  if ($fxApp <= 0) $fxApp = 1.0;

  if ($currency === 'KZT') {
    return ['rate' => 1.0, 'source' => 'kzt'];
  }

  $operatorId = (int)($app['operator_id'] ?? 0);
  if ($operatorId <= 0) {
    return ['rate' => $fxApp, 'source' => 'app_fallback'];
  }

  // 1) created_at
  try {
    $st = $pdo->prepare("
      SELECT rate_to_kzt
      FROM operator_fx_rates
      WHERE operator_id = ?
        AND currency = ?
      ORDER BY created_at DESC, id DESC
      LIMIT 1
    ");
    $st->execute([$operatorId, $currency]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    $rate = $row ? (float)($row['rate_to_kzt'] ?? 0) : 0.0;
    if ($rate > 0) return ['rate' => $rate, 'source' => 'operator_today'];
  } catch (\Throwable $e) {
    // ignore
  }

  // 2) captured_at
  try {
    $st = $pdo->prepare("
      SELECT rate_to_kzt
      FROM operator_fx_rates
      WHERE operator_id = ?
        AND currency = ?
      ORDER BY captured_at DESC, id DESC
      LIMIT 1
    ");
    $st->execute([$operatorId, $currency]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    $rate = $row ? (float)($row['rate_to_kzt'] ?? 0) : 0.0;
    if ($rate > 0) return ['rate' => $rate, 'source' => 'operator_today'];
  } catch (\Throwable $e) {
    // ignore
  }

  return ['rate' => $fxApp, 'source' => 'app_fallback'];
}

/** CUR -> KZT по заданному курсу */
function fx_cur_to_kzt(float $amountCur, string $currency, float $fxRate): float
{
  if ($currency === 'KZT') return round($amountCur, 2);
  return round($amountCur * $fxRate, 2);
}