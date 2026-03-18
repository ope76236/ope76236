<?php
declare(strict_types=1);

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $to): void {
  // если вывод уже начался — хотя бы попробуем через JS (fallback)
  if (headers_sent()) {
    echo "<script>location.href=" . json_encode($to) . ";</script>";
    echo "<noscript><meta http-equiv='refresh' content='0;url=" . htmlspecialchars($to, ENT_QUOTES) . "'></noscript>";
    exit;
  }

  header('Location: ' . $to);
  exit;
}

function post(string $key, string $default = ''): string {
  return trim((string)($_POST[$key] ?? $default));
}

function get(string $key, string $default = ''): string {
  return trim((string)($_GET[$key] ?? $default));
}

function is_valid_email(string $email): bool {
  return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function gen_password(int $len = 10): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
  $out = '';
  $max = strlen($alphabet) - 1;
  for ($i=0; $i<$len; $i++) {
    $out .= $alphabet[random_int(0, $max)];
  }
  return $out;
}
function translit_to_en(string $s): string
{
  $s = trim($s);
  if ($s === '') return '';

  $s = str_replace(['’', '‘', '`', '´'], "'", $s);
  $s = preg_replace('~\s+~u', ' ', $s);

  $map = [
    'А'=>'A','Ә'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Ғ'=>'G','Д'=>'D','Е'=>'E','Ё'=>'E','Ж'=>'ZH','З'=>'Z','И'=>'I','Й'=>'Y',
    'К'=>'K','Қ'=>'K','Л'=>'L','М'=>'M','Н'=>'N','Ң'=>'N','О'=>'O','Ө'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U','Ұ'=>'U','Ү'=>'U',
    'Ф'=>'F','Х'=>'KH','Һ'=>'H','Ц'=>'TS','Ч'=>'CH','Ш'=>'SH','Щ'=>'SHCH','Ъ'=>'','Ы'=>'Y','І'=>'I','Ь'=>'','Э'=>'E','Ю'=>'YU','Я'=>'YA',
    'а'=>'a','ә'=>'a','б'=>'b','в'=>'v','г'=>'g','ғ'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y',
    'к'=>'k','қ'=>'k','л'=>'l','м'=>'m','н'=>'n','ң'=>'n','о'=>'o','ө'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ұ'=>'u','ү'=>'u',
    'ф'=>'f','х'=>'kh','һ'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch','ъ'=>'','ы'=>'y','і'=>'i','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
  ];

  $out = strtr($s, $map);

  // Оставим только латиницу/пробел/дефис/апостроф
  $out = preg_replace("~[^A-Za-z\\s\\-']+~", '', $out);
  $out = preg_replace('~\s+~', ' ', $out);
  $out = trim($out);

  // верхний регистр
  $out = mb_strtoupper($out, 'UTF-8');

  return $out;
}