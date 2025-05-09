#define HAS_GRIOTTE_BUILD_ID
const char GRIOTTE_BUILD_ID[] = "This is build 2025-05-08 12:51";

#define HAS_MESH_CREDS
const char MESH_PREFIX[] = "mesh_ssid";
const char MESH_PASSWORD[] = "mesh_passwd";
const int MESH_PORT = 5555;
const int MESH_CHANNEL = 11;
const int MESH_HIDDEN = 1;
const int MESH_MAXCONN = 100;
const int MESH_ROOT_NODE = 1002444205;  // esp32-c3
const char MESH_ROOT_HOST[] = "root.griotte.home";


#ifdef ESP32
#define HAS_STATION_CREDS
const char STATION_SSID[] = "home_ssid";
const char STATION_PASSWORD[] = "home_passwd";

#define HAS_HTTP_CREDS
const char HTTP_ADDR[] = "192.168.1.1"; // raspberrypi zero
const int  HTTP_PORT = 8080;
const char HTTP_PATH[] = "/griotte/";
const char HTTP_METHOD[] = "POST";
const char HTTP_USERAGENT[] = "Griotte";
#endif
