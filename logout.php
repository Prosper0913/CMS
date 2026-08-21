<?php
// classroom/logout.php
session_start();
session_unset();
session_destroy();
header("Location: /classroomv2/login.php");
exit;