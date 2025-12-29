#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>
#include <Adafruit_NeoPixel.h>

// ===================================================================
// 灯带硬件配置（根据您的实际硬件调整）
// ===================================================================
#define LED_PIN    4
#define LED_COUNT  60

// --- 选择您的灯带类型（只启用一个） ---
#define STRIP_TYPE NEO_GRB + NEO_KHZ800     // GRB灯带（如WS2812B）
// #define STRIP_TYPE NEO_RGB + NEO_KHZ800     // RGB灯带
// #define STRIP_TYPE NEO_RGBW + NEO_KHZ800    // RGBW灯带（如SK6812RGBW）

// --- 颜色校正设置 ---
#define USE_GAMMA_CORRECTION true  // 启用伽马校正（强烈建议）
#define GAMMA_VALUE 2.2            // 伽马值（2.2是标准值）
#define COLOR_TEMPERATURE_CORRECTION true // 色温校正
#define MAX_BRIGHTNESS 200         // 全局最大亮度（0-255），降低可提高颜色纯度

// ===================================================================
// WiFi配置
// ===================================================================
const char* ssid = "你的WiFi名称";
const char* password = "你的WiFi密码";
const char* serverUrl = "http://192.168.1.100/your_project_folder/index.php?api=esp32"; 

// ===================================================================
// 动画设置
// ===================================================================
#define ANIMATION_FPS    60       // 刷新率
#define ANIMATION_SPEED  0.005    // 流动速度
#define BREATHING_SPEED  0.3      // 呼吸速度
#define MIN_BREATH       0.3      // 呼吸最小亮度
#define MAX_BREATH       1.0      // 呼吸最大亮度

// ===================================================================
// 全局对象和变量
// ===================================================================
Adafruit_NeoPixel strip(LED_COUNT, LED_PIN, STRIP_TYPE);

JsonDocument currentColorsDoc;
unsigned long lastApiCall = 0;
unsigned long lastAnimationUpdate = 0;
float animationOffset = 0.0;
uint8_t gamma8[256];

// ===================================================================
// 初始化
// ===================================================================
void setup() {
  Serial.begin(115200);
  Serial.println("\n\n=== LED Strip Controller Starting ===");
  
  initGammaTable();
  
  strip.begin();
  strip.setBrightness(MAX_BRIGHTNESS);
  strip.clear();
  strip.show();
  
  testStrip();
  
  connectWiFi();
  
  fetchAndUpdateGlobalColors();
}

// ===================================================================
// 主循环
// ===================================================================
void loop() {
  unsigned long currentMillis = millis();
  
  if (currentMillis - lastApiCall >= 10000) {
    lastApiCall = currentMillis;
    if (WiFi.status() == WL_CONNECTED) {
      fetchAndUpdateGlobalColors();
    }
  }
  
  if (currentMillis - lastAnimationUpdate >= (1000 / ANIMATION_FPS)) {
    lastAnimationUpdate = currentMillis;
    updateAnimation();
  }
}

// ===================================================================
// 颜色处理核心函数
// ===================================================================
void updateAnimation() {
  JsonArray colors = currentColorsDoc.as<JsonArray>();
  int numColors = colors.size();
  
  if (numColors == 0) {
    showRainbow();
    return;
  }
  
  float breath = 1.0;
  if (MAX_BREATH != MIN_BREATH) {
    float breathPhase = sin(millis() * 0.001 * BREATHING_SPEED * 2 * PI);
    breath = MIN_BREATH + (MAX_BREATH - MIN_BREATH) * (breathPhase + 1.0) / 2.0;
  }
  
  animationOffset += ANIMATION_SPEED;
  if (animationOffset >= 1.0) animationOffset -= 1.0;
  
  for (int i = 0; i < LED_COUNT; i++) {
    float position = (float)i / (float)LED_COUNT + animationOffset;
    if (position >= 1.0) position -= 1.0;
    
    uint8_t r, g, b;
    getInterpolatedColor(colors, position, r, g, b);
    
    float fr = r * breath;
    float fg = g * breath;
    float fb = b * breath;
    
    if (COLOR_TEMPERATURE_CORRECTION) {
      float minVal = min(min(fr, fg), fb);
      if (minVal > 0 && minVal < 128) {
        float reduction = minVal * 0.5;
        // ===================================================================
        //  FIXED LINES: Use 0.0f for float comparison in max()
        // ===================================================================
        fr = max(0.0f, fr - reduction);
        fg = max(0.0f, fg - reduction);
        fb = max(0.0f, fb - reduction);
      }
    }
    
    r = (uint8_t)fr;
    g = (uint8_t)fg;
    b = (uint8_t)fb;

    if (USE_GAMMA_CORRECTION) {
      r = gamma8[r];
      g = gamma8[g];
      b = gamma8[b];
    }
    
    setPixelColor(i, r, g, b);
  }
  
  strip.show();
}

// ===================================================================
// 辅助函数
// ===================================================================
void getInterpolatedColor(JsonArray& colors, float position, uint8_t& r, uint8_t& g, uint8_t& b) {
  int numColors = colors.size();
  
  if (numColors == 1) {
    r = constrain((int)colors[0][0], 0, 255);
    g = constrain((int)colors[0][1], 0, 255);
    b = constrain((int)colors[0][2], 0, 255);
  } else {
    float segment = position * (numColors - 1);
    int idx1 = floor(segment);
    int idx2 = min(idx1 + 1, numColors - 1);
    float frac = segment - idx1;
    
    r = lerp((int)colors[idx1][0], (int)colors[idx2][0], frac);
    g = lerp((int)colors[idx1][1], (int)colors[idx2][1], frac);
    b = lerp((int)colors[idx1][2], (int)colors[idx2][2], frac);
  }
}

uint8_t lerp(int start, int end, float fraction) {
  return constrain((int)(start + (end - start) * fraction), 0, 255);
}

void setPixelColor(int pixel, uint8_t r, uint8_t g, uint8_t b) {
  #if (STRIP_TYPE == (NEO_RGBW + NEO_KHZ800)) || (STRIP_TYPE == (NEO_GRBW + NEO_KHZ800))
    uint8_t w = min(min(r, g), b);
    strip.setPixelColor(pixel, strip.Color(r - w, g - w, b - w, w));
  #else
    strip.setPixelColor(pixel, strip.Color(r, g, b));
  #endif
}

void initGammaTable() {
  Serial.println("Initializing gamma correction table...");
  for (int i = 0; i < 256; i++) {
    gamma8[i] = pow((float)i / 255.0, GAMMA_VALUE) * 255.0 + 0.5;
  }
}

void testStrip() {
  Serial.println("Testing LED strip...");
  strip.clear();
  Serial.println("Test: Pure RED");
  for (int i = 0; i < LED_COUNT; i++) setPixelColor(i, 255, 0, 0);
  strip.show(); delay(1000);
  
  Serial.println("Test: Pure GREEN");
  for (int i = 0; i < LED_COUNT; i++) setPixelColor(i, 0, 255, 0);
  strip.show(); delay(1000);
  
  Serial.println("Test: Pure BLUE");
  for (int i = 0; i < LED_COUNT; i++) setPixelColor(i, 0, 0, 255);
  strip.show(); delay(1000);
  
  strip.clear(); strip.show();
  Serial.println("Test complete.");
}

void showRainbow() {
  static uint16_t hue = 0;
  for (int i = 0; i < LED_COUNT; i++) {
    uint32_t color = strip.ColorHSV(hue + (i * 65536L / LED_COUNT), 255, 200);
    strip.setPixelColor(i, color);
  }
  strip.show();
  hue += 256;
}

void connectWiFi() {
  Serial.print("Connecting to WiFi: "); Serial.println(ssid);
  WiFi.begin(ssid, password);
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts < 30) {
    delay(500); Serial.print("."); attempts++;
  }
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\nWiFi connected!"); Serial.print("IP: "); Serial.println(WiFi.localIP());
  } else {
    Serial.println("\nWiFi failed! Using rainbow mode.");
  }
}

void fetchAndUpdateGlobalColors() {
  if (WiFi.status() != WL_CONNECTED) return;
  HTTPClient http;
  http.begin(serverUrl);
  http.setTimeout(5000);
  Serial.println("Fetching colors...");
  int httpCode = http.GET();
  if (httpCode == HTTP_CODE_OK) {
    String payload = http.getString();
    currentColorsDoc.clear();
    if (deserializeJson(currentColorsDoc, payload) == DeserializationError::Ok) {
      Serial.print("Colors updated: ");
      JsonArray colors = currentColorsDoc.as<JsonArray>();
      for (const auto& color : colors) {
        Serial.printf("RGB(%d,%d,%d) ", (int)color[0], (int)color[1], (int)color[2]);
      }
      Serial.println();
    }
  }
  http.end();
}
