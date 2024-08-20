#include "painlessMesh.h"

#define   MESH_PREFIX   "Griotte"
#define   MESH_PASSWORD "missing penalize vista tag alumina"
#define   MESH_PORT     5555
#define   MESH_CONNECT  WIFI_AP_STA
#define   MESH_CHANNEL  1
#define   MESH_HIDDEN   0
#define   MESH_MAXCONN  4

painlessMesh mesh;

void receivedCallback(uint32_t from, String &msg) {
  Serial.printf("Received from %u msg=%s\n", from, msg.c_str());
}

void newConnectionCallback(uint32_t nodeId) {
  Serial.printf("New connection %u\n", nodeId);
}

void changedConnectionCallback() {
  Serial.printf("Changed connection\n");
}


void setup() {
  Serial.begin(115200);
  mesh.setDebugMsgTypes(ERROR | MESH_STATUS | CONNECTION | SYNC | COMMUNICATION | GENERAL | MSG_TYPES | REMOTE | DEBUG);
  mesh.init(MESH_PREFIX, MESH_PASSWORD, MESH_PORT, MESH_CONNECT, MESH_CHANNEL, MESH_HIDDEN, MESH_MAXCONN);
  mesh.onReceive(&receivedCallback);
  mesh.onNewConnection(&newConnectionCallback);
  mesh.onChangedConnections(&changedConnectionCallback);
  Serial.println("Mesh network initialized");
}

void loop() {
  mesh.update();
  static uint32_t lastMillis = 0;
  if((millis() - lastMillis) > 5000) {
    Serial.println("Sending message...");
    if(!mesh.sendBroadcast("Test message lorem ipsum")) {
      Serial.println("msg failure");
    }
    lastMillis = millis();
  }
}
