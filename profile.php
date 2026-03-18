<?php
declare(strict_types=1);

$title = 'Профиль';
require __DIR__ . '/_layout_top.php';

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/helpers.php';

$pdo = db();

$u = current_user();
$uid = (int)($u['id'] ?? 0);

$st = $pdo->prepare("
  SELECT u.email, u.name, u.phone,
         t.iin, t.last_name, t.first_name, t.middle_name,
         t.birth_date, t.passport_no, t.passport_issue_date, t.passport_expiry_date,
         t.citizenship, t.address
  FROM users u
  LEFT JOIN tourists t ON t.user_id = u.id
  WHERE u.id=? AND u.role='tourist'
  LIMIT 1
");
$st->execute([$uid]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  http_response_code(404);
  echo "Профиль не найден";
  exit;
}

function fmt_dmy(?string $ymd): string {
  $ymd = trim((string)$ymd);
  if ($ymd === '' || $ymd === '0000-00-00') return '';
  $ts = strtotime($ymd);
  if ($ts === false) return '';
  return date('d.m.Y', $ts);
}

$fio = trim(
  trim((string)($row['last_name'] ?? '')) . ' ' .
  trim((string)($row['first_name'] ?? '')) . ' ' .
  trim((string)($row['middle_name'] ?? ''))
);
$displayName = $fio !== '' ? $fio : trim((string)($row['name'] ?? 'Турист'));

/**
 * Заполняем из карточки туриста / пользователя.
 * Показываем только НЕ пустые поля.
 */
$docs = [
  'ИИН' => trim((string)($row['iin'] ?? '')),
  'Дата рождения' => fmt_dmy((string)($row['birth_date'] ?? '')),
  'Паспорт' => trim((string)($row['passport_no'] ?? '')),
  'Дата выдачи' => fmt_dmy((string)($row['passport_issue_date'] ?? '')),
  'Срок действия' => fmt_dmy((string)($row['passport_expiry_date'] ?? '')),
  'Гражданство' => trim((string)($row['citizenship'] ?? '')),
  'Адрес' => trim((string)($row['address'] ?? '')),
];

$contacts = [
  'Email (логин)' => trim((string)($row['email'] ?? '')),
  'Телефон' => trim((string)($row['phone'] ?? '')),
];

$docs = array_filter($docs, fn($v) => $v !== '');
$contacts = array_filter($contacts, fn($v) => $v !== '');

$hasAny = (bool)$docs || (bool)$contacts;
?>

<style>
  :root{
    --w-strong: 750;
    --w-normal: 600;
  }

  .muted{ color: var(--muted); font-weight: var(--w-normal); }
  .mini{ font-size:12px; }
  .nowrap{ white-space:nowrap; }

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
    width:9px;
    height:9px;
    border-radius:999px;
    background: rgba(14,165,233,.92);
    box-shadow: 0 8px 18px rgba(14,165,233,.18);
    display:inline-block;
  }

  .section{
    margin-top:10px;
    padding:14px;
    border-radius:16px;
    border:1px solid rgba(226,232,240,.90);
    background: rgba(255,255,255,.72);
    overflow:hidden;
    min-width:0;
  }

  /* key-value table */
  .kv{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
    table-layout:fixed;
  }
  .kv th, .kv td{
    padding:10px 10px;
    border-bottom:1px solid rgba(226,232,240,.75);
    text-align:left;
    font-size:13px;
    vertical-align:top;
    font-weight: var(--w-normal);
  }
  .kv tr:last-child th, .kv tr:last-child td{ border-bottom:none; }
  .kv th{
    width:240px;
    font-size:12px;
    color:var(--muted);
    font-weight: var(--w-normal);
  }
  .kv td{
    color:#0f172a;
    overflow-wrap:anywhere;
    word-break:break-word;
  }

  @media (max-width: 760px){
    .kv th{ width: 150px; }
  }

  .hint{
    margin-top:12px;
    color: var(--muted);
    font-size:12px;
    line-height:1.45;
    font-weight: var(--w-normal);
  }
</style>

<div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
  <div style="min-width:0;">
    <h1 class="h1" style="margin-bottom:6px;">
      Профиль <?= h($displayName !== '' ? $displayName : '—') ?>
    </h1>
    <div class="badge">Данные из карточки туриста</div>
  </div>
  <a class="btn" href="/tourist/">← В кабинет</a>
</div>

<?php if (!$hasAny): ?>
  <div class="badge" style="margin-top:14px;">
    Данные профиля пока не заполнены. Обратитесь к менеджеру.
  </div>
<?php else: ?>

  <?php if ($docs): ?>
    <div class="section-title">Документы</div>
    <div class="section">
      <table class="kv">
        <tbody>
          <?php foreach ($docs as $k => $v): ?>
            <tr>
              <th><?= h($k) ?></th>
              <td><?= h($v) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($contacts): ?>
    <div class="section-title">Контакты</div>
    <div class="section">
      <table class="kv">
        <tbody>
          <?php foreach ($contacts as $k => $v): ?>
            <tr>
              <th><?= h($k) ?></th>
              <td><?= h($v) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <div class="hint">
    Если нужно изменить данные — обратитесь к менеджеру (в этой версии редактирование профиля туристом отключено).
  </div>

<?php endif; ?>

<?php require __DIR__ . '/_layout_bottom.php'; ?>