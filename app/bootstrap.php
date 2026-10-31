<?php
declare(strict_types=1);

ini_set('display_errors', '0');
session_name('SHOPLABSESSID');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

$db = database();
