<?php
declare(strict_types=1);

final class TargetAnalyzer
{
    public static function discover(GitHubClient $github,string $fullName,string $branch,array $paths,?callable $aiFallback=null): array
    {
        $targets=[];

        // Several mature firmware projects publish their supported hardware as an
        // inline GitHub Actions matrix. Reuse it as the authoritative model list.
        $workflowPaths=array_values(array_filter($paths,fn($path)=>preg_match('~^\.github/workflows/.*\.ya?ml$~i',$path)));
        usort($workflowPaths,fn($a,$b)=>(str_contains($b,'build_parallel')?1:0)<=>(str_contains($a,'build_parallel')?1:0));
        foreach(array_slice($workflowPaths,0,30) as $path){
            $yaml=$github->file($fullName,$path,$branch)??'';
            foreach(preg_split('/\R/',$yaml) as $line){
                if(preg_match('/^\s*-\s*\{.*?name:\s*"([^"]+)".*?flag:\s*"([^"]+)".*?(?:fbqn|fqbn):\s*"([^"]+)"/',$line,$match)){
                    $flag=trim($match[2]);
                    if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$flag))continue;
                    // Treat repository matrices only as hardware metadata. Generate a
                    // fresh compile-only workflow instead of executing publishing or
                    // token-enabled repository workflow steps.
                    $targets[]=['id'=>$flag,'name'=>$match[1],'type'=>'arduino','fqbn'=>$match[3],'flag'=>$flag,'build_flags'=>'-D'.$flag,'source'=>'workflow_metadata'];
                    continue;
                }
                // ESP-IDF repositories commonly pair each board with its chip and
                // an authoritative sdkconfig file in an inline Actions matrix.
                if(preg_match('/^\s*-\s*\{.*?name:\s*"([^"]+)".*?idf_target:\s*"([^"]+)".*?sdkconfig_file:\s*"([^"]+)"/',$line,$match)){
                    $id='idf-'.substr(hash('sha256',$match[3]),0,16);
                    if(!preg_match('/^esp32(?:s2|s3|c3|c5|c6)?$/',$match[2])||!self::safeConfigPath($match[3]))continue;
                    $targets[]=['id'=>$id,'name'=>$match[1],'type'=>'esp-idf','idf_target'=>$match[2],'config_path'=>$match[3],'source'=>'workflow_metadata'];
                }
            }
            if($targets) return $targets;
        }

        $targets=self::discoverPlatformIOTargets($github,$fullName,$branch,$paths);
        if($targets) return $targets;

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

    private static function safeConfigPath(string $path): bool
    {
        return strlen($path)<=300&&!str_starts_with($path,'/')&&!preg_match('~(?:^|/)\.\.(?:/|$)|[\x00-\x1F\x7F]~',$path);
    }

    public static function select(array $targets,string $id): ?array
    {
        foreach($targets as $target) if(hash_equals((string)$target['id'],$id)) return $target;
        return null;
    }

    public static function filterMatrix(string $yaml,string $value,string $name,string $field='flag'): string
    {
        $lines=[]; $found=false; $key=preg_quote($field,'/');
        foreach(preg_split('/\R/',$yaml) as $line){
            if(preg_match('/^\s*-\s*\{.*?'.$key.':\s*"([^"]+)"/',$line,$match)){
                if($match[1]!==$value) continue;
                $found=true;
            }
            $lines[]=$line;
        }
        if(!$found) throw new RuntimeException('The selected hardware model is no longer present in the repository workflow.',409);
        $result=implode("\n",$lines);
        $result=preg_replace('/^name:.*$/m','name: ESPForge · '.str_replace(["\r","\n"],' ',$name),$result,1)??$result;
        self::assertCompileOnlyWorkflow($result);
        return self::addBuildCorrelation($result);
    }

    private static function assertCompileOnlyWorkflow(string $yaml): void
    {
        $dangerous=[
            '/\b(?:softprops\/action-gh-release|actions\/create-release|peaceiris\/actions-gh-pages)@/i',
            '/\b(?:npm\s+publish|docker\s+(?:push|login)|gh\s+release|git\s+push)\b/i',
            '/^\s*environment\s*:/mi', '/\bsecrets\s*\./i',
            '/^\s*[a-z-]+\s*:\s*write\s*$/mi',
            '/^\s*permissions\s*:\s*write-all\s*$/mi',
            '/^\s*permissions\s*:\s*\{[^}]*\bwrite\b[^}]*\}\s*$/mi',
            '/\$\{\{\s*github\.token\s*\}\}/i',
        ];
        foreach($dangerous as $pattern) if(preg_match($pattern,$yaml))
            throw new RuntimeException('This repository matrix includes publishing, deployment, token, or write-enabled behavior. ESPForge refused to execute it; use a compile-only workflow.',422);
        preg_match_all('/^\s*-?\s*uses\s*:\s*(\S+)\s*$/mi',$yaml,$uses);
        foreach($uses[1]??[] as $reference){$reference=trim($reference,"\"'");
            if(str_starts_with($reference,'./')||str_starts_with($reference,'docker://')) continue;
            $at=strrpos($reference,'@');$revision=$at===false?'':substr($reference,$at+1);
            if(!preg_match('/^[a-f0-9]{40}$/i',$revision)) throw new RuntimeException('This repository workflow uses a mutable action reference. Pin every third-party action to a full commit SHA before ESPForge executes it.',422);
        }
    }

    private static function addBuildCorrelation(string $yaml): string
    {
        $expression='${{ inputs.espforge_build_uuid || github.event.client_payload.espforge_build_uuid }}';
        if(!preg_match('/^run-name:/m',$yaml)) $yaml=preg_replace('/^(name:.*)$/m',"$1\nrun-name: ESPForge build {$expression}",$yaml,1)??$yaml;
        if(!preg_match('/^permissions\s*:/m',$yaml)){
            $yaml=preg_replace('/^jobs\s*:/m',"permissions:\n  contents: read\n\njobs:",$yaml,1)??$yaml;
            if(!preg_match('/^permissions\s*:/m',$yaml)) throw new RuntimeException('The repository workflow has no recognizable jobs section.',422);
        }

        // Existing matrix workflows vary widely. Add one dispatch input without
        // touching their jobs, permissions, matrix, release flags, or other inputs.
        if(preg_match('/^(\s*)workflow_dispatch:\s*\{\s*\}\s*$/m',$yaml,$match)){
            $indent=$match[1];
            $replacement=$indent."workflow_dispatch:\n".$indent."  inputs:\n".$indent."    espforge_build_uuid:\n".$indent."      description: Unique ESPForge build identifier\n".$indent."      required: true\n".$indent."      type: string";
            $yaml=preg_replace('/^\s*workflow_dispatch:\s*\{\s*\}\s*$/m',$replacement,$yaml,1)??$yaml;
        } elseif(!preg_match('/^\s+espforge_build_uuid:\s*$/m',$yaml) && preg_match('/^(\s*)workflow_dispatch:\s*\n\1  inputs:\s*$/m',$yaml,$match)) {
            $indent=$match[1];
            $insertion=$match[0]."\n".$indent."    espforge_build_uuid:\n".$indent."      description: Unique ESPForge build identifier\n".$indent."      required: true\n".$indent."      type: string";
            $yaml=preg_replace('/^(\s*)workflow_dispatch:\s*\n\1  inputs:\s*$/m',$insertion,$yaml,1)??$yaml;
        } elseif(!preg_match('/^\s+espforge_build_uuid:\s*$/m',$yaml) && preg_match('/^(\s*)workflow_dispatch:\s*$/m',$yaml,$match)) {
            $indent=$match[1];
            $insertion=$match[0]."\n".$indent."  inputs:\n".$indent."    espforge_build_uuid:\n".$indent."      description: Unique ESPForge build identifier\n".$indent."      required: true\n".$indent."      type: string";
            $yaml=preg_replace('/^\s*workflow_dispatch:\s*$/m',$insertion,$yaml,1)??$yaml;
        }
        return $yaml;
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

    private static function parseDefaultEnvironments(string $ini): array
    {
        $entries=[];$reading=false;$assignmentDisabled=false;
        foreach(preg_split('/\R/',$ini) as $line){
            if(!$reading){
                if(!preg_match('/^\s*([;#]\s*)?default_envs\s*=\s*(.*)$/i',$line,$start)) continue;
                $reading=true;$assignmentDisabled=trim((string)($start[1]??''))!=='';$line=(string)$start[2];
            } elseif(preg_match('/^\s*\[/', $line)||preg_match('/^\s*[A-Za-z0-9_.-]+\s*=/', $line)) break;
            if(trim($line)==='') continue;
            $lineDisabled=$assignmentDisabled||preg_match('/^\s*[;#]/',$line)===1;
            $clean=preg_replace('/^\s*[;#]\s*/','',trim($line))??'';
            // Commas are valid separators in PlatformIO's default_envs option.
            // A whitespace comment is removed only after a complete identifier.
            foreach(preg_split('/\s*,\s*/',$clean) as $candidate){
                $candidate=preg_replace('/\s+[;#].*$/','',trim($candidate))??'';
                $candidate=trim($candidate," \t\n\r\0\x0B\"'");
                if(!preg_match('/^[A-Za-z0-9_.-]+$/',$candidate)||strtolower($candidate)==='native') continue;
                $entries[$candidate]=['environment'=>$candidate,'disabled'=>$lineDisabled];
            }
        }
        return array_values($entries);
    }

    private static function discoverPlatformIOTargets(GitHubClient $github,string $fullName,string $branch,array $paths): array
    {
        $targets=[];
        // default_envs is commonly used as a board catalogue where a semicolon means
        // "not built by default", not that the [env] itself is unavailable.
        if(in_array('platformio.ini',$paths,true)){
            $root=$github->file($fullName,'platformio.ini',$branch)??'';
            foreach(self::parseDefaultEnvironments($root) as $entry){
                $environment=$entry['environment'];
                $targets[$environment]=['id'=>$environment,'name'=>self::label($environment),'type'=>'platformio','environment'=>$environment,'config_path'=>'platformio.ini','disabled'=>$entry['disabled'],'source'=>'default_envs'];
            }
            if($targets) return array_values($targets);
        }

        $files=array_values(array_filter($paths,fn($path)=>
            !preg_match('~(^|/)(?:lib|libs|libraries|vendor|examples?|test|tests)/~i',$path)
            && substr_count($path,'/')<=3
            && preg_match('~(^|/)(?:platformio[^/]*|[^/]*(?:env|board|target)[^/]*)\.ini$~i',$path)
        ));
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
            $fqbn=match($chip){
                'esp32s3'=>'esp32:esp32:esp32s3:PSRAM=enabled,PartitionScheme=min_spiffs,FlashMode=dio',
                'esp32'=>'esp32:esp32:esp32:PartitionScheme=huge_app',
                default=>'esp32:esp32:'.$chip,
            };
            $targets[]=['id'=>strtolower($macro),'name'=>self::label($macro),'type'=>'arduino_define','define'=>$macro,'config_paths'=>array_values(array_unique($configPaths)),'fqbn'=>$fqbn,'source'=>'config'];
        }
        return $targets;
    }

    private static function label(string $id): string
    {
        return ucwords(strtolower(str_replace(['_','-'], ' ', $id)));
    }
}
