#include "griotte_creds_mar.h"
#include "painlessMesh.h"

/*
# iaqSensor.staticIaq
  Range: 0.0 to 500.0
  Meaning: Represents long-term air quality; lower is better.
    0–50: Excellent
    51–100: Good
    101–150: Lightly polluted
    151–200: Moderately polluted
    201–250: Heavily polluted
    251–500: Severely polluted

# iaqSensor.co2Equivalent
  Range: ~400 ppm to ~10,000+ ppm
  Meaning: Equivalent estimated CO₂ level based on VOCs (not actual CO₂ sensor!)
    400–1000 ppm: normal indoor
    1000–2000 ppm: drowsiness
    2000–5000 ppm: headaches, poor air

# iaqSensor.breathVocEquivalent
  Range: 0.0 to ~10.0+
  Meaning: Breath VOC (volatile organic compound) estimate in ppm (e.g., ethanol equivalents)
    ~0.5 ppm is typical for fresh air indoors
    1.0 ppm indicates increasing VOC levels
*/

#ifdef ESP8266
#include "bsec.h"
#include <Wire.h>
#endif

#ifdef ESP32
#include "base64.hpp"
#include <wifi.h>
#include <ArduinoHttpClient.h>
#endif

const byte FALSE = 0;
const byte TRUE = 1;
static const unsigned int MAX_MSG_LEN = 256;
static const unsigned int REMINDERS_THRESHOLD = 45000;
char outBuffer[MAX_MSG_LEN];
byte isRootReachable = FALSE;
unsigned int nbRootUnreachable = 0;
unsigned long lastReminderTimer = 0;

void errLeds();
void setupNetwork();
void reminders(unsigned long);
void hasRootNode(uint32_t, byte);


#ifdef ESP8266
const unsigned int LED = LED_BUILTIN;
unsigned long lastReadData = 0;
static unsigned int sensorFailCount = 0;
static const unsigned int SENSOR_FAIL_THRESHOLD = 3;
byte iaqAddress = 0; // address 0 is n/a
Bsec iaqSensor;

static const unsigned int MAX_METRIC_SIZE = 10;
char mTimeBuffer[MAX_METRIC_SIZE];
char mPressureBuffer[MAX_METRIC_SIZE];
char mHumidityBuffer[MAX_METRIC_SIZE];
char temperatureBuffer[MAX_METRIC_SIZE];
char mIaqBuffer[MAX_METRIC_SIZE];
char mCo2Buffer[MAX_METRIC_SIZE];
char mVocBuffer[MAX_METRIC_SIZE];

byte detectIaqSensor();
void setupIaqSensor();
void checkIaqSensorStatus();
void formatOutputMsg(unsigned long);
void checkSensorData(unsigned long);
#endif

#ifdef ESP32
const unsigned int LED = 8;
#endif

#ifdef HAS_STATION_CREDS
unsigned char base64[MAX_MSG_LEN];
char jsonBuffer[MAX_MSG_LEN];
char payloadBuffer[MAX_MSG_LEN];
byte foundWifiStation = FALSE;
byte scanWifi();
void wifiCallback_OnEvent(WiFiEvent_t);
byte hasWlanIP = FALSE;
WiFiClient wifi;
void sendHttp(unsigned long, uint32_t, String &);
IPAddress getWanIP();
IPAddress myWanIP(0,0,0,0);
#endif


painlessMesh mesh;

void meshCallback_OnReceived(uint32_t, String &); // Set a callback routine for any messages that are addressed to this node.
void meshCallback_OnNewConnection(uint32_t); // Callback that gets called every time the local node makes a new connection
void meshCallback_OnDroppedConnection(uint32_t); // Callback that gets called every time the local node drops a connection.
void meshCallback_OnChangedConnections(); // Callback that gets called every time the layout of the mesh changes
void meshCallback_OnNodeTimeAdjusted(int32_t); // Callback that gets called every time node time gets adjusted
void meshCallback_OnNodeDelayReceived(uint32_t, int32_t); // Callback that gets called when a delay measurement is received.

uint32_t currentNode;
unsigned long timeTrigger;


IPAddress getMeshIP();
IPAddress myMeshIP(0,0,0,0);


void setup(void)
{
  Serial.begin(115200);
  while (!Serial);
  Serial.println();
  Serial.println("Hi!");
  Serial.println(GRIOTTE_BUILD_ID);

  pinMode(LED, OUTPUT);
  digitalWrite(LED, HIGH);

  // ERROR | MESH_STATUS | CONNECTION | SYNC | COMMUNICATION | GENERAL | MSG_TYPES | REMOTE | DEBUG | STARTUP
  // ERROR | MESH_STATUS | REMOTE | DEBUG

#ifdef ESP32
  Serial.println("This is ESP32");
  // mesh.setDebugMsgTypes(ERROR | MESH_STATUS | CONNECTION | SYNC | MSG_TYPES | REMOTE | DEBUG);
  // mesh.setDebugMsgTypes(ERROR | MESH_STATUS | CONNECTION | SYNC | COMMUNICATION | GENERAL | MSG_TYPES | REMOTE | DEBUG);
  // mesh.setDebugMsgTypes(ERROR | STARTUP | CONNECTION | REMOTE | DEBUG);
#endif

#ifdef ESP8266
  Serial.println("This is ESP8266");
  // mesh.setDebugMsgTypes(ERROR | MESH_STATUS | CONNECTION | SYNC | COMMUNICATION | GENERAL | MSG_TYPES | REMOTE | DEBUG);
  // mesh.setDebugMsgTypes(ERROR | STARTUP | REMOTE | DEBUG);
  setupIaqSensor();
#endif

  setupNetwork();

  Serial.println();
}


void loop(void)
{
  timeTrigger = millis();
  mesh.update();

#ifdef ESP8266
  checkSensorData(timeTrigger);
#endif

  reminders(timeTrigger);
}


void errLeds(char *errmsg)
{
  Serial.println(errmsg);
  mesh.sendBroadcast(errmsg);
  mesh.update();
  delay(100);

  mesh.stop();
  while(1)
    delay(100);

  /*
  pinMode(LED_BUILTIN, OUTPUT);
  digitalWrite(LED_BUILTIN, HIGH);
  delay(100);
  digitalWrite(LED_BUILTIN, LOW);
  delay(100);
  */
}


void hasRootNode(uint32_t nodeId, byte nodeIsAvailable) {
  if(MESH_ROOT_NODE != nodeId) {
    // this is about a different node: we can't tell about root
  }
  else if(nodeIsAvailable == isRootReachable) {
    // never mind, no change
  }
  else if(FALSE == nodeIsAvailable) {
    Serial.println("The root node has left the chat");
    isRootReachable = FALSE;
  }
  else {
    Serial.println("The root node has joined the chat");
    isRootReachable = TRUE;
  }
}


void reminders(unsigned long startedAt) {
  if(REMINDERS_THRESHOLD > startedAt - lastReminderTimer) {
    // too early
  }
  else {
    lastReminderTimer = timeTrigger;

    Serial.printf("I am node #%u in the mesh", currentNode); // simply helpful on the Serial console
    Serial.println();

    if(MESH_ROOT_NODE == currentNode) {
      mesh.sendBroadcast("Hello this is root speaking"); // keep the mesh alive
    }

#ifdef HAS_STATION_CREDS
    String meshJson = "topology::" + mesh.subConnectionJson();
    sendHttp(timeTrigger, currentNode, meshJson); // keep the wifi alive
#endif
  }
}


//
// mesh callbacks
//

void meshCallback_OnReceived(uint32_t from, String &msg) {
  const unsigned long receivedAt = millis();

  hasRootNode(from, TRUE);

#ifdef HAS_STATION_CREDS
  sendHttp(receivedAt, from, msg);
#endif
}

void meshCallback_OnNewConnection(uint32_t nodeId) {
  Serial.printf("New mesh connection with #%u", nodeId);
  Serial.println();
  hasRootNode(nodeId, TRUE);
}

void meshCallback_OnDroppedConnection(uint32_t nodeId) {
  Serial.printf("Dropped mesh connection from #%u", nodeId);
  Serial.println();
  hasRootNode(nodeId, FALSE);
}

void meshCallback_OnChangedConnections() {
  Serial.printf("Changed mesh connections; new topology is: %s", mesh.subConnectionJson().c_str());
  Serial.println();
}

void meshCallback_OnNodeTimeAdjusted(int32_t offset) {
}

void meshCallback_OnNodeDelayReceived(uint32_t nodeId, int32_t delay) {
}

IPAddress getMeshIP() {
  return IPAddress(mesh.getAPIP());
}


void setupNetwork() {
  mesh.init(MESH_PREFIX, MESH_PASSWORD, (uint16_t) MESH_PORT, WIFI_AP_STA, (uint8_t) MESH_CHANNEL, (uint8_t) MESH_HIDDEN, (uint8_t) MESH_MAXCONN);
  mesh.onReceive(&meshCallback_OnReceived);
  mesh.onNewConnection(&meshCallback_OnNewConnection);
  mesh.onDroppedConnection(&meshCallback_OnDroppedConnection);
  mesh.onChangedConnections(&meshCallback_OnChangedConnections);
  mesh.onNodeTimeAdjusted(&meshCallback_OnNodeTimeAdjusted);
  mesh.onNodeDelayReceived(&meshCallback_OnNodeDelayReceived);


  currentNode = mesh.getNodeId();
  Serial.printf("I am node #%u in the mesh", currentNode);
  Serial.println();

#ifdef ESP8266
  mesh.setContainsRoot(true);
#endif

#ifdef HAS_STATION_CREDS
  WiFi.onEvent(&wifiCallback_OnEvent);

  if(MESH_ROOT_NODE != currentNode) {
    Serial.println("I am not the root node");
    mesh.setContainsRoot(true);
  }
  else {
    Serial.println("I _am_ the root node");

    scanWifi(); // debug WiFi issues

    mesh.setRoot(true);
    mesh.stationManual(STATION_SSID, STATION_PASSWORD);
    mesh.setHostname(MESH_ROOT_HOST);
    mesh.sendBroadcast("Hello this is root speaking");
  }
#endif

#ifdef ESP32
  mesh.sendBroadcast("Hi, ESP32 starting up");
#endif
#ifdef ESP8266
  mesh.sendBroadcast("Hi, ESP8266 starting up");
#endif
}


#ifdef HAS_STATION_CREDS
void sendHttp(unsigned long receivedAt, uint32_t from, String &msg) {
  Serial.printf("At %us from #%u: %s", (int) receivedAt/1000, from, msg.c_str());

  if(MESH_ROOT_NODE != currentNode) {
    Serial.println("\tnot forwarded (not the root node)");
    // Serial.println();
    return;
  }

  if(FALSE == hasWlanIP) {
    // Serial.printf("I don't have an IP on the WiFi: %s", STATION_SSID);
    // Serial.println();
    Serial.println("\tnot forwarded (no WAN IP)");
    return;
  }

  Serial.println();
  digitalWrite(LED, LOW);

  encode_base64((unsigned char *) msg.c_str(), msg.length(), base64);

  StaticJsonDocument<MAX_MSG_LEN> doc;
  doc["msg"] = base64;
  serializeJson(doc, jsonBuffer);

  snprintf(payloadBuffer, MAX_MSG_LEN, "data=%s&uptime=%u", jsonBuffer, receivedAt);

  //Serial.printf("Dbg: size=%u, payload=%s", strlen(payloadBuffer), payloadBuffer);
  //Serial.println();

  char userAgent[100];
  sprintf(userAgent, "%s/%u", HTTP_USERAGENT, from);

  HttpClient http = HttpClient(wifi, HTTP_ADDR, HTTP_PORT);

  http.setHttpResponseTimeout((int) 100);
  http.setHttpWaitForDataDelay((int) 200);
  http.noDefaultRequestHeaders();

  http.beginRequest();
  http.post(HTTP_PATH);
  http.sendHeader("User-Agent", userAgent);
  http.sendHeader("Connection", "close");
  http.sendHeader("Content-Type", "application/x-www-form-urlencoded");
  http.sendHeader("Content-Length", strlen(payloadBuffer));
  http.beginBody();
  http.print(payloadBuffer);
  http.endRequest();
  http.stop();

/*
  const unsigned int statusCode = http.responseStatusCode();
  const unsigned long timeSpent = millis() - receivedAt;

  if(200 == statusCode) {
    Serial.printf(
      "Forwarded readings (%uo) from #%u in %ums",
      strlen(payloadBuffer), from, timeSpent
    );
    Serial.println();
  }
  else {
    Serial.printf(
      "Failed (probably) to forward data from #%u in %ums: status %u",
      from, timeSpent, statusCode
    );
    Serial.println();
  }
*/

  // Serial.printf("Free HEAP after meshCallback_OnReceived: %u", ESP.getFreeHeap());
  // Serial.println();
  digitalWrite(LED, HIGH);
}

IPAddress getWanIP() {
  return IPAddress(mesh.getStationIP());
}

byte scanWifi() {
  const unsigned long receivedAt = millis();
  Serial.println("Starting WiFi scan...");
  const unsigned int n = WiFi.scanNetworks(false, true);
  if(0 == n) {
    Serial.println("No networks found.");
  } else {
    foundWifiStation = FALSE;
    for(unsigned int i = 0; i < n; ++i) {
      Serial.printf("Network %s on channel %d with BSSID=%s", WiFi.SSID(i).c_str(), WiFi.channel(i), WiFi.BSSIDstr(i).c_str());
      Serial.println();
      if(0 == strcmp(STATION_SSID, WiFi.SSID(i).c_str()) && MESH_CHANNEL == WiFi.channel(i)) {
        foundWifiStation = TRUE;
        // Serial.printf("Found network %s on channel %d with BSSID=%s", WiFi.SSID(i).c_str(), WiFi.channel(i), WiFi.BSSIDstr(i).c_str());
        // Serial.println();
        break;
      }
    }
    if(FALSE == foundWifiStation) {
      Serial.printf("Network %s NOT FOUND on channel %d out of %u networks", STATION_SSID, MESH_CHANNEL, n);
      Serial.println();
    }
  }
  const unsigned long timeSpent = millis() - receivedAt;
  Serial.printf("WiFi scan ended in %ums", timeSpent);
  Serial.println();
  return foundWifiStation;
}

/**
 * Events list from
 * https://github.com/espressif/arduino-esp32/blob/master/libraries/WiFi/examples/WiFiClientEvents/WiFiClientEvents.ino
 */
void wifiCallback_OnEvent(WiFiEvent_t event) {
  switch(event) {
    // case ARDUINO_EVENT_WIFI_READY:               Serial.println("WiFi: interface ready"); break;
    // case ARDUINO_EVENT_WIFI_SCAN_DONE:           Serial.println("WiFi: completed scan for access points"); break;
    // case ARDUINO_EVENT_WIFI_STA_START:           Serial.println("WiFi STA: client started"); break;
    case ARDUINO_EVENT_WIFI_STA_STOP:
      Serial.println("WiFi STA: client stopped");
      hasWlanIP = FALSE;
      break;
    case ARDUINO_EVENT_WIFI_STA_CONNECTED:
      Serial.println("WiFi STA: connected to access point");
      break;
    case ARDUINO_EVENT_WIFI_STA_DISCONNECTED:
      Serial.println("WiFi STA: disconnected from access point");
      hasWlanIP = FALSE;
      // WiFi.config(0U, 0U, 0U, 0U); // clear static IP
      WiFi.begin(STATION_SSID, STATION_PASSWORD);
      break;
    // case ARDUINO_EVENT_WIFI_STA_AUTHMODE_CHANGE: Serial.println("WiFi: authentication mode of access point has changed"); break;
    case ARDUINO_EVENT_WIFI_STA_GOT_IP:
      Serial.print("WiFi STA: Obtained WAN IP address ");
      Serial.print(WiFi.localIP());
      Serial.printf(" at %us", (long) millis()/1000);
      Serial.println();
      hasWlanIP = TRUE;
      myWanIP = getWanIP();
      break;
    case ARDUINO_EVENT_WIFI_STA_LOST_IP:
      Serial.println("WiFi STA: Lost WAN IP address");
      hasWlanIP = FALSE;
      break;
    // case ARDUINO_EVENT_WPS_ER_SUCCESS:          Serial.println("WiFi Protected Setup (WPS): succeeded in enrollee mode"); break;
    // case ARDUINO_EVENT_WPS_ER_FAILED:           Serial.println("WiFi Protected Setup (WPS): failed in enrollee mode"); break;
    // case ARDUINO_EVENT_WPS_ER_TIMEOUT:          Serial.println("WiFi Protected Setup (WPS): timeout in enrollee mode"); break;
    // case ARDUINO_EVENT_WPS_ER_PIN:              Serial.println("WiFi Protected Setup (WPS): pin code in enrollee mode"); break;
    // case ARDUINO_EVENT_WIFI_AP_START:           Serial.println("WiFi AP: access point started"); break;
    // case ARDUINO_EVENT_WIFI_AP_STOP:            Serial.println("WiFi AP: access point stopped"); break;
    // case ARDUINO_EVENT_WIFI_AP_STACONNECTED:    Serial.println("WiFi AP: remote client connected"); break;
    // case ARDUINO_EVENT_WIFI_AP_STADISCONNECTED: Serial.println("WiFi AP: remote client disconnected"); break;
    // case ARDUINO_EVENT_WIFI_AP_STAIPASSIGNED:   Serial.println("WiFi AP: assigned IP address to remote client"); break;
    // case ARDUINO_EVENT_WIFI_AP_PROBEREQRECVED:  Serial.println("WiFi AP: received probe request"); break;
    // case ARDUINO_EVENT_WIFI_AP_GOT_IP6:         Serial.println("WiFi AP: IPv6 is preferred"); break;
    // case ARDUINO_EVENT_WIFI_STA_GOT_IP6:        Serial.println("WiFi STA: IPv6 is preferred"); break;
    // case ARDUINO_EVENT_ETH_GOT_IP6:             Serial.println("Ethernet IPv6 is preferred"); break;
    // case ARDUINO_EVENT_ETH_START:               Serial.println("Ethernet started"); break;
    // case ARDUINO_EVENT_ETH_STOP:                Serial.println("Ethernet stopped"); break;
    // case ARDUINO_EVENT_ETH_CONNECTED:           Serial.println("Ethernet connected"); break;
    // case ARDUINO_EVENT_ETH_DISCONNECTED:        Serial.println("Ethernet disconnected"); break;
    // case ARDUINO_EVENT_ETH_GOT_IP:              Serial.println("Obtained eth IP address"); break;
    default: break;
  }
}
#endif


#ifdef ESP8266
byte detectIaqSensor() {
  byte address;

  address = 0x76;
  Wire.beginTransmission(address);
  if(0 != Wire.endTransmission()) {
    address = 0x77;
    Wire.beginTransmission(address);
    if(0 != Wire.endTransmission()) {
      address = 0;
    }
  }

  if(0 == address) {
    sprintf(outBuffer, "No IAQ sensor found");
    Serial.println(outBuffer);
  }
  else {
    sprintf(outBuffer, "IAQ sensor found at address 0x%.2X, initializing...", address);
    Serial.println(outBuffer);
  }

  return address;
}

void checkIaqSensorStatus()
{
  if (iaqSensor.status != BSEC_OK) {
    if (iaqSensor.status < BSEC_OK) {
      sprintf(outBuffer, "BSEC error code : %u", iaqSensor.status);
      errLeds(outBuffer); /* Halt in case of failure */
    } else {
      sprintf(outBuffer, "BSEC warning code : %u", iaqSensor.status);
      Serial.println(outBuffer);
    }
  }

  if (iaqSensor.bme680Status != BME680_OK) {
    if (iaqSensor.bme680Status < BME680_OK) {
      sprintf(outBuffer, "BME680 error code : %u", iaqSensor.bme680Status);
      errLeds(outBuffer); /* Halt in case of failure */
    } else {
      sprintf(outBuffer, "BME680 warning code : %u", iaqSensor.bme680Status);
      Serial.println(outBuffer);
    }
  }
}

void setupIaqSensor() {
  Wire.begin();
  iaqAddress = detectIaqSensor();

  if(0 != iaqAddress) {
    // set up the IAQ sensor

    iaqSensor.begin(iaqAddress, Wire);

    sprintf(
      outBuffer,
      "BSEC library version %u.%u.%u.%u",
      iaqSensor.version.major,
      iaqSensor.version.minor,
      iaqSensor.version.major_bugfix,
      iaqSensor.version.minor_bugfix
    );
    Serial.println(outBuffer);

    bsec_virtual_sensor_t sensorList[10] = {
      BSEC_OUTPUT_RAW_TEMPERATURE,
      BSEC_OUTPUT_RAW_PRESSURE,
      BSEC_OUTPUT_RAW_HUMIDITY,
      BSEC_OUTPUT_RAW_GAS,
      BSEC_OUTPUT_IAQ,
      BSEC_OUTPUT_STATIC_IAQ,
      BSEC_OUTPUT_CO2_EQUIVALENT,
      BSEC_OUTPUT_BREATH_VOC_EQUIVALENT,
      BSEC_OUTPUT_SENSOR_HEAT_COMPENSATED_TEMPERATURE,
      BSEC_OUTPUT_SENSOR_HEAT_COMPENSATED_HUMIDITY,
    };

    checkIaqSensorStatus();
    iaqSensor.updateSubscription(sensorList, 10, BSEC_SAMPLE_RATE_LP);
    checkIaqSensorStatus();
  }
}

void formatOutputMsg(unsigned long readAt) {
  snprintf(mTimeBuffer, sizeof(mTimeBuffer), "%u", (int) readAt/1000);
  snprintf(mPressureBuffer, sizeof(mPressureBuffer), "%.0f", iaqSensor.pressure);
  snprintf(mHumidityBuffer, sizeof(mHumidityBuffer), "%.0f", iaqSensor.humidity);
  snprintf(temperatureBuffer, sizeof(temperatureBuffer), "%.0f", iaqSensor.temperature);

  if(0 == iaqSensor.iaqAccuracy) {
    snprintf(mIaqBuffer, sizeof(mIaqBuffer), "%.1f", 0.0);
    snprintf(mCo2Buffer, sizeof(mCo2Buffer), "%.0f", 0.0);
    snprintf(mVocBuffer, sizeof(mVocBuffer), "%.2f", 0.0);
  }
  else {
    snprintf(mIaqBuffer, sizeof(mIaqBuffer), "%.1f", iaqSensor.staticIaq);
    snprintf(mCo2Buffer, sizeof(mCo2Buffer), "%.0f", iaqSensor.co2Equivalent);
    snprintf(mVocBuffer, sizeof(mVocBuffer), "%.2f", iaqSensor.breathVocEquivalent);
  }

  sprintf(
    outBuffer,
    // "%s [hPa]; %s hum. [%%]; %s temp. [°C]; %s IAQ; %s eCO² [PPM]; %s VOC [PPM]; %u/3 iAQ accuracy; %u free heap [o]; %s uptime [s]",
    "%s;%s;%s;%s;%s;%s;%u;%u;%s",
    mPressureBuffer, mHumidityBuffer, temperatureBuffer, mIaqBuffer, mCo2Buffer, mVocBuffer,
    iaqSensor.iaqAccuracy, ESP.getFreeHeap(), mTimeBuffer
  );

  /*
  if(2 <= iaqSensor.iaqAccuracy
    && (10000 < iaqSensor.co2Equivalent
      || 10.0 < iaqSensor.breathVocEquivalent
      || 500 < iaqSensor.staticIaq)) {
    Serial.printf(
      "Wild sensor values: co2Equivalent=%s, breathVocEquivalent=%s, staticIaq=%s",
      mCo2Buffer, mVocBuffer, mIaqBuffer
    );
    Serial.println();
    Serial.println(outBuffer);
    // Serial.printf("[Heap after run] Free: %u", ESP.getFreeHeap());
    // Serial.println();
    ESP.restart();
  }
  */
}

void checkSensorData(unsigned long startedAt) {
  if(0 != iaqAddress) {
    checkIaqSensorStatus();

    if(iaqSensor.run(startedAt)) { // If new data is available
      digitalWrite(LED, LOW);
      // Serial.printf("[Heap before run] Free: %u", ESP.getFreeHeap());
      // Serial.println();

      if(FALSE == isRootReachable) {
        if(0 == nbRootUnreachable) {
          ++nbRootUnreachable;
          Serial.println("Root node is not reachable");
        }
        if(5 <= nbRootUnreachable) {
          nbRootUnreachable = 0;
        }
      }

      lastReadData = startedAt;
      sensorFailCount = 0;

      formatOutputMsg(startedAt);

      mesh.sendBroadcast(outBuffer);
      Serial.println(outBuffer);
      digitalWrite(LED, HIGH);
      // Serial.printf("[Heap after run] Free: %u", ESP.getFreeHeap());
      // Serial.println();
    }
    else if(startedAt - lastReadData < 3*1100) {
      // never mind, sensor has values about every 3 seconds
    }
    else if(0 == lastReadData && startedAt < 5*60*1100) {
      // never mind, calibrating probably
    }
    else if(0 == lastReadData) {
      snprintf(mTimeBuffer, sizeof(mTimeBuffer), "%u", startedAt/1000);
      sprintf(outBuffer, "No data for %ss...", mTimeBuffer);
      Serial.println(outBuffer);
    }
    else if(startedAt < 50*1000) {
      // never mind, give it more time?
    }
    else {
      sensorFailCount++;
      Serial.printf("Sensor read failed %u times in a row", sensorFailCount);
      Serial.println();
      if(SENSOR_FAIL_THRESHOLD < sensorFailCount) {
        sprintf(outBuffer, "Sensor unresponsive. Rebooting...");
        Serial.println(outBuffer);
        // delay(500);
        // ESP.restart();  // or soft-reset just the sensor if possible
      }
    }
  }
}
#endif
