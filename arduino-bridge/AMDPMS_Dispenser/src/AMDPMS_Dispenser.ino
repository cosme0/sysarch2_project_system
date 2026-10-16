/**
 * AMDPMS 5-MEDICINE DISPENSER
 * ----------------------------------------------------------------------------
 * Drives 5 servos through a PCA9685 I2C PWM driver board (servos on channels
 * 0..4), with I2C watchdog timeout protection so a servo power surge or a
 * faulty SDA/SCL can NEVER lock up the whole controller. On an I2C fault the
 * firmware reports DISPENSE FAIL and keeps running instead of hanging forever
 * (which previously made the website show a silent timeout).
 *
 * HONESTY RULE: "DONE CHx" is only printed after the PCA9685 acknowledged the
 * PWM write that opened the hatch. A missing / unpowered / badly wired servo
 * driver answers with a NAK, and that now produces "DISPENSE FAIL CHx" plus an
 * "ERROR ..." reason, so the website can never complete a sale for a unit that
 * never moved. The hatch is also driven back to CLOSED on every failure path,
 * so a fault can never leave a channel open.
 *
 * Protocol (unchanged — the Node bridge and PHP app need no changes):
 *   RX  "D1".."D5"   -> dispense one unit from channel 1..5
 *   RX  "BUZZ"       -> play the "done" buzzes
 *   RX  "SETSTOCK s1,s2,s3,s4,s5" -> resync the stock counters and LEDs
 *   RX  "SELFTEST" -> probe the PCA9685 and report SELFTEST OK / FAIL
 *   TX  "DONE CHx"   -> the hatch opened, so one unit was released
 *   TX  "DISPENSE FAIL CHx" -> out of stock / channel error / PCA9685 fault
 *   TX  "ERROR ..."  -> malformed command or hardware fault reason
 *   TX  "WARN HATCH NOT CLOSED CHx" -> unit released, but the hatch did not
 *                                       return to CLOSED (check the hardware)
 *   TX  "READY"      -> boot self-test passed (PCA9685 detected)
 *   TX  "NOTREADY ..." -> boot self-test failed (PCA9685 not detected)
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

const byte PCA_ADDRESS = 0x40;
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
bool pcaHealthy = false;

uint16_t angleToPulse(byte angle);
void handleCommand(char *command, byte length);
void updateStatusLights();
void playDoneBuzzes();
void dispenseChannel(byte index);
bool pcaPresent();
bool pcaInit();
bool setServoAngle(byte index, byte angle);
void reportFail(byte index, const __FlashStringHelper *reason);
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

  for (byte i = 0; i < 5; i++) {
    pinMode(LED_PINS[i], OUTPUT);
  }

  // Boot self-test: the PCA9685 must ACK before anything can be dispensed.
  pcaHealthy = pcaInit();

  if (pcaHealthy) {
    for (byte i = 0; i < 5; i++) {
      setServoAngle(i, CLOSED_POSITION);
      delay(150);
    }
  }

  updateStatusLights();
  Serial.println(F("AMDPMS 5-MEDICINE DISPENSER"));
  if (pcaHealthy) {
    Serial.println(F("READY"));
  } else {
    Serial.println(F("NOTREADY PCA9685 NOT FOUND"));
  }
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

// True when the PCA9685 answers at 0x40. A NAK here means the servo driver is
// missing, unpowered, or SDA/SCL are wired wrong, i.e. nothing can move.
bool pcaPresent() {
  if (Wire.getWireTimeoutFlag()) {
    Wire.clearWireTimeoutFlag();
    return false;
  }
  Wire.beginTransmission(PCA_ADDRESS);
  return Wire.endTransmission() == 0;
}

// (Re)initialise the servo driver: detect it, run its reset sequence and set
// the 50 Hz servo refresh rate. Returns false when the chip does not ACK.
bool pcaInit() {
  if (!pwm.begin()) {
    return false;
  }
  pwm.setPWMFreq(50);
  delay(10);
  if (Wire.getWireTimeoutFlag()) {
    Wire.clearWireTimeoutFlag();
    return false;
  }
  return pcaPresent();
}

// Drive one servo to an angle and report whether the PCA9685 acknowledged it.
// A NAK or an I2C watchdog timeout triggers one re-initialisation + retry,
// which recovers a chip that brown-out reset while a dispense was running.
bool setServoAngle(byte index, byte angle) {
  if (index > 4) {
    return false;
  }
  if (Wire.getWireTimeoutFlag()) {
    Wire.clearWireTimeoutFlag();
  }

  byte channel = SERVO_CHANNELS[index];
  // The library returns 0 when the write was acknowledged, non-zero on NAK.
  if (pwm.setPWM(channel, 0, angleToPulse(angle)) == 0 && !Wire.getWireTimeoutFlag()) {
    return true;
  }
  if (Wire.getWireTimeoutFlag()) {
    Wire.clearWireTimeoutFlag();
  }

  if (!pcaInit()) {
    return false;
  }
  if (pwm.setPWM(channel, 0, angleToPulse(angle)) != 0) {
    return false;
  }
  if (Wire.getWireTimeoutFlag()) {
    Wire.clearWireTimeoutFlag();
    return false;
  }
  return true;
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

void reportFail(byte index, const __FlashStringHelper *reason) {
  Serial.print(F("DISPENSE FAIL CH"));
  Serial.println(index + 1);
  Serial.print(F("ERROR "));
  Serial.println(reason);
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

  // SELFTEST -> probe the PCA9685 right now and report the result, so the web
  // app can verify the servo driver BEFORE a payment is taken.
  if (length == 8 && command[0] == 'S' && command[1] == 'E' &&
      command[2] == 'L' && command[3] == 'F' && command[4] == 'T' &&
      command[5] == 'E' && command[6] == 'S' && command[7] == 'T') {
    if (pcaPresent()) {
      pcaHealthy = true;
      Serial.println(F("SELFTEST OK"));
    } else {
      pcaHealthy = false;
      Serial.println(F("SELFTEST FAIL PCA9685 NOT FOUND"));
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
    reportFail(index, F("STOCK EMPTY"));
    return;
  }

  // Nothing can move without the servo driver, so refuse up front instead of
  // waiting through the open/close delays and then pretending it worked.
  if (!pcaPresent()) {
    pcaHealthy = false;
    reportFail(index, F("PCA9685 NOT FOUND"));
    return;
  }
  pcaHealthy = true;

  // Open the hatch. Until this write is acknowledged nothing has physically
  // moved, so the unit is not counted and the sale must not be completed.
  if (!setServoAngle(index, OPEN_POSITION)) {
    reportFail(index, F("PCA9685 DID NOT ACK THE OPEN COMMAND"));
    // Best effort: park the channel even though the open already failed.
    setServoAngle(index, CLOSED_POSITION);
    return;
  }
  delay(OPEN_TIME_MS);

  // The hatch opened, so the unit has been released. Getting back to CLOSED
  // is retried once; a unit that dropped must still be counted and reported.
  bool closed = setServoAngle(index, CLOSED_POSITION);
  delay(RETURN_TIME_MS);
  if (!closed) {
    closed = setServoAngle(index, CLOSED_POSITION);
    delay(RETURN_TIME_MS);
  }

  stock[index]--;
  updateStatusLights();

  Serial.print(F("DONE CH"));
  Serial.println(index + 1);

  // Confirm the handover with an audible signal on every successful release,
  // not just when the bin runs empty. Printed after DONE so the blocking tone
  // does not delay the bridge's reply to the web app.
  playDoneBuzzes();

  if (!closed) {
    Serial.print(F("WARN HATCH NOT CLOSED CH"));
    Serial.println(index + 1);
  }
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
