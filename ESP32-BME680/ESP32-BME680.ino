#include "griotte_creds_local.h"
#include "painlessMesh.h"

#define HAS_GRIOTTE_BUILD_ID
const char GRIOTTE_BUILD_ID[] = "v2.2.6";

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
unsigned long lastReminderTimer = 0;

void errLeds();
void setupNetwork();
void reminders(unsigned long);
void hasRootNode(uint32_t, byte);


#ifdef ESP8266
static const unsigned int MESH_STABILITY_THRESHOLD = 135000;
const unsigned int LED = LED_BUILTIN;
unsigned long lastReadData = 0;
static unsigned int sensorFailCount = 0;
unsigned long lastMeshStabilityTimer = 0;
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
void checkMeshStability(unsigned long);
#endif

#ifdef ESP32
const unsigned int LED = 8;
const char DATASTRUCT_TYPOLOGY[] = "typology";
const char DATASTRUCT_BME680[] = "bme680";
#endif

#ifdef HAS_STATION_CREDS
unsigned char base64[MAX_MSG_LEN];
char jsonBuffer[MAX_MSG_LEN];
char payloadBuffer[MAX_MSG_LEN];
byte foundWifiStation = FALSE;
byte scanWifi(int);
void wifiCallback_OnEvent(WiFiEvent_t);
byte hasWlanIP = FALSE;
WiFiClient wifi;
byte sendHttp(unsigned long, uint32_t, const char*, String &);
IPAddress wanIP(0,0,0,0);
static const unsigned int HTTP_RESPONSE_TIMEOUT = 350;
static const unsigned int HTTP_WAIT_FOR_DATA_DELAY = 50;
static const unsigned int HTTP_NB_RETRIES = 2;
HttpClient http(wifi, HTTP_ADDR, HTTP_PORT);
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


void setup(void)
{
  btStop(); // disable BlueTooth
  Serial.begin(115200);
  while (!Serial);

#ifdef ESP32
  sleep(5); // lets me restart my serial log tail
#endif

  pinMode(LED, OUTPUT);
  digitalWrite(LED, HIGH);

  Serial.println();
  Serial.println("Hi!");
  Serial.printf("This is build %s", GRIOTTE_BUILD_ID);
  Serial.println();

  // ERROR | MESH_STATUS | CONNECTION | SYNC | COMMUNICATION | GENERAL | MSG_TYPES | REMOTE | DEBUG | STARTUP
  // ERROR | MESH_STATUS | REMOTE | DEBUG
  // mesh.setDebugMsgTypes(CONNECTION); // set before mesh.init() to see startup messages

#ifdef ESP32
  Serial.println("This is ESP32");
  // mesh.setDebugMsgTypes(ERROR | MESH_STATUS | CONNECTION | SYNC | MSG_TYPES | REMOTE | DEBUG);
  // mesh.setDebugMsgTypes(ERROR | MESH_STATUS | CONNECTION | SYNC | COMMUNICATION | GENERAL | MSG_TYPES | REMOTE | DEBUG);
  mesh.setDebugMsgTypes(ERROR | REMOTE | DEBUG);
#endif

#ifdef ESP8266
  Serial.println("This is ESP8266");
  // mesh.setDebugMsgTypes(ERROR | MESH_STATUS | CONNECTION | SYNC | COMMUNICATION | GENERAL | MSG_TYPES | REMOTE | DEBUG);
  // mesh.setDebugMsgTypes(ERROR | STARTUP | REMOTE | DEBUG);
  mesh.setDebugMsgTypes(ERROR | REMOTE | DEBUG);
  setupIaqSensor();
#endif

  digitalWrite(LED, LOW);
  setupNetwork();
  digitalWrite(LED, HIGH);

  Serial.println();
}


void loop(void)
{
  timeTrigger = millis();
  mesh.update();

#ifdef ESP8266
  checkSensorData(timeTrigger);
  checkMeshStability(timeTrigger);
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
    Serial.printf("The root node has left the chat: #%u", nodeId);
    Serial.println();
    isRootReachable = FALSE;
  }
  else {
    Serial.printf("The root node has joined the chat: #%u", nodeId);
    Serial.println();
    isRootReachable = TRUE;
  }
}


#ifdef ESP8266
void checkMeshStability(unsigned long startedAt) {
  if(MESH_STABILITY_THRESHOLD > startedAt - lastMeshStabilityTimer) {
    return; // too early
  }

  lastMeshStabilityTimer = timeTrigger;
  if(FALSE == isRootReachable) {
    Serial.printf("Root not is not reachable");
    Serial.println();
    ESP.restart();
  }

  return;
}
#endif


void reminders(unsigned long startedAt) {
  if(REMINDERS_THRESHOLD > startedAt - lastReminderTimer) {
    return; // too early
  }

  String meshJson = mesh.subConnectionJson();
  lastReminderTimer = timeTrigger;

  Serial.printf(
    "I am node #%u at %us over MESH/%s:%d@%d (%u subs, stability %d)"
    , currentNode
    , (int) startedAt/1000
    , mesh.getAPIP().toString().c_str()
    , MESH_PORT
    , MESH_CHANNEL
    , mesh.subs.size()
    , mesh.stability
  );

#ifdef HAS_STATION_CREDS
  if(FALSE == hasWlanIP) {
    Serial.print(", no WAN IP");
  }
  else {
    Serial.printf(", WAN/%s@%d (%d dBm)", wanIP.toString().c_str(), MESH_CHANNEL, WiFi.RSSI());
  }
#endif

  Serial.printf(" and %uo free HEAP", ESP.getFreeHeap());
  Serial.println();

  if(mesh.isRoot()) {
    Serial.println("I am the root node");
    mesh.sendBroadcast("Hello this is root"); // keep the mesh alive
  }
  else if(FALSE == isRootReachable) {
    Serial.printf("Root node #%u is _not_ reachable", MESH_ROOT_NODE);
    Serial.println();
  }
  else {
    Serial.printf("Root node #%u is reachable", MESH_ROOT_NODE);
    Serial.println();
  }

  Serial.printf("Current mesh is: %s", meshJson.c_str());
  Serial.println();

#ifdef HAS_STATION_CREDS
  sendHttp(timeTrigger, currentNode, DATASTRUCT_TYPOLOGY, meshJson); // keep the wifi alive
#endif
}


//
// mesh callbacks
//

void meshCallback_OnReceived(uint32_t from, String &msg) {
  const unsigned long receivedAt = millis();

  hasRootNode(from, TRUE);

#ifdef HAS_STATION_CREDS
  byte isSuccess = FALSE;
  for(int i=0; i<HTTP_NB_RETRIES; ++i) {
    isSuccess = sendHttp(receivedAt, from, DATASTRUCT_BME680, msg);
    if(isSuccess || FALSE == hasWlanIP) {
      break;
    }
  }
#else ESP32
  Serial.printf("At %us: %s message %s from #%u: \t%s", (int) receivedAt/1000, DATASTRUCT_BME680, from, msg.c_str());
  Serial.println();
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
  // Serial.printf("OnNodeTimeAdjusted at %u", offset);
  // Serial.println();
}

void meshCallback_OnNodeDelayReceived(uint32_t nodeId, int32_t delay) {
  Serial.printf("OnNodeDelayReceived from #%u at %u", nodeId, delay);
  Serial.println();
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

#ifdef HAS_STATION_CREDS
  WiFi.onEvent(&wifiCallback_OnEvent);

  // debug WiFi issues
  if(!scanWifi(MESH_CHANNEL)) {
    scanWifi(-1);
  }

  mesh.stationManual(STATION_SSID, STATION_PASSWORD);
#endif

  if(MESH_ROOT_NODE != currentNode) {
    Serial.println("I am not the root node");
    mesh.setContainsRoot(true);
  }
  else {
    Serial.println("I _am_ the root node");
    mesh.setRoot(true);
    mesh.setHostname(MESH_ROOT_HOST);
    mesh.sendBroadcast("Hello this is root speaking");
  }

#ifdef ESP32
  mesh.sendBroadcast("Hi, ESP32 starting up");
#endif
#ifdef ESP8266
  mesh.sendBroadcast("Hi, ESP8266 starting up");
#endif
}


#ifdef HAS_STATION_CREDS
byte sendHttp(unsigned long receivedAt, uint32_t from, const char* dataType, String &msg) {
  const unsigned long startedAt = millis();

  // Serial.printf("HTTP endpoint is %s on port %u", HTTP_ADDR, HTTP_PORT);
  // Serial.println();

  Serial.printf("At %us: %s message %s from #%u: \t%s", (int) receivedAt/1000, DATASTRUCT_BME680, from, msg.c_str());

  // if(MESH_ROOT_NODE != currentNode) {
  //   Serial.println("\tnot forwarded (not the root node)");
  //   return TRUE;
  // }

  if(WL_CONNECTED != WiFi.status()) {
    if(TRUE == hasWlanIP) {
      Serial.println("\tnot forwarded (lost WAN IP)");
      sprintf(outBuffer, "Message from #%u not forwarded (lost WAN IP)", from);
      mesh.sendBroadcast(outBuffer);
      ESP.restart();
    }
    Serial.println("\tnot forwarded (no WAN IP)");
    sprintf(outBuffer, "Message from #%u not forwarded (no WAN IP)", from);
    mesh.sendBroadcast(outBuffer);
    return TRUE;
  }

  digitalWrite(LED, LOW);

  encode_base64((unsigned char *) msg.c_str(), msg.length(), base64);

  StaticJsonDocument<MAX_MSG_LEN> doc;
  doc["msg"] = base64;
  serializeJson(doc, jsonBuffer);

  char userAgent[100];
  const int signalStrength = WiFi.RSSI();
  snprintf(payloadBuffer, MAX_MSG_LEN, "struct=%s&signal=%d&%s=%s", dataType, signalStrength, dataType, jsonBuffer);

  http.beginRequest();
  http.setHttpResponseTimeout(HTTP_RESPONSE_TIMEOUT);
  http.setHttpWaitForDataDelay(HTTP_WAIT_FOR_DATA_DELAY);
  http.post(HTTP_PATH); // must be set before the headers

  snprintf(userAgent, sizeof(userAgent), "%s/%u", HTTP_USERAGENT, from);
  http.sendHeader("User-Agent", userAgent);
  snprintf(userAgent, sizeof(userAgent), "Build/%s", GRIOTTE_BUILD_ID);
  http.sendHeader("User-Agent", userAgent);

  http.sendHeader("Connection", "keep-alive");
  http.sendHeader("Content-Type", "application/x-www-form-urlencoded");
  http.sendHeader("Content-Length", strlen(payloadBuffer));

  http.beginBody();
  http.print(payloadBuffer);
  http.endRequest();

  const int statusCode = http.responseStatusCode();
  const unsigned long timeSpent = millis() - startedAt;
  http.skipResponseHeaders();
  while (http.available()) {
    http.read();  // force socket cleanup & discard response
  }

  Serial.printf("\t %uo HTTP payload in %ums (%d/%d dBm): status %d", strlen(payloadBuffer), timeSpent, signalStrength, WiFi.RSSI(), statusCode);
  Serial.println();

  const byte httpSuccess = (statusCode >= 200 && statusCode <= 299);
  const byte httpFailure = (statusCode <= 0);
  if(httpSuccess) {
    // Serial.printf("\tSent %uo in %ums via %s (%d dBm): HTTP %d", strlen(payloadBuffer), timeSpent, wanIP.toString().c_str(), statusCode);
    // Serial.println();
  }
  else if (httpFailure) {
    sprintf(outBuffer, "Failed to forward %uo from #%u in %ums: error code %d", strlen(payloadBuffer), from, timeSpent, statusCode);
    mesh.sendBroadcast(outBuffer);
    Serial.printf(
      "Failed to forward %uo from #%u in %ums: error code %d",
      strlen(payloadBuffer), from, timeSpent, statusCode
    );
    Serial.println();
    Serial.print("Data was: ");
    Serial.println(payloadBuffer);
  }
  else {
    Serial.printf(
      "Failed to forward %uo from #%u in %ums: HTTP %d",
      strlen(payloadBuffer), from, timeSpent, statusCode
    );
    Serial.println();
  }

  // Serial.printf("Free HEAP after meshCallback_OnReceived: %u", ESP.getFreeHeap());
  // Serial.println();
  digitalWrite(LED, HIGH);
  return httpSuccess;
}

byte scanWifi(int desiredChannel) {
  const unsigned long receivedAt = millis();
  unsigned int nbNetworks;
  int foundChannel = -1;

  if(-1 == desiredChannel) {
    Serial.print("Starting WiFi scan on all channels...");
    nbNetworks = WiFi.scanNetworks(false, true, false, WIFI_SCAN_MAX_MS_PER_CHAN);
  }
  else {
    Serial.printf("Starting WiFi scan on channel %d...", desiredChannel);
    nbNetworks = WiFi.scanNetworks(false, true, false, WIFI_SCAN_MAX_MS_PER_CHAN, desiredChannel);
  }

  if(0 == nbNetworks) {
    Serial.printf("\tNo networks in range in %ums", millis() - receivedAt);
    Serial.println();
    return false;
  }

  Serial.println();
  for(unsigned int i = 0; i < nbNetworks; ++i) {
    Serial.printf("Network %s on channel %d with BSSID=%s", WiFi.SSID(i).c_str(), WiFi.channel(i), WiFi.BSSIDstr(i).c_str());
    Serial.println();
    if(0 == strcmp(STATION_SSID, WiFi.SSID(i).c_str()) && (-1 == desiredChannel || WiFi.channel(i) && desiredChannel)) {
      foundChannel = i;
      break;
    }
  }

  if(-1 != foundChannel) {
    Serial.printf("Found network %s on channel %d with BSSID=%s", WiFi.SSID(foundChannel).c_str(), WiFi.channel(foundChannel), WiFi.BSSIDstr(foundChannel).c_str());
    Serial.println();
  }
  else if(-1 == desiredChannel) {
    Serial.printf("Network %s NOT FOUND out of %u networks", STATION_SSID, nbNetworks);
    Serial.println();
  }
  else {
    Serial.printf("Network %s NOT FOUND on channel %d out of %u networks", STATION_SSID, desiredChannel, nbNetworks);
    Serial.println();
  }

  Serial.printf("WiFi scan ended in %ums", millis() - receivedAt);
  Serial.println();
  return (-1 != foundChannel);
}

/**
 * Events list from
 * https://github.com/espressif/arduino-esp32/blob/master/libraries/WiFi/examples/WiFiClientEvents/WiFiClientEvents.ino
 */
void wifiCallback_OnEvent(WiFiEvent_t event) {
  const int signalStrength = WiFi.RSSI();
  if(signalStrength > 0) {
    Serial.print("WiFi: signal strength is ");
    Serial.println(WiFi.RSSI());
  }

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
      wanIP = mesh.getStationIP();
      Serial.printf("WiFi STA: Obtained WAN IP address %s (%d dBm) at %us", wanIP.toString().c_str(), WiFi.RSSI(), (long) millis()/1000);
      Serial.println();
      hasWlanIP = TRUE;
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
    "%s;%s;%s;%s;%s;%s;%u;%u;%s;%u;%u;%s"
    , mPressureBuffer, mHumidityBuffer, temperatureBuffer, mIaqBuffer, mCo2Buffer, mVocBuffer, iaqSensor.iaqAccuracy
    , ESP.getFreeHeap()
    , mesh.getAPIP().toString().c_str(), mesh.subs.size(), mesh.stability
    , mTimeBuffer
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
      lastReadData = startedAt;
      sensorFailCount = 0;

      formatOutputMsg(startedAt); // sets outBuffer

      mesh.sendBroadcast(outBuffer); // this is important: the entire mesh receives the sensor readings
      Serial.println(outBuffer);
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
        sprintf(outBuffer, "Sensor unresponsive. Consider rebooting?");
        Serial.println(outBuffer);
        // delay(500);
        // ESP.restart();  // or soft-reset just the sensor if possible
      }
    }
  }
}
#endif
