<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
session_unset();
session_destroy();

header('Location: ../login/');
exit;
