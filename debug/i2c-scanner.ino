#include <Wire.h>

void setup() {
  Wire.begin();
  Serial.begin(115200);
  while (!Serial);
  Serial.println("\nSerial scanner");
}

void loop() {
  byte error, address;
  int nbDevices = 0;

  Serial.println("\nScanning...");

  for(address = 1; address < 127; address++) {
    Wire.beginTransmission(address);
    error = Wire.endTransmission();
    if(error == 0) {
      nbDevices++;
      Serial.print("I2C device found");
      Serial.print(" at address 0x");
      if(address < 16) {
        Serial.print("0");
      }
      Serial.print(address, HEX);
      Serial.print("\n");
    }
    else if(error == 1) {
      Serial.print("Data too long");
      Serial.print(" at address 0x");
      if(address < 16) {
        Serial.print("0");
      }
      Serial.print(address, HEX);
      Serial.print("\n");
    }
    /*
    else if(error == 2) {
      Serial.print("NACK on Address");
      Serial.print(" at address 0x");
      if(address < 16) {
        Serial.print("0");
      }
      Serial.print(address, HEX);
      Serial.print("\n");
      }
    */
    else if(error == 3) {
      Serial.print("NACK on Data");
      Serial.print(" at address 0x");
      if(address < 16) {
        Serial.print("0");
      }
      Serial.print(address, HEX);
      Serial.print("\n");
    }
    else if(error >= 4) {
      Serial.print("Unknown error ");
      Serial.print(error, HEX);
      Serial.print(" at address 0x");
      if(address < 16) {
        Serial.print("0");
      }
      Serial.print(address, HEX);
      Serial.print("\n");
    }

  }

  if(nbDevices == 0) {
    Serial.println("No devices found");
  }
  else {
    Serial.println("Done");
  }

  delay(5000);
}
