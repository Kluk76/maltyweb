<?php
declare(strict_types=1);

require __DIR__ . "/../app/auth.php";

auth_logout();
header("Location: /login.php", true, 302);
exit;
