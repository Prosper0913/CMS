<?php
echo "PHP error log location: " . ini_get('error_log') . "<br><br>";
 $log = ini_get('error_log');
if ($log && file_exists($log)) {
    $lines = file($log);
    // Get last 30 lines, filter for sync_to_guidance
    $recent = array_slice($lines, -100);
    $matches = array_filter($recent, fn($line) => strpos($line, 'sync_to_guidance') !== false);
    echo "<h3>Last sync_to_guidance log entries:</h3>";
    echo "<pre>" . htmlspecialchars(implode('', array_slice($matches, -10))) . "</pre>";
    echo "<h3>Last 30 lines of full log:</h3>";
    echo "<pre>" . htmlspecialchars(implode('', array_slice($lines, -30))) . "</pre>";
} else {
    echo "Log file not found at: $log<br>";
    echo "Try: C:\\xampp1\\apache\\logs\\error.log";
}