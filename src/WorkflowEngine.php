<?php
declare(strict_types=1);

final class WorkflowEngine
{
    public static function analyze(array $paths): array
    {
        $lower=array_map('strtolower',$paths); $has=fn(string $name):bool=>in_array(strtolower($name),$lower,true);
        if($has('platformio.ini')) return ['framework'=>'platformio','board'=>'configured in platformio.ini','confidence'=>0.99];
        if($has('idf_component.yml')||$has('sdkconfig')||($has('cmakelists.txt')&&count(array_filter($lower,fn($p)=>str_contains($p,'main/')))>0)) return ['framework'=>'esp-idf','board'=>'ESP32','confidence'=>0.93];
        if(count(array_filter($lower,fn($p)=>str_ends_with($p,'.ino')))>0) return ['framework'=>'arduino','board'=>'esp32','confidence'=>0.92];
        throw new RuntimeException('No supported PlatformIO, ESP-IDF, or Arduino project was detected.');
    }

    public static function workflow(string $framework, array $paths=[], string $source=''): string
    {
        $header="name: ESPForge firmware build\n\non:\n  workflow_dispatch:\n\npermissions:\n  contents: read\n\njobs:\n  firmware:\n    runs-on: ubuntu-latest\n    timeout-minutes: 30\n    steps:\n      - uses: actions/checkout@v4\n";
        if($framework==='platformio') return $header.<<<'YAML'
      - uses: actions/setup-python@v5
        with:
          python-version: "3.x"
      - name: Install PlatformIO
        run: pip install --disable-pip-version-check platformio
      - name: Build firmware
        run: pio run
      - name: Collect binaries
        run: |
          mkdir -p firmware-output
          find . -maxdepth 1 -type f -name "*.bin" -exec cp {} firmware-output/ \;
          find .pio/build -type f -name "*.bin" -exec cp --parents {} firmware-output/ \;
      - uses: actions/upload-artifact@v4
        with:
          name: espforge-firmware
          path: firmware-output/
          if-no-files-found: error
YAML;
        if($framework==='esp-idf') return $header.<<<'YAML'
      - uses: espressif/esp-idf-ci-action@v1
        with:
          esp_idf_version: latest
          target: esp32
          path: "."
      - name: Collect binaries
        run: |
          mkdir -p firmware-output
          find build -maxdepth 2 -type f -name "*.bin" -exec cp --parents {} firmware-output/ \;
      - uses: actions/upload-artifact@v4
        with:
          name: espforge-firmware
          path: firmware-output/
          if-no-files-found: error
YAML;
        return $header.self::arduinoSteps($paths,$source);
    }

    private static function arduinoSteps(array $paths,string $source): string
    {
        $map=[
            'PCF8574.h'=>'PCF8574 library','Adafruit_PN532.h'=>'Adafruit PN532','ArduinoJson.h'=>'ArduinoJson',
            'XPT2046_Touchscreen.h'=>'XPT2046_Touchscreen','RF24.h'=>'RF24','RCSwitch.h'=>'rc-switch',
            'NimBLEDevice.h'=>'NimBLE-Arduino@1.4.2','IRremoteESP8266.h'=>'IRremoteESP8266','arduinoFFT.h'=>'arduinoFFT@1.6.2',
            'Adafruit_NeoPixel.h'=>'Adafruit NeoPixel',
        ];
        $libraries=[]; foreach($map as $include=>$library) if(str_contains($source,$include)) $libraries[]=$library;
        $install=$libraries ? implode("\n",array_map(fn($lib)=>'          arduino-cli lib install '.escapeshellarg($lib),$libraries)) : '          echo "No registry libraries detected"';
        $hasZips=count(array_filter($paths,fn($p)=>str_starts_with(strtolower($p),'libraries/')&&str_ends_with(strtolower($p),'.zip')))>0;
        if($hasZips) $install.="\n          find Libraries -type f -name '*.zip' -print0 | while IFS= read -r -d '' zip; do arduino-cli lib install --zip-path \"\$zip\"; done";
        // Some legacy projects bundle platform.txt for the 2.0.x ESP32 core and do not compile on 3.x.
        $legacy=count(array_filter($paths,fn($p)=>strtolower($p)==='libraries/platform.txt'))>0;
        $core=$legacy?'esp32:esp32@2.0.10':'esp32:esp32';
        $platformPatch=$legacy?'          cp "Libraries/platform.txt" "$HOME/.arduino15/packages/esp32/hardware/esp32/2.0.10/platform.txt"':'';
        return <<<YAML
      - uses: actions/setup-python@v5
        with:
          python-version: "3.11"
      - name: Install Arduino CLI
        run: |
          python -m pip install --disable-pip-version-check pyserial
          curl -fsSL https://raw.githubusercontent.com/arduino/arduino-cli/master/install.sh | sh
          echo "\$PWD/bin" >> "\$GITHUB_PATH"
      - name: Install ESP32 core
        run: |
          ./bin/arduino-cli config init
          ./bin/arduino-cli config add board_manager.additional_urls https://espressif.github.io/arduino-esp32/package_esp32_index.json
          ./bin/arduino-cli config set library.enable_unsafe_install true
          ./bin/arduino-cli core update-index
          ./bin/arduino-cli core install {$core}
{$platformPatch}
      - name: Install detected libraries
        run: |
{$install}
      - name: Compile firmware
        run: arduino-cli compile --fqbn esp32:esp32:esp32 --output-dir firmware-output "\$(dirname "\$(find . -name '*.ino' -print -quit)")"
      - uses: actions/upload-artifact@v4
        with:
          name: espforge-firmware
          path: firmware-output/*.bin
          if-no-files-found: error
YAML;
    }
}
