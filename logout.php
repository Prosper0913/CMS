<?php
// classroom/logout.php
session_start();
session_unset();
session_destroy();
header("Location: /classroom/login.php");
exit;