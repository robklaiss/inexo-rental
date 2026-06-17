<?php
declare(strict_types=1);

putenv('INEXO_SKIP_DISPATCH=1');
require dirname(__DIR__) . '/index.php';

$recipient = strtolower(trim((string) ($argv[1] ?? '')));
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php scripts/test_smtp.php destinatario@example.com\n");
    exit(2);
}
if (smtp_config() === null) {
    fwrite(STDERR, "El proceso CLI no puede ver el SMTP productivo. Revise el .env o entorno existente.\n");
    exit(1);
}

$error = '';
$sent = send_email(
    $recipient,
    'Prueba SMTP - Inexo Rental',
    'Prueba de configuracion SMTP generada el ' . date('c') . '.',
    'text/plain; charset=UTF-8',
    contact_recipient_email(),
    [],
    $error
);

if (!$sent) {
    fwrite(STDERR, 'No se pudo enviar: ' . ($error !== '' ? $error : 'error desconocido') . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Email de prueba enviado a {$recipient}.\n");
