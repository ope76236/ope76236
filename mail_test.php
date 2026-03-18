<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/mailer.php';

require_role('manager');

$config = require __DIR__ . '/../app/config.php';
$from = (string)($config['mail']['from_email'] ?? '');
$name = (string)($config['mail']['from_name'] ?? 'TurDoc CRM');

$to = (string)($_GET['to'] ?? '');
if ($to === '') {
  echo "Добавьте ?to=вашemail\n";
  exit;
}

$ok = send_mail($to, 'TEST TurDoc', '<b>test</b>', $from, $name);
echo $ok ? "OK" : "FAIL";