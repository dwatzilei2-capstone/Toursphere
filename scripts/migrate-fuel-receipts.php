<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/bootstrap.php';
try {db()->exec(file_get_contents(ROOT_PATH.'/database/migrations/2026_10_06_fuel_receipts.sql'));echo "Fuel receipt metadata migration applied. No receipts generated.\n";}
catch(Throwable $e){if(db()->inTransaction())db()->rollBack();fwrite(STDERR,$e->getMessage());exit(1);}
