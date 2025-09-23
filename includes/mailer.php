<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__.'/../vendor/phpmailer/src/Exception.php';
require_once __DIR__.'/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__.'/../vendor/phpmailer/src/SMTP.php';

function send_alert_email($subject, $html, $toList) {
  $m = new PHPMailer(true);
  try {
    $m->isSMTP();
    $m->Host       = ALERT_SMTP_HOST;
    $m->SMTPAuth   = true;
    $m->Username   = ALERT_SMTP_USER;
    $m->Password   = ALERT_SMTP_PASS;
    $m->CharSet    = 'UTF-8';

    if (ALERT_SMTP_SECURE==='ssl') {
      $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; $m->Port = 465;
    } else {
      $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $m->Port = 587;
    }

    $m->setFrom(ALERT_FROM_EMAIL, ALERT_FROM_NAME);

    foreach (array_map('trim', explode(',', $toList)) as $addr) {
      if ($addr) $m->addAddress($addr);
    }

    $m->isHTML(true);
    $m->Subject = $subject;
    $m->Body    = $html;
    $m->AltBody = strip_tags($html);

    return $m->send();
  } catch (Exception $e) {
    // En producción, mejor loguear:
    // error_log('Mailer error: '.$m->ErrorInfo);
    return false;
  }
}
