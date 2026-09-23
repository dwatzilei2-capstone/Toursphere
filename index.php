<?php
 


require_once __DIR__ . '/includes/bootstrap.php';
require_login();

redirect_to(BASE_URL . '/' . home_path());
