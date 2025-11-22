#ifndef GRIOTTE_CREDS_H
#define GRIOTTE_CREDS_H

#define HAS_MESH_CREDS
const char MESH_PREFIX[] = "...";
const char MESH_PASSWORD[] = "...";
const int MESH_PORT = 5555;
const int MESH_CHANNEL = 11;
const int MESH_HIDDEN = 1;
const int MESH_MAXCONN = 100;
const int MESH_ROOT_NODE = 1002444205; // esp32-c3
const char MESH_ROOT_HOST[] = "bridge.griot.local";


#ifdef ESP32
#define HAS_STATION_CREDS
const char STATION_SSID[] = "...";
const char STATION_PASSWORD[] = "...";
const unsigned int WIFI_SCAN_MAX_MS_PER_CHAN = 500;

#define HAS_HTTP_CREDS
const char HTTP_ADDR[] = "192.168.1.15"; // raspberrypi zero
const int  HTTP_PORT = 8081;
const char HTTP_PATH[] = "/";
const char HTTP_METHOD[] = "POST";
const char HTTP_USERAGENT[] = "Griotte";
#endif // ESP32

#endif // GRIOTTE_CREDS_H
