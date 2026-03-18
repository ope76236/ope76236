<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function app_config(): array {
  static $cfg = null;
  if ($cfg !== null) return $cfg;
  $cfg = require __DIR__ . '/config.php';
  return $cfg;
}

function start_session(): void {
  $cfg = app_config();
  if (session_status() === PHP_SESSION_NONE) {
    session_name($cfg['app']['session_name']);
    session_start();
  }
}

function is_logged_in(): bool {
  start_session();
  return isset($_SESSION['user']);
}

function current_user(): ?array {
  start_session();
  return $_SESSION['user'] ?? null;
}

function require_login(): void {
  if (!is_logged_in()) {
    header('Location: /login.php');
    exit;
  }
}

function require_role(string $role): void {
  require_login();
  $u = current_user();
  if (!$u || ($u['role'] ?? '') !== $role) {
    http_response_code(403);
    echo "Доступ запрещён";
    exit;
  }
}

/**
 * Возвращает user (без password_hash) или null
 */
function auth_attempt(string $email, string $password): ?array {
  $email = trim(mb_strtolower($email));
  $pdo = db();

  $st = $pdo->prepare("SELECT id, role, email, password_hash, name, phone, active FROM users WHERE email = ? LIMIT 1");
  $st->execute([$email]);
  $u = $st->fetch();

  if (!$u) return null;
  if ((int)$u['active'] !== 1) return null;

  if (!password_verify($password, (string)$u['password_hash'])) {
    return null;
  }

  return [
    'id' => (int)$u['id'],
    'role' => (string)$u['role'],
    'email' => (string)$u['email'],
    'name' => (string)$u['name'],
    'phone' => (string)$u['phone'],
  ];
}

function auth_login(array $user): void {
  start_session();
  session_regenerate_id(true);
  $_SESSION['user'] = $user;
}

function auth_logout(): void {
  start_session();
  $_SESSION = [];
  session_destroy();
}