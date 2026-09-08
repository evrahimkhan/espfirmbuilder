<?php
declare(strict_types=1);

final class WorkflowEngine
{
    public static function analyze(array $paths): array
    {
        $lower = array_map('strtolower', $paths);
        $has = fn(string $name): bool => in_array(strtolower($name), $lower, true);
        if ($has('platformio.ini')) return ['framework' => 'platformio', 'board' => 'configured in platformio.ini', 'confidence' => 0.99];
        if ($has('idf_component.yml') || $has('sdkconfig') || ($has('cmakelists.txt') && count(array_filter($lower, fn($p) => str_contains($p, 'main/'))) > 0)) return ['framework' => 'esp-idf', 'board' => 'ESP32', 'confidence' => 0.93];
        if (count(array_filter($lower, fn($p) => str_ends_with($p, '.ino'))) > 0) return ['framework' => 'arduino', 'board' => 'esp32', 'confidence' => 0.92];
        throw new RuntimeException('No supported PlatformIO, ESP-IDF, or Arduino project was detected.');
    }

    public static function workflow(string $framework): string
    {
        $header = "name: ESPForge firmware build\n\non:\n  workflow_dispatch:\n\npermissions:\n  contents: read\n\njobs:\n  firmware:\n    runs-on: ubuntu-latest\n    timeout-minutes: 30\n    steps:\n      - uses: actions/checkout@v4\n";
        if ($framework === 'platformio') return $header . <<<'YAML'
      - uses: actions/setup-python@v5
        with:
          python-version: "3.x"
      - name: Install PlatformIO
        run: pip install --disable-pip-version-check platformio
      - name: Build firmware
        run: pio run
      - name: Collect binaries
        run: find .pio/build -type f -name "*.bin" -exec cp --parents {} firmware-output/ \;
      - uses: actions/upload-artifact@v4
        with:
          name: espforge-firmware
          path: firmware-output/
          if-no-files-found: error
YAML;
        if ($framework === 'esp-idf') return $header . <<<'YAML'
      - uses: espressif/esp-idf-ci-action@v1
        with:
          esp_idf_version: latest
          target: esp32
          path: "."
      - name: Collect binaries
        run: find build -maxdepth 2 -type f -name "*.bin" -exec cp --parents {} firmware-output/ \;
      - uses: actions/upload-artifact@v4
        with:
          name: espforge-firmware
          path: firmware-output/
          if-no-files-found: error
YAML;
        return $header . <<<'YAML'
      - uses: actions/setup-python@v5
        with:
          python-version: "3.x"
      - name: Install Arduino CLI
        run: |
          curl -fsSL https://raw.githubusercontent.com/arduino/arduino-cli/master/install.sh | sh
          echo "$PWD/bin" >> "$GITHUB_PATH"
      - name: Install ESP32 core
        run: |
          arduino-cli config init
          arduino-cli config add board_manager.additional_urls https://espressif.github.io/arduino-esp32/package_esp32_index.json
          arduino-cli core update-index
          arduino-cli core install esp32:esp32
      - name: Compile firmware
        run: arduino-cli compile --fqbn esp32:esp32:esp32 --output-dir firmware-output "$(dirname "$(find . -name '*.ino' -print -quit)")"
      - uses: actions/upload-artifact@v4
        with:
          name: espforge-firmware
          path: firmware-output/*.bin
          if-no-files-found: error
YAML;
    }
}
