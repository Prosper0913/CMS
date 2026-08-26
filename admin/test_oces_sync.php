<?php
require_once '../config/db.php';
require_once __DIR__ . '/../includes/sync_to_oces.php';

// Pick a student_id from your CMS that's enrolled in NSTP 1 or NSTP 2
// Change this to a real student_id in your CMS DB
 $student_id = '01';

echo "<h3>Testing OCES push for student_id = '$student_id'</h3>";

// Call the push function
 $ok = push_student_to_oces($conn, $student_id);

echo "<p>Push result: " . ($ok ? '<b style="color:green">SUCCESS</b>' : '<b style="color:red">FAILED</b>') . "</p>";
echo "<p>If FAILED, check the PHP error log for [sync_to_oces] messages.</p>";

// Check what's in OCES now
echo "<h3>OCES users with source='cms_push':</h3>";
 $conn2 = new mysqli('localhost', 'root', '', 'oces_db');
 $conn2->set_charset('utf8mb4');
 $res = $conn2->query("SELECT id, id_number, username, full_name, email, is_active, source, last_synced_at FROM users WHERE source='cms_push' ORDER BY id DESC LIMIT 10");
if ($res && $res->num_rows > 0) {
    echo "<table border='1' cellpadding='6'>";
    echo "<tr><th>id</th><th>id_number</th><th>username</th><th>full_name</th><th>email</th><th>is_active</th><th>last_synced_at</th></tr>";
    while ($r = $res->fetch_assoc()) {
        echo "<tr><td>{$r['id']}</td><td>{$r['id_number']}</td><td>{$r['username']}</td><td>{$r['full_name']}</td><td>{$r['email']}</td><td>{$r['is_active']}</td><td>{$r['last_synced_at']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red'>No cms_push users found in OCES.</p>";
}

// Check enrollments
echo "<h3>OCES student_enrollments (NSTP):</h3>";
 $res2 = $conn2->query("
    SELECT u.id_number, u.full_name, se.subject_name, se.section_name, se.is_active, se.synced_at
    FROM student_enrollments se
    JOIN users u ON u.id = se.user_id
    ORDER BY se.synced_at DESC
    LIMIT 10
");
if ($res2 && $res2->num_rows > 0) {
    echo "<table border='1' cellpadding='6'>";
    echo "<tr><th>id_number</th><th>full_name</th><th>section</th><th>subject</th><th>is_active</th><th>synced_at</th></tr>";
    while ($r = $res2->fetch_assoc()) {
        echo "<tr><td>{$r['id_number']}</td><td>{$r['full_name']}</td><td>{$r['section_name']}</td><td>{$r['subject_name']}</td><td>{$r['is_active']}</td><td>{$r['synced_at']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red'>No NSTP enrollments found in OCES.</p>";
}