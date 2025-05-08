#include "painlessMesh.h"

#if defined(ESP8266)
#include "bsec.h"
#include <Wire.h>
#endif

#if defined(ESP32)
#include "base64.hpp"
#include <wifi.h>
#include <ArduinoHttpClient.h>
#endif

const String BUILD_ID      = "This is build LOREM IPSUM";
const String MESH_PREFIX   = "mesh_ssid";
const String MESH_PASSWORD = "mesh_passwd";
const int MESH_PORT    = 5555;
const int MESH_CHANNEL = 2;
const int MESH_HIDDEN  = 1;
const int MESH_MAXCONN = 100;
const int MESH_ROOT_NODE = 1002444205;  // esp32-c3
const String MESH_ROOT_HOST = "root.griotte.home";

#if defined(ESP32)
const String STATION_SSID = "home_ssid";
const String STATION_PASSWORD = "home_passwd";

const char   HTTP_ADDR[]    = "192.168.1.1";
const int    HTTP_PORT      = 8080;
const String HTTP_PATH      = "/griotte/";
const String HTTP_METHOD    = "POST";
const String HTTP_USERAGENT = "Griotte";

const int LED = 8;
#endif


char outBuffer[100];
unsigned char base64[256];
byte iaqAddress = 0; // address 0 is n/a

void errLeds(void);


#if defined(ESP8266)
Bsec iaqSensor;

char mTimeBuffer[10];
char mPressureBuffer[10];
char mHumidityBuffer[10];
char temperatureBuffer[10];
char mIaqBuffer[10];
char mCo2Buffer[10];
char mVocBuffer[10];
char jsonBuffer[300];
char payloadBuffer[200];

byte detectIaqSensor(void);
void checkIaqSensorStatus(void);
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


#if defined(ESP32)
WiFiClient wifi;

IPAddress getlocalIP();
IPAddress myIP(0,0,0,0);
#endif


void setup(void)
{
  Serial.begin(115200);
  while (!Serial);
  Serial.println();
  Serial.println("Hi!");
  Serial.println(BUILD_ID);

#if defined(ESP32)
  Serial.println("This is ESP32");
  pinMode(LED, OUTPUT);
  digitalWrite(LED, HIGH);
#endif
#if defined(ESP8266)
  Serial.println("This is ESP8266");
  Wire.begin();
#endif


#if defined(ESP8266)
  // detect the IAQ sensor
  iaqAddress = detectIaqSensor();

  if(0 != iaqAddress) {
    // set up the IAQ sensor

    iaqSensor.begin(iaqAddress, Wire);

    sprintf(
      outBuffer,
      "BSEC library version %d.%d.%d.%d",
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

  // ERROR | MESH_STATUS | CONNECTION | SYNC | COMMUNICATION | GENERAL | MSG_TYPES | REMOTE | DEBUG
  // ERROR | MESH_STATUS | REMOTE | DEBUG
  mesh.setDebugMsgTypes(ERROR | REMOTE | DEBUG);
  // mesh.setDebugMsgTypes(ERROR | MESH_STATUS | CONNECTION | SYNC | COMMUNICATION | GENERAL | MSG_TYPES | REMOTE | DEBUG);
  mesh.init(MESH_PREFIX, MESH_PASSWORD, (uint16_t) MESH_PORT, WIFI_AP_STA, (uint8_t) MESH_CHANNEL, (uint8_t) MESH_HIDDEN, (uint8_t) MESH_MAXCONN);
  mesh.onReceive(&onReceivedCallback);
  mesh.onNewConnection(&onNewConnectionCallback);
  mesh.onDroppedConnection(&onDroppedConnectionCallback);
  mesh.onChangedConnections(&onChangedConnectionsCallback);
  mesh.onNodeTimeAdjusted(&onNodeTimeAdjustedCallback);
  mesh.onNodeDelayReceived(&onNodeDelayReceived);

  currentNode = mesh.getNodeId();
  Serial.printf("I am node #%u\n", currentNode);

#if defined(ESP32)
  mesh.sendBroadcast("Hi, ESP32 starting up");
#endif
#if defined(ESP8266)
  mesh.sendBroadcast("Hi, ESP8266 starting up");
#endif

#if defined(ESP8266)
  mesh.setContainsRoot(true);
#endif

#if defined(ESP32)
  if(currentNode != MESH_ROOT_NODE) {
    Serial.println("I am not the root node");
    mesh.setContainsRoot(true);
  }
  else {
    Serial.println("I am the root node");
    mesh.setRoot(true);
    mesh.stationManual(STATION_SSID, STATION_PASSWORD);
    mesh.setHostname(MESH_ROOT_HOST.c_str());
    //mesh.sendBroadcast("Root node is now: "+currentNode);
  }
#endif
}


void loop(void)
{
  timeTrigger = millis();
  mesh.update();

#if defined(ESP8266)
  if(0 != iaqAddress) {
    checkIaqSensorStatus();

    if(iaqSensor.run(timeTrigger)) { // If new data is available
      Serial.printf("[Heap before run] Free: %u\n", ESP.getFreeHeap());

      lastReadData = timeTrigger;
      sensorFailCount = 0;

      snprintf(mPressureBuffer, sizeof(mPressureBuffer), "%.0f", iaqSensor.pressure);
      snprintf(mHumidityBuffer, sizeof(mHumidityBuffer), "%.0f", iaqSensor.humidity);
      snprintf(temperatureBuffer, sizeof(temperatureBuffer), "%.0f", iaqSensor.temperature);

      if(0 != iaqSensor.iaqAccuracy) {
        snprintf(mIaqBuffer, sizeof(mIaqBuffer), "%.1f", iaqSensor.staticIaq);
        snprintf(mCo2Buffer, sizeof(mCo2Buffer), "%.0f", iaqSensor.co2Equivalent);
        snprintf(mVocBuffer, sizeof(mVocBuffer), "%.2f", iaqSensor.breathVocEquivalent);
      }
      else {
        snprintf(mTimeBuffer, sizeof(mTimeBuffer), "%.0f", timeTrigger/1000);

        sprintf(
          outBuffer,
          "Calibrating the sensor for %ss...",
          mTimeBuffer
        );

        Serial.println(outBuffer);
        //mesh.sendBroadcast(outBuffer);
        snprintf(mIaqBuffer, sizeof(mIaqBuffer), "%.1f", 0.0);
        snprintf(mCo2Buffer, sizeof(mCo2Buffer), "%.0f", 0.0);
        snprintf(mVocBuffer, sizeof(mVocBuffer), "%.2f", 0.0);
      }

      sprintf(
        outBuffer,
        "%s hPa;%s%% (humidity);%s °C; %s IAQ;%s ppm (eCO2); %s VOC; %d iAQ accuracy",
        mPressureBuffer, mHumidityBuffer, temperatureBuffer, mIaqBuffer, mCo2Buffer, mVocBuffer,
        iaqSensor.iaqAccuracy
      );

      if(MESH_ROOT_NODE != currentNode) {
        mesh.sendBroadcast(outBuffer);
      }

      Serial.println(outBuffer);
      Serial.printf("[Heap after run] Free: %u\n", ESP.getFreeHeap());
    }
    else if(timeTrigger - lastReadData < 3*1100) {
      // never mind, sensor has values about every 3 seconds
    }
    else if(0 == lastReadData && timeTrigger < 5*60*1100) {
      // never mind, calibrating probably
    }
    else if(0 == lastReadData) {
      snprintf(mTimeBuffer, sizeof(mTimeBuffer), "%.0f", timeTrigger/1000);
      sprintf(outBuffer, "No data for %ss...", mTimeBuffer);
      Serial.println(outBuffer);
    }
    else if(timeTrigger < 50*1000) {
      // never mind, give it more time?
    }
    else {
      sensorFailCount++;
      Serial.printf("Sensor read failed %d times in a row\n", sensorFailCount);
      if(SENSOR_FAIL_THRESHOLD < sensorFailCount) {
        sprintf(outBuffer, "Sensor unresponsive. Rebooting...");
        Serial.println(outBuffer);
        // delay(500);
        ESP.restart();  // or soft-reset just the sensor if possible
      }
    }
  }
#endif

}


#if defined(ESP8266)
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
      sprintf(outBuffer, "BSEC error code : %d", iaqSensor.status);
      Serial.println(outBuffer);
      errLeds(outBuffer); /* Halt in case of failure */
    } else {
      sprintf(outBuffer, "BSEC warning code : %d", iaqSensor.status);
      Serial.println(outBuffer);
    }
  }

  if (iaqSensor.bme680Status != BME680_OK) {
    if (iaqSensor.bme680Status < BME680_OK) {
      sprintf(outBuffer, "BME680 error code : %d", iaqSensor.bme680Status);
      Serial.println(outBuffer);
      errLeds(outBuffer); /* Halt in case of failure */
    } else {
      sprintf(outBuffer, "BME680 warning code : %d", iaqSensor.bme680Status);
      Serial.println(outBuffer);
    }
  }
}
#endif

void errLeds(char *errmsg)
{
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

  // Serial.printf("Received from #%u: %s\n", from, msg.c_str());

#if defined(ESP32)
  if(MESH_ROOT_NODE == currentNode && getlocalIP() != myIP) {
    myIP = getlocalIP();
    Serial.println("My IP is now: " + myIP.toString());
  }

  if(MESH_ROOT_NODE == currentNode && myIP.toString() != "0.0.0.0") {
    digitalWrite(LED, LOW);

    encode_base64((unsigned char *) msg.c_str(), msg.length(), base64);

    StaticJsonDocument<256> doc;
    doc["msg"] = base64;
    serializeJson(doc, jsonBuffer);

    sprintf(payloadBuffer, "data=%s&uptime=%u", jsonBuffer, receivedAt);

    Serial.println();
    Serial.printf("Dbg: size=%u, payload=%s\n", strlen(payloadBuffer), payloadBuffer);

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
        "Forwarded readings (%do) from #%u in %dms\n",
        strlen(payloadBuffer), from, timeSpent
      );
    }
    else {
      Serial.printf(
        "Failed (probably) to forward data from #%u in %dms: status %d\n",
        from, timeSpent, statusCode
      );
    }
*/

    digitalWrite(LED, HIGH);
  }
#endif
}

void onNewConnectionCallback(uint32_t nodeId) {
  Serial.printf("New mesh connection with #%u\n", nodeId);
}

void onDroppedConnectionCallback(uint32_t nodeId) {
  Serial.printf("Dropped mesh connection from #%u\n", nodeId);
}

void onChangedConnectionsCallback() {
  // Serial.printf("Changed mesh connections; new topology is: %s\n", mesh.subConnectionJson().c_str());
}

void onNodeTimeAdjustedCallback(int32_t offset) {
}

void onNodeDelayReceived(uint32_t nodeId, int32_t delay) {
}

#if defined(ESP32)
IPAddress getlocalIP() {
  // IPAddress(mesh.getAPIP());
  return IPAddress(mesh.getStationIP());
}
#endif
