<?php
 
require_once __DIR__ . '/includes/bootstrap.php';

session_invalidate();

redirect_to(BASE_URL . '/login.php');
