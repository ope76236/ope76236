<?php
declare(strict_types=1);

/**
 * ВАЖНО:
 * Не ломаем существующий mailer.
 * Добавляем недостающие функции ТОЛЬКО если они не определены.
 */

if (!function_exists('send_mail')) {
  function send_mail(string $toEmail, string $subject, string $htmlBody, string $fromEmail, string $fromName = ''): bool
  {
    $toEmail = trim($toEmail);
    $fromEmail = trim($fromEmail);
    $fromName = trim($fromName);

    if ($toEmail === '' || $fromEmail === '') return false;

    $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers = [];
    if ($fromName !== '') {
      $fromNameEncoded = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
      $headers[] = "From: {$fromNameEncoded} <{$fromEmail}>";
    } else {
      $headers[] = "From: {$fromEmail}";
    }
    $headers[] = "Reply-To: {$fromEmail}";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: text/html; charset=utf-8";

    try {
      return (bool)@mail($toEmail, $subjectEncoded, $htmlBody, implode("\r\n", $headers));
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('send_tourist_welcome_email')) {
  /**
   * Совместимость с вашим вызовом:
   * send_tourist_welcome_email($to, $login, $pass, $baseUrl, $fromEmail)
   */
  function send_tourist_welcome_email(string $toEmail, string $loginEmail, string $passwordPlain, string $baseUrl, string $fromEmail): bool
  {
    $toEmail = trim($toEmail);
    $loginEmail = trim($loginEmail);
    $passwordPlain = (string)$passwordPlain;
    $baseUrl = rtrim(trim($baseUrl), '/');
    $fromEmail = trim($fromEmail);

    if ($toEmail === '' || $fromEmail === '' || $baseUrl === '') return false;

    $cabinetUrl = $baseUrl . '/tourist/';
    $changePassUrl = $baseUrl . '/tourist/password.php';

    $subject = "Доступ в личный кабинет туриста";

    $safeLogin = htmlspecialchars($loginEmail, ENT_QUOTES);
    $safePass = htmlspecialchars($passwordPlain, ENT_QUOTES);
    $safeCabinetUrl = htmlspecialchars($cabinetUrl, ENT_QUOTES);
    $safeChangeUrl = htmlspecialchars($changePassUrl, ENT_QUOTES);

    $html = "
    <div style='font-family:Arial,sans-serif; color:#0f172a; line-height:1.5'>
      <h2 style='margin:0 0 10px'>Доступ в личный кабинет</h2>
      <p style='margin:0 0 8px'><b>Ссылка:</b> <a href='{$safeCabinetUrl}'>{$safeCabinetUrl}</a></p>
      <p style='margin:0 0 8px'><b>Логин:</b> {$safeLogin}</p>
      <p style='margin:0 0 12px'><b>Пароль:</b> {$safePass}</p>
      <p style='margin:0 0 12px'>
        После входа рекомендуем сменить пароль:
        <a href='{$safeChangeUrl}'>{$safeChangeUrl}</a>
      </p>
      <hr style='border:none; border-top:1px solid #e2e8f0; margin:16px 0'>
      <p style='margin:0; color:#475569; font-size:12px'>
        Если вы не ожидали это письмо — просто проигнорируйте его.
      </p>
    </div>
    ";

    // Если у вас есть send_mail — используем его, иначе mail()
    if (function_exists('send_mail')) {
      return send_mail($toEmail, $subject, $html, $fromEmail, '');
    }

    $headers = "From: {$fromEmail}\r\n"
      . "MIME-Version: 1.0\r\n"
      . "Content-Type: text/html; charset=utf-8\r\n";

    $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    try {
      return (bool)@mail($toEmail, $subjectEncoded, $html, $headers);
    } catch (Throwable $e) {
      return false;
    }
  }
}