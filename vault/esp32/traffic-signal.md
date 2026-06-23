---
title: Traffic Signal
tags: [esp32, embedded, project]
---

# Traffic Signal

A small ESP32 project driving a 3-LED traffic light with configurable timing.

## Bill of materials

- 1 × ESP32 dev board
- 3 × LEDs (red / amber / green)
- 3 × 220 Ω resistors

## Sketch

```cpp
const int RED = 25, AMBER = 26, GREEN = 27;

void setup() {
  pinMode(RED, OUTPUT);
  pinMode(AMBER, OUTPUT);
  pinMode(GREEN, OUTPUT);
}

void loop() {
  digitalWrite(GREEN, HIGH); delay(5000); digitalWrite(GREEN, LOW);
  digitalWrite(AMBER, HIGH); delay(1500); digitalWrite(AMBER, LOW);
  digitalWrite(RED, HIGH);   delay(5000); digitalWrite(RED, LOW);
}
```

#esp32 #embedded #project
