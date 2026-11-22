#include <WiFi.h>
#include <HTTPClient.h>
#include <Wire.h>
#include <Adafruit_MPU6050.h>
#include <Adafruit_Sensor.h>
#include <TinyGPS++.h>

// ==========================================
// 1. WIFI & LOCAL XAMPP SERVER
// ==========================================
const char* ssid = "WIFI NAME ";          
const char* password = "PASSWORD";  

// 🚨 CRITICAL: Update this IP address via cmd -> ipconfig 🚨
String serverName = "http://IP ADDRESS/VisionAid/api/sensor_data.php"; 


// ==========================================
// 2. HARDWARE PINS
// ==========================================
#define TRIG_PIN 5
#define ECHO_PIN 18
#define WATER_PIN 34
#define GPS_RX 16 
#define GPS_TX 17 
#define MPU_SDA 21
#define MPU_SCL 22
#define BUZZER_PIN 26
#define VIB_PIN 27
#define SOS_PIN 13
#define GSM_TX 32 
#define GSM_RX 33 

Adafruit_MPU6050 mpu;
TinyGPSPlus gps;
HardwareSerial gpsSerial(2); 
HardwareSerial gsmSerial(1); 

unsigned long lastTime = 0;
// Set to 200ms for lightning-fast ultrasonic and dashboard updates
unsigned long timerDelay = 500; 
unsigned long lastSmsTime = 0; 

// ==========================================
// 3. GSM EMERGENCY FUNCTION
// ==========================================
void sendEmergencySMS(String lat, String lng) {
  Serial.println("Sending Emergency SMS...");
  gsmSerial.println("AT+CMGF=1"); 
  delay(200);
  
  // REPLACE WITH CARETAKER'S PHONE NUMBER
  gsmSerial.println("AT+CMGS=\"+919999999999\""); 
  delay(200);
  
  gsmSerial.print("VISIONAID ALERT: User needs help! Location: ");
  gsmSerial.print("http://maps.google.com/?q=");
  gsmSerial.print(lat);
  gsmSerial.print(",");
  gsmSerial.print(lng);
  delay(200);
  gsmSerial.write(26); 
  delay(3000);
}

void setup() {
  Serial.begin(115200);
  gpsSerial.begin(9600, SERIAL_8N1, GPS_RX, GPS_TX);
  gsmSerial.begin(9600, SERIAL_8N1, GSM_RX, GSM_TX);
  
  pinMode(TRIG_PIN, OUTPUT);
  pinMode(ECHO_PIN, INPUT);
  pinMode(WATER_PIN, INPUT);
  pinMode(BUZZER_PIN, OUTPUT);
  pinMode(VIB_PIN, OUTPUT);
  pinMode(SOS_PIN, INPUT_PULLUP);
  
  WiFi.begin(ssid, password);
  while(WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }
  Serial.println("\nConnected to WiFi!");

  Wire.begin(MPU_SDA, MPU_SCL);
  if (mpu.begin()) {
    mpu.setAccelerometerRange(MPU6050_RANGE_8_G);
  }
}

void loop() {
  // A. Ultrasonic Sensor (Real-Time)
  digitalWrite(TRIG_PIN, LOW);
  delayMicroseconds(2);
  digitalWrite(TRIG_PIN, HIGH);
  delayMicroseconds(10);
  digitalWrite(TRIG_PIN, LOW);
  
  long duration = pulseIn(ECHO_PIN, HIGH, 25000);
  int distance = duration * 0.034 / 2;
  
  // Prevent 0cm glitch when path is clear
  if (distance <= 0 || distance > 400) {
    distance = 400; 
  }
  
  // B. Water Sensor
  int waterLevel = analogRead(WATER_PIN);
  bool waterDetected = (waterLevel > 1000); 

  // C. MPU6050 Tilt Sensor
  sensors_event_t a, g, temp;
  mpu.getEvent(&a, &g, &temp);
  bool fallDetected = (abs(a.acceleration.x) > 6.0 || abs(a.acceleration.y) > 6.0);

  // D. SOS Button
  bool sosPressed = (digitalRead(SOS_PIN) == LOW); 
  if (sosPressed) {
    distance = 14; 
  }

  // E. GPS Module
  while (gpsSerial.available() > 0) {
    gps.encode(gpsSerial.read());
  }
  
  String lat = "13.0827";
  String lng = "80.2707";
  if (gps.location.isValid()) {
    lat = String(gps.location.lat(), 6);
    lng = String(gps.location.lng(), 6);
  }

  // F. Emergency Triggers & Buzzer Logic
  bool isEmergency = (distance > 0 && distance <= 30) || waterDetected || fallDetected || sosPressed;

  if (isEmergency) { 
    digitalWrite(BUZZER_PIN, HIGH);
    digitalWrite(VIB_PIN, HIGH);
    if (lastSmsTime == 0 || (millis() - lastSmsTime) > 60000) {
      sendEmergencySMS(lat, lng);
      lastSmsTime = millis(); 
    }
  } else {
    digitalWrite(BUZZER_PIN, LOW);
    digitalWrite(VIB_PIN, LOW);
  }

  String waterStr = waterDetected ? "Danger" : "Safe";
  String tiltStr = fallDetected ? "Danger" : "Safe";
  
  // G. Ultra-Fast Network Transmission
  if ((millis() - lastTime) > timerDelay) {
    
    Serial.print("Dist: "); Serial.print(distance);
    Serial.print(" cm | Water: "); Serial.print(waterStr);
    Serial.print(" | Tilt: "); Serial.print(tiltStr);
    Serial.print(" | SOS: "); Serial.println(sosPressed);

    if (WiFi.status() == WL_CONNECTED) {
      HTTPClient http;
      
      // 🚨 Strict 200ms timeout prevents the sensor from freezing when IP is wrong 🚨
      http.setTimeout(1000); 
      
      http.begin(serverName);
      http.addHeader("Content-Type", "application/x-www-form-urlencoded");
      
      String httpRequestData = "device_id=ESP32_01&obstacle_distance=" + String(distance) + 
                               "&water_status=" + waterStr + "&tilt_status=" + tiltStr +
                               "&sos=" + String(sosPressed) + "&lat=" + lat + "&lng=" + lng;
      
      int httpResponseCode = http.POST(httpRequestData);
      
      if (httpResponseCode > 0) {
        Serial.print("Dashboard Updated. Code: ");
        Serial.println(httpResponseCode);
      } else {
        Serial.print("Server not found! Error: ");
        Serial.println(httpResponseCode);
      }
      
      http.end();
    }
    lastTime = millis();
  }
}