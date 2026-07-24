<?php
// ============================================================
// api/bio_enroll.php  —  SERVER-SIDE MINUTIAE VERSION
//
// ESP32 captures a raw fingerprint image (UpImage) and POSTs it here,
// same as attendance scanning. This endpoint runs mindtct on the image
// to extract minutiae (.xyt) and stores THAT (not the raw image) in
// fingerprint_templates — this is what bio_match.php's bozorth3 step
// compares against later.
//
// POST /classroom/api/bio_enroll.php
// Body (x-www-form-urlencoded):
//   device_key = DEVICE_KEY_HERE
//   student_id = "2024-001"
//   image_b64  = URL-safe base64 of the packed raw image from UpImage
//
// Response:
//   { "status":"ok", "student":"Ana Reyes" }
//   { "status":"error", "message":"..." }
//
// NOTE: requires an ACTIVE session on this device (same as bio_match.php)
// so we know which subject to log this enrollment under. Start a
// biometric session for the target subject before enrolling students.
// ============================================================

header('Content-Type: application/json');
require_once '../config/db.php';
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// ── Config — keep in sync with bio_match.php ───────────────────
define('WSL_USER',    'dances');
define('MINDTCT_BIN', '/home/' . WSL_USER . '/nbis/mindtct/bin/mindtct');
define('IMG_WIDTH',   256);
define('IMG_HEIGHT',  288);
define('TMP_DIR', __DIR__ . '/../tmp/bio');

// ── Parse body ────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$data = [];
if (!empty($raw)) {
    $data = json_decode($raw, true) ?? [];
}
$data = array_merge($_POST, $data);

$device_key = trim($data['device_key'] ?? '');
$student_id = trim($data['student_id'] ?? '');
$image_b64  = trim($data['image_b64'] ?? '');
$ip         = $_SERVER['REMOTE_ADDR'] ?? '';

function fail($msg, $httpCode = 400) {
    http_response_code($httpCode);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

if ($device_key === '') fail('Missing device_key');
if ($student_id === '') fail('Missing student_id');
if ($image_b64 === '')  fail('Missing image_b64');

// ── Authenticate device ───────────────────────────────────────
$dq = $conn->prepare("SELECT id FROM bio_devices WHERE device_key=? LIMIT 1");
$dq->bind_param('s', $device_key);
$dq->execute();
$device = $dq->get_result()->fetch_assoc();
if (!$device) fail('Unknown device', 403);
$device_id = (int)$device['id'];

$upd = $conn->prepare("UPDATE bio_devices SET last_seen=NOW() WHERE id=?");
$upd->bind_param('i', $device_id);
$upd->execute();

// ── Require an active session (tells us which subject this enrollment
//    is logged under — fingerprint_templates itself is a global pool
//    matched via subject_enrollments, not scoped by this field, but we
//    still want it recorded for the biometric_log / audit trail) ─────
$conn->query("UPDATE bio_sessions SET status='ended', ended_at=NOW()
              WHERE status='active' AND auto_expire_at IS NOT NULL AND auto_expire_at < NOW()");

$sq = $conn->prepare(
    "SELECT subject_id FROM bio_sessions
     WHERE device_id=? AND status='active'
     ORDER BY started_at DESC LIMIT 1"
);
$sq->bind_param('i', $device_id);
$sq->execute();
$session = $sq->get_result()->fetch_assoc();
if (!$session) fail('No active session on this device. Start a session before enrolling.');
$subject_id = (int)$session['subject_id'];

// ── Verify student exists ─────────────────────────────────────
$stq = $conn->prepare("SELECT student_id, first_name, last_name FROM students WHERE student_id=? LIMIT 1");
$stq->bind_param('s', $student_id);
$stq->execute();
$student = $stq->get_result()->fetch_assoc();
if (!$student) fail("Student '{$student_id}' not found");
$display = $student['first_name'] . ' ' . $student['last_name'];

// ── Decode packed image (URL-safe base64 -> standard -> raw bytes) ──
$image_b64_std = str_replace(['-', '_'], ['+', '/'], $image_b64);
$pad = strlen($image_b64_std) % 4;
if ($pad > 0) $image_b64_std .= str_repeat('=', 4 - $pad);

$imgRaw = base64_decode($image_b64_std, true);
if ($imgRaw === false) fail('Bad base64 image');

$expectedBytes = (IMG_WIDTH * IMG_HEIGHT) / 2;
if (strlen($imgRaw) < $expectedBytes) {
    fail('Image data too short (expected ' . $expectedBytes . ' bytes, got ' . strlen($imgRaw) . ')');
}

if (!extension_loaded('gd')) fail('Server misconfigured: GD extension not available', 500);

// ── Render as PNG via GD (same unpacking as bio_match.php) ──────
if (!is_dir(TMP_DIR)) {
    if (!mkdir(TMP_DIR, 0775, true)) fail('Server misconfigured: cannot create tmp dir', 500);
}

$reqId   = uniqid('enroll_', true);
$pngPath = TMP_DIR . "/{$reqId}.png";
$orootWin = TMP_DIR . "/{$reqId}";
$xytPath  = TMP_DIR . "/{$reqId}.xyt";

$im = imagecreatetruecolor(IMG_WIDTH, IMG_HEIGHT);
$palette = [];
for ($v = 0; $v < 256; $v++) $palette[$v] = imagecolorallocate($im, $v, $v, $v);

$idx = 0;
for ($y = 0; $y < IMG_HEIGHT; $y++) {
    for ($x = 0; $x < IMG_WIDTH; $x += 2) {
        $byte = ord($imgRaw[$idx++]);
        $hi = ($byte >> 4) & 0x0F;
        $lo = $byte & 0x0F;
        imagesetpixel($im, $x,     $y, $palette[$hi * 17]);
        imagesetpixel($im, $x + 1, $y, $palette[$lo * 17]);
    }
}
$pngOk = imagepng($im, $pngPath);
imagedestroy($im);
if (!$pngOk) fail('Failed to write enrollment PNG', 500);

function winToWsl($winPath) {
    $p = str_replace('\\', '/', $winPath);
    if (preg_match('#^([A-Za-z]):/(.*)$#', $p, $m)) {
        return '/mnt/' . strtolower($m[1]) . '/' . $m[2];
    }
    return $p;
}

$pngWsl   = winToWsl(realpath($pngPath));
$orootWsl = winToWsl($orootWin);

$cmd = 'wsl.exe ' . MINDTCT_BIN . ' ' . escapeshellarg($pngWsl) . ' ' . escapeshellarg($orootWsl) . ' 2>&1';
$mindtctOut = shell_exec($cmd);

if (!file_exists($xytPath)) {
    @unlink($pngPath);
    fail('Minutiae extraction failed: ' . trim((string)$mindtctOut), 500);
}

$xytData = file_get_contents($xytPath);

// Clean up all mindtct output files (.xyt, .brw, .dm, .hcm, .qm, .lcm, .min, and the .png)
foreach (glob(TMP_DIR . "/{$reqId}.*") as $f) @unlink($f);

if ($xytData === false || trim($xytData) === '') {
    fail('No minutiae detected in capture — try enrolling again with a cleaner scan', 500);
}

// ── Upsert template (INSERT or UPDATE if already enrolled) ────
$ins = $conn->prepare(
    "INSERT INTO fingerprint_templates (student_id, template_b64, subject_id)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE
        template_b64 = VALUES(template_b64),
        subject_id   = VALUES(subject_id),
        updated_at   = NOW()"
);
$ins->bind_param('ssi', $student_id, $xytData, $subject_id);
if (!$ins->execute()) {
    fail('Database error: ' . $conn->error, 500);
}

// ── Log the enrollment ────────────────────────────────────────
$msg = "Fingerprint enrolled for {$display} (minutiae, " . strlen($xytData) . " bytes)";
$ll = $conn->prepare(
    "INSERT INTO biometric_log (device_id, student_id, subject_id, scanned_at, ip_address, status, message)
     VALUES (?, ?, ?, NOW(), ?, 'enrolled', ?)"
);
$ll->bind_param('iisss', $device_id, $student_id, $subject_id, $ip, $msg);
$ll->execute();

echo json_encode([
    'status'  => 'ok',
    'student' => $display,
    'message' => "Fingerprint enrolled for {$display}",
]);