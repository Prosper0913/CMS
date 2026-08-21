// ============================================================
//  secrets.example.h
//  Template for secrets.h — copy this file to secrets.h and
//  fill in real values before flashing. secrets.h itself is
//  gitignored so real credentials never get committed.
// ============================================================
#pragma once

const char* WIFI_SSID     = "YOUR_WIFI_SSID";
const char* WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";

const char* SERVER_BASE   = "http://YOUR_SERVER_IP_OR_DOMAIN";

// This device's unique key — must match the device_key column in bio_devices
const char* DEVICE_KEY    = "esp32-roomN-changeme";