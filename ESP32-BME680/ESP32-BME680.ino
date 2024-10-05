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

const String MESH_PREFIX   = "mesh_ssid";
const String MESH_PASSWORD = "mesh_passwd";
const int MESH_PORT    = 5555;
const int MESH_CHANNEL = 2;
const int MESH_HIDDEN  = 1;
const int MESH_MAXCONN = 10;
const int    MESH_ROOT_NODE = 1002444205;  // esp32-c3
const String MESH_ROOT_HOST = "root.griotte.home";

#if defined(ESP32)
const String STATION_SSID = "home_ssid";
const String STATION_PASSWORD = "home_passwd";

const String HTTP_HOST      = "pihole-gr";
const int    HTTP_PORT      = 8080;
const String HTTP_PATH      = "/griotte/";
const String HTTP_METHOD    = "POST";
const String HTTP_USERAGENT = "Griotte";

const int LED = 8;
#endif


String output;
char outBuffer[100];
unsigned char base64[256];
unsigned int base64_length;
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
  mesh.setDebugMsgTypes(ERROR | CONNECTION | REMOTE | DEBUG);
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

    if (iaqSensor.run(timeTrigger)) { // If new data is available

      if(0 == iaqSensor.iaqAccuracy) {
        sprintf(
          outBuffer,
          "Calibrating the sensor for%ss...",
          dtostrf(timeTrigger/1000, 4, 0, mTimeBuffer)
        );
        //mesh.sendBroadcast(outBuffer);
      }
      else {
        sprintf(
          outBuffer,
          "%s hPa;%s%% (humidity);%s °C; %s IAQ;%s ppm (eCO2); %s VOC",
          String(dtostrf(iaqSensor.pressure, 6, 0, mPressureBuffer)),
          String(dtostrf(iaqSensor.humidity, 3, 0, mHumidityBuffer)),
          String(dtostrf(iaqSensor.temperature, 3, 0, temperatureBuffer)),
          String(dtostrf(iaqSensor.staticIaq, 4, 1, mIaqBuffer)),
          String(dtostrf(iaqSensor.co2Equivalent, 5, 0, mCo2Buffer)),
          String(dtostrf(iaqSensor.breathVocEquivalent, 3, 2, mVocBuffer))
        );

        if(MESH_ROOT_NODE != currentNode) {
          mesh.sendBroadcast(outBuffer);
        }

      }

      Serial.println(outBuffer);
    }
  }
#endif

}


#if defined(ESP8266)
byte detectIaqSensor(void) {
  byte error, address;

  address = 0x76;
  Wire.beginTransmission(address);
  error = Wire.endTransmission();
  if(0 != error) {
    address = 0x77;
    Wire.beginTransmission(address);
    error = Wire.endTransmission();
    if(0 != error) {
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
      output = "BSEC error code : " + String(iaqSensor.status);
      Serial.println(output);
      errLeds(output); /* Halt in case of failure */
    } else {
      output = "BSEC warning code : " + String(iaqSensor.status);
      Serial.println(output);
    }
  }

  if (iaqSensor.bme680Status != BME680_OK) {
    if (iaqSensor.bme680Status < BME680_OK) {
      output = "BME680 error code : " + String(iaqSensor.bme680Status);
      Serial.println(output);
      errLeds(output); /* Halt in case of failure */
    } else {
      output = "BME680 warning code : " + String(iaqSensor.bme680Status);
      Serial.println(output);
    }
  }
}
#endif

void errLeds(String &errmsg)
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

  Serial.printf("Received from #%u: %s\n", from, msg.c_str());

#if defined(ESP32)
  if(MESH_ROOT_NODE == currentNode && getlocalIP() != myIP) {
    myIP = getlocalIP();
    Serial.println("My IP is now: " + myIP.toString());
  }

  if(MESH_ROOT_NODE == currentNode && myIP.toString() != "0.0.0.0") {
    digitalWrite(LED, LOW);

    base64_length = encode_base64((unsigned char *) msg.c_str(), msg.length(), base64);

    String payload = "";
    StaticJsonDocument<256> doc;
    doc["msg"] = base64;
    serializeJson(doc, payload);

    //serializeJson(doc, Serial);
    //Serial.println();
    //Serial.printf("Dbg: size=%u, payload=%s\n", payload.length(), payload.c_str());

    char payloadBuffer[200];
    sprintf(payloadBuffer, "data=%s&uptime=%u", payload.c_str(), receivedAt);
    payload = String(payloadBuffer);

    char userAgent[100];
    sprintf(userAgent, "%s/%u", HTTP_USERAGENT, from);

    HttpClient http = HttpClient(wifi, HTTP_HOST.c_str(), HTTP_PORT);

    http.setHttpResponseTimeout((int) 100);
    http.setHttpWaitForDataDelay((int) 200);
    http.noDefaultRequestHeaders();

    http.beginRequest();
    http.post(HTTP_PATH);
    http.sendHeader("User-Agent", userAgent);
    http.sendHeader("Connection", "close");
    http.sendHeader("Content-Type", "application/x-www-form-urlencoded");
    http.sendHeader("Content-Length", payload.length());
    http.beginBody();
    http.print(payload);
    http.endRequest();

/*
    Serial.printf(
      "Forwarded readings (%so) from #%u in %dms\n",
      String(payload.length()), from, timeSpent
    );
*/

/*
    int statusCode = http.responseStatusCode();
    int timeSpent = millis() - receivedAt;

    if(200 == statusCode) {
      Serial.printf(
        "Forwarded readings (%so) from #%u in %dms\n",
        String(payload.length()), from, timeSpent
      );
    }
    else {
      Serial.printf(
        "Failed (probably) to forward data from #%u in %dms: status %s\n",
        from, timeSpent, String(statusCode)
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
  Serial.printf("Changed mesh connections; new topology is: %s\n", mesh.subConnectionJson().c_str());
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
