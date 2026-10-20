<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Fuel submission now requires authorization and a receipt, followed by separate reviews.
require __DIR__.'/fuel_operations_http_test.php';
