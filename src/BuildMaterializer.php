<?php
declare(strict_types=1);

final class BuildMaterializer
{
    public static function materialize(GitHubClient $github,string $repository,string $commit,array $entries,array $paths,array $target,array $targets,array $aiLibraries=[],?string $preparedArduinoSource=null): array
    {
        $analysis=WorkflowEngine::analyze($paths);$type=(string)($target['type']??'');
        $framework=in_array($type,['arduino','arduino_define'],true)?'arduino':($type==='esp-idf'?'esp-idf':$analysis['framework']);
        $config=json_encode($target,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$configDigest=hash('sha256',$config);
        if($type==='workflow_matrix'){
            $path=(string)($target['workflow_path']??'');$original=$github->file($repository,$path,$commit);if(!$original)throw new RuntimeException('The repository hardware workflow could not be read.');
            $workflow=TargetAnalyzer::filterMatrix($original,(string)$target['flag'],(string)$target['name'],(string)($target['matrix_field']??'flag'));
            return ['framework'=>$framework,'workflow'=>$workflow,'workflow_sha256'=>hash('sha256',$workflow),'configuration_sha256'=>$configDigest];
        }
        $source=$framework==='arduino'?($preparedArduinoSource??$github->sourceBundle($repository,$commit,$entries)):'';$workflow=WorkflowEngine::workflow($framework,$paths,$source,$aiLibraries);
        if($framework==='arduino'){$compatibility=WorkflowEngine::sourceCompatibilityStep($source);if($compatibility!=='')$workflow=str_replace('      - name: Compile firmware',$compatibility.'      - name: Compile firmware',$workflow);}
        if($framework==='arduino'&&!empty($target['core_version']))$workflow=preg_replace('/esp32:esp32@[0-9]+\.[0-9]+\.[0-9]+/','esp32:esp32@'.$target['core_version'],$workflow)??$workflow;
        if($framework==='arduino'&&!empty($target['nimble_version']))$workflow=preg_replace('/NimBLE-Arduino@[0-9]+\.[0-9]+\.[0-9]+/','NimBLE-Arduino@'.$target['nimble_version'],$workflow)??$workflow;
        if($framework==='arduino'&&!empty($target['tft_setup'])){$setup=escapeshellarg((string)$target['tft_setup']);$workflow=str_replace('      - name: Compile firmware',"      - name: Configure display for selected hardware\n        run: cp {$setup} \"\$HOME/Arduino/libraries/TFT_eSPI/User_Setup.h\"\n      - name: Compile firmware",$workflow);}
        if(in_array($type,['platformio','platformio_disabled'],true))$workflow=str_replace('run: pio run','run: pio run -e '.escapeshellarg((string)$target['environment']),$workflow);
        if(in_array($type,['arduino','arduino_define'],true)&&!empty($target['fqbn'])){$flags=(string)($target['build_flags']??'');$fqbn=WorkflowEngine::compatibleFqbn((string)$target['fqbn'],$flags,$source);$replacement='--fqbn "'.$fqbn.'"'.($flags!==''?' --build-property compiler.cpp.extra_flags="'.$flags.'"':'');$workflow=preg_replace('/--fqbn "[^"]+"/',$replacement,$workflow,1)??$workflow;$workflow=str_replace('      - name: Compile firmware',"      - name: Validate selected Arduino board configuration\n        run: arduino-cli board details --fqbn \"{$fqbn}\" >/dev/null\n      - name: Compile firmware",$workflow);}
        $step=TargetAnalyzer::configurationStep($target,$targets);if($step!==''){$marker=str_starts_with($type,'platformio')?'      - name: Build firmware':($type==='esp-idf'?'      - uses: espressif/esp-idf-ci-action@':'      - name: Compile firmware');$workflow=str_replace($marker,$step.$marker,$workflow);}
        if($type==='esp-idf'&&!empty($target['idf_target']))$workflow=preg_replace('/target:\s*esp32\b/','target: '.$target['idf_target'],$workflow,1)??$workflow;
        $chip=self::chip($target);$marker='      - uses: actions/upload-artifact@';$workflow=str_replace($marker,WorkflowEngine::artifactNamingStep((string)$target['name']).WorkflowEngine::manifestStep($framework,$chip,$configDigest,AppPolicy::ANALYZER_VERSION).$marker,$workflow);
        return ['framework'=>$framework,'workflow'=>$workflow,'workflow_sha256'=>hash('sha256',$workflow),'configuration_sha256'=>$configDigest];
    }
    private static function chip(array $target): string
    {
        $chip=strtolower((string)($target['idf_target']??''));if($chip===''&&preg_match('/^esp32:esp32:([a-z0-9]+)/i',(string)($target['fqbn']??''),$m))$chip=in_array(strtolower($m[1]),['esp32s2','esp32s3','esp32c3','esp32c5','esp32c6'],true)?strtolower($m[1]):'esp32';if($chip===''&&preg_match('/esp32(?:s2|s3|c3|c5|c6)?/i',(string)($target['id']??''),$m))$chip=strtolower($m[0]);return in_array($chip,['esp32','esp32s2','esp32s3','esp32c3','esp32c5','esp32c6'],true)?$chip:'esp32';
    }
}
