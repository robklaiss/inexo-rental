<?php
declare(strict_types=1);

putenv('INEXO_SKIP_DISPATCH=1');
require dirname(__DIR__) . '/index.php';

init_db();
$result = process_due_order_reminders();
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($result['failed'] > 0 ? 1 : 0);
