/*
 * ======================================================================================
 * FIRMWARE MESIN ABSENSI ESP32 (RFID RC522 & FINGERPRINT AS608 / R307)
 * Proyek: Sistem Absensi Terpadu Siswa & Staff (An-Nahl Islamic School)
 * ======================================================================================
 * 
 * FITUR UTAMA:
 * 1. Web Portal Setup (Captive Portal) jika belum ada koneksi / tombol reset ditekan 3 dtk.
 * 2. Pengaturan WiFi, Server API URL, Device ID, dan Device Token tersimpan di Flash (NVS).
 * 3. Anti-Tabrakan Perangkat: Setiap mesin mengirimkan Device ID & Token rahasia unik.
 * 4. Mendukung pemindaian Kartu RFID (MFRC522 SPI) dan Sidik Jari (AS608/R307 UART).
 * 5. Indikator LED 2 Warna (Hijau = Sukses/Ready, Merah = Gagal/Ditolak/Offline).
 * 6. Audio Buzzer Feedback (Beep 1x = Sukses, Beep 2x = Gagal/Tidak Terdaftar).
 * 7. Tombol Reset / Setup (GPIO 14) untuk reset konfigurasi.
 * 
 * ======================================================================================
 * TABEL PIN WIRING ESP32 (DevKit 30/38 Pin):
 * ======================================================================================
 * KOMPONEN               PIN ESP32 (GPIO)      KETERANGAN
 * --------------------------------------------------------------------------------------
 * RFID RC522 (SPI):
 *   - SDA / SS           GPIO 5                SPI Chip Select
 *   - SCK                GPIO 18               SPI Clock
 *   - MOSI               GPIO 23               SPI MOSI
 *   - MISO               GPIO 19               SPI MISO
 *   - RST                GPIO 22               Reset Pin
 *   - 3.3V               3.3V                  (PERINGATAN: WAJIB 3.3V, BUKAN 5V!)
 *   - GND                GND                   Ground
 * --------------------------------------------------------------------------------------
 * FINGERPRINT (AS608 / R307 / FPM10A):
 *   - TX Sensor          GPIO 16 (RX2)         Data dari sensor ke ESP32
 *   - RX Sensor          GPIO 17 (TX2)         Data dari ESP32 ke sensor
 *   - VCC                3.3V / 5V             Sesuai modul (umumnya toleran 3.3V - 5V)
 *   - GND                GND                   Ground
 * --------------------------------------------------------------------------------------
 * INDIKATOR LED (2 Warna Berbeda):
 *   - LED Hijau (+)      GPIO 2                Melalui resistor 220-330 Ohm ke GND
 *   - LED Merah (+)      GPIO 4                Melalui resistor 220-330 Ohm ke GND
 * --------------------------------------------------------------------------------------
 * AUDIO BUZZER:
 *   - Buzzer (+)         GPIO 15               Active Buzzer
 *   - Buzzer (-)         GND                   Ground
 * --------------------------------------------------------------------------------------
 * TOMBOL RESET / SETUP:
 *   - Push Button        GPIO 14               Input Pullup (Pin 1 ke GPIO 14, Pin 2 ke GND)
 *                                              Tahan 3 detik untuk masuk Setup AP
 * ======================================================================================
 */

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <WebServer.h>
#include <DNSServer.h>
#include <HTTPClient.h>
#include <Preferences.h>
#include <SPI.h>
#include <MFRC522.h>
#include <Adafruit_Fingerprint.h>
#include <ArduinoJson.h>

// ==========================================
// DEFINISI PIN PERIPHERAL
// ==========================================
#define RFID_SS_PIN      5
#define RFID_RST_PIN     22

#define FP_RX_PIN        16   // Ke TX Fingerprint
#define FP_TX_PIN        17   // Ke RX Fingerprint

#define LED_GREEN_PIN    2
#define LED_RED_PIN      4
#define BUZZER_PIN       15
#define BUTTON_RESET_PIN 14

// Objek Perangkat Keras
MFRC522 mfrc522(RFID_SS_PIN, RFID_RST_PIN);
HardwareSerial mySerial(2); // Serial2 UART untuk Fingerprint
Adafruit_Fingerprint finger = Adafruit_Fingerprint(&mySerial);

Preferences preferences;
WebServer server(80);
DNSServer dnsServer;

// Variabel Konfigurasi
String wifi_ssid     = "";
String wifi_pass     = "";
String api_url       = "http://192.168.1.100/absensi-anak/public/api/esp32_attendance.php";
String device_id     = "ESP32-UNIT1-DEV1";
String device_token  = "TOK-DEV-SD-01-A8B9C";

bool isConfigMode    = false;
unsigned long lastDebounceTime = 0;
String lastScannedCode = "";

// ==========================================
// FUNGSI FEEDBACK AUDIVISUAL (LED & BUZZER)
// ==========================================
void feedbackSuccess() {
  // LED Hijau Kedip 2x & Beep 1x
  digitalWrite(LED_GREEN_PIN, HIGH);
  digitalWrite(BUZZER_PIN, HIGH);
  delay(120);
  digitalWrite(BUZZER_PIN, LOW);
  digitalWrite(LED_GREEN_PIN, LOW);
  delay(100);
  digitalWrite(LED_GREEN_PIN, HIGH);
  delay(300);
  digitalWrite(LED_GREEN_PIN, LOW);
}

void feedbackFail() {
  // LED Merah Kedip 3x & Beep 2x Panjang
  for (int i = 0; i < 2; i++) {
    digitalWrite(LED_RED_PIN, HIGH);
    digitalWrite(BUZZER_PIN, HIGH);
    delay(200);
    digitalWrite(BUZZER_PIN, LOW);
    digitalWrite(LED_RED_PIN, LOW);
    delay(100);
  }
  digitalWrite(LED_RED_PIN, HIGH);
  delay(300);
  digitalWrite(LED_RED_PIN, LOW);
}

void feedbackCooldown() {
  // LED Hijau Nyala Tenang 1 Detik
  digitalWrite(LED_GREEN_PIN, HIGH);
  digitalWrite(BUZZER_PIN, HIGH);
  delay(80);
  digitalWrite(BUZZER_PIN, LOW);
  delay(800);
  digitalWrite(LED_GREEN_PIN, LOW);
}

void feedbackBoot() {
  digitalWrite(LED_GREEN_PIN, HIGH);
  delay(150);
  digitalWrite(LED_GREEN_PIN, LOW);
  digitalWrite(LED_RED_PIN, HIGH);
  delay(150);
  digitalWrite(LED_RED_PIN, LOW);
}

// ==========================================
// LOAD & SAVE KONFIGURASI DARI FLASH (NVS)
// ==========================================
void loadConfig() {
  preferences.begin("absensi_cfg", true);
  wifi_ssid    = preferences.getString("ssid", "");
  wifi_pass    = preferences.getString("pass", "");
  api_url      = preferences.getString("api", api_url);
  device_id    = preferences.getString("dev_id", device_id);
  device_token = preferences.getString("dev_tok", device_token);
  preferences.end();

  Serial.println("--- Konfigurasi Terbaca dari NVS ---");
  Serial.println("SSID: " + wifi_ssid);
  Serial.println("API URL: " + api_url);
  Serial.println("Device ID: " + device_id);
}

void saveConfig(String s, String p, String a, String did, String dtok) {
  preferences.begin("absensi_cfg", false);
  preferences.putString("ssid", s);
  preferences.putString("pass", p);
  preferences.putString("api", a);
  preferences.putString("dev_id", did);
  preferences.putString("dev_tok", dtok);
  preferences.end();
  Serial.println("Konfigurasi berhasil disimpan ke NVS. Merestart ESP32...");
}

// ==========================================
// CAPTIVE PORTAL SETUP (MODE ACCESS POINT)
// ==========================================
void handleRoot() {
  // Scan WiFi di sekitar
  int n = WiFi.scanNetworks();
  String wifiOptions = "";
  for (int i = 0; i < n; ++i) {
    String netName = WiFi.SSID(i);
    if (netName.length() > 0) {
      wifiOptions += "<option value='" + netName + "'>" + netName + " (" + String(WiFi.RSSI(i)) + " dBm)</option>";
    }
  }

  String html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1.0'>";
  html += "<title>Setup Mesin Absensi ESP32</title>";
  html += "<style>";
  html += "body{font-family:system-ui,-apple-system,sans-serif;background:#0f172a;color:#f8fafc;padding:20px;margin:0;}";
  html += ".box{max-width:440px;margin:0 auto;background:#1e293b;padding:25px;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,0.3);}";
  html += "h2{margin-top:0;color:#38bdf8;font-size:20px;}";
  html += "label{display:block;font-size:12px;color:#94a3b8;margin-top:12px;margin-bottom:4px;font-weight:600;}";
  html += "input,select{width:100%;box-sizing:border-box;padding:10px;border-radius:8px;border:1px solid #334155;background:#0f172a;color:#fff;font-size:14px;}";
  html += "input:focus,select:focus{border-color:#38bdf8;outline:none;}";
  html += ".btn{width:100%;margin-top:22px;background:#0284c7;color:#fff;border:none;padding:12px;border-radius:8px;font-weight:700;font-size:15px;cursor:pointer;}";
  html += ".note{font-size:11px;color:#64748b;margin-top:4px;}";
  html += "</style></head><body><div class='box'>";
  html += "<h2>⚙️ Setup Absensi ESP32</h2>";
  html += "<p style='font-size:13px;color:#cbd5e1;margin-top:-6px;'>Hubungkan alat ini ke WiFi sekolah dan API backend agar tidak bertabrakan.</p>";
  html += "<form method='POST' action='/save'>";
  html += "<label>Pilih WiFi Sekolah</label><select name='ssid_select' onchange='document.getElementById(\"ssid\").value=this.value'>";
  html += "<option value=''>-- Pilih SSID WiFi --</option>" + wifiOptions + "</select>";
  html += "<label>SSID WiFi Manual</label><input type='text' id='ssid' name='ssid' value='" + wifi_ssid + "' required>";
  html += "<label>Password WiFi</label><input type='password' name='pass' value='" + wifi_pass + "'>";
  html += "<label>URL REST API Backend</label><input type='text' name='api' value='" + api_url + "' required>";
  html += "<p class='note'>Lokal: http://192.168.1.100/.../esp32_attendance.php<br>Online: https://domain.com/public/api/esp32_attendance.php</p>";
  html += "<label>Device ID (Unik Per Alat)</label><input type='text' name='dev_id' value='" + device_id + "' required>";
  html += "<p class='note'>Contoh: ESP32-UNIT1-DEV1, ESP32-UNIT1-DEV2</p>";
  html += "<label>Device Token (Secret Key)</label><input type='text' name='dev_tok' value='" + device_token + "' required>";
  html += "<p class='note'>Salin dari menu Pengaturan -> Perangkat ESP32 di Web Dashboard</p>";
  html += "<button type='submit' class='btn'>Simpan & Sambungkan</button>";
  html += "</form></div></body></html>";

  server.send(200, "text/html", html);
}

void handleSave() {
  String s = server.arg("ssid");
  String p = server.arg("pass");
  String a = server.arg("api");
  String did = server.arg("dev_id");
  String dtok = server.arg("dev_tok");

  if (s.length() > 0 && a.length() > 0 && did.length() > 0) {
    saveConfig(s, p, a, did, dtok);
    String res = "<!DOCTYPE html><html><body style='font-family:sans-serif;text-align:center;padding:50px;background:#0f172a;color:#fff;'>";
    res += "<h2 style='color:#10b981;'>Konfigurasi Tersimpan!</h2>";
    res += "<p>ESP32 sedang merestart untuk menyambung ke WiFi <b>" + s + "</b>...</p>";
    res += "<p>Silakan tutup halaman ini.</p></body></html>";
    server.send(200, "text/html", res);
    delay(1500);
    ESP.restart();
  } else {
    server.send(400, "text/plain", "Field wajib tidak boleh kosong.");
  }
}

void startSetupPortal() {
  isConfigMode = true;
  Serial.println("Memulai Mode Setup Captive Portal...");
  WiFi.mode(WIFI_AP);
  
  String apName = "ABSENSI-SETUP";
  WiFi.softAP(apName.c_str());
  
  dnsServer.start(53, "*", WiFi.softAPIP());
  server.on("/", handleRoot);
  server.on("/save", HTTP_POST, handleSave);
  server.onNotFound(handleRoot); // Redirect Captive Portal
  server.begin();

  Serial.println("Access Point Aktif: " + apName);
  Serial.print("IP Address Portal: ");
  Serial.println(WiFi.softAPIP());
}

// ==========================================
// PENGIRIMAN DATA PRESENSI KE BACKEND API
// ==========================================
void sendAttendanceToApi(String type, String code) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("[ERR] WiFi tidak tersambung!");
    feedbackFail();
    return;
  }

  HTTPClient http;
  WiFiClient client;
  WiFiClientSecure clientSecure;

  if (api_url.startsWith("https://")) {
    clientSecure.setInsecure(); // Mengabaikan validasi SSL CA/Fingerprint agar fleksibel dengan domain SSL/HTTPS
    http.begin(clientSecure, api_url);
  } else {
    http.begin(client, api_url);
  }

  http.addHeader("Content-Type", "application/json");
  http.addHeader("X-Device-Id", device_id);
  http.addHeader("X-Device-Token", device_token);
  http.setTimeout(8000);

  // Buat Payload JSON
  StaticJsonDocument<256> doc;
  doc["device_id"] = device_id;
  doc["token"]     = device_token;
  doc["type"]      = type;
  doc["code"]      = code;

  String requestBody;
  serializeJson(doc, requestBody);

  Serial.println("[API] Mengirim data: " + requestBody);
  int httpResponseCode = http.POST(requestBody);

  if (httpResponseCode > 0) {
    String response = http.getString();
    Serial.println("[API] HTTP Response: " + String(httpResponseCode));
    Serial.println("[API] Payload: " + response);

    StaticJsonDocument<512> resDoc;
    DeserializationError err = deserializeJson(resDoc, response);

    if (!err) {
      String status = resDoc["status"] | "error";
      String message = resDoc["message"] | "";
      String name = resDoc["name"] | "";

      if (status == "success") {
        Serial.println("[SUCCESS] Presensi Berhasil: " + name + " -> " + message);
        feedbackSuccess();
      } else if (status == "cooldown") {
        Serial.println("[COOLDOWN] " + message);
        feedbackCooldown();
      } else if (status == "already") {
        Serial.println("[ALREADY] " + message);
        feedbackCooldown();
      } else {
        Serial.println("[REJECTED] " + message);
        feedbackFail();
      }
    } else {
      Serial.println("[ERR] Gagal parsing JSON server response");
      feedbackFail();
    }
  } else {
    Serial.println("[ERR] HTTP POST Error: " + http.errorToString(httpResponseCode));
    feedbackFail();
  }
  http.end();
}

// ==========================================
// PEMINDAIAN SENSOR FINGERPRINT (AS608)
// ==========================================
void checkFingerprint() {
  uint8_t p = finger.getImage();
  if (p != FINGERPRINT_OK) return;

  p = finger.image2Tz();
  if (p != FINGERPRINT_OK) return;

  p = finger.fingerSearch();
  if (p == FINGERPRINT_OK) {
    Serial.println("Fingerprint Cocok! ID #" + String(finger.fingerID) + " Akurasi: " + String(finger.confidence));
    sendAttendanceToApi("fingerprint", String(finger.fingerID));
    delay(1500); // Jeda agar tidak double scan
  } else if (p == FINGERPRINT_NOTFOUND) {
    Serial.println("Sidik Jari tidak dikenali!");
    feedbackFail();
    delay(1000);
  }
}

// ==========================================
// PEMINDAIAN SENSOR RFID (MFRC522)
// ==========================================
void checkRfid() {
  if (!mfrc522.PICC_IsNewCardPresent() || !mfrc522.PICC_ReadCardSerial()) {
    return;
  }

  // Format UID ke Hex String uppercase
  String cardUid = "";
  for (byte i = 0; i < mfrc522.uid.byteSize; i++) {
    if (mfrc522.uid.uidByte[i] < 0x10) cardUid += "0";
    cardUid += String(mfrc522.uid.uidByte[i], HEX);
  }
  cardUid.toUpperCase();

  Serial.println("RFID Kartu Terdeteksi UID: " + cardUid);
  mfrc522.PICC_HaltA();
  mfrc522.PCD_StopCrypto1();

  // Debounce Lokal 3 Detik
  unsigned long now = millis();
  if (cardUid == lastScannedCode && (now - lastDebounceTime) < 3000) {
    Serial.println("Debounce aktif, kartu yang sama diabaikan sementara.");
    return;
  }
  lastScannedCode = cardUid;
  lastDebounceTime = now;

  sendAttendanceToApi("rfid", cardUid);
}

// ==========================================
// CEK TOMBOL RESET / SETUP (HOLD 3 DETIK)
// ==========================================
void checkResetButton() {
  if (digitalRead(BUTTON_RESET_PIN) == LOW) {
    unsigned long pressStart = millis();
    Serial.println("Tombol ditekan, cek durasi hold...");

    while (digitalRead(BUTTON_RESET_PIN) == LOW) {
      if (millis() - pressStart >= 3000) {
        // Tahan 3 detik: Masuk mode setup AP!
        Serial.println("Reset ditekan 3 detik! Menghapus WiFi dan masuk Setup Portal...");
        digitalWrite(LED_RED_PIN, HIGH);
        digitalWrite(LED_GREEN_PIN, HIGH);
        digitalWrite(BUZZER_PIN, HIGH);
        delay(500);
        digitalWrite(BUZZER_PIN, LOW);

        preferences.begin("absensi_cfg", false);
        preferences.clear();
        preferences.end();

        ESP.restart();
      }
      delay(50);
    }
  }
}

// ==========================================
// SETUP UTAMA
// ==========================================
void setup() {
  Serial.begin(115200);
  delay(500);
  Serial.println("\n=== BOOTING MESIN ABSENSI ESP32 ===");

  // Inisialisasi GPIO
  pinMode(LED_GREEN_PIN, OUTPUT);
  pinMode(LED_RED_PIN, OUTPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  pinMode(BUTTON_RESET_PIN, INPUT_PULLUP);

  digitalWrite(LED_GREEN_PIN, LOW);
  digitalWrite(LED_RED_PIN, LOW);
  digitalWrite(BUZZER_PIN, LOW);

  feedbackBoot();

  // Baca Konfigurasi
  loadConfig();

  // Inisialisasi SPI & RFID RC522
  SPI.begin();
  mfrc522.PCD_Init();
  delay(50);
  mfrc522.PCD_DumpVersionToSerial();

  // Inisialisasi Fingerprint AS608
  mySerial.begin(57600, SERIAL_8N1, FP_RX_PIN, FP_TX_PIN);
  finger.begin(57600);
  delay(100);

  if (finger.verifyPassword()) {
    Serial.println("Sensor Fingerprint AS608 Terdeteksi & Siap!");
  } else {
    Serial.println("[WARN] Sensor Fingerprint tidak merespon. Pastikan kabel RX/TX terpasang benar.");
  }

  // Jika WiFi belum pernah diset, langsung masuk Captive Portal
  if (wifi_ssid.length() == 0) {
    startSetupPortal();
    return;
  }

  // Sambungkan ke WiFi
  Serial.print("Menyambung ke WiFi: " + wifi_ssid);
  WiFi.mode(WIFI_STA);
  WiFi.begin(wifi_ssid.c_str(), wifi_pass.c_str());

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 25) {
    delay(400);
    Serial.print(".");
    digitalWrite(LED_GREEN_PIN, !digitalRead(LED_GREEN_PIN));
    attempts++;
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\nWiFi Berhasil Terhubung!");
    Serial.print("IP Address ESP32: ");
    Serial.println(WiFi.localIP());
    digitalWrite(LED_GREEN_PIN, HIGH); // Standby Hijau Menyala
    delay(500);
    digitalWrite(LED_GREEN_PIN, LOW);
  } else {
    Serial.println("\nGagal tersambung ke WiFi. Beralih ke Mode Setup Portal...");
    digitalWrite(LED_RED_PIN, HIGH);
    startSetupPortal();
  }
}

// ==========================================
// LOOP UTAMA
// ==========================================
void loop() {
  // Cek tombol reset kapan saja
  checkResetButton();

  // Jika sedang dalam mode konfigurasi captive portal
  if (isConfigMode) {
    dnsServer.processNextRequest();
    server.handleClient();

    // Kedip LED Merah & Hijau bergantian saat mode setup
    static unsigned long lastBlink = 0;
    if (millis() - lastBlink > 400) {
      lastBlink = millis();
      digitalWrite(LED_GREEN_PIN, !digitalRead(LED_GREEN_PIN));
      digitalWrite(LED_RED_PIN, !digitalRead(LED_GREEN_PIN));
    }
    return;
  }

  // Auto-reconnect WiFi jika terputus
  if (WiFi.status() != WL_CONNECTED) {
    static unsigned long lastReconnect = 0;
    if (millis() - lastReconnect > 10000) {
      lastReconnect = millis();
      Serial.println("[WIFI] Koneksi terputus, mencoba menyambung kembali...");
      WiFi.reconnect();
    }
    digitalWrite(LED_RED_PIN, HIGH); // Indikator offline
  } else {
    digitalWrite(LED_RED_PIN, LOW);
  }

  // Jalankan pembacaan sensor
  checkRfid();
  checkFingerprint();

  delay(20);
}
