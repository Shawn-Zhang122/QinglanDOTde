<?php
/**
 * 青蓝中文 · 联系表单处理
 * 放在网站根目录，前端 <form action="senden.php">
 *
 * 需要服务器已配置好 mail()（多数 LAMP 环境默认可用）
 * 如果 mail() 不通，见文件末尾的 SMTP 方案
 */

declare(strict_types=1);

// ── 配置 ────────────────────────────────────
const EMPFAENGER = 'qinglankids@gmail.com';   // 收件邮箱
const ABSENDER   = 'noreply@qinglan.de';      // 发件地址（必须是本域名）
const BETREFF    = 'Anfrage über qinglan.de';

// ── 只接受 POST ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// ── 蜜罐：机器人会填这个隐藏字段 ──────────────
if (!empty($_POST['_gotcha'])) {
    http_response_code(200);   // 假装成功，不给机器人反馈
    exit(json_encode(['ok' => true]));
}

// ── 简易频率限制：同 IP 60 秒内只允许一次 ─────
session_start();
$now = time();
if (isset($_SESSION['last_submit']) && ($now - $_SESSION['last_submit']) < 60) {
    http_response_code(429);
    exit(json_encode(['ok' => false, 'error' => 'too_many_requests']));
}

// ── 取值并清洗 ──────────────────────────────
function feld(string $name, int $max = 500): string {
    $v = trim((string)($_POST[$name] ?? ''));
    $v = str_replace(["\r", "\n", "%0a", "%0d"], ' ', $v);  // 防邮件头注入
    return mb_substr($v, 0, $max);
}

$kind     = feld('Kind', 100);
$alter    = feld('Alter', 100);
$email    = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$kurs       = feld('Kurs', 100);
$wortschatz = feld('Wortschatz', 100);
$einwilligung = !empty($_POST['Einwilligung']) ? 'ja' : 'nein';
$nachricht = trim((string)($_POST['Nachricht'] ?? ''));
$nachricht = mb_substr($nachricht, 0, 3000);

// ── 必填校验 ────────────────────────────────
if ($kind === '' || $email === false || $einwilligung !== 'ja') {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'error' => 'invalid_input']));
}

// ── 组装邮件 ────────────────────────────────
$body = "Neue Anfrage über qinglan.de\n"
      . str_repeat('─', 40) . "\n\n"
      . "Kind:      {$kind}\n"
      . "Alter:     " . ($alter ?: '—') . "\n"
      . "E-Mail:    {$email}\n"
      . "Kurs:      " . ($kurs ?: '—') . "\n"
      . "Wortschatz: " . ($wortschatz ?: '—') . "\n"
      . "Einwilligung Datenschutz: {$einwilligung}\n\n"
      . "Nachricht:\n" . ($nachricht ?: '—') . "\n\n"
      . str_repeat('─', 40) . "\n"
      . 'Gesendet: ' . date('d.m.Y H:i') . "\n";

$headers = [
    'From: '         => ABSENDER,
    'Reply-To: '     => $email,
    'Content-Type: ' => 'text/plain; charset=UTF-8',
    'X-Mailer: '     => 'PHP/' . phpversion(),
];
$header_str = '';
foreach ($headers as $k => $v) { $header_str .= $k . $v . "\r\n"; }

$betreff = '=?UTF-8?B?' . base64_encode(BETREFF . ' — ' . $kind) . '?=';

// ── 发送 ────────────────────────────────────
$ok = mail(EMPFAENGER, $betreff, $body, $header_str);

if ($ok) {
    $_SESSION['last_submit'] = $now;
    header('Content-Type: application/json');
    exit(json_encode(['ok' => true]));
}

http_response_code(500);
exit(json_encode(['ok' => false, 'error' => 'send_failed']));

/* ══════════════════════════════════════════════
   如果 mail() 不可用（很多主机禁用了），改用 SMTP：

   1. 装 PHPMailer:
        composer require phpmailer/phpmailer

   2. 把上面的 mail() 那段换成：

      require 'vendor/autoload.php';
      use PHPMailer\PHPMailer\PHPMailer;

      $m = new PHPMailer(true);
      $m->isSMTP();
      $m->Host       = 'smtp.ihr-provider.de';
      $m->SMTPAuth   = true;
      $m->Username   = 'noreply@qinglan.de';
      $m->Password   = getenv('SMTP_PASS');   // 别写死在代码里
      $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
      $m->Port       = 587;
      $m->CharSet    = 'UTF-8';

      $m->setFrom(ABSENDER, 'Qinglan Chinesisch');
      $m->addAddress(EMPFAENGER);
      $m->addReplyTo($email, $kind);
      $m->Subject = BETREFF . ' — ' . $kind;
      $m->Body    = $body;
      $m->send();
   ══════════════════════════════════════════════ */
