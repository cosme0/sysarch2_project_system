/**
 * AMDPMS 5-MEDICINE DISPENSER
 * ----------------------------------------------------------------------------
 * Drives 5 servos through a PCA9685 I2C PWM driver board (servos on channels
 * 0..4), with I2C watchdog timeout protection so a servo power surge or a
 * faulty SDA/SCL can NEVER lock up the whole controller. On an I2C fault the
 * firmware reports DISPENSE FAIL and keeps running instead of hanging forever
 * (which previously made the website show a silent timeout).
 *
 * Protocol (unchanged — the Node bridge and PHP app need no changes):
 *   RX  "D1".."D5"   -> dispense one unit from channel 1..5
 *   RX  "BUZZ"       -> play the "done" buzzes
 *   TX  "DONE CHx"   -> successful dispense
 *   TX  "DISPENSE FAIL CHx" -> out of stock / channel error / I2C fault
 *   TX  "ERROR ..."  -> malformed command
 *
 * Wiring:
 *   PCA9685 SDA  -> Arduino A4
 *   PCA9685 SCL  -> Arduino A5
 *   PCA9685 VCC  -> 5V
 *   PCA9685 V+   -> 5V servo supply (external supply + shared GND recommended)
 *   PCA9685 OE   -> GND (outputs enabled)
 *   PCA9685 GND  -> Arduino GND (shared ground, REQUIRED)
 *   Servos       -> PCA9685 channels 0,1,2,3,4
 *   Buzzer       -> Arduino pin 13
 *   Status LEDs  -> Arduino pins 2,4,7,8,12
 */

#include <Wire.h>
#include <Adafruit_PWMServoDriver.h>

Adafruit_PWMServoDriver pwm(0x40);

const byte SERVO_CHANNELS[5] = {0, 1, 2, 3, 4};
const byte LED_PINS[5] = {2, 4, 7, 8, 12};
const byte BUZZER_PIN = 13;

const byte CLOSED_POSITION = 0;
const byte OPEN_POSITION = 90;

const byte LOW_STOCK_LIMIT = 30;
const unsigned long LOW_BLINK_INTERVAL_MS = 500;

const byte DONE_BUZZ_COUNT = 3;
const unsigned long DONE_BUZZ_MS = 400;

// Dispense timing for a single unit (open -> rest -> close).
const unsigned long OPEN_TIME_MS = 800;
const unsigned long RETURN_TIME_MS = 500;

char commandBuffer[40];
byte commandLength = 0;
int stock[5] = {402, 99, 40, 100, 208};
unsigned long lastBlinkTime = 0;
bool blinkState = false;

void handleCommand(char *command, byte length);
void updateStatusLights();
void playDoneBuzzes();
void dispenseChannel(byte index);
bool i2cHealthy();
bool setStockFromString(char *p);

void setup() {
  Serial.begin(9600);
  delay(50);

  // I2C watchdog: if a transaction takes longer than this the Wire library
  // aborts it and returns an error instead of hanging the CPU forever.
  Wire.setWireTimeout(20000, true);
  Wire.begin();

  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);

  pwm.begin();
  pwm.setPWMFreq(50);
  delay(10);

  for (byte i = 0; i < 5; i++) {
    pinMode(LED_PINS[i], OUTPUT);
    pwm.setPWM(SERVO_CHANNELS[i], 0, angleToPulse(CLOSED_POSITION));
    delay(150);
  }

  updateStatusLights();
  Serial.println(F("AMDPMS 5-MEDICINE DISPENSER"));
  Serial.println(F("READY"));
}

void loop() {
  if (millis() - lastBlinkTime >= LOW_BLINK_INTERVAL_MS) {
    lastBlinkTime = millis();
    blinkState = !blinkState;
    updateStatusLights();
  }

  while (Serial.available() > 0) {
    char received = (char)Serial.read();

    if (received == '\n' || received == '\r') {
      if (commandLength > 0) {
        handleCommand(commandBuffer, commandLength);
        commandLength = 0;
        commandBuffer[0] = '\0';
      }
    } else if (commandLength < sizeof(commandBuffer) - 1) {
      commandBuffer[commandLength++] = received;
      commandBuffer[commandLength] = '\0';
    } else {
      commandLength = 0;
      commandBuffer[0] = '\0';
      Serial.println(F("ERROR COMMAND TOO LONG"));
    }
  }
}

uint16_t angleToPulse(byte angle) {
  angle = constrain(angle, 0, 180);
  return map(angle, 0, 180, 150, 600);
}

// Parse "s1,s2,s3,s4,s5" (no leading comma) after "SETSTOCK". Returns true on
// success. A missing/invalid value leaves that channel unchanged.
bool setStockFromString(char *p) {
  byte channel = 0;
  while (*p != '\0' && channel < 5) {
    while (*p == ',' || *p == ' ') { p++; }
    if (*p < '0' || *p > '9') {
      return false;
    }
    long v = 0;
    while (*p >= '0' && *p <= '9') {
      v = v * 10 + (*p - '0');
      p++;
    }
    stock[channel] = (int)(v > 32767 ? 32767 : v);
    channel++;
  }
  // Require exactly five values for a full sync.
  while (*p == ',' || *p == ' ') { p++; }
  return channel >= 5 && *p == '\0';
}

void handleCommand(char *command, byte length) {
  for (byte i = 0; i < length; i++) {
    if (command[i] >= 'a' && command[i] <= 'z') {
      command[i] -= 32;
    }
  }
  command[length] = '\0';

  if (length == 4 && command[0] == 'B' && command[1] == 'U' &&
      command[2] == 'Z' && command[3] == 'Z') {
    playDoneBuzzes();
    Serial.println(F("DONE BUZZ"));
    return;
  }

  // SETSTOCK s1,s2,s3,s4,s5  -> sync the internal stock counters (and LEDs)
  // from the web database so the status LEDs always reflect reality.
  if (length >= 9 && command[0] == 'S' && command[1] == 'E' &&
      command[2] == 'T' && command[3] == 'S' && command[4] == 'T' &&
      command[5] == 'O' && command[6] == 'C' && command[7] == 'K') {

    if (setStockFromString(command + 8)) {
      updateStatusLights();
      Serial.println(F("DONE STOCK"));
    } else {
      Serial.println(F("ERROR STOCK FORMAT"));
    }
    return;
  }

  if (length != 2 || command[0] != 'D' ||
      command[1] < '1' || command[1] > '5') {
    Serial.println(F("ERROR USE D1-D5"));
    return;
  }

  dispenseChannel(command[1] - '1');
}

// True if the most recent I2C transaction completed without tripping the
// watchdog timeout. Wire.getWireTimeoutFlag() is set when a timeout occurred.
bool i2cHealthy() {
  if (Wire.getWireTimeoutFlag()) {
    Wire.clearWireTimeoutFlag();
    return false;
  }
  return true;
}

void dispenseChannel(byte index) {
  if (index > 4) {
    return;
  }

  if (stock[index] <= 0) {
    Serial.print(F("DISPENSE FAIL CH"));
    Serial.println(index + 1);
    return;
  }

  bool lastDispense = (stock[index] == 1);
  byte pcaChannel = SERVO_CHANNELS[index];

  if (!i2cHealthy()) {
    Serial.print(F("DISPENSE FAIL CH"));
    Serial.println(index + 1);
    Serial.println(F("ERROR I2C TIMEOUT"));
    return;
  }

  // Open the hatch.
  pwm.setPWM(pcaChannel, 0, angleToPulse(OPEN_POSITION));
  if (!i2cHealthy()) {
    Serial.print(F("DISPENSE FAIL CH"));
    Serial.println(index + 1);
    Serial.println(F("ERROR I2C TIMEOUT"));
    return;
  }
  delay(OPEN_TIME_MS);

  // Close the hatch.
  pwm.setPWM(pcaChannel, 0, angleToPulse(CLOSED_POSITION));
  if (!i2cHealthy()) {
    Serial.print(F("DISPENSE FAIL CH"));
    Serial.println(index + 1);
    Serial.println(F("ERROR I2C TIMEOUT"));
    return;
  }
  delay(RETURN_TIME_MS);

  stock[index]--;
  updateStatusLights();

  if (lastDispense) {
    playDoneBuzzes();
  }

  Serial.print(F("DONE CH"));
  Serial.println(index + 1);
}

void playDoneBuzzes() {
  for (byte i = 0; i < DONE_BUZZ_COUNT; i++) {
    tone(BUZZER_PIN, 2200);
    delay(DONE_BUZZ_MS);
    noTone(BUZZER_PIN);
    delay(200);
  }
}

void updateStatusLights() {
  for (byte i = 0; i < 5; i++) {
    if (stock[i] == 0) {
      digitalWrite(LED_PINS[i], LOW);
    } else if (stock[i] <= LOW_STOCK_LIMIT) {
      digitalWrite(LED_PINS[i], blinkState ? HIGH : LOW);
    } else {
      digitalWrite(LED_PINS[i], HIGH);
    }
  }
}
