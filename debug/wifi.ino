#include <WiFi.h>

void setup() {
  Serial.begin(115200);
  WiFi.begin("home_ssid", "home_passwd");

  WiFi.onEvent([](WiFiEvent_t event, WiFiEventInfo_t info) {
    if (event == ARDUINO_EVENT_WIFI_STA_DISCONNECTED) {
      Serial.print("WiFi disconnect reason: ");
      Serial.println(info.wifi_sta_disconnected.reason);
      Serial.print("Signal strength: ");
      Serial.println(WiFi.RSSI());
    }
  });

  Serial.println("Connecting...");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }

  Serial.println("\nConnected!");
  Serial.println(WiFi.localIP());
  Serial.println(WiFi.RSSI());
}

void loop() {}
