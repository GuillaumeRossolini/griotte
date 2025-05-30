#include "griotte_creds_mar.h"
#include "painlessMesh.h"

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
char outBuffer[100];
unsigned char base64[256];
byte iaqAddress = 0; // address 0 is n/a
byte isRootReachable = FALSE;
unsigned int nbRootUnreachable = 0;

void errLeds(void);


#ifdef ESP8266
const int LED = LED_BUILTIN;
Bsec iaqSensor;

char mTimeBuffer[10];
char mPressureBuffer[10];
char mHumidityBuffer[10];
char temperatureBuffer[10];
char mIaqBuffer[10];
char mCo2Buffer[10];
char mVocBuffer[10];

byte detectIaqSensor(void);
void checkIaqSensorStatus(void);
#endif

#ifdef ESP32
const int LED = 8;
char jsonBuffer[200];
char payloadBuffer[250];
byte foundWifiStation = FALSE;
#endif


painlessMesh mesh;

void onReceivedCallback(uint32_t, String &); // Set a callback routine for any messages that are addressed to this node.
void onNewConnectionCallback(uint32_t); // Callback that gets called every time the local node makes a new connection
void onDroppedConnectionCallback(uint32_t); // Callback that gets called every time the local node drops a connection.
void onChangedConnectionsCallback(); // Callback that gets called every time the layout of the mesh changes
void onNodeTimeAdjustedCallback(int32_t); // Callback that gets called every time node time gets adjusted
void onNodeDelayReceived(uint32_t, int32_t); // Callback that gets called when a delay measurement is received.

uint32_t currentNode;
unsigned long timeTrigger;
unsigned long lastReadData = 0;
static int sensorFailCount = 0;
static const int SENSOR_FAIL_THRESHOLD = 3;


IPAddress getMeshIP();
IPAddress myMeshIP(0,0,0,0);

#ifdef ESP32
WiFiClient wifi;

IPAddress getWanIP();
IPAddress myWanIP(0,0,0,0);
#endif


void setup(void)
{
  Serial.begin(115200);
  while (!Serial);
  Serial.println();
  Serial.println("Hi!");
  Serial.println(GRIOTTE_BUILD_ID);

  pinMode(LED, OUTPUT);
  digitalWrite(LED, HIGH);

#ifdef ESP32
  Serial.println("This is ESP32");
#endif
#ifdef ESP8266
  Serial.println("This is ESP8266");
  Wire.begin();
#endif


#ifdef ESP8266
  // detect the IAQ sensor
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

    // The IAQ scale ranges from 0 (clean air) to 500 (heavily polluted air)
    // IAQ=50 corresponds to typical good air and IAQ=200 indicates typical polluted air
  }
#endif


  // set up the mesh network

  // ERROR | MESH_STATUS | CONNECTION | SYNC | COMMUNICATION | GENERAL | MSG_TYPES | REMOTE | DEBUG | STARTUP
  // ERROR | MESH_STATUS | REMOTE | DEBUG

#ifdef ESP32
  //mesh.setDebugMsgTypes(ERROR | MESH_STATUS | CONNECTION | SYNC | COMMUNICATION | GENERAL | MSG_TYPES | REMOTE | DEBUG);
  mesh.setDebugMsgTypes(ERROR | STARTUP | CONNECTION | REMOTE | DEBUG);
#endif
#ifdef ESP8266
  //mesh.setDebugMsgTypes(ERROR | STARTUP | REMOTE | DEBUG);
#endif

  Serial.setDebugOutput(true);
  WiFi.onEvent([](WiFiEvent_t event) {
    Serial.printf("[WiFi-event] event: %d\n", event);
  });

  mesh.init(MESH_PREFIX, MESH_PASSWORD, (uint16_t) MESH_PORT, WIFI_AP_STA, (uint8_t) MESH_CHANNEL, (uint8_t) MESH_HIDDEN, (uint8_t) MESH_MAXCONN);
  mesh.onReceive(&onReceivedCallback);
  mesh.onNewConnection(&onNewConnectionCallback);
  mesh.onDroppedConnection(&onDroppedConnectionCallback);
  mesh.onChangedConnections(&onChangedConnectionsCallback);
  mesh.onNodeTimeAdjusted(&onNodeTimeAdjustedCallback);
  mesh.onNodeDelayReceived(&onNodeDelayReceived);

  currentNode = mesh.getNodeId();
  Serial.printf("I am node #%u in the mesh", currentNode);
  Serial.println();

#ifdef ESP8266
  mesh.setContainsRoot(true);
#endif

#ifdef HAS_STATION_CREDS
  if(MESH_ROOT_NODE != currentNode) {
    Serial.println("I am not the root node");
    mesh.setContainsRoot(true);
  }
  else {
    Serial.println("I am the root node");
    
    int n = WiFi.scanNetworks(false, true);  // async=false, show_hidden=true
    if(0 == n) {
      Serial.println("No networks found.");
    } else {
      // Serial.printf("%d networks found:\n", n);
      for(int i = 0; i < n; ++i) {
        if(0 == strcmp(STATION_SSID, WiFi.SSID(i).c_str()) && MESH_CHANNEL == WiFi.channel(i)) {
          foundWifiStation = TRUE;
          Serial.printf("Found network %s on channel %d with BSSID=%s", STATION_SSID, MESH_CHANNEL, WiFi.BSSIDstr(i).c_str());
          Serial.println();
        }
      }
      if(FALSE == foundWifiStation) {
        Serial.printf("Network %s NOT FOUND on channel %d", STATION_SSID, MESH_CHANNEL);
      }
    }

    mesh.setRoot(true);
    mesh.stationManual(STATION_SSID, STATION_PASSWORD);
    mesh.setHostname(MESH_ROOT_HOST);
    // WiFi.begin(STATION_SSID, STATION_PASSWORD);
    //mesh.sendBroadcast("Root node is now: "+currentNode);
  }
#endif

#ifdef ESP32
  mesh.sendBroadcast("Hi, ESP32 starting up");
#endif
#ifdef ESP8266
  mesh.sendBroadcast("Hi, ESP8266 starting up");
#endif

  Serial.println();
}

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


void loop(void)
{
  timeTrigger = millis();
  mesh.update();

#ifdef HAS_STATION_CREDS
  if(MESH_ROOT_NODE == currentNode && getWanIP() != myWanIP) {
    myWanIP = getWanIP();
    Serial.println("My WAN IP is now: " + myWanIP.toString());
  }
#endif

#ifdef ESP8266
  if(0 != iaqAddress) {
    checkIaqSensorStatus();

    if(iaqSensor.run(timeTrigger)) { // If new data is available
      digitalWrite(LED, LOW);
      // Serial.printf("[Heap before run] Free: %u", ESP.getFreeHeap());
      // Serial.println();

      lastReadData = timeTrigger;
      sensorFailCount = 0;

      snprintf(mTimeBuffer, sizeof(mTimeBuffer), "%u", timeTrigger/1000);

      snprintf(mPressureBuffer, sizeof(mPressureBuffer), "%.0f", iaqSensor.pressure);
      snprintf(mHumidityBuffer, sizeof(mHumidityBuffer), "%.0f", iaqSensor.humidity);
      snprintf(temperatureBuffer, sizeof(temperatureBuffer), "%.0f", iaqSensor.temperature);

      if(0 != iaqSensor.iaqAccuracy) {
        snprintf(mIaqBuffer, sizeof(mIaqBuffer), "%.1f", iaqSensor.staticIaq);
        snprintf(mCo2Buffer, sizeof(mCo2Buffer), "%.0f", iaqSensor.co2Equivalent);
        snprintf(mVocBuffer, sizeof(mVocBuffer), "%.2f", iaqSensor.breathVocEquivalent);
      }
      else {

        sprintf(
          outBuffer,
          "Calibrating the sensor for %ss...",
          mTimeBuffer
        );

        //Serial.println(outBuffer);
        //mesh.sendBroadcast(outBuffer);
        snprintf(mIaqBuffer, sizeof(mIaqBuffer), "%.1f", 0.0);
        snprintf(mCo2Buffer, sizeof(mCo2Buffer), "%.0f", 0.0);
        snprintf(mVocBuffer, sizeof(mVocBuffer), "%.2f", 0.0);
      }

      sprintf(
        outBuffer,
        "%s [hPa]; %s hum. [%%]; %s temp. [°C]; %s IAQ; %s eCO² [PPM]; %s VOC [PPM]; %u/3 iAQ accuracy; %u free heap [o]; %s uptime [s]",
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

      if(FALSE == isRootReachable) {
        if(0 == nbRootUnreachable++) {
          Serial.println("Root node is not reachable?");
        }
        if(5 <= nbRootUnreachable) {
          nbRootUnreachable = 0;
        }
      }

      mesh.sendBroadcast(outBuffer);
      Serial.println(outBuffer);
      digitalWrite(LED, HIGH);
      // Serial.printf("[Heap after run] Free: %u", ESP.getFreeHeap());
      // Serial.println();
    }
    else if(timeTrigger - lastReadData < 3*1100) {
      // never mind, sensor has values about every 3 seconds
    }
    else if(0 == lastReadData && timeTrigger < 5*60*1100) {
      // never mind, calibrating probably
    }
    else if(0 == lastReadData) {
      snprintf(mTimeBuffer, sizeof(mTimeBuffer), "%u", timeTrigger/1000);
      sprintf(outBuffer, "No data for %ss...", mTimeBuffer);
      Serial.println(outBuffer);
    }
    else if(timeTrigger < 50*1000) {
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
        //ESP.restart();  // or soft-reset just the sensor if possible
      }
    }
  }
#endif

}


#ifdef ESP8266
byte detectIaqSensor(void) {
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

void checkIaqSensorStatus(void)
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
#endif

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


//
// mesh callbacks
//

void onReceivedCallback(uint32_t from, String &msg) {
  int receivedAt = millis();

#ifdef ESP32
  Serial.printf("Received from #%u: %s", from, msg.c_str());
  Serial.println();

  if(MESH_ROOT_NODE == currentNode && myWanIP.toString() == "0.0.0.0") {
    //Serial.printf("I don't have an IP on the WiFi: %s", STATION_SSID);
    //Serial.println();
  }

  if(MESH_ROOT_NODE == currentNode && myWanIP.toString() != "0.0.0.0") {
    digitalWrite(LED, LOW);

    encode_base64((unsigned char *) msg.c_str(), msg.length(), base64);

    StaticJsonDocument<256> doc;
    doc["msg"] = base64;
    serializeJson(doc, jsonBuffer);

    sprintf(payloadBuffer, "data=%s&uptime=%u", jsonBuffer, receivedAt);

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
    int statusCode = http.responseStatusCode();
    int timeSpent = millis() - receivedAt;

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

    //Serial.printf("Free HEAP after onReceivedCallback: %u", ESP.getFreeHeap());
    //Serial.println();
    digitalWrite(LED, HIGH);
  }
#endif
}

void onNewConnectionCallback(uint32_t nodeId) {
  Serial.printf("New mesh connection with #%u", nodeId);
  Serial.println();
  if(MESH_ROOT_NODE == nodeId) {
    Serial.println("The root node has entered the chat");
    isRootReachable = TRUE;
  }
  else {
    // generic node
  }
}

void onDroppedConnectionCallback(uint32_t nodeId) {
  Serial.printf("Dropped mesh connection from #%u", nodeId);
  Serial.println();
  if(MESH_ROOT_NODE == nodeId) {
    Serial.println("The root node has left the chat");
    isRootReachable = FALSE;
    nbRootUnreachable = 0;
  }
  else {
    // generic node
  }
}

void onChangedConnectionsCallback() {
  Serial.printf("Changed mesh connections; new topology is: %s", mesh.subConnectionJson().c_str());
  Serial.println();
}

void onNodeTimeAdjustedCallback(int32_t offset) {
}

void onNodeDelayReceived(uint32_t nodeId, int32_t delay) {
}

IPAddress getMeshIP() {
  return IPAddress(mesh.getAPIP());
}

#ifdef ESP32
IPAddress getWanIP() {
  return IPAddress(mesh.getStationIP());
}
#endif
