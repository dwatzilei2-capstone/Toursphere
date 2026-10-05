<?php
 
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) audit_security('LOGOUT', current_user());
session_invalidate();

redirect_to(BASE_URL . '/login.php');
