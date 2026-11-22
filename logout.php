<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

csrf_check();
do_logout();
redirect('login.php?saiu=1');
