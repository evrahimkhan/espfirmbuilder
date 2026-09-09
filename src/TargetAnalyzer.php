<?php
declare(strict_types=1);

final class TargetAnalyzer
{
    public static function discover(GitHubClient $github,string $fullName,string $branch,array $paths,?callable $aiFallback=null): array
    {
        $targets=self::discoverPlatformIOTargets($github,$fullName,$branch,$paths);
        if($targets) return $targets;

        // Several mature firmware projects publish their supported hardware as an
        // inline GitHub Actions matrix. Reuse it as the authoritative model list.
        foreach(['.github/workflows/build_parallel.yml','.github/workflows/build.yml','.github/workflows/firmware.yml'] as $path){
            if(!in_array($path,$paths,true)) continue;
            $yaml=$github->file($fullName,$path,$branch)??'';
            foreach(preg_split('/\R/',$yaml) as $line){
                if(!preg_match('/^\s*-\s*\{.*?name:\s*"([^"]+)".*?flag:\s*"([^"]+)".*?(?:fbqn|fqbn):\s*"([^"]+)"/',$line,$match)) continue;
                $targets[]=['id'=>$match[2],'name'=>$match[1],'type'=>'workflow_matrix','fqbn'=>$match[3],'flag'=>$match[2],'workflow_path'=>$path];
            }
            if($targets) return $targets;
        }

        $idfTargets=self::discoverEspIdfTargets($github,$fullName,$branch,$paths);
        if(count($idfTargets)>1) return $idfTargets;

        $defineTargets=self::discoverBoardDefines($github,$fullName,$branch,$paths);
        if(count($defineTargets)>1) return $defineTargets;

        if($aiFallback){
            try { $aiTargets=$aiFallback(); if(is_array($aiTargets)&&$aiTargets) return $aiTargets; }
            catch(Throwable $e){ error_log('ESPForge AI target fallback: '.$e->getMessage()); }
        }
        $source=$github->sourceBundle($fullName,$branch,array_map(fn($path)=>['path'=>$path,'type'=>'blob','size'=>0],$paths),20);
        $s3=preg_match('/^\s*#\s*define\s+BOARD_ESP32_DIV_V2\b/m',$source)===1||preg_match('/\bESP32[-_ ]?S3\b/i',$source)===1;
        return [[
            'id'=>$s3?'esp32s3':'esp32', 'name'=>$s3?'ESP32-S3':'ESP32', 'type'=>'arduino',
            'fqbn'=>$s3?'esp32:esp32:esp32s3:PSRAM=enabled,PartitionScheme=min_spiffs,FlashMode=dio':'esp32:esp32:esp32'
        ]];
    }

    public static function select(array $targets,string $id): ?array
    {
        foreach($targets as $target) if(hash_equals((string)$target['id'],$id)) return $target;
        return null;
    }

    public static function filterMatrix(string $yaml,string $flag,string $name): string
    {
        $lines=[]; $found=false;
        foreach(preg_split('/\R/',$yaml) as $line){
            if(preg_match('/^\s*-\s*\{.*?flag:\s*"([^"]+)"/',$line,$match)){
                if($match[1]!==$flag) continue;
                $found=true;
            }
            $lines[]=$line;
        }
        if(!$found) throw new RuntimeException('The selected hardware model is no longer present in the repository workflow.',409);
        $result=implode("\n",$lines);
        return preg_replace('/^name:.*$/m','name: ESPForge · '.str_replace(["\r","\n"],' ',$name),$result,1)??$result;
    }

    public static function configurationStep(array $selected,array $targets): string
    {
        if(($selected['type']??'')==='platformio_disabled'){
            $path=base64_encode((string)$selected['config_path']); $environment=base64_encode((string)$selected['environment']);
            return <<<YAML
      - name: Enable selected PlatformIO environment
        env:
          ESPFORGE_INI_PATH: "{$path}"
          ESPFORGE_PIO_ENV: "{$environment}"
        run: |
          python - <<'PY'
          import base64, os, pathlib, re
          path = pathlib.Path(base64.b64decode(os.environ["ESPFORGE_INI_PATH"]).decode())
          env = base64.b64decode(os.environ["ESPFORGE_PIO_ENV"]).decode()
          lines = path.read_text(errors="ignore").splitlines(keepends=True)
          output, inside = [], False
          for line in lines:
              header = re.match(r"^(\s*)[;#]\s*\[env:([^]]+)\](.*)$", line, re.I)
              any_header = re.match(r"^\s*(?:[;#]\s*)?\[.+\]", line)
              if header:
                  inside = header.group(2).strip() == env
                  line = f"{header.group(1)}[env:{header.group(2)}]{header.group(3)}"
              elif any_header:
                  inside = False
              elif inside:
                  line = re.sub(r"^(\s*)[;#]\s?", r"\1", line)
              output.append(line)
          path.write_text("".join(output))
          PY

YAML;
        }
        if(($selected['type']??'')!=='arduino_define') return '';
        $macro=(string)$selected['define']; $paths=[]; $macros=[];
        foreach($targets as $target) if(($target['type']??'')==='arduino_define'){
            $macros[]=(string)$target['define']; foreach($target['config_paths']??[] as $path) $paths[]=(string)$path;
        }
        $payload=base64_encode(json_encode(['selected'=>$macro,'macros'=>array_values(array_unique($macros)),'paths'=>array_values(array_unique($paths))],JSON_UNESCAPED_SLASHES));
        return <<<YAML
      - name: Configure selected hardware model
        env:
          ESPFORGE_BOARD_CONFIG: "{$payload}"
        run: |
          python - <<'PY'
          import base64, json, os, pathlib, re
          cfg = json.loads(base64.b64decode(os.environ["ESPFORGE_BOARD_CONFIG"]))
          names = set(cfg["macros"])
          for filename in cfg["paths"]:
              path = pathlib.Path(filename)
              if not path.is_file():
                  continue
              lines = path.read_text(errors="ignore").splitlines(keepends=True)
              output = []
              for line in lines:
                  match = re.match(r"^(\s*)(?://\s*)?#\s*define\s+([A-Z][A-Z0-9_]*)(.*)$", line)
                  if not match or match.group(2) not in names:
                      output.append(line); continue
                  prefix, name, suffix = match.groups()
                  ending = chr(10) if line.endswith(chr(10)) else ""
                  if name == cfg["selected"]:
                      output.append(f"{prefix}#define {name}{suffix.rstrip()}{ending}")
                  else:
                      output.append(f"{prefix}// ESPForge disabled: #define {name}{suffix.rstrip()}{ending}")
              path.write_text("".join(output))
          PY

YAML;
    }

    private static function discoverPlatformIOTargets(GitHubClient $github,string $fullName,string $branch,array $paths): array
    {
        $targets=[];
        // default_envs is commonly used as a board catalogue where a semicolon means
        // "not built by default", not that the [env] itself is unavailable.
        if(in_array('platformio.ini',$paths,true)){
            $root=$github->file($fullName,'platformio.ini',$branch)??''; $reading=false;
            foreach(preg_split('/\R/',$root) as $line){
                if(!$reading && preg_match('/^\s*default_envs\s*=\s*(.*)$/i',$line,$start)){
                    $reading=true; $line=$start[1];
                } elseif($reading && (trim($line)===''||preg_match('/^\s*\[/', $line)||preg_match('/^\s*[A-Za-z0-9_.-]+\s*=/', $line))) break;
                if(!$reading) continue;
                $disabled=preg_match('/^\s*[;#]/',$line)===1;
                $environment=preg_replace('/^\s*[;#]\s*/','',trim($line));
                $environment=preg_replace('/\s+[;#].*$/','',$environment??'');
                $environment=trim((string)$environment," \t\n\r\0\x0B\"'");
                if(!preg_match('/^[A-Za-z0-9_.-]+$/',$environment)||strtolower($environment)==='native') continue;
                $targets[$environment]=['id'=>$environment,'name'=>self::label($environment),'type'=>'platformio','environment'=>$environment,'config_path'=>'platformio.ini','disabled'=>$disabled,'source'=>'default_envs'];
            }
            if($targets) return array_values($targets);
        }

        $files=array_values(array_filter($paths,fn($path)=>preg_match('~(^|/)(?:platformio[^/]*|[^/]*(?:env|board|target)[^/]*)\.ini$~i',$path)));
        foreach(array_slice($files,0,120) as $path){
            $content=$github->file($fullName,$path,$branch); if($content===null) continue;
            preg_match_all('/^\s*([;#]\s*)?\[env:([^\]]+)\]/mi',$content,$matches,PREG_SET_ORDER);
            foreach($matches as $match){
                $environment=trim($match[2]); if(strtolower($environment)==='native') continue;
                $disabled=trim((string)($match[1]??''))!=='';
                $targets[$environment]=['id'=>$environment,'name'=>self::label($environment),'type'=>$disabled?'platformio_disabled':'platformio','environment'=>$environment,'config_path'=>$path,'disabled'=>$disabled,'source'=>'platformio'];
            }
        }
        return array_values($targets);
    }

    private static function discoverEspIdfTargets(GitHubClient $github,string $fullName,string $branch,array $paths): array
    {
        $chips=[]; $valid=['esp32','esp32s2','esp32s3','esp32c3','esp32c5','esp32c6'];
        foreach($paths as $path){
            if(!preg_match('~(^|/)(sdkconfig[^/]*|.*(?:target|board).*\.(?:cmake|conf|txt|defaults))$~i',$path)) continue;
            $normalized=strtolower(preg_replace('/[^a-zA-Z0-9]/','',$path));
            foreach($valid as $chip) if(str_contains($normalized,$chip)) $chips[$chip][]=$path;
            $content=$github->file($fullName,$path,$branch)??'';
            if(preg_match_all('/CONFIG_IDF_TARGET(?:_|=")?(ESP32(?:S2|S3|C3|C5|C6)?)/i',$content,$matches)) foreach($matches[1] as $chip) if(in_array(strtolower($chip),$valid,true)) $chips[strtolower($chip)][]=$path;
        }
        $targets=[]; foreach($chips as $chip=>$configPaths) $targets[]=['id'=>$chip,'name'=>$chip==='esp32'?'ESP32':strtoupper(substr($chip,0,5).'-'.substr($chip,5)),'type'=>'esp-idf','idf_target'=>$chip,'config_paths'=>array_values(array_unique($configPaths)),'source'=>'esp-idf'];
        return $targets;
    }

    private static function discoverBoardDefines(GitHubClient $github,string $fullName,string $branch,array $paths): array
    {
        $candidates=array_values(array_filter($paths,fn($path)=>preg_match('~(^|/)(?:board|boards|boardconfig|config|configs|target|targets|device|devices|pins|settings)[^/]*\.(?:h|hpp|ino|cpp|txt)$~i',$path)));
        $found=[];
        foreach(array_slice($candidates,0,35) as $path){
            $content=$github->file($fullName,$path,$branch); if($content===null) continue;
            preg_match_all('/^\s*(?:\/\/\s*)?#\s*define\s+((?:(?:BOARD|TARGET|DEVICE|MODEL|MARAUDER)_[A-Z0-9_]+|ESP32_(?:S2|S3|C3|C5|C6)(?:_[A-Z0-9_]+)?))\b/m',$content,$matches);
            foreach($matches[1]??[] as $macro){
                if(preg_match('/(?:^BOARD_HAS_|_(?:PIN|ENABLED|DISABLED|VERSION|FEATURE|SUPPORT))$/',$macro)) continue;
                $found[$macro][]=$path;
            }
        }
        $targets=[];
        foreach($found as $macro=>$configPaths){
            $chip=str_contains($macro,'ESP32_DIV_V2')||str_contains($macro,'ESP32_S3')||str_contains($macro,'ESP32S3')||str_contains($macro,'_S3')?'esp32s3':(str_contains($macro,'_S2')?'esp32s2':(str_contains($macro,'_C3')?'esp32c3':(str_contains($macro,'_C6')?'esp32c6':'esp32')));
            $fqbn=$chip==='esp32s3'
                ?'esp32:esp32:esp32s3:PSRAM=enabled,PartitionScheme=min_spiffs,FlashMode=dio'
                :'esp32:esp32:'.$chip;
            $targets[]=['id'=>strtolower($macro),'name'=>self::label($macro),'type'=>'arduino_define','define'=>$macro,'config_paths'=>array_values(array_unique($configPaths)),'fqbn'=>$fqbn,'source'=>'config'];
        }
        return $targets;
    }

    private static function label(string $id): string
    {
        return ucwords(strtolower(str_replace(['_','-'], ' ', $id)));
    }
}
