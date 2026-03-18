<?php
declare(strict_types=1);

/**
 * Конфиг проекта TurDoc CRM
 * ВНИМАНИЕ: не публикуйте этот файл и не отправляйте пароли в чат.
 */

return [
  'db' => [
    'host' => 'localhost',
    'name' => 'l96746oz_turdoc',
    'user' => 'l96746oz_turdoc',
    'pass' => '47Ahuteh',
    'charset' => 'utf8mb4',
  ],
  'app' => [
    'name' => 'TurDoc CRM',
    'session_name' => 'turdoc_session',
  ],

  // URL-адреса проекта (нужно для ссылок в письмах)
  'urls' => [
    'base' => 'https://turdoc.kz',
  ],

  // Настройки отправки почты (mail() на хостинге)
  // ВАЖНО: лучше создать этот ящик в панели хостинга, чтобы письма не попадали в спам.
  'mail' => [
    'from_email' => 'no-reply@turdoc.kz',
    'from_name' => 'TurDoc CRM',
  ],
];