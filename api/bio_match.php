<?php
// ============================================================
// api/bio_match.php  —  SERVER-SIDE MATCHING VERSION
//
// ESP32 scans a finger → uploads the RAW IMAGE (UpImage, 4-bit
// packed grayscale, 256x288) → POSTs it here as base64.
//
// This endpoint now does ALL the work that used to happen on the
// ESP32 + AS608:
//   1. Decode the packed image, render it as a PNG (via GD)
//   2. Run mindtct on the PNG to extract minutiae -> probe.xyt
//   3. For every student enrolled in this subject with a stored
//      .xyt template, run bozorth3 (probe vs candidate) and keep
//      the highest score
//   4. If the best score clears MATCH_THRESHOLD, return the
//      student + Present/Late status (using the session's REAL
//      late_threshold — this also fixes the old hardcoded
//      08:15:00 bug).
//
// POST /classroom/api/bio_match.php
// Body:
//   device_key = DEVICE_KEY_HERE
//   image_b64  = base64 of the packed raw image from UpImage
//   date       = YYYY-MM-DD (from RTC)
//   time       = HH:MM:SS (from RTC)
//
// Response:
//   { "status":"ok", "result":"MATCH:2024-001:Ana Reyes:Present" }
//   { "status":"ok", "result":"NO_MATCH" }
//   { "status":"error", "message":"..." }
//
// IMPORTANT — dependency on bio_enroll.php:
//   fingerprint_templates.template_b64 must now hold the mindtct
//   .xyt minutiae text for each student, NOT the old AS608
//   DownChar/UpChar template. Students enrolled under the old
//   flow will not match anything until re-enrolled through a
//   rewritten bio_enroll.php (same UpImage + mindtct approach).
// ============================================================

header('Content-Type: application/json');
require_once '../config/db.php';
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// ── Config: adjust these to match your WSL setup ───────────────
define('WSL_USER',      'dances');
define('MINDTCT_BIN',   '/home/' . WSL_USER . '/nbis/mindtct/bin/mindtct');
define('BOZORTH3_BIN',  '/home/' . WSL_USER . '/nbis/bozorth3/bin/bozorth3');
define('MATCH_THRESHOLD', 40);     // bozorth3 score cutoff — tune once you have real captures
define('IMG_WIDTH',  256);
define('IMG_HEIGHT', 288);
define('TMP_DIR', __DIR__ . '/../tmp/bio');   // must exist and be writable by the web server

// ── Parse body ────────────────────────────────────────────────
$raw = file_get_contents('php://input');

// ── TEMP DIAGNOSTICS — remove once device_key parsing is confirmed fixed ──
error_log('[BIO_MATCH DIAG] CONTENT_LENGTH=' . ($_SERVER['CONTENT_LENGTH'] ?? 'unset')
    . ' CONTENT_TYPE=' . ($_SERVER['CONTENT_TYPE'] ?? 'unset')
    . ' strlen(raw)=' . strlen($raw)
    . ' count(_POST)=' . count($_POST)
    . ' POST keys=' . implode(',', array_keys($_POST))
    . ' raw first 80=' . substr($raw, 0, 80));

$data = [];
if (!empty($raw)) {
    $data = json_decode($raw, true) ?? [];
}
$data = array_merge($_POST, $data);

$device_key = trim($data['device_key'] ?? '');
$image_b64  = trim($data['image_b64'] ?? '');
$scan_date  = $data['date'] ?? date('Y-m-d');
$scan_time  = $data['time'] ?? date('H:i:s');
$ip         = $_SERVER['REMOTE_ADDR'] ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scan_date)) $scan_date = date('Y-m-d');
if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $scan_time)) $scan_time = date('H:i:s');

function fail($msg, $httpCode = 400) {
    http_response_code($httpCode);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

if ($device_key === '') {
    $diag = 'CL=' . ($_SERVER['CONTENT_LENGTH'] ?? 'unset')
          . ' CT=' . ($_SERVER['CONTENT_TYPE'] ?? 'unset')
          . ' rawLen=' . strlen($raw)
          . ' postKeys=' . implode(',', array_keys($_POST))
          . ' rawStart=' . substr($raw, 0, 60);
    fail('Missing device_key [' . $diag . ']');
}
if ($image_b64 === '')  fail('Missing image_b64');

// ── Authenticate device ───────────────────────────────────────
$dq = $conn->prepare("SELECT id, subject_id FROM bio_devices WHERE device_key = ? LIMIT 1");
$dq->bind_param('s', $device_key);
$dq->execute();
$device = $dq->get_result()->fetch_assoc();
if (!$device) fail('Unknown device. Register it in Biometric Setup.', 403);

$upd = $conn->prepare("UPDATE bio_devices SET last_seen = NOW() WHERE id = ?");
$upd->bind_param('i', $device['id']);
$upd->execute();

$conn->query("UPDATE bio_sessions SET status='ended', ended_at=NOW()
              WHERE status='active' AND auto_expire_at IS NOT NULL AND auto_expire_at < NOW()");

$sq = $conn->prepare(
    "SELECT id, subject_id, late_threshold FROM bio_sessions
     WHERE device_id=? AND status='active'
     ORDER BY started_at DESC LIMIT 1"
);
$sq->bind_param('i', $device['id']);
$sq->execute();
$session = $sq->get_result()->fetch_assoc();
if (!$session) {
    echo json_encode(['status' => 'error', 'message' => 'No active session. Ask teacher to start one.']);
    exit;
}

$subject_id  = (int)$session['subject_id'];
$session_id  = (int)$session['id'];
// Real session threshold — fixes the old hardcoded 08:15:00 bug
$late_cutoff = $session['late_threshold'] ?? '08:15:00';

// ── Load candidate .xyt templates for this subject ─────────────
$tq = $conn->prepare(
    "SELECT ft.student_id,
            ft.template_b64 AS xyt_data,
            CONCAT(s.first_name, ' ', s.last_name) AS name
     FROM fingerprint_templates ft
     JOIN students s ON s.student_id COLLATE utf8mb4_unicode_ci = ft.student_id COLLATE utf8mb4_unicode_ci
     JOIN subject_enrollments se
       ON se.student_id COLLATE utf8mb4_unicode_ci = ft.student_id COLLATE utf8mb4_unicode_ci
      AND se.subject_id = ?
     ORDER BY s.last_name ASC"
);
$tq->bind_param('i', $subject_id);
$tq->execute();
$candidates = $tq->get_result()->fetch_all(MYSQLI_ASSOC);

function logAttempt($conn, $device, $session_id, $subject_id, $scan_date, $scan_time, $ip, $status, $message, $student_id = null) {
    $ll = $conn->prepare(
        "INSERT INTO biometric_log
            (device_id, session_id, student_id, subject_id, scanned_at, ip_address, status, message)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $scanned_at = $scan_date . ' ' . $scan_time;
    $ll->bind_param('iisisss', $device['id'], $session_id, $student_id, $subject_id, $scanned_at, $ip, $status, $message);
    $ll->execute();
}

if (empty($candidates)) {
    logAttempt($conn, $device, $session_id, $subject_id, $scan_date, $scan_time, $ip, 'no_templates', 'No enrolled fingerprints for this subject');
    echo json_encode(['status' => 'error', 'message' => 'No fingerprints enrolled for this subject yet.']);
    exit;
}

// ── Prep temp workspace ─────────────────────────────────────────
if (!is_dir(TMP_DIR)) {
    if (!mkdir(TMP_DIR, 0775, true)) fail('Server misconfigured: cannot create tmp dir', 500);
}

$reqId    = uniqid('scan_', true);
$pngPath  = TMP_DIR . "/{$reqId}.png";
$orootWin = TMP_DIR . "/{$reqId}";       // mindtct appends .xyt itself
$xytPath  = TMP_DIR . "/{$reqId}.xyt";
$candXytPath = TMP_DIR . "/{$reqId}_cand.xyt";

$cleanupFiles = [];
function cleanup($files) {
    foreach ($files as $f) { if (file_exists($f)) @unlink($f); }
}

// ── Decode packed image and render as PNG via GD ────────────────
// Firmware sends URL-safe base64 (- and _ instead of + and /, no padding)
// to avoid a large %XX-escaping memory blowup on the ESP32. Convert back
// to standard base64 before decoding.
$image_b64_std = str_replace(['-', '_'], ['+', '/'], $image_b64);
$pad = strlen($image_b64_std) % 4;
if ($pad > 0) $image_b64_std .= str_repeat('=', 4 - $pad);

$imgRaw = base64_decode($image_b64_std, true);
if ($imgRaw === false) {
    logAttempt($conn, $device, $session_id, $subject_id, $scan_date, $scan_time, $ip, 'error', 'Bad base64 image');
    fail('Bad base64 image');
}

$expectedBytes = (IMG_WIDTH * IMG_HEIGHT) / 2;   // 4-bit packed = 2 px/byte
if (strlen($imgRaw) < $expectedBytes) {
    logAttempt($conn, $device, $session_id, $subject_id, $scan_date, $scan_time, $ip, 'error', 'Image too short: ' . strlen($imgRaw) . ' bytes');
    fail('Image data too short (expected ' . $expectedBytes . ' bytes, got ' . strlen($imgRaw) . ')');
}

if (!extension_loaded('gd')) fail('Server misconfigured: GD extension not available', 500);

$im = imagecreatetruecolor(IMG_WIDTH, IMG_HEIGHT);
$palette = [];
for ($v = 0; $v < 256; $v++) $palette[$v] = imagecolorallocate($im, $v, $v, $v);

$idx = 0;
for ($y = 0; $y < IMG_HEIGHT; $y++) {
    for ($x = 0; $x < IMG_WIDTH; $x += 2) {
        $byte = ord($imgRaw[$idx++]);
        // NOTE: if the resulting fingerprint image looks pixel-shuffled
        // or mirrored once you inspect a real capture, swap hi/lo below —
        // AS608 nibble order isn't confirmed against your exact unit yet.
        $hi = ($byte >> 4) & 0x0F;
        $lo = $byte & 0x0F;
        imagesetpixel($im, $x,     $y, $palette[$hi * 17]);
        imagesetpixel($im, $x + 1, $y, $palette[$lo * 17]);
    }
}
$pngOk = imagepng($im, $pngPath);
imagedestroy($im);
if (!$pngOk) {
    fail('Failed to write probe PNG', 500);
}
$cleanupFiles[] = $pngPath;

// ── Translate Windows path -> WSL path ───────────────────────────
function winToWsl($winPath) {
    $p = str_replace('\\', '/', $winPath);
    if (preg_match('#^([A-Za-z]):/(.*)$#', $p, $m)) {
        return '/mnt/' . strtolower($m[1]) . '/' . $m[2];
    }
    return $p; // already posix-style
}

$pngWsl   = winToWsl(realpath($pngPath));
$orootWsl = winToWsl($orootWin);

// ── Run mindtct on the probe image ───────────────────────────────
$cmd = 'wsl.exe ' . MINDTCT_BIN . ' ' . escapeshellarg($pngWsl) . ' ' . escapeshellarg($orootWsl) . ' 2>&1';
$mindtctOut = shell_exec($cmd);

if (!file_exists($xytPath)) {
    logAttempt($conn, $device, $session_id, $subject_id, $scan_date, $scan_time, $ip, 'error', 'mindtct failed: ' . trim((string)$mindtctOut));
    cleanup($cleanupFiles);
    fail('Minutiae extraction failed', 500);
}
$cleanupFiles[] = $xytPath;
// mindtct also produces several other .* files alongside the .xyt (e.g. .brw, .dm, .hcm, .qm, .lcm, .min, .xyt) — clean those up too
foreach (glob(TMP_DIR . "/{$reqId}.*") as $f) $cleanupFiles[] = $f;

// ── Run bozorth3 against every candidate, keep the best score ────
$bestScore   = -1;
$bestStudent = null;
$bestName    = null;

foreach ($candidates as $cand) {
    if (empty($cand['xyt_data'])) continue;

    file_put_contents($candXytPath, $cand['xyt_data']);
    $candWsl = winToWsl(realpath($candXytPath));
    $probeXytWsl = winToWsl(realpath($xytPath));

    $cmd = 'wsl.exe ' . BOZORTH3_BIN . ' ' . escapeshellarg($probeXytWsl) . ' ' . escapeshellarg($candWsl) . ' 2>&1';
    $out = trim((string)shell_exec($cmd));
    $score = is_numeric($out) ? (int)$out : -1;

    if ($score > $bestScore) {
        $bestScore   = $score;
        $bestStudent = $cand['student_id'];
        $bestName    = $cand['name'];
    }
}
$cleanupFiles[] = $candXytPath;
cleanup($cleanupFiles);

// ── Decide match vs no-match ──────────────────────────────────────
if ($bestScore < MATCH_THRESHOLD || $bestStudent === null) {
    logAttempt($conn, $device, $session_id, $subject_id, $scan_date, $scan_time, $ip, 'unknown_fp',
               "Best score {$bestScore} below threshold");
    echo json_encode(['status' => 'ok', 'result' => 'NO_MATCH']);
    exit;
}

$att_status = ($scan_time >= $late_cutoff) ? 'Late' : 'Present';

logAttempt($conn, $device, $session_id, $subject_id, $scan_date, $scan_time, $ip,
           strtolower($att_status), "Matched score={$bestScore}", $bestStudent);

echo json_encode([
    'status' => 'ok',
    'result' => "MATCH:{$bestStudent}:{$bestName}:{$att_status}",
]);