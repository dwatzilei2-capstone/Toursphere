<?php
// Delivery adapters never return a code to the browser or log message contents.
function toursphere_mailer(): PHPMailer\PHPMailer\PHPMailer
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('Mail dependency missing.');
    require_once $autoload;
    foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_EMAIL'] as $key) {
        if (!getenv($key)) throw new RuntimeException('Mail configuration missing.');
    }
    $encryption = strtolower(getenv('SMTP_ENCRYPTION') ?: 'tls');
    if (!in_array($encryption, ['tls', 'ssl'], true)) throw new RuntimeException('SMTP must use TLS.');
    $brand = company_name();
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = getenv('SMTP_HOST');
    $mail->Port = (int)getenv('SMTP_PORT');
    $mail->SMTPAuth = true;
    $mail->Username = getenv('SMTP_USERNAME');
    $mail->Password = getenv('SMTP_PASSWORD');
    $mail->SMTPSecure = $encryption;
    $mail->SMTPDebug = 0;
    $mail->Timeout = 15;
    $mail->getSMTPInstance()->Timelimit = 15;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom(getenv('SMTP_FROM_EMAIL'), $brand);
    return $mail;
}

function recovery_send_email(string $email, string $name, string $otp): void
{
    $brand = company_name();
    $mail = toursphere_mailer();
    $mail->addAddress($email, $name);
    $mail->Subject = 'Password Reset Verification Code';
    $mail->isHTML(true);
    $mail->Body = '<div style="font-family:Arial,sans-serif;background:#F3F6FA;padding:28px 16px;color:#1F2937"><div style="max-width:520px;margin:auto;background:white;border:1px solid #E1E7EF;border-radius:12px;padding:32px">'
        . '<h2 style="color:#1F2937;font-size:20px;margin:0 0 22px">' . e($brand) . '</h2><p>Hello ' . e($name) . ',</p>'
        . '<p>We received a request to reset your password.</p><p>Your verification code is:</p>'
        . '<p style="font-size:30px;font-weight:bold;letter-spacing:6px;background:#EBF3FD;padding:16px;text-align:center">' . e($otp) . '</p>'
        . '<p>This code will expire in 5 minutes.</p><p>If you did not request a password reset, you may safely ignore this email.</p>'
        . '<p>Do not share this verification code with anyone.</p><p>Regards,<br>' . e($brand) . '</p></div></div>';
    $mail->AltBody = "{$brand}\n\nHello {$name},\n\nWe received a request to reset your password.\n\nYour verification code is: {$otp}\n\nThis code will expire in 5 minutes.\n\nIf you did not request a password reset, you may safely ignore this email.\nDo not share this verification code with anyone.\n\nRegards,\n{$brand}";
    $mail->send();
}

function login_2fa_send_email(string $email, string $name, string $otp): void
{
    $brand = company_name();
    $mail = toursphere_mailer();
    $mail->addAddress($email, $name);
    $mail->Subject = $brand . ' Login Verification';
    $mail->isHTML(true);
    $mail->Body = '<div style="font-family:Arial,sans-serif;background:#F3F6FA;padding:28px 16px;color:#1F2937"><div style="max-width:520px;margin:auto;background:white;border:1px solid #E1E7EF;border-radius:12px;padding:32px">'
        . '<h2 style="color:#1F2937;font-size:20px;margin:0 0 22px">' . e($brand) . ' Login Verification</h2><p>Hello ' . e($name) . ',</p>'
        . '<p>A login attempt was made for your ' . e($brand) . ' account.</p><p>Your verification code is:</p>'
        . '<p style="font-size:30px;font-weight:bold;letter-spacing:6px;background:#EBF3FD;padding:16px;text-align:center">' . e($otp) . '</p>'
        . '<p>This code expires in <strong>5 minutes</strong>.</p><p>If you did not attempt to log in, you can ignore this email.</p>'
        . '<p>Do not share this verification code with anyone.</p><p>Regards,<br>' . e($brand) . '</p></div></div>';
    $mail->AltBody = "{$brand} Login Verification\n\nHello {$name},\n\nA login attempt was made for your {$brand} account.\n\nYour verification code is: {$otp}\n\nThis code expires in 5 minutes.\n\nIf you did not attempt to log in, you can ignore this email.\nDo not share this verification code with anyone.\n\nRegards,\n{$brand}";
    $mail->send();
}

function sendSmsOtp(string $phoneNumber, string $otp): void
{
    // Future provider adapter. Never simulate delivery or enable phone verification here.
    throw new LogicException('Phone recovery is not yet configured. Please use email recovery.');
}
