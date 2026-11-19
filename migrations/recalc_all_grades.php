<?php
// Recalculate EVERY student's grade in EVERY subject with the current rules
// (60% passing, Excused = present, 3 Lates = 1 Absent).
// Run once from the server after deploying:   php migrations/recalc_all_grades.php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$subjects = $conn->query("SELECT id FROM subjects")->fetch_all(MYSQLI_ASSOC);
$n = 0;
foreach ($subjects as $s) { recalcAllStudentsInSubject($conn, (int)$s['id']); $n++; }
echo "Recalculated grades for {$n} subject(s).\n";
