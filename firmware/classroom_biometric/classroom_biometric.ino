// ============================================================
//  classroom_biometric.ino
//  ESP32 + AS608  —  Server-Side Fingerprint Attendance
//
//  Architecture: ZERO matching happens on the ESP32 or AS608.
//  The ESP32 is a thin client that only captures and uploads
//  raw images — all minutiae extraction and matching happens
//  server-side (NBIS mindtct + bozorth3):
//    1. Capture raw fingerprint image (UpImage)
//    2. POST image to server (bio_match.php)
//    3. Server extracts minutiae + matches against enrolled
//       students for the active session, returns result
//    4. On match → POST student_id to bio_record.php
//
//  Enrollment flow (UNCHANGED FOR NOW — still on-device):
//    Teacher selects a student on the LCD menu →
//    ESP32 captures 2 images → merges → UpChar 512-byte template →
//    POSTs base64 template to bio_enroll.php
//    NOTE: this still uses the old AS608-template approach and is
//    a candidate for the same UpImage-based rewrite as a follow-up.
//
//  Subject/Teacher binding:
//    Each device has a unique DEVICE_KEY flashed in.
//    On boot, calls bio_config.php → gets subject_id, subject name,
//    teacher name, late threshold. No re-flashing needed to
//    reassign a device to a different subject.
//
//  Hardware (same pins as original sketch):
//    FP sensor  RX=32, TX=33  (HardwareSerial 2, 57600 baud)
//    LCD        SDA=21, SCL=22  (I2C 0x27, 16×2)
//    RTC DS3231 SDA=21, SCL=22  (same I2C bus)
//    Green LED  GPIO 25
//    Red LED    GPIO 26
//    Buzzer     GPIO 27
//
//  Libraries (all available in Library Manager):
//    Adafruit Fingerprint Sensor Library  (Adafruit)
//    RTClib                               (Adafruit)
//    LiquidCrystal I2C                    (Frank de Brabander)
//    WiFi, HTTPClient                     (built-in ESP32 core)
//
//  NO ArduinoJson needed — JSON parsed with simple string search.
// ============================================================

#include <WiFi.h>
#include <HTTPClient.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <Adafruit_Fingerprint.h>
#include <RTClib.h>

// ============================================================
//  CONFIG — edit before flashing
// ============================================================

const char* WIFI_SSID     = "ASUS-TUF-GAMING";
const char* WIFI_PASSWORD = "passwOrd";

const char* SERVER_BASE   = "http://68.183.228.242";

// This device's unique key — must match the device_key column in bio_devices
const char* DEVICE_KEY    = "pre-defense-demo";

// ── Pins ──────────────────────────────────────────────────────
#define I2C_SDA    21
#define I2C_SCL    22
#define FP_RX      32
#define FP_TX      33
#define LED_GREEN  25
#define LED_RED    26
#define BUZZER     27   // set to -1 if no buzzer

// ── Timing ────────────────────────────────────────────────────
#define SCAN_COOLDOWN_MS    3000
#define LCD_HOLD_MS         2800
#define WIFI_RETRY_MS       30000
#define HTTP_TIMEOUT_MS     15000   // image upload (~48KB base64) + server-side minutiae match
#define ENROLL_POLL_MS      4000    // how often to check for queued enrollments
#define NUM_ENROLL_CAPTURES 4       // number of finger positions captured per enrollment
#define SESSION_POLL_MS     5000    // how often to re-check session status

//----------------------------------------------------------
#define IMG_WIDTH 256
#define IMG_HEIGHT 288
#define IMG_SIZE (IMG_WIDTH * IMG_HEIGHT / 2)  // AS608 packs 2 pixels/byte, 4-bit grayscale

// ============================================================
//  Globals
// ============================================================
RTC_DS3231              rtc;
HardwareSerial          fpSerial(2);
Adafruit_Fingerprint    finger = Adafruit_Fingerprint(&fpSerial);
LiquidCrystal_I2C       lcd(0x27, 16, 2);

bool    rtcReady        = false;
bool    configLoaded    = false;
int     deviceSubjectId = 0;
String  subjectCode     = "";
String  subjectName     = "";
String  teacherName     = "";
String  lateCutoff      = "08:15:00";

uint32_t lastScanMs     = 0;
int      lastScanResult = -1;   // student fingerprint match cooldown
uint32_t lastWifiRetry  = 0;
uint32_t lastEnrollPoll = 0;    // tracks last enrollment queue check
uint32_t lastSessionPoll = 0;   // tracks last session status re-check

// Enrollment state
bool     enrollMode      = false;
int      enrollQueueId   = 0;   // bio_enroll_queue row id, for marking done/failed
String   enrollStudentId = "";
String   enrollStudentName = "";

// ============================================================
//  Forward declarations
// ============================================================
void     connectWiFi();
bool     loadConfig();
void     checkEnrollQueue();
void     attendanceMode();
void     enrollmentMode();
bool     captureFeature(uint8_t slot);
bool     captureAndUploadTemplate();
String   uploadImageAndMatch(uint8_t* imgBuf, const String& dateStr, const String& timeStr);
bool     imageHasContent(const uint8_t* buf, int len);
bool     uploadImage(uint8_t* dst, int dstLen);
int      readDataStream(uint8_t* dst, int dstLen, uint32_t timeoutMs);
void     base64ToUrlSafeInPlace(String& s);
String   base64Encode(const uint8_t* data, int len);
String   urlencode(const String& s);
bool     recordAttendance(const String& studentId, const String& dateStr,
                          const String& timeStr, const String& status);
void     showResult(const String& l1, const String& l2, bool ok);
void     showIdle();
void     updateClock();
void     beep(int n, bool ok);
String   getRTCDate();
String   getRTCTime();
String   jsonExtract(const String& json, const String& key);
void     postBody(HTTPClient& http, const String& url, const String& body,
                  int& httpCode, String& response);
void     postBodyRaw(const String& host, uint16_t port, const String& path,
                     const String& body, int& httpCode, String& response);

// ============================================================
//  SETUP
// ============================================================
void setup() {
    Serial.begin(115200);

    if (LED_GREEN >= 0) { pinMode(LED_GREEN, OUTPUT); digitalWrite(LED_GREEN, LOW); }
    if (LED_RED   >= 0) { pinMode(LED_RED,   OUTPUT); digitalWrite(LED_RED,   LOW); }
    if (BUZZER    >= 0) { pinMode(BUZZER,    OUTPUT); digitalWrite(BUZZER,    LOW); }

    Wire.begin(I2C_SDA, I2C_SCL);

    lcd.begin();
    lcd.backlight();
    lcd.clear();
    lcd.setCursor(0,0); lcd.print("Classroom CMS");
    lcd.setCursor(0,1); lcd.print("Starting up...");
    delay(600);

    // RTC
    rtcReady = rtc.begin();
    if (!rtcReady) {
        Serial.println("[RTC] NOT found");
        lcd.setCursor(0,1); lcd.print("RTC missing!    ");
        delay(1200);
    } else {
        // Uncomment once to set time, then re-comment and reflash:
        // rtc.adjust(DateTime(F(__DATE__), F(__TIME__)));
        Serial.println("[RTC] OK");
    }

    // Fingerprint sensor
    fpSerial.begin(57600, SERIAL_8N1, FP_RX, FP_TX);
    lcd.setCursor(0,1); lcd.print("FP Sensor...    ");
    bool fpOk = false;
    for (int i = 0; i < 5 && !fpOk; i++) {
        if (finger.verifyPassword()) fpOk = true;
        else delay(300);
    }
    if (!fpOk) {
        lcd.clear();
        lcd.setCursor(0,0); lcd.print("!! SENSOR ERROR");
        lcd.setCursor(0,1); lcd.print("Check wiring");
        Serial.println("[FP] Sensor not responding — halting");
        while (true) {
            if (LED_RED >= 0) { digitalWrite(LED_RED, HIGH); delay(200); digitalWrite(LED_RED, LOW); delay(200); }
        }
    }
    Serial.println("[FP] Sensor OK");

    // NOTE: We do NOT load templates into the sensor here.
    // The sensor is used purely for image capture + feature extraction.
    // All template storage and matching is host-driven.

    // WiFi
    lcd.setCursor(0,1); lcd.print("WiFi...         ");
    connectWiFi();

    // Pull device config from server (subject binding)
    lcd.setCursor(0,1); lcd.print("Loading config..");
    if (WiFi.status() == WL_CONNECTED) {
        if (loadConfig()) {
            lcd.clear();
            lcd.setCursor(0,0);
            String code = subjectCode.length() > 8 ? subjectCode.substring(0,8) : subjectCode;
            lcd.print(code);
            lcd.setCursor(0,1); lcd.print("Config loaded   ");
            delay(1000);
        } else {
            lcd.setCursor(0,1); lcd.print("Config failed!  ");
            delay(1500);
        }
    }

    showIdle();
    Serial.println("[BOOT] Ready");
}

// ============================================================
//  LOOP
// ============================================================
void loop() {
    // Keep WiFi alive
    if (WiFi.status() != WL_CONNECTED) {
        if (millis() - lastWifiRetry > WIFI_RETRY_MS) {
            lastWifiRetry = millis();
            connectWiFi();
            if (WiFi.status() == WL_CONNECTED && !configLoaded) loadConfig();
        }
    }

    updateClock();

    // Re-check session status every SESSION_POLL_MS — this is what lets
    // the LCD pick up a newly started/stopped session live, instead of
    // only ever reading it once at boot.
    if (!enrollMode && WiFi.status() == WL_CONNECTED &&
        millis() - lastSessionPoll > SESSION_POLL_MS) {
        lastSessionPoll = millis();
        loadConfig();
    }

    // Poll server for pending enrollment every ENROLL_POLL_MS
    if (!enrollMode && WiFi.status() == WL_CONNECTED &&
        millis() - lastEnrollPoll > ENROLL_POLL_MS) {
        lastEnrollPoll = millis();
        checkEnrollQueue();
    }

    // If in enrollment mode, hand off to enrollment handler
    if (enrollMode) {
        enrollmentMode();
        return;
    }

// Normal attendance scanning — only if session is loaded
if (!configLoaded) {
    delay(50);
    return;
}
attendanceMode();
}

// ============================================================
//  ATTENDANCE MODE
//  Scans a finger, fetches templates from server,
//  runs 1:N match on-device, records attendance.
// ============================================================
// Quick contrast check on the packed 4-bit image — rejects blank/uniform
// captures that don't represent a real finger. A false FINGERPRINT_OK
// trigger (dust, static, residual moisture) typically produces a flat,
// low-contrast image with no real ridge/valley structure, whereas a real
// finger always shows meaningful nibble-value spread. Samples every 16th
// byte for speed rather than scanning all 36864 bytes.
bool imageHasContent(const uint8_t* buf, int len) {
    uint8_t minV = 15, maxV = 0;
    for (int i = 0; i < len; i += 16) {
        uint8_t b = buf[i];
        uint8_t hi = (b >> 4) & 0x0F;
        uint8_t lo = b & 0x0F;
        if (hi < minV) minV = hi;
        if (hi > maxV) maxV = hi;
        if (lo < minV) minV = lo;
        if (lo > maxV) maxV = lo;
    }
    return (maxV - minV) >= 3;
}

void attendanceMode() {
    // Hard gate: don't even touch the sensor if there's no active session.
    // subjectCode is only ever non-empty when loadConfig() last confirmed
    // an active session for this device (see the periodic re-poll in loop()).
    if (subjectCode.length() == 0) return;

    // Step 1: Capture a raw fingerprint image (no on-device feature
    // extraction needed anymore — matching happens server-side)
    uint8_t p = finger.getImage();
    if (p == FINGERPRINT_NOFINGER) return;
    if (p != FINGERPRINT_OK) {
        if (p != FINGERPRINT_IMAGEMESS)   // suppress idle noise
            Serial.printf("[FP] getImage error %d\n", p);
        return;
    }

    // Quick re-confirmation — the AS608 occasionally reports a false
    // FINGERPRINT_OK with no real finger present (dust, static, ambient
    // light). Requiring a second consecutive OK filters most of these
    // out before committing to a full scan+upload cycle.
    delay(80);
    uint8_t p2 = finger.getImage();
    if (p2 != FINGERPRINT_OK) return;

    // Cooldown guard — don't re-process the same scan immediately
    // (AS608 getImage can fire multiple times per finger press)
    if (millis() - lastScanMs < SCAN_COOLDOWN_MS) {
        // Clear the buffer to avoid stale data
        return;
    }
    lastScanMs = millis();

    // Pull the actual image off the sensor now (before touching the LCD),
    // and content-check it. A false getImage() trigger with no real finger
    // produces a flat, low-contrast capture — reject those silently so
    // "Scanning..." never shows for a phantom trigger.
    static uint8_t imgBuf[IMG_SIZE];
    if (!uploadImage(imgBuf, IMG_SIZE)) return;
    if (!imageHasContent(imgBuf, IMG_SIZE)) {
        Serial.println("[FP] Rejected blank/low-contrast capture (likely false trigger)");
        return;
    }

    lcd.clear();
    lcd.setCursor(0,0); lcd.print("Scanning...");
    lcd.setCursor(0,1); lcd.print("Please wait");

    String dateStr = getRTCDate();
    String timeStr = getRTCTime();

    if (WiFi.status() != WL_CONNECTED) {
        showResult("No WiFi!", "Try again later", false);
        beep(2, false);
        delay(LCD_HOLD_MS);
        showIdle();
        return;
    }

    // Step 2: Send the already-captured image — server extracts minutiae and matches
    String matchResult = uploadImageAndMatch(imgBuf, dateStr, timeStr);
    // matchResult is either:
    //   "MATCH:2024-001:Ana Reyes:Present"
    //   "MATCH:2024-001:Ana Reyes:Late"
    //   "NO_MATCH"
    //   "ERROR:message"

    if (matchResult.startsWith("ERROR:")) {
        String msg = matchResult.substring(6);
        if (msg.length() > 16) msg = msg.substring(0,16);
        showResult("Server Error", msg, false);
        beep(3, false);
        delay(LCD_HOLD_MS);
        showIdle();
        return;
    }

    if (matchResult == "NO_MATCH") {
        showResult("Not Recognized", "Unregistered?", false);
        beep(3, false);
        delay(LCD_HOLD_MS);
        showIdle();
        return;
    }

    // Parse "MATCH:student_id:name:status"
    // e.g.  "MATCH:2024-001:Ana Reyes:Present"
    int c1 = matchResult.indexOf(':', 6);    // after "MATCH:"
    int c2 = matchResult.indexOf(':', c1+1);
    int c3 = matchResult.indexOf(':', c2+1);

    String studentId = matchResult.substring(6,     c1);
    String name      = matchResult.substring(c1+1,  c2);
    String status    = matchResult.substring(c2+1);

    // Step 3: Record attendance on server
    bool recorded = recordAttendance(studentId, dateStr, timeStr, status);

    // Step 4: Show result
    String nameTrunc = name.length() > 16 ? name.substring(0,15) + "." : name;
    if (status == "Late") {
        showResult(nameTrunc, "LATE " + timeStr.substring(0,5), false);
        beep(2, false);
    } else {
        showResult(nameTrunc, "Present " + timeStr.substring(0,5), true);
        beep(1, true);
    }

    // "dup" comes back when already marked — update display
    if (!recorded) {
        showResult(nameTrunc, "Already marked", false);
    }

    delay(LCD_HOLD_MS);
    showIdle();
}

// ============================================================
//  UPLOAD RAW IMAGE + SERVER-SIDE MATCH
//
//  Protocol:
//    1. UpImage: pull the raw fingerprint image off the AS608
//       (IMG_SIZE bytes, 4-bit packed grayscale, 256x288)
//    2. POST it (base64) to bio_match.php
//    3. Server runs mindtct (minutiae extraction) on the image,
//       then bozorth3 against every enrolled student's stored
//       minutiae for the active session, and returns a "result"
//       field already formatted as:
//         "MATCH:2024-001:Ana Reyes:Present"
//         "MATCH:2024-001:Ana Reyes:Late"
//         "NO_MATCH"
//    4. This function just forwards that string (or an ERROR:)
//       back to attendanceMode(), which parses it exactly as before.
// ============================================================
String uploadImageAndMatch(uint8_t* imgBuf, const String& dateStr, const String& timeStr) {
    // ── POST to bio_match.php ─────────────────────────────────
    Serial.printf("[DIAG] Free heap before encode: %u bytes\n", ESP.getFreeHeap());
    String img_b64 = base64Encode(imgBuf, IMG_SIZE);
    base64ToUrlSafeInPlace(img_b64);   // avoids %XX-escaping a 49KB string later
    Serial.printf("[DIAG] img_b64 length: %u  (free heap now: %u)\n", img_b64.length(), ESP.getFreeHeap());

    // Build body via a single reserved buffer + sequential appends —
    // NOT chained '+' operators, which each create a large temporary
    // String and can silently return empty on allocation failure.
    String body;
    body.reserve(img_b64.length() + 128);
    body += "device_key=";
    body += urlencode(String(DEVICE_KEY));   // short — safe to urlencode
    body += "&image_b64=";
    body += img_b64;                          // already URL-safe, no escaping needed
    body += "&date=";
    body += dateStr;
    body += "&time=";
    body += timeStr;
    Serial.printf("[DIAG] body length: %u  (free heap now: %u)\n", body.length(), ESP.getFreeHeap());
    Serial.println("[DIAG] body first 80 chars: " + body.substring(0, 80));

    // Extract host from SERVER_BASE (e.g. "http://192.168.137.1" -> "192.168.137.1")
    String serverHost = String(SERVER_BASE);
    serverHost.replace("http://", "");
    serverHost.replace("https://", "");
    int slashPos = serverHost.indexOf('/');
    if (slashPos >= 0) serverHost = serverHost.substring(0, slashPos);

    int    httpCode = 0;
    String response = "";
    postBodyRaw(serverHost, 80, "/classroomv2/api/bio_match.php", body, httpCode, response);

    if (httpCode != 200) {
        Serial.printf("[MATCH] HTTP error %d\n", httpCode);
        return "ERROR:HTTP " + String(httpCode);
    }

    if (response.indexOf("\"status\":\"error\"") >= 0) {
        String msg = jsonExtract(response, "message");
        return "ERROR:" + msg;
    }

    String result = jsonExtract(response, "result");
    if (result == "") return "ERROR:No result in response";
    return result;
}

// NOTE: captureFeature() and uploadCharBuffer() below are no longer called
// anywhere — enrollmentMode() now uses uploadImage() + server-side minutiae
// extraction, same as attendanceMode(). Left in place in case of rollback.
// ============================================================
//  CAPTURE FEATURE from sensor into CharBuffer (slot 1 or 2)
//  Returns true if image captured and converted OK.
// ============================================================
bool captureFeature(uint8_t slot) {
    uint8_t p = finger.getImage();
    if (p == FINGERPRINT_NOFINGER) return false;
    if (p != FINGERPRINT_OK) {
        if (p != FINGERPRINT_IMAGEMESS)   // suppress idle noise
            Serial.printf("[FP] getImage error %d\n", p);
        return false;
    }
    p = finger.image2Tz(slot);
    if (p != FINGERPRINT_OK) {
        if (p != FINGERPRINT_IMAGEMESS)
            Serial.printf("[FP] image2Tz(%d) error %d\n", slot, p);
        return false;
    }
    return true;
}
// ============================================================
//  CHECK ENROLLMENT QUEUE
//  Polls bio_enroll_poll.php. If a teacher has queued an
//  enrollment for this device, sets enrollMode = true.
// ============================================================
void checkEnrollQueue() {
    String url = String(SERVER_BASE)
                 + "/classroomv2/api/bio_enroll_poll.php?key="
                 + urlencode(String(DEVICE_KEY));

    HTTPClient http;
    http.setTimeout(5000);
    http.begin(url);
    int code = http.GET();
    String resp = (code == 200) ? http.getString() : "";
    http.end();

    if (code != 200) {
        Serial.printf("[POLL] HTTP %d\n", code);
        return;
    }

    // TEMP DIAGNOSTIC — remove once enrollment queue detection is confirmed working
    Serial.println("[POLL DIAG] raw response: " + resp.substring(0, 150));

    String status = jsonExtract(resp, "status");
    if (status != "enroll") return;  // idle — nothing queued

    enrollQueueId      = jsonExtract(resp, "queue_id").toInt();
    enrollStudentId    = jsonExtract(resp, "student_id");
    enrollStudentName  = jsonExtract(resp, "name");
    enrollMode         = true;

    Serial.printf("[POLL] Enrollment queued: %s (%s) queue_id=%d\n",
                  enrollStudentName.c_str(), enrollStudentId.c_str(), enrollQueueId);
}

// ============================================================
//  REPORT ENROLLMENT RESULT BACK TO SERVER
// ============================================================
void reportEnrollDone(bool success) {
    if (enrollQueueId <= 0) return;
    String url    = String(SERVER_BASE) + "/classroomv2/api/bio_enroll_done.php";
    String result = success ? "done" : "failed";
    String body   = "key="      + urlencode(String(DEVICE_KEY))
                  + "&queue_id=" + String(enrollQueueId)
                  + "&result="   + result;
    HTTPClient http;
    http.setTimeout(5000);
    http.begin(url);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");
    http.POST(body);
    http.end();
    enrollQueueId = 0;
}

// ============================================================
//  ENROLLMENT MODE
//  Captures 2 images, creates model, uploads to server.
// ============================================================

void enrollmentMode() {
    String shortName = enrollStudentName.length() > 9
                       ? enrollStudentName.substring(0,9)
                       : enrollStudentName;

    const char* slotPrompts[NUM_ENROLL_CAPTURES] = { "Center", "Left side", "Right side", "Finger tip" };

    for (int slot = 1; slot <= NUM_ENROLL_CAPTURES; slot++) {
        lcd.clear();
        lcd.setCursor(0,0);
        lcd.print("Enroll:");
        lcd.print(shortName);
        lcd.setCursor(0,1);
        char line2[17];
        snprintf(line2, sizeof(line2), "%d/%d %s", slot, NUM_ENROLL_CAPTURES, slotPrompts[slot-1]);
        lcd.print(line2);

        Serial.printf("[ENROLL] Waiting for finger (slot %d/%d: %s)...\n",
                      slot, NUM_ENROLL_CAPTURES, slotPrompts[slot-1]);

        // Keep retrying THIS slot silently on a false trigger — never
        // advance or fail the whole enrollment just because the sensor
        // reported a phantom OK with no real finger present.
        static uint8_t imgBuf[IMG_SIZE];
        bool gotGoodCapture = false;
        while (!gotGoodCapture) {
            uint8_t p = FINGERPRINT_NOFINGER;
            while (p != FINGERPRINT_OK) {
                p = finger.getImage();
                delay(50);
            }

            // Double-confirmation, same as attendance
            delay(80);
            uint8_t p2 = finger.getImage();
            if (p2 != FINGERPRINT_OK) continue;   // false trigger — keep waiting

            if (!uploadImage(imgBuf, IMG_SIZE)) {
                // Genuine sensor communication failure — this one IS fatal
                showResult("Upload failed", "Sensor error", false);
                beep(3, false);
                reportEnrollDone(false);
                delay(LCD_HOLD_MS);
                enrollMode = false;
                showIdle();
                return;
            }

            if (!imageHasContent(imgBuf, IMG_SIZE)) {
                Serial.println("[ENROLL] Rejected blank/low-contrast capture, retrying same slot");
                continue;   // false trigger — keep waiting on this same slot
            }

            gotGoodCapture = true;
        }
        beep(1, true);

        lcd.setCursor(0,1); lcd.print("Uploading...    ");

        String img_b64 = base64Encode(imgBuf, IMG_SIZE);
        base64ToUrlSafeInPlace(img_b64);   // same memory-safe encoding as attendance

        String body;
        body.reserve(img_b64.length() + 128);
        body += "device_key=";
        body += urlencode(String(DEVICE_KEY));
        body += "&student_id=";
        body += urlencode(enrollStudentId);
        body += "&slot=";
        body += String(slot);
        body += "&image_b64=";
        body += img_b64;

        String serverHost = String(SERVER_BASE);
        serverHost.replace("http://", "");
        serverHost.replace("https://", "");
        int slashPos = serverHost.indexOf('/');
        if (slashPos >= 0) serverHost = serverHost.substring(0, slashPos);

        int    httpCode = 0;
        String response = "";
        postBodyRaw(serverHost, 80, "/classroomv2/api/bio_enroll.php", body, httpCode, response);

        if (!(httpCode == 200 && response.indexOf("\"status\":\"ok\"") >= 0)) {
            String msg = jsonExtract(response, "message");
            if (msg.length() > 16) msg = msg.substring(0,16);
            showResult("Enroll Failed", msg, false);
            beep(3, false);
            reportEnrollDone(false);
            Serial.printf("[ENROLL] Failed on slot %d: %d %s\n", slot, httpCode, response.c_str());
            delay(LCD_HOLD_MS);
            enrollMode = false;
            showIdle();
            return;
        }

        Serial.printf("[ENROLL] Slot %d/%d OK for %s\n", slot, NUM_ENROLL_CAPTURES, enrollStudentId.c_str());

        if (slot < NUM_ENROLL_CAPTURES) {
            lcd.setCursor(0,1); lcd.print("Remove finger   ");
            delay(800);
            while (finger.getImage() != FINGERPRINT_NOFINGER) delay(50);
        }
    }

    showResult("Enrolled!", shortName, true);
    beep(2, true);
    reportEnrollDone(true);
    Serial.printf("[ENROLL] All %d captures OK for %s\n", NUM_ENROLL_CAPTURES, enrollStudentId.c_str());

    delay(LCD_HOLD_MS);
    enrollMode = false;
    showIdle();
}

// ============================================================
//  RECORD ATTENDANCE  (bio_record.php)
//  Returns false if the server says already marked (dup).
// ============================================================
bool recordAttendance(const String& studentId, const String& dateStr,
                      const String& timeStr,   const String& status) {
    String url  = String(SERVER_BASE) + "/classroomv2/api/bio_record.php";
    String body = "device_key=" + urlencode(String(DEVICE_KEY))
                + "&student_id=" + urlencode(studentId)
                + "&date="       + dateStr
                + "&time="       + timeStr
                + "&status="     + status;

    HTTPClient http;
    http.setTimeout(HTTP_TIMEOUT_MS);
    int    httpCode = 0;
    String response = "";
    postBody(http, url, body, httpCode, response);

    if (httpCode != 200) {
        Serial.printf("[RECORD] HTTP error %d\n", httpCode);
        return false;
    }

    String st = jsonExtract(response, "status");
    return (st == "present" || st == "late");
}

// ============================================================
//  LOAD DEVICE CONFIG  (bio_config.php)
//  Returns true if device is registered (ok OR idle).
//  "idle" means registered but no active session — that's fine.
// ============================================================
bool loadConfig() {
    String url = String(SERVER_BASE)
                 + "/classroomv2/api/bio_config.php?key="
                 + urlencode(String(DEVICE_KEY));

    HTTPClient http;
    http.setTimeout(8000);
    http.begin(url);
    int code = http.GET();
    String resp = (code == 200) ? http.getString() : "";
    http.end();

    Serial.printf("[CONFIG] HTTP %d\n", code);
    Serial.println("[CONFIG] Response: " + resp.substring(0, 120));

    if (code <= 0) {
        Serial.printf("[CONFIG] Connection failed: %d — check SERVER_BASE IP\n", code);
        lcd.clear();
        lcd.setCursor(0,0); lcd.print("Server unreachab");
        lcd.setCursor(0,1); lcd.print("Check IP in code");
        delay(3000);
        return false;
    }

    if (code == 404) {
        lcd.clear();
        lcd.setCursor(0,0); lcd.print("Device Unknown!");
        lcd.setCursor(0,1); lcd.print("Register in web");
        delay(3000);
        return false;
    }

    if (code != 200) {
        Serial.printf("[CONFIG] HTTP error %d\n", code);
        lcd.clear();
        lcd.setCursor(0,0); lcd.print("Config error");
        lcd.setCursor(0,1); lcd.print("HTTP " + String(code));
        delay(3000);
        return false;
    }

    String status = jsonExtract(resp, "status");

    // "idle" = device is registered, no active session yet — still OK
    if (status == "idle") {
        bool wasActive = (subjectCode.length() > 0);
        configLoaded = true;
        subjectCode  = "";
        subjectName  = jsonExtract(resp, "message");
        Serial.println("[CONFIG] Device idle — no active session");
        if (wasActive) showIdle();   // session just ended — refresh LCD live
        return true;
    }

    if (status == "ok") {
        String newSubjectCode = jsonExtract(resp, "subject_code");
        bool changed = (newSubjectCode != subjectCode);

        deviceSubjectId = jsonExtract(resp, "subject_id").toInt();
        subjectCode     = newSubjectCode;
        subjectName     = jsonExtract(resp, "subject_name");
        teacherName     = jsonExtract(resp, "teacher");
        lateCutoff      = jsonExtract(resp, "late_after_minutes");
        if (lateCutoff == "") lateCutoff = "15";
        configLoaded = true;
        Serial.printf("[CONFIG] Subject: %s  Teacher: %s  Late after: %s min\n",
                      subjectCode.c_str(), teacherName.c_str(), lateCutoff.c_str());
        if (changed) showIdle();   // new session just started — refresh LCD live
        return true;
    }

    // Genuine error (unknown device, server error, etc.)
    String msg = jsonExtract(resp, "message");
    Serial.println("[CONFIG] Error: " + msg);
    lcd.clear();
    lcd.setCursor(0,0); lcd.print("Config failed:");
    lcd.setCursor(0,1);
    String m = msg.length() > 16 ? msg.substring(0,16) : msg;
    lcd.print(m);
    delay(3000);
    return false;
}

// ============================================================
//  AS608 RAW PACKET HELPERS
//  The Adafruit library doesn't expose UpChar / DownChar / Match
//  so we send the packets directly over fpSerial.
//
//  Packet format:
//    Header:  0xEF01
//    Address: 0xFFFFFFFF
//    PID:     0x01 (command) / 0x02 (data) / 0x08 (end-of-data)
//    Length:  2 bytes (payload + checksum bytes)
//    Payload
//    Checksum: sum of PID+Length+Payload bytes (2 bytes)
// ============================================================

// Send a raw command packet and read ACK
bool sendCmd(uint8_t* payload, int payLen, uint8_t* ackBuf, int ackLen) {
    // Build packet
    uint8_t pkt[32];
    int idx = 0;
    pkt[idx++] = 0xEF; pkt[idx++] = 0x01;
    pkt[idx++] = 0xFF; pkt[idx++] = 0xFF; pkt[idx++] = 0xFF; pkt[idx++] = 0xFF;
    pkt[idx++] = 0x01;  // command PID
    uint16_t len = payLen + 2;
    pkt[idx++] = len >> 8; pkt[idx++] = len & 0xFF;
    uint16_t cs = 0x01 + (len >> 8) + (len & 0xFF);
    for (int i = 0; i < payLen; i++) { pkt[idx++] = payload[i]; cs += payload[i]; }
    pkt[idx++] = cs >> 8; pkt[idx++] = cs & 0xFF;

    // Flush input
    while (fpSerial.available()) fpSerial.read();

    fpSerial.write(pkt, idx);
    fpSerial.flush();

    // Read ACK (header 9 bytes minimum)
    uint32_t t = millis();
    int rIdx = 0;
    while (rIdx < ackLen && millis() - t < 3000) {
        if (fpSerial.available()) ackBuf[rIdx++] = fpSerial.read();
    }
    return (rIdx >= ackLen);
}

// Shared packet-reading loop used by both UpChar and UpImage.
// Reads data packets (PID=0x02) until an end-of-data packet (PID=0x08),
// writing payload bytes into dst. Each packet:
//   0xEF 0x01  (header)
//   0xFF 0xFF 0xFF 0xFF  (address)
//   PID  (0x02=data, 0x08=end-of-data)
//   LEN_HIGH LEN_LOW  (payload + 2 checksum bytes)
//   [payload bytes]
//   CS_HIGH CS_LOW
int readDataStream(uint8_t* dst, int dstLen, uint32_t timeoutMs) {
    int received = 0;
    uint32_t timeout = millis();

    while (millis() - timeout < timeoutMs) {
        // Wait for 0xEF 0x01 sync
        uint8_t b;
        bool synced = false;
        while (millis() - timeout < timeoutMs) {
            if (fpSerial.available()) {
                b = fpSerial.read();
                if (b == 0xEF) {
                    // wait for 0x01
                    uint32_t t2 = millis();
                    while (!fpSerial.available() && millis()-t2 < 200);
                    if (fpSerial.available() && fpSerial.read() == 0x01) {
                        synced = true;
                        break;
                    }
                }
            }
        }
        if (!synced) break;

        // Read 4-byte address (skip)
        for (int i = 0; i < 4; i++) {
            uint32_t t2 = millis();
            while (!fpSerial.available() && millis()-t2 < 500);
            if (fpSerial.available()) fpSerial.read();
        }

        // Read PID
        uint32_t t2 = millis();
        while (!fpSerial.available() && millis()-t2 < 500);
        uint8_t pid = fpSerial.available() ? fpSerial.read() : 0xFF;

        // Read 2-byte length
        uint8_t lh, ll;
        t2 = millis();
        while (!fpSerial.available() && millis()-t2 < 500);
        lh = fpSerial.available() ? fpSerial.read() : 0;
        t2 = millis();
        while (!fpSerial.available() && millis()-t2 < 500);
        ll = fpSerial.available() ? fpSerial.read() : 0;

        uint16_t pktLen = ((uint16_t)lh << 8) | ll;
        int dataLen = (int)pktLen - 2;  // exclude 2-byte checksum
        if (dataLen <= 0 || dataLen > 256) {
            Serial.printf("[STREAM] Unexpected dataLen=%d pid=0x%02X\n", dataLen, pid);
            break;
        }

        // Read payload bytes into dst
        for (int i = 0; i < dataLen; i++) {
            t2 = millis();
            while (!fpSerial.available() && millis()-t2 < 500);
            uint8_t databyte = fpSerial.available() ? fpSerial.read() : 0;
            if (received < dstLen) dst[received++] = databyte;
        }

        // Read and discard 2-byte checksum
        for (int i = 0; i < 2; i++) {
            t2 = millis();
            while (!fpSerial.available() && millis()-t2 < 300);
            if (fpSerial.available()) fpSerial.read();
        }

        Serial.printf("[STREAM] pid=0x%02X dataLen=%d received=%d\n", pid, dataLen, received);

        if (pid == 0x08) break;  // end-of-data packet
    }

    return received;
}

// UpChar: sensor → ESP32  (upload CharBuffer bufId into dst, dstLen bytes)
// Still used by enrollmentMode() for now.
bool uploadCharBuffer(uint8_t bufId, uint8_t* dst, int dstLen) {
    // Send UpChar command (0x08) with buffer ID
    uint8_t cmd[] = { 0x08, bufId };
    uint8_t ack[12];
    if (!sendCmd(cmd, 2, ack, 12)) {
        Serial.println("[UPCHAR] sendCmd timeout");
        return false;
    }
    if (ack[9] != 0x00) {
        Serial.printf("[UPCHAR] Sensor error code: 0x%02X\n", ack[9]);
        return false;
    }

    int received = readDataStream(dst, dstLen, 6000);
    Serial.printf("[UPCHAR] Total received: %d bytes\n", received);
    return (received >= 256);
}

// UpImage: sensor → ESP32  (upload the raw captured fingerprint image)
// Command 0x0A — same packet protocol as UpChar, but no buffer-ID byte
// and a much larger payload (IMG_SIZE bytes vs 512 for a template).
bool uploadImage(uint8_t* dst, int dstLen) {
    uint8_t cmd[] = { 0x0A };
    uint8_t ack[12];
    if (!sendCmd(cmd, 1, ack, 12)) {
        Serial.println("[UPIMAGE] sendCmd timeout");
        return false;
    }
    if (ack[9] != 0x00) {
        Serial.printf("[UPIMAGE] Sensor error code: 0x%02X\n", ack[9]);
        return false;
    }

    // Images are much bigger than templates, so allow more time
    int received = readDataStream(dst, dstLen, 15000);
    Serial.printf("[UPIMAGE] Total received: %d / %d bytes\n", received, dstLen);
    // Allow a little slack — the final packet may be padded
    return (received >= dstLen - 1024);
}

// NOTE: downloadCharBuffer() and matchBuffers() below are no longer called
// from attendanceMode() — matching now happens server-side on the raw
// image. Left in place (unused for now) in case enrollmentMode() gets the
// same UpImage-based rewrite later, or in case of rollback.

// DownChar: ESP32 → sensor  (download src into CharBuffer bufId)
// Sends 512 bytes in 4 data packets + 1 end packet.
bool downloadCharBuffer(uint8_t bufId, uint8_t* src, int srcLen) {
    // Command: DownChar (0x09) bufId
    uint8_t cmd[] = { 0x09, bufId };
    uint8_t ack[12];
    if (!sendCmd(cmd, 2, ack, 12)) return false;
    if (ack[9] != 0x00) return false;

    // Send 4 data packets of 128 bytes each
    int offset = 0;
    int total  = (srcLen == 512) ? 512 : 256;
    int chunkSize = 128;
    int chunks = total / chunkSize;

    for (int c = 0; c < chunks; c++) {
        bool isLast = (c == chunks - 1);
        uint8_t pid  = isLast ? 0x08 : 0x02;
        uint16_t len = chunkSize + 2;
        uint8_t pkt[140];
        int idx = 0;

        pkt[idx++] = 0xEF; pkt[idx++] = 0x01;
        pkt[idx++] = 0xFF; pkt[idx++] = 0xFF;
        pkt[idx++] = 0xFF; pkt[idx++] = 0xFF;
        pkt[idx++] = pid;
        pkt[idx++] = len >> 8; pkt[idx++] = len & 0xFF;

        uint16_t cs = pid + (len >> 8) + (len & 0xFF);
        for (int i = 0; i < chunkSize; i++) {
            uint8_t b = (offset + i < srcLen) ? src[offset + i] : 0;
            pkt[idx++] = b;
            cs += b;
        }
        pkt[idx++] = cs >> 8; pkt[idx++] = cs & 0xFF;

        fpSerial.write(pkt, idx);
        fpSerial.flush();
        offset += chunkSize;
        delay(10);   // give sensor time to process each packet
    }

    return true;
}

// Match: compare CharBuffer1 vs CharBuffer2 on-sensor (1:1)
// Returns true if match, sets score.
bool matchBuffers(uint16_t& score) {
    uint8_t cmd[] = { 0x03 };
    uint8_t ack[14];
    if (!sendCmd(cmd, 1, ack, 14)) return false;
    // ack[9] = confirmation code: 0x00 = match, 0x08 = no match
    if (ack[9] != 0x00) return false;
    score = ((uint16_t)ack[10] << 8) | ack[11];
    return (score > 0);
}

// ============================================================
//  BASE64 encode/decode  (no library needed)
// ============================================================
static const char b64chars[] =
    "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

String base64Encode(const uint8_t* data, int len) {
    String out = "";
    out.reserve(((len + 2) / 3) * 4 + 1);   // pre-allocate exact size — avoids repeated reallocation
    for (int i = 0; i < len; i += 3) {
        uint8_t b0 = data[i];
        uint8_t b1 = (i+1 < len) ? data[i+1] : 0;
        uint8_t b2 = (i+2 < len) ? data[i+2] : 0;
        out += b64chars[b0 >> 2];
        out += b64chars[((b0 & 3) << 4) | (b1 >> 4)];
        out += (i+1 < len) ? b64chars[((b1 & 0xF) << 2) | (b2 >> 6)] : '=';
        out += (i+2 < len) ? b64chars[b2 & 0x3F]                      : '=';
    }
    return out;
}

// Converts a standard base64 String to URL-safe base64 IN PLACE — no new
// allocation, so it's safe to use on very large strings (like our 49KB
// image encoding) without risking the fragmentation-driven failure we saw:
// '+' -> '-', '/' -> '_', and trailing '=' padding is trimmed off (PHP's
// base64_decode() tolerates missing padding, so we just re-pad server-side
// if needed).
void base64ToUrlSafeInPlace(String& s) {
    int len = s.length();
    int end = len;
    for (int i = 0; i < len; i++) {
        char c = s[i];
        if (c == '+') s.setCharAt(i, '-');
        else if (c == '/') s.setCharAt(i, '_');
        else if (c == '=' && end == len) end = i;   // mark first '=' padding position
    }
    if (end < len) s.remove(end);   // trim padding chars off the end
}

int base64Decode(const String& in, uint8_t* out, int maxLen) {
    auto b64val = [](char c) -> int {
        if (c >= 'A' && c <= 'Z') return c - 'A';
        if (c >= 'a' && c <= 'z') return c - 'a' + 26;
        if (c >= '0' && c <= '9') return c - '0' + 52;
        if (c == '+') return 62;
        if (c == '/') return 63;
        return -1;
    };
    int len = in.length();
    int outIdx = 0;
    for (int i = 0; i < len - 3 && outIdx < maxLen; i += 4) {
        int v0 = b64val(in[i]);
        int v1 = b64val(in[i+1]);
        int v2 = b64val(in[i+2]);
        int v3 = b64val(in[i+3]);
        if (v0 < 0 || v1 < 0) break;
        out[outIdx++] = (v0 << 2) | (v1 >> 4);
        if (v2 >= 0 && outIdx < maxLen) out[outIdx++] = ((v1 & 0xF) << 4) | (v2 >> 2);
        if (v3 >= 0 && outIdx < maxLen) out[outIdx++] = ((v2 & 3) << 6) | v3;
    }
    return outIdx;
}

// ============================================================
//  URL ENCODE  (spaces and special chars in POST body)
// ============================================================
String urlencode(const String& s) {
    String out = "";
    for (int i = 0; i < (int)s.length(); i++) {
        char c = s[i];
        if ((c >= 'A' && c <= 'Z') || (c >= 'a' && c <= 'z') ||
            (c >= '0' && c <= '9') || c == '-' || c == '_' || c == '.' || c == '~') {
            out += c;
        } else {
            char hex[4];
            snprintf(hex, sizeof(hex), "%%%02X", (uint8_t)c);
            out += hex;
        }
    }
    return out;
}

// ============================================================
//  MINIMAL JSON VALUE EXTRACTOR
//  Extracts the string or number value for a given key.
//  Works for flat JSON objects only (no nested key collision).
//  e.g. jsonExtract("{\"status\":\"ok\",\"subject_id\":5}", "subject_id") → "5"
// ============================================================
String jsonExtract(const String& json, const String& key) {
    String needle = "\"" + key + "\":";
    int start = json.indexOf(needle);
    if (start < 0) return "";
    start += needle.length();
    while (start < (int)json.length() && json[start] == ' ') start++;
    if (start >= (int)json.length()) return "";

    if (json[start] == '"') {
        // String value
        start++;
        int end = json.indexOf('"', start);
        if (end < 0) return "";
        return json.substring(start, end);
    } else {
        // Numeric / boolean value
        int end = start;
        while (end < (int)json.length() &&
               json[end] != ',' && json[end] != '}' && json[end] != ']') end++;
        return json.substring(start, end);
    }
}

// ============================================================
//  HTTP POST HELPER
// ============================================================
void postBody(HTTPClient& http, const String& url, const String& body,
              int& httpCode, String& response) {
    http.begin(url);
    http.addHeader("Content-Type", "application/x-www-form-urlencoded");
    httpCode = http.POST(body);
    response = (httpCode > 0) ? http.getString() : "";
    http.end();
    Serial.printf("[HTTP] POST %s → %d\n", url.c_str(), httpCode);
    if (response.length() > 200)
        Serial.println("[HTTP] Response (first 200): " + response.substring(0,200));
    else
        Serial.println("[HTTP] Response: " + response);
}

// Raw-socket POST — used for the large fingerprint-image upload only.
// HTTPClient's automatic Content-Length handling was found unreliable
// for this ~49KB body on this ESP32 core (Content-Length arrived
// "unset" server-side, so PHP read a zero-length body). This builds
// the HTTP request manually so we control every header explicitly.
void postBodyRaw(const String& host, uint16_t port, const String& path,
                  const String& body, int& httpCode, String& response) {
    WiFiClient client;
    httpCode = -1;
    response = "";

    if (!client.connect(host.c_str(), port)) {
        Serial.println("[HTTPRAW] connect() failed");
        return;
    }

    client.print("POST " + path + " HTTP/1.1\r\n");
    client.print("Host: " + host + "\r\n");
    client.print("Content-Type: application/x-www-form-urlencoded\r\n");
    client.print("Content-Length: " + String(body.length()) + "\r\n");
    client.print("Connection: close\r\n");
    client.print("\r\n");
    client.print(body);

    uint32_t start = millis();
    while (client.connected() && !client.available() && millis() - start < 20000) {
        delay(10);
    }
    if (!client.available()) {
        Serial.println("[HTTPRAW] No response before timeout");
        client.stop();
        return;
    }

    // Parse status line, e.g. "HTTP/1.1 200 OK"
    String statusLine = client.readStringUntil('\n');
    int sp1 = statusLine.indexOf(' ');
    int sp2 = (sp1 >= 0) ? statusLine.indexOf(' ', sp1 + 1) : -1;
    if (sp1 > 0 && sp2 > sp1) {
        httpCode = statusLine.substring(sp1 + 1, sp2).toInt();
    }

    // Skip response headers
    while (client.connected() || client.available()) {
        String line = client.readStringUntil('\n');
        if (line.length() <= 1) break;   // blank line ("\r") = end of headers
    }

    // Read response body
    uint32_t bodyStart = millis();
    while ((client.connected() || client.available()) && millis() - bodyStart < 5000) {
        while (client.available()) {
            response += (char)client.read();
        }
    }

    client.stop();
    Serial.printf("[HTTPRAW] POST %s%s → %d\n", host.c_str(), path.c_str(), httpCode);
    if (response.length() > 200)
        Serial.println("[HTTPRAW] Response (first 200): " + response.substring(0,200));
    else
        Serial.println("[HTTPRAW] Response: " + response);
}

// ============================================================
//  RTC / TIME
// ============================================================
String getRTCDate() {
    if (rtcReady) {
        DateTime now = rtc.now();
        char buf[11];
        snprintf(buf, sizeof(buf), "%04d-%02d-%02d",
                 now.year(), now.month(), now.day());
        return String(buf);
    }
    return "2026-01-01";
}

String getRTCTime() {
    if (rtcReady) {
        DateTime now = rtc.now();
        char buf[9];
        snprintf(buf, sizeof(buf), "%02d:%02d:%02d",
                 now.hour(), now.minute(), now.second());
        return String(buf);
    }
    uint32_t s = millis() / 1000;
    char buf[9];
    snprintf(buf, sizeof(buf), "%02lu:%02lu:%02lu",
             (s/3600)%24, (s/60)%60, s%60);
    return String(buf);
}

// ============================================================
//  LCD / LEDS / BUZZER
// ============================================================
void showResult(const String& l1, const String& l2, bool ok) {
    lcd.clear();
    lcd.setCursor(0,0); lcd.print(l1.substring(0,16));
    lcd.setCursor(0,1); lcd.print(l2.substring(0,16));
    if (LED_GREEN >= 0) digitalWrite(LED_GREEN, ok  ? HIGH : LOW);
    if (LED_RED   >= 0) digitalWrite(LED_RED,   !ok ? HIGH : LOW);
}

void showIdle() {
    if (LED_GREEN >= 0) digitalWrite(LED_GREEN, LOW);
    if (LED_RED   >= 0) digitalWrite(LED_RED,   LOW);
    lcd.clear();
    if (subjectCode.length() > 0) {
        String code = subjectCode.length() > 10 ? subjectCode.substring(0,10) : subjectCode;
        lcd.setCursor(0,0); lcd.print(code);
        lcd.setCursor(0,1); lcd.print("Place finger... ");
    } else {
        lcd.setCursor(0,0); lcd.print("Classroom CMS   ");
        lcd.setCursor(0,1); lcd.print("Waiting session ");
    }
}

void updateClock() {
    static uint32_t last = 0;
    if (millis() - last < 1000) return;
    last = millis();
    String t = getRTCTime();
    String d = getRTCDate();
    char buf[17];
    snprintf(buf, sizeof(buf), "%s  %s/%s",
             t.c_str(),
             d.substring(5,7).c_str(),
             d.substring(8,10).c_str());
    lcd.setCursor(0,0);
    lcd.print(buf);
}

void beep(int n, bool ok) {
    if (BUZZER < 0) return;
    int ms = ok ? 80 : 150;
    for (int i = 0; i < n; i++) {
        digitalWrite(BUZZER, HIGH); delay(ms);
        digitalWrite(BUZZER, LOW);
        if (i < n-1) delay(80);
    }
}

// ============================================================
//  WiFi
// ============================================================
void connectWiFi() {
    if (WiFi.status() == WL_CONNECTED) return;
    WiFi.mode(WIFI_STA);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
    uint32_t start = millis();
    while (WiFi.status() != WL_CONNECTED && millis() - start < 12000) delay(250);
    if (WiFi.status() == WL_CONNECTED) {
        String espIP     = WiFi.localIP().toString();
        String gatewayIP = WiFi.gatewayIP().toString();
        String subnetIP  = WiFi.subnetMask().toString();
        Serial.println("================================");
        Serial.println("[WiFi] CONNECTED");
        Serial.println("[WiFi] ESP32 IP  : " + espIP);
        Serial.println("[WiFi] Gateway   : " + gatewayIP);
        Serial.println("[WiFi] Subnet    : " + subnetIP);
        Serial.println("[WiFi] Server URL: " + String(SERVER_BASE));
        Serial.println("================================");
        lcd.clear();
        lcd.setCursor(0,0); lcd.print("WiFi OK:");
        lcd.setCursor(0,1); lcd.print(espIP);
        delay(2000);
    } else {
        Serial.println("[WiFi] FAILED to connect to: " + String(WIFI_SSID));
        lcd.clear();
        lcd.setCursor(0,0); lcd.print("WiFi FAILED");
        lcd.setCursor(0,1); lcd.print(String(WIFI_SSID).substring(0,16));
        delay(2000);
    }
    lastWifiRetry = millis();
}

// ============================================================
//  PUBLIC API: trigger enrollment from web / serial
//  Call this from your web UI's AJAX call to bio_enroll_trigger.php
//  which sets a flag the device polls, OR call startEnrollment()
//  from a physical button handler.
// ============================================================
void startEnrollment(const String& studentId, const String& studentName) {
    enrollStudentId   = studentId;
    enrollStudentName = studentName;
    enrollMode        = true;
    Serial.printf("[ENROLL] Triggered for %s (%s)\n",
                  studentName.c_str(), studentId.c_str());
}
