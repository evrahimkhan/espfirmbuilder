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

    public static function workflow(string $framework, array $paths=[], string $source='', array $additionalLibraries=[]): string
    {
        // Quote the trigger key and use an explicit empty mapping. This avoids YAML
        // 1.1 parsers treating `on` as a boolean and guarantees GitHub registers it.
        $header="name: ESPForge firmware build\nrun-name: ESPForge build \${{ inputs.espforge_build_uuid || github.event.client_payload.espforge_build_uuid }}\n\n\"on\":\n  workflow_dispatch:\n    inputs:\n      espforge_build_uuid:\n        description: Unique ESPForge build identifier\n        required: true\n        type: string\n  repository_dispatch:\n    types: [espforge_build]\n\npermissions:\n  contents: read\n\nconcurrency:\n  group: espforge-\${{ inputs.espforge_build_uuid || github.event.client_payload.espforge_build_uuid }}\n  cancel-in-progress: false\n\njobs:\n  firmware:\n    runs-on: ubuntu-latest\n    timeout-minutes: 30\n    steps:\n      - uses: actions/checkout@08c6903cd8c0fde910a37f88322edcfb5dd907a8\n        with:\n          persist-credentials: false\n          submodules: recursive\n";
        if($framework==='platformio') return $header.<<<'YAML'
      - uses: actions/setup-python@a26af69be951a213d495a4c3e4e4022e16d87065
        with:
          python-version: "3.12"
      - name: Install PlatformIO
        run: pip install --disable-pip-version-check platformio==6.1.19
      - name: Build firmware
        run: pio run
      - name: Collect binaries
        run: |
          mkdir -p firmware-output
          find . -maxdepth 1 -type f -name "*.bin" -exec cp {} firmware-output/ \;
          find .pio/build -type f -name "*.bin" -exec cp --parents {} firmware-output/ \;
      - uses: actions/upload-artifact@65462800fd760344b1a7b4382951275a0abb4808
        with:
          name: espforge-firmware
          path: firmware-output/
          if-no-files-found: error
YAML;
        if($framework==='esp-idf') return $header.<<<'YAML'
      - uses: espressif/esp-idf-ci-action@e6f5c74232b1ccd4c97ed641f1e48553853f1fd5
        with:
          esp_idf_version: v5.5.1
          target: esp32
          path: "."
      - name: Collect binaries
        run: |
          mkdir -p firmware-output
          find build -maxdepth 2 -type f -name "*.bin" -exec cp --parents {} firmware-output/ \;
      - uses: actions/upload-artifact@65462800fd760344b1a7b4382951275a0abb4808
        with:
          name: espforge-firmware
          path: firmware-output/
          if-no-files-found: error
YAML;
        return $header.self::arduinoSteps($paths,$source);
    }

    public static function sourceCompatibilityStep(string $source): string
    {
        if(!str_contains($source,'MARAUDER_VERSION')&&!str_contains($source,'MARAUDER_CYD_'))return '';
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

    public static function compatibleFqbn(string $fqbn,string $buildFlags,string $source): string
    {
        $marauder=str_contains($source,'MARAUDER_VERSION')||str_contains($source,'MARAUDER_CYD_');
        if($marauder&&preg_match('/^esp32:esp32:d32(?::|$)/',$fqbn)){
            // Current Marauder d32 profiles (including CYD 2 USB and V4) exceed
            // min_spiffs. The d32 menu exposes no_ota (2 MiB app), not huge_app.
            if(str_contains($fqbn,'PartitionScheme=min_spiffs'))return str_replace('PartitionScheme=min_spiffs','PartitionScheme=no_ota',$fqbn);
            if(!str_contains($fqbn,'PartitionScheme='))return $fqbn.':PartitionScheme=no_ota';
        }
        return $fqbn;
    }

    public static function artifactNamingStep(string $targetName): string
    {
        $prefix=trim(preg_replace('/[^A-Za-z0-9_.-]+/','-',$targetName)??'','.-');if($prefix==='')$prefix='firmware';$prefix=substr($prefix,0,80);
        return <<<YAML
      - name: Name firmware files for selected hardware
        env:
          ESPFORGE_ARTIFACT_PREFIX: "{$prefix}"
        run: |
          python - <<'PY'
          import os, pathlib, re
          root = pathlib.Path("firmware-output")
          prefix = os.environ["ESPFORGE_ARTIFACT_PREFIX"]
          def role(path):
              name = path.name.lower()
              if "bootloader" in name: return "bootloader", ".bin"
              if "partition" in name: return "partitions", ".bin"
              if "merged" in name or "merged-flash" in name: return "merged", ".bin"
              if any(item in name for item in ("littlefs", "spiffs", "fatfs", "filesystem")): return "filesystem", ".bin"
              if name.endswith(".elf"): return "application", ".elf"
              if name.endswith(".map"): return "application", ".map"
              if name == "firmware.bin" or name.endswith(".ino.bin"): return "application", ".bin"
              label = re.sub(r"[^a-z0-9_.-]+", "-", path.stem).strip(".-") or "firmware"
              return label, path.suffix or ".bin"
          for path in [item for item in root.rglob("*") if item.is_file()]:
              label, extension = role(path)
              filename = f"{prefix}-{label}{extension}"
              destination, counter = root / filename, 2
              while destination.exists() and destination != path:
                  destination = root / f"{prefix}-{label}-{counter}{extension}"
                  counter += 1
              if destination != path:
                  path.replace(destination)
          for directory in sorted([item for item in root.rglob("*") if item.is_dir()], reverse=True):
              try: directory.rmdir()
              except OSError: pass
          PY

YAML;
    }

    public static function manifestStep(string $framework,string $chip,string $configDigest='',string $analyzerVersion=''): string
    {
        $framework=preg_replace('/[^a-z0-9_-]/i','',$framework)?:'unknown';
        $chip=preg_replace('/[^a-z0-9_-]/i','',$chip)?:'unknown';
        $configDigest=preg_match('/^[a-f0-9]{64}$/',$configDigest)?$configDigest:'';
        $analyzerVersion=preg_replace('/[^A-Za-z0-9_.-]/','',$analyzerVersion)??'';
        return <<<YAML
      - name: Generate ESPForge flashing manifest
        env:
          ESPFORGE_FRAMEWORK: "{$framework}"
          ESPFORGE_CHIP: "{$chip}"
          ESPFORGE_CONFIG_DIGEST: "{$configDigest}"
          ESPFORGE_ANALYZER_VERSION: "{$analyzerVersion}"
        run: |
          python - <<'PY'
          import hashlib, json, os, pathlib, subprocess
          root = pathlib.Path("firmware-output")
          files = []
          for path in sorted(root.rglob("*.bin")):
              data = path.read_bytes()
              files.append({
                  "path": path.relative_to(root).as_posix(),
                  "size": len(data),
                  "sha256": hashlib.sha256(data).hexdigest(),
                  "offset": None
              })
          manifest = {
              "schema": "https://espforge.dev/schemas/flash-manifest-v1.json",
              "version": 1,
              "chip": os.environ["ESPFORGE_CHIP"],
              "framework": os.environ["ESPFORGE_FRAMEWORK"],
              "commit": subprocess.check_output(["git", "rev-parse", "HEAD"], text=True).strip(),
              "configuration_sha256": os.environ["ESPFORGE_CONFIG_DIGEST"] or None,
              "analyzer_version": os.environ["ESPFORGE_ANALYZER_VERSION"] or None,
              "files": files,
              "warning": "Offsets are intentionally unset unless supplied by the project toolchain. Verify offsets before flashing."
          }
          root.mkdir(parents=True, exist_ok=True)
          (root / "espforge-manifest.json").write_text(json.dumps(manifest, indent=2) + "\\n")
          PY

YAML;
    }

    private static function arduinoSteps(array $paths,string $source): string
    {
        $legacy=count(array_filter($paths,fn($p)=>strtolower($p)==='libraries/platform.txt'))>0;
        $sketches=array_values(array_filter($paths,fn($path)=>preg_match('/\.ino$/i',(string)$path)));
        if(!$sketches)throw new RuntimeException('No Arduino sketch (.ino) was found for this build.',422);
        usort($sketches,static function(string $left,string $right):int{
            $score=static function(string $path):int{$lower=strtolower($path);$directory=dirname($path);$stem=pathinfo($path,PATHINFO_FILENAME);$score=substr_count($path,'/')*10;if(preg_match('~(?:^|/)(?:test|tests|testing|example|examples|demo|demos)(?:/|$)|(?:test|example|demo)[^/]*\.ino$~i',$path))$score+=1000;if($directory!=='.'&&strcasecmp(basename($directory),$stem)===0)$score-=200;if(preg_match('/(?:firmware|marauder|main)/',$lower))$score-=40;return $score;};return $score($left)<=>$score($right)?:strcasecmp($left,$right);});
        $sketchDirectory=escapeshellarg(dirname($sketches[0])==='.'?'.':dirname($sketches[0]));
        $isEsp32S3=preg_match('/^\s*#\s*define\s+BOARD_ESP32_DIV_V2\b/m',$source)===1 || preg_match('/\bESP32[-_ ]?S3\b/i',$source)===1;
        $map=[
            'PCF8574.h'=>'PCF8574 library@2.3.7','Adafruit_PN532.h'=>'Adafruit PN532@1.3.4','ArduinoJson.h'=>'ArduinoJson@6.18.2',
            'TFT_eSPI.h'=>'TFT_eSPI@2.5.43',
            'XPT2046_Touchscreen.h'=>'XPT2046_Touchscreen@1.4','RF24.h'=>'RF24@1.5.0','RCSwitch.h'=>'rc-switch@2.6.4',
            'NimBLEDevice.h'=>'NimBLE-Arduino@1.4.2','LinkedList.h'=>'LinkedList@1.3.3','SoftwareSerial.h'=>'EspSoftwareSerial@8.1.0','MicroNMEA.h'=>'MicroNMEA@2.0.6','IRremoteESP8266.h'=>'IRremoteESP8266@2.8.6','arduinoFFT.h'=>'arduinoFFT@1.6.2',
            'Adafruit_NeoPixel.h'=>'Adafruit NeoPixel@1.15.1','Adafruit_I2CDevice.h'=>'Adafruit BusIO@1.15.0','Adafruit_MAX1704X.h'=>'Adafruit MAX1704X@1.0.2','Adafruit_TCA8418.h'=>'Adafruit TCA8418@1.0.2',
        ];
        $gitMap=[
            'ESP32Ping.h'=>['marian-craciunescu/ESP32Ping','aadac3c08f5e6f5062faf26ae1b17e566e22728c','ESP32Ping'],
            'AsyncTCP.h'=>['ESP32Async/AsyncTCP','b2e5f4f368b137442f66a9ba7242b8759e6aef59','AsyncTCP'],
            'ESPAsyncWebServer.h'=>['ESP32Async/ESPAsyncWebServer','4fc46e0c1b6ed559f7cd0c2548ed163a3e2b3412','ESPAsyncWebServer'],
            'lvgl.h'=>['lvgl/lv_arduino','8651c35e977bddfd25bf9ef59a041f6ab243c7f2','lv_arduino'],
            'JPEGDecoder.h'=>['Bodmer/JPEGDecoder','6a0912ba05532eab707bd092bd4fa43477e38f5c','JPEGDecoder'],
        ];
        $libraries=[]; foreach($map as $include=>$library) if(str_contains($source,$include)) $libraries[]=$library;
        $resolved=[];foreach($libraries as $library){$name=strtolower(strstr($library,'@',true)?:$library);if(!isset($resolved[$name]))$resolved[$name]=$library;}$libraries=array_values($resolved);
        $gitProvided=['esp32ping','asynctcp','async tcp','espasyncwebserver','esp async webserver','lv_arduino','jpegdecoder'];$aiLibraries=[];
        // AI may identify a dependency, but it cannot choose an executable package
        // fact. Only exact packages/versions in ESPForge's reviewed registry pass.
        $verifiedRegistry=[];foreach(array_values($map) as $verified){$verifiedRegistry[strtolower((string)strstr($verified,'@',true))]=$verified;}
        foreach($additionalLibraries as $library)if(is_string($library)&&preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]{0,99}@[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/',$library)){$name=strtolower((string)strstr($library,'@',true));$verified=$verifiedRegistry[$name]??null;if($verified!==null&&hash_equals(strtolower($verified),strtolower($library))&&!isset($resolved[$name])&&!in_array($name,$gitProvided,true)){$resolved[$name]=$verified;$aiLibraries[]=$verified;}}
        $allRegistryLibraries=array_values($resolved);
        $install=$allRegistryLibraries ? implode("\n",array_map(fn($lib)=>'          arduino-cli lib install '.escapeshellarg($lib),$allRegistryLibraries)) : '          echo "No reviewed registry libraries detected"';
        $marauderProfile=str_contains($source,'MARAUDER_VERSION')||str_contains($source,'MARAUDER_CYD_');
        foreach($gitMap as $include=>$dependency)if(str_contains($source,$include)||($marauderProfile&&in_array($include,['lvgl.h','JPEGDecoder.h'],true))){$repository=$dependency[0];$sha=$dependency[1];$name=$dependency[2];$install.="\n          rm -rf \"\$RUNNER_TEMP/espforge-{$name}\"\n          git init -q \"\$RUNNER_TEMP/espforge-{$name}\"\n          git -C \"\$RUNNER_TEMP/espforge-{$name}\" remote add origin https://github.com/{$repository}.git\n          git -C \"\$RUNNER_TEMP/espforge-{$name}\" fetch -q --depth=1 origin {$sha}\n          git -C \"\$RUNNER_TEMP/espforge-{$name}\" checkout -q --detach FETCH_HEAD\n          mkdir -p \"\$HOME/Arduino/libraries\"\n          cp -R \"\$RUNNER_TEMP/espforge-{$name}\" \"\$HOME/Arduino/libraries/{$name}\"";}
        $hasLocalLibraries=count(array_filter($paths,fn($p)=>str_contains(strtolower($p),'/libraries/')||str_starts_with(strtolower($p),'libraries/')))>0;
        if($hasLocalLibraries)$install.="\n          python - <<'PY'\n          import pathlib, re, shutil\n          destination = pathlib.Path.home() / 'Arduino' / 'libraries'\n          destination.mkdir(parents=True, exist_ok=True)\n          def metadata(path):\n              values = {}\n              for line in path.read_text(errors='ignore').splitlines():\n                  if '=' in line:\n                      key, value = line.split('=', 1); values[key.strip().lower()] = value.strip()\n              return values\n          installed = {re.sub(r'[^a-z0-9]', '', metadata(path).get('name', path.parent.name).lower()) for path in destination.glob('*/library.properties')}\n          for properties in pathlib.Path('.').glob('**/library.properties'):\n              if '.git' in properties.parts or len(properties.parts) > 8: continue\n              info = metadata(properties); key = re.sub(r'[^a-z0-9]', '', info.get('name', properties.parent.name).lower())\n              if not key or key in installed: continue\n              target = destination / re.sub(r'[^A-Za-z0-9_.-]', '-', properties.parent.name)\n              if not target.exists(): shutil.copytree(properties.parent, target)\n              installed.add(key)\n          PY";
        $hasZips=count(array_filter($paths,fn($p)=>str_starts_with(strtolower($p),'libraries/')&&str_ends_with(strtolower($p),'.zip')))>0;
        if($hasZips){
            $zipFilter=$isEsp32S3?" ! -iname '*TFT*'":'';
            $install.="\n          find Libraries -type f -name '*.zip'{$zipFilter} -print0 | while IFS= read -r -d '' zip; do arduino-cli lib install --zip-path \"\$zip\"; done";
        }
        $hasV2Setup=in_array('Libraries/User_Setup v2.h',$paths,true);
        if($isEsp32S3&&$hasV2Setup) $install.="\n          cp \"Libraries/User_Setup v2.h\" \"\$HOME/Arduino/libraries/TFT_eSPI/User_Setup.h\"";
        // Some legacy projects bundle platform.txt for the 2.0.x ESP32 core and do not compile on 3.x.
        $core=$legacy?'esp32:esp32@2.0.10':'esp32:esp32@3.2.1';
        $platformPatch=$legacy?'          cp "Libraries/platform.txt" "$HOME/.arduino15/packages/esp32/hardware/esp32/2.0.10/platform.txt"':'';
        $fqbn=$isEsp32S3?'esp32:esp32:esp32s3:PSRAM=enabled,PartitionScheme=min_spiffs,FlashMode=dio':'esp32:esp32:esp32';
        return <<<YAML
      - uses: actions/setup-python@a26af69be951a213d495a4c3e4e4022e16d87065
        with:
          python-version: "3.11"
      - name: Install Arduino CLI
        run: |
          python -m pip install --disable-pip-version-check pyserial==3.5
          curl --proto '=https' --tlsv1.2 -fsSLo arduino-cli.tar.gz https://github.com/arduino/arduino-cli/releases/download/v1.3.1/arduino-cli_1.3.1_Linux_64bit.tar.gz
          echo "376428d7d45be640c00812a71612e1742edc2f5f9ee3742a2d6da7870e079588  arduino-cli.tar.gz" | sha256sum --check --strict
          mkdir -p bin && tar -xzf arduino-cli.tar.gz -C bin arduino-cli
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
        run: arduino-cli compile --fqbn "{$fqbn}" --output-dir firmware-output {$sketchDirectory}
      - uses: actions/upload-artifact@65462800fd760344b1a7b4382951275a0abb4808
        with:
          name: espforge-firmware
          path: firmware-output/
          if-no-files-found: error
YAML;
    }
}
