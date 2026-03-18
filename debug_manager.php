<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

try {
  // Если это менеджер — можно показать ошибки на экране (временно)
  if (function_exists('current_user')) {
    $u = current_user();
    if ($u && ($u['role'] ?? '') === 'manager') {
      ini_set('display_errors', '1');
      ini_set('display_startup_errors', '1');
      error_reporting(E_ALL);
    }
  }
} catch (Throwable $e) {
  // ничего
}

// Всегда логируем
ini_set('log_errors', '1');