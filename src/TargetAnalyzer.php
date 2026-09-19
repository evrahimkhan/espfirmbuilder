<?php
declare(strict_types=1);

final class TargetAnalyzer
{
    public static function discover(GitHubClient $github,string $fullName,string $branch,array $paths,?callable $aiFallback=null): array
    {
        $targets=[];

        // Collect every deterministic source instead of returning after the first
        // partial match. This supports mixed Arduino, PlatformIO, and ESP-IDF repos.
        $workflowPaths=array_values(array_filter($paths,fn($path)=>preg_match('~^\.github/workflows/.*\.ya?ml$~i',$path)));
        usort($workflowPaths,fn($a,$b)=>(str_contains($b,'build_parallel')?1:0)<=>(str_contains($a,'build_parallel')?1:0));
        foreach(array_slice($workflowPaths,0,30) as $path){
            $yaml=$github->file($fullName,$path,$branch)??'';
            foreach(self::parseWorkflowMatrix($yaml,$path) as $target)$targets[]=$target;
        }
        foreach(self::discoverPlatformIOTargets($github,$fullName,$branch,$paths) as $target)$targets[]=$target;
        foreach(self::discoverEspIdfTargets($github,$fullName,$branch,$paths) as $target)$targets[]=$target;
        foreach(self::discoverBoardDefines($github,$fullName,$branch,$paths) as $target)$targets[]=$target;
        $deterministic=self::mergeTargets($targets);$aiTargets=[];

        // AI is the primary analyst: it runs for every configured account and its
        // ordering/naming leads the result. Deterministic parsers remain the trust
        // boundary that verifies executable environment/FQBN/configuration facts.
        if($aiFallback){
            try{$proposals=$aiFallback();if(is_array($proposals))$aiTargets=self::mergeTargets($proposals);}
            catch(Throwable $e){error_log('ESPForge AI primary target analysis: '.$e->getMessage());}
        }
        $targets=self::reconcileAiTargets($aiTargets,$deterministic);
        if($targets)return $targets;

        $source=$github->sourceBundle($fullName,$branch,array_map(fn($path)=>['path'=>$path,'type'=>'blob','size'=>0],$paths),20);
        $s3=preg_match('/^\s*#\s*define\s+BOARD_ESP32_DIV_V2\b/m',$source)===1;
        return [[
            'id'=>$s3?'fallback:esp32s3':'fallback:esp32','name'=>$s3?'ESP32-S3 (inferred)':'ESP32 (inferred)','type'=>'arduino',
            'fqbn'=>$s3?'esp32:esp32:esp32s3:PSRAM=enabled,PartitionScheme=min_spiffs,FlashMode=dio':'esp32:esp32:esp32','source'=>'fallback','warning'=>'Hardware was inferred because no authoritative target configuration was found.'
        ]];
    }

    private static function parseWorkflowMatrix(string $yaml,string $path): array
    {
        $fieldRows=[];
        // Bounded flow-style rows, independent of key order and quote style.
        preg_match_all('/^\s*-\s*\{([^{}]{1,4000})\}\s*$/m',$yaml,$rows);
        foreach($rows[1]??[] as $row){$fields=[];preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)\s*:\s*(?:"([^"]*)"|\'([^\']*)\'|([^,]+))/', $row,$pairs,PREG_SET_ORDER);foreach($pairs as $pair)$fields[strtolower($pair[1])]=self::yamlScalar((string)($pair[2]!==''?$pair[2]:($pair[3]!==''?$pair[3]:$pair[4])));if($fields)$fieldRows[]=$fields;}

        // Parse block-style matrix.include rows and safe YAML anchor merges. This
        // deliberately handles scalar metadata only; executable YAML is never
        // interpreted and still passes the compile-only workflow policy later.
        $lines=preg_split('/\R/',$yaml)?:[];$anchors=[];$inInclude=false;$includeIndent=-1;$current=null;$rowIndent=-1;
        $flush=static function()use(&$current,&$fieldRows,&$anchors):void{if(!is_array($current))return;$alias=$current['<<']??null;unset($current['<<']);if(is_string($alias)&&isset($anchors[$alias]))$current=array_replace($anchors[$alias],$current);if(isset($current['@anchor'])){$anchors[$current['@anchor']]=$current;unset($current['@anchor']);}$fieldRows[]=$current;$current=null;};
        foreach($lines as $line){if(strlen($line)>4000)continue;$indent=strlen($line)-strlen(ltrim($line));
            if(!$inInclude){if(preg_match('/^\s*include\s*:\s*(?:#.*)?$/',$line)){$inInclude=true;$includeIndent=$indent;}continue;}
            if(trim($line)===''||preg_match('/^\s*#/',$line))continue;
            if($indent<=$includeIndent){$flush();$inInclude=false;continue;}
            if(preg_match('/^\s*-\s*(?:&([A-Za-z_][A-Za-z0-9_-]*)\s*)?(.*)$/',$line,$startRow)){$flush();$current=[];$rowIndent=$indent;if(($startRow[1]??'')!=='')$current['@anchor']=$startRow[1];$line=(string)$startRow[2];if($line==='')continue;}
            elseif(!is_array($current)||$indent<=$rowIndent)continue;
            if(preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*|<<)\s*:\s*(.*?)\s*(?:#.*)?$/',$line,$pair)){$value=self::yamlScalar($pair[2]);if($pair[1]==='<<'&&preg_match('/^\*([A-Za-z_][A-Za-z0-9_-]*)$/',$value,$alias))$value=$alias[1];$current[strtolower($pair[1])]=$value;}
        }$flush();

        $targets=[];
        foreach(array_slice($fieldRows,0,300) as $fields){$name=(string)($fields['name']??'');$flag=(string)($fields['flag']??'');$fqbn=(string)($fields['fqbn']??$fields['fbqn']??'');
            if($name!==''&&preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$flag)&&self::validEsp32Fqbn($fqbn)){$target=['id'=>'arduino:'.strtolower($flag),'name'=>substr($name,0,120),'type'=>'arduino','fqbn'=>$fqbn,'flag'=>$flag,'build_flags'=>'-D'.$flag,'source'=>'workflow_metadata','evidence'=>$path];if(preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',(string)($fields['idf_ver']??'')))$target['core_version']=$fields['idf_ver'];if(preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/',(string)($fields['nimble_ver']??'')))$target['nimble_version']=$fields['nimble_ver'];if(preg_match('/^[A-Za-z0-9_.-]+\.h$/',(string)($fields['tft_file']??'')))$target['tft_setup']=$fields['tft_file'];$targets[]=$target;continue;}
            $chip=strtolower((string)($fields['idf_target']??''));$config=(string)($fields['sdkconfig_file']??'');if($name!==''&&preg_match('/^esp32(?:s2|s3|c3|c5|c6)?$/',$chip)&&self::safeConfigPath($config))$targets[]=['id'=>'esp-idf:'.substr(hash('sha256',$config),0,16),'name'=>substr($name,0,120),'type'=>'esp-idf','idf_target'=>$chip,'config_path'=>$config,'source'=>'workflow_metadata','evidence'=>$path];
        }return $targets;
    }

    private static function yamlScalar(string $value): string
    {
        $value=trim($value);if(strlen($value)>1000)return '';
        if((str_starts_with($value,'"')&&str_ends_with($value,'"'))||(str_starts_with($value,"'")&&str_ends_with($value,"'")))$value=substr($value,1,-1);
        return trim($value);
    }

    private static function validEsp32Fqbn(string $fqbn): bool
    {
        if(!preg_match('/^esp32:esp32:([A-Za-z0-9_.-]+)(?::(.*))?$/',$fqbn,$match))return false;
        if(empty($match[2]))return true;
        foreach(explode(',',$match[2]) as $option)if(!preg_match('/^[A-Za-z0-9_.-]+=[A-Za-z0-9_.-]+$/',$option))return false;
        return true;
    }

    private static function mergeTargets(array $targets): array
    {
        $merged=[];
        foreach($targets as $target){if(!is_array($target)||empty($target['id'])||empty($target['type']))continue;$identity=match($target['type']){'platformio','platformio_disabled'=>'platformio:'.strtolower((string)($target['environment']??$target['id'])),'arduino','arduino_define'=>'arduino:'.strtolower((string)($target['flag']??$target['define']??$target['id'])),'esp-idf'=>'esp-idf:'.strtolower((string)($target['idf_target']??'')).':'.strtolower((string)($target['config_path']??implode(',',(array)($target['config_paths']??[])))),default=>(string)$target['type'].':'.strtolower((string)$target['id'])};if(!isset($merged[$identity])||($target['source']??'')==='workflow_metadata'){$target['id']=$identity;$merged[$identity]=$target;}}
        return array_values($merged);
    }

    private static function reconcileAiTargets(array $ai,array $deterministic): array
    {
        $result=[];$used=[];
        foreach($ai as $proposal){$match=null;$matchIndex=null;
            foreach($deterministic as $index=>$candidate){if(($proposal['type']??'')!==($candidate['type']??'')&&!(str_starts_with((string)($candidate['type']??''),'platformio')&&($proposal['type']??'')==='platformio')&&!(in_array($candidate['type']??'', ['arduino','arduino_define'],true)&&($proposal['type']??'')==='arduino'))continue;$same=match($proposal['type']??''){'platformio'=>strcasecmp((string)($proposal['environment']??''),(string)($candidate['environment']??''))===0,'esp-idf'=>strcasecmp((string)($proposal['idf_target']??''),(string)($candidate['idf_target']??''))===0,'arduino'=>(isset($proposal['fqbn'],$candidate['fqbn'])&&strcasecmp((string)$proposal['fqbn'],(string)$candidate['fqbn'])===0)||(isset($proposal['build_flags'],$candidate['build_flags'])&&trim((string)$proposal['build_flags'])===trim((string)$candidate['build_flags'])),default=>false};if($same){$match=$candidate;$matchIndex=$index;break;}}
            if($match!==null){$verified=array_replace($proposal,$match);$verified['name']=$proposal['name']??$match['name'];$verified['source']='ai_verified';$verified['ai_primary']=true;$verified['evidence']=$match['evidence']??$match['config_path']??implode(', ',(array)($match['config_paths']??[]));$result[]=$verified;$used[$matchIndex]=true;continue;}
            if(self::verifiedStandaloneAiTarget($proposal)){$proposal['source']='ai_verified';$proposal['ai_primary']=true;$proposal['requires_confirmation']=true;$proposal['warning']='AI selected this generic board because the repository has no authoritative hardware configuration. Review the FQBN before building.';$proposal['evidence']='Validated against ESPForge built-in ESP32 board policy';$result[]=$proposal;}
        }
        foreach($deterministic as $index=>$candidate)if(!isset($used[$index]))$result[]=$candidate;
        return self::mergeTargets($result);
    }

    private static function verifiedStandaloneAiTarget(array $target): bool
    {
        if(($target['type']??'')==='arduino'){
            $fqbn=(string)($target['fqbn']??'');$generic=['esp32','esp32s2','esp32s3','esp32c3','esp32c5','esp32c6'];
            return preg_match('/^esp32:esp32:([a-z0-9]+)$/',$fqbn,$match)===1&&in_array($match[1],$generic,true);
        }
        // PlatformIO environments and ESP-IDF config pairings must be proven by
        // repository configuration; AI cannot invent either executable identity.
        return false;
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
        if(!preg_match('/^\s+espforge_source_commit:\s*$/m',$yaml)){
            $yaml=preg_replace('/^(\s+espforge_build_uuid:\s*\n\s+description:.*\n\s+required:\s*true\s*\n(\s+)type:\s*string\s*)$/m',"$1\n$2espforge_source_commit:\n$2  description: Immutable source revision to compile\n$2  required: true\n$2  type: string",$yaml,1)??$yaml;
        }
        $sourceExpression='${{ inputs.espforge_source_commit || github.event.client_payload.espforge_source_commit }}';
        if(!str_contains($yaml,'inputs.espforge_source_commit')&&!str_contains($yaml,'client_payload.espforge_source_commit'))throw new RuntimeException('The repository workflow could not accept an immutable source revision.',422);
        if(!preg_match('/^\s+ref:\s*\$\{\{\s*inputs\.espforge_source_commit/m',$yaml)){
            $checkoutPattern='/^(\s*)- uses:\s*actions\/checkout@([a-f0-9]{40})\s*\n(?:(\1  with:)\s*\n)?/mi';
            $yaml=preg_replace_callback($checkoutPattern,static function(array $match)use($sourceExpression):string{$indent=$match[1];$base=$indent.'- uses: actions/checkout@'.$match[2]."\n";if(!empty($match[3]))return $base.$match[3]."\n".$indent.'    ref: '.$sourceExpression."\n";return $base.$indent."  with:\n".$indent.'    ref: '.$sourceExpression."\n";},$yaml,1)??$yaml;
        }
        if(!str_contains($yaml,'ref: '.$sourceExpression))throw new RuntimeException('The repository workflow checkout could not be pinned to the immutable source revision.',422);
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
        if(($selected['type']??'')==='esp-idf'){
            $config=(string)($selected['config_path']??(($selected['config_paths'][0]??'')));
            if($config===''||!self::safeConfigPath($config))return '';
            $encoded=base64_encode($config);
            return <<<YAML
      - name: Apply selected ESP-IDF hardware configuration
        env:
          ESPFORGE_SDKCONFIG: "{$encoded}"
        run: |
          python - <<'PY'
          import base64, os, pathlib, shutil
          source = pathlib.Path(base64.b64decode(os.environ["ESPFORGE_SDKCONFIG"]).decode())
          if not source.is_file():
              raise SystemExit(f"Selected sdkconfig file does not exist: {source}")
          destination = pathlib.Path("sdkconfig")
          if source.resolve() != destination.resolve():
              shutil.copyfile(source, destination)
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
        $targets=[];$valid=['esp32','esp32s2','esp32s3','esp32c3','esp32c5','esp32c6'];
        foreach($paths as $path){
            if(!preg_match('~(^|/)(sdkconfig[^/]*|.*(?:target|board).*\.(?:cmake|conf|txt|defaults))$~i',$path))continue;
            $content=$github->file($fullName,$path,$branch)??'';$chips=[];
            if(preg_match_all('/CONFIG_IDF_TARGET(?:_[A-Z0-9_]+)?\s*=\s*["\']?(esp32(?:s2|s3|c3|c5|c6)?)/i',$content,$matches))foreach($matches[1] as $chip)$chips[strtolower($chip)]=true;
            if(!$chips){$normalized=strtolower(preg_replace('/[^a-zA-Z0-9]/','',$path));foreach(array_reverse($valid) as $chip)if(str_contains($normalized,$chip)){$chips[$chip]=true;break;}}
            // Shared/ambiguous configs are not selectable until a deterministic
            // chip-to-config relationship can be proven.
            if(count($chips)!==1)continue;$chip=array_key_first($chips);if(!in_array($chip,$valid,true)||!self::safeConfigPath($path))continue;
            $label=$chip==='esp32'?'ESP32':strtoupper(substr($chip,0,5).'-'.substr($chip,5));$targets[]=['id'=>'esp-idf:'.substr(hash('sha256',$chip."\0".$path),0,16),'name'=>$label.' · '.basename($path),'type'=>'esp-idf','idf_target'=>$chip,'config_path'=>$path,'source'=>'esp-idf','evidence'=>$path];
        }
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
