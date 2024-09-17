#include "painlessMesh.h"

#define   MESH_PREFIX   "Griotte"
#define   MESH_PASSWORD "missing penalize vista tag alumina"
#define   MESH_PORT     5555
#define   MESH_CONNECT  WIFI_AP_STA
#define   MESH_CHANNEL  11
#define   MESH_HIDDEN   1
#define   MESH_MAXCONN  10

painlessMesh mesh;

void receivedCallback(uint32_t, String &);
void newConnectionCallback(uint32_t);
void changedConnectionCallback();


void setup() {
  Serial.begin(115200);
  while (!Serial);
  // ERROR | MESH_STATUS | CONNECTION | SYNC | COMMUNICATION | GENERAL | MSG_TYPES | REMOTE | DEBUG
  mesh.setDebugMsgTypes(ERROR | MESH_STATUS | SYNC | REMOTE | DEBUG);
  mesh.init(MESH_PREFIX, MESH_PASSWORD, (uint16_t) MESH_PORT, MESH_CONNECT, (uint8_t) MESH_CHANNEL, (uint8_t) MESH_HIDDEN, (uint8_t) MESH_MAXCONN);
  mesh.onReceive(&receivedCallback);
  mesh.onNewConnection(&newConnectionCallback);
  mesh.onChangedConnections(&changedConnectionCallback);
  //Serial.println("Mesh network initialized");
}

void loop() {
  mesh.update();
}


void receivedCallback(uint32_t from, String &msg) {
  Serial.printf("Received from #%u: %s\n", from, msg.c_str());
}

void newConnectionCallback(uint32_t nodeId) {
  //Serial.printf("New connectio:n %u\n", nodeId);
}

void changedConnectionCallback() {
  //Serial.printf("Changed connection\n");
}
