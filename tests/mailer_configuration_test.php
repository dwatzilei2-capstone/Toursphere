<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Configuration-only test: never connects to SMTP or sends an email.
function company_name(): string { return 'TourSphere'; }
foreach (['SMTP_HOST'=>'smtp.example.invalid','SMTP_PORT'=>'587','SMTP_USERNAME'=>'test@example.invalid','SMTP_PASSWORD'=>'test-only','SMTP_FROM_EMAIL'=>'test@example.invalid','SMTP_ENCRYPTION'=>'tls'] as $key=>$value) putenv($key.'='.$value);
set_error_handler(static function(int $severity,string $message): void { throw new RuntimeException($message); });
require dirname(__DIR__).'/includes/recovery-delivery.php';
try {
    $mail=toursphere_mailer();
    if ($mail->Timeout!==15 || $mail->getSMTPInstance()->Timelimit!==15) throw new RuntimeException('Incorrect SMTP timeout configuration.');
    if (array_key_exists('Timelimit',get_object_vars($mail))) throw new RuntimeException('Unexpected dynamic mailer property.');
    echo "PASS: mailer configuration creates no warnings or deprecated dynamic properties; no email sent.\n";
} finally { restore_error_handler(); }
