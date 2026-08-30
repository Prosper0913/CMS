<?php
require_once '../config/db.php';
require_once __DIR__ . '/../includes/sync_to_guidance.php';

 $student_id = '01';

echo "<h3>Testing Guidance push for student_id = '$student_id'</h3>";
echo "GUIDANCE_API_BASE: " . GUIDANCE_API_BASE . "<br>";
echo "GUIDANCE_SYNC_KEY length: " . strlen(GUIDANCE_SYNC_KEY) . "<br><br>";

// Build the payload manually so we can see what's being sent
 $payload = _build_student_payload_for_guidance($conn, $student_id);
if ($payload === null) {
    echo "<p style='color:red'>Student '$student_id' not found in CMS.</p>";
    exit;
}
echo "<h4>Payload being sent:</h4>";
echo "<pre style='background:#f4f4f4;padding:10px;border-radius:5px;max-height:300px;overflow:auto;'>" . htmlspecialchars(json_encode($payload, JSON_PRETTY_PRINT)) . "</pre>";

// Make the POST call manually so we can see the raw response
 $url = GUIDANCE_API_BASE . '/receive_student.php';
 $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

 $ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $json,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-Sync-Key: ' . GUIDANCE_SYNC_KEY,
    ],
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
]);
 $raw = curl_exec($ch);
 $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
 $err  = curl_error($ch);
curl_close($ch);

echo "<h4>Push results:</h4>";
echo "URL: " . htmlspecialchars($url) . "<br>";
echo "HTTP status: <b>$http</b><br>";
if ($err) echo "Curl error: <b style='color:red'>$err</b><br>";
echo "<br><b>Raw response from Guidance:</b>";
echo "<pre style='background:#fff3cd;padding:10px;border-radius:5px;'>" . htmlspecialchars($raw) . "</pre>";

 $ok = ($http === 200) && !empty(json_decode($raw, true)['success']);
echo "<p>Push result: " . ($ok ? '<b style="color:green">SUCCESS</b>' : '<b style="color:red">FAILED</b>') . "</p>";