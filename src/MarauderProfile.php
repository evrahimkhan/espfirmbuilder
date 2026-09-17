<?php
declare(strict_types=1);

/** Versioned repository-specific behavior kept outside the generic workflow engine. */
final class MarauderProfile
{
    public const VERSION='marauder-v1';

    public static function matches(string $source): bool
    {
        return str_contains($source,'MARAUDER_VERSION')||str_contains($source,'MARAUDER_CYD_');
    }

    public static function compatibilityStep(string $source): string
    {
        if(!self::matches($source))return '';
        return <<<'YAML'
      - name: Fix source compatibility for selected toolchain
        run: |
          python - <<'PY'
          import pathlib, re
          root = pathlib.Path("esp32_marauder")
          evil_h, evil_cpp = root / "EvilPortal.h", root / "EvilPortal.cpp"
          if evil_h.is_file() and evil_cpp.is_file():
              text = evil_h.read_text(errors="ignore")
              text, changed = re.subn(r'(?m)^(\s*)char\s+index_html\s*\[MAX_HTML_SIZE\]\s*=\s*"TEST"\s*;', r'\1extern char index_html[MAX_HTML_SIZE];', text, count=1)
              evil_h.write_text(text)
              implementation = evil_cpp.read_text(errors="ignore")
              if changed and not re.search(r'(?m)^\s*char\s+index_html\s*\[MAX_HTML_SIZE\]', implementation):
                  implementation = implementation.replace('#include "EvilPortal.h"', '#include "EvilPortal.h"\n\n#ifndef HAS_PSRAM\nchar index_html[MAX_HTML_SIZE] = "TEST";\n#endif', 1)
                  evil_cpp.write_text(implementation)
          wifi_h, wifi_cpp = root / "WiFiScan.h", root / "WiFiScan.cpp"
          if wifi_h.is_file() and wifi_cpp.is_file():
              header = wifi_h.read_text(errors="ignore")
              definitions = []
              for name in ("operationInProgress", "connectionPending"):
                  pattern = rf'(?m)^(\s*)bool\s+{name}\s*=\s*(true|false)\s*;'
                  match = re.search(pattern, header)
                  if match:
                      definitions.append((name, match.group(2)))
                      header = re.sub(pattern, rf'\1extern bool {name};', header, count=1)
              wifi_h.write_text(header)
              implementation = wifi_cpp.read_text(errors="ignore")
              additions = ''.join(f'bool {name} = {value};\n' for name, value in definitions if not re.search(rf'(?m)^\s*bool\s+{name}\s*=', implementation))
              if additions: implementation = implementation.replace('#include "WiFiScan.h"', '#include "WiFiScan.h"\n\n' + additions.rstrip(), 1)
              # Rename the project implementation and every project call site together.
              # Renaming only the first occurrence leaves RunSetup() referring to the
              # ESP-IDF symbol that is intentionally hidden by the C declaration order.
              implementation = implementation.replace('ieee80211_raw_frame_sanity_check(', 'marauder_ieee80211_raw_frame_sanity_check(')
              wifi_cpp.write_text(implementation)
          battery_h, battery_cpp = root / "BatteryInterface.h", root / "BatteryInterface.cpp"
          if battery_h.is_file() and battery_cpp.is_file():
              header = battery_h.read_text(errors="ignore")
              header, changed = re.subn(r'(?m)^(\s*)AXP192\s+axp192_obj\s*;', r'\1extern AXP192 axp192_obj;', header, count=1)
              battery_h.write_text(header)
              implementation = battery_cpp.read_text(errors="ignore")
              if changed and not re.search(r'(?m)^\s*AXP192\s+axp192_obj\s*;', implementation):
                  implementation = implementation.replace('#include "BatteryInterface.h"', '#include "BatteryInterface.h"\n\n#ifdef HAS_AXP192\nAXP192 axp192_obj;\n#endif', 1)
                  battery_cpp.write_text(implementation)
          gps = root / "GpsInterface.cpp"
          if gps.is_file():
              text = gps.read_text(errors="ignore")
              # ESP32/S3 cores already own Serial2, while chips with only two
              # hardware UARTs (including ESP32-S2) do not declare it. Keep the
              # project instance only where the core cannot provide one.
              text = re.sub(r'(?m)^\s*HardwareSerial\s+Serial2\s*\(GPS_SERIAL_INDEX\)\s*;\s*$', '#if SOC_UART_NUM <= 2\nHardwareSerial Serial2(GPS_SERIAL_INDEX);\n#endif', text, count=1)
              gps.write_text(text)
          PY

YAML;
    }

    public static function compatibleFqbn(string $fqbn,string $source): string
    {
        $marauder=self::matches($source);
        if($marauder&&preg_match('/^esp32:esp32:d32(?::|$)/',$fqbn)){
            // Current Marauder d32 profiles (including CYD 2 USB and V4) exceed
            // min_spiffs. The d32 menu exposes no_ota (2 MiB app), not huge_app.
            if(str_contains($fqbn,'PartitionScheme=min_spiffs'))return str_replace('PartitionScheme=min_spiffs','PartitionScheme=no_ota',$fqbn);
            if(!str_contains($fqbn,'PartitionScheme='))return $fqbn.':PartitionScheme=no_ota';
        }
        return $fqbn;
    }

    public static function supplementalHeaders(): array
    {
        return ['lvgl.h','JPEGDecoder.h'];
    }
}
