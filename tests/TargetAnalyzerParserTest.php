<?php
declare(strict_types=1);
require __DIR__.'/../src/TargetAnalyzer.php';

$method=new ReflectionMethod(TargetAnalyzer::class,'parseDefaultEnvironments');
$method->setAccessible(true);
$cases=[
    "[platformio]\ndefault_envs = esp32dev, esp32-s3-devkitc-1\n" => ['esp32dev'=>false,'esp32-s3-devkitc-1'=>false],
    "[platformio]\ndefault_envs =\n  active_board\n  ; disabled_board\n  # disabled_too\n[env:active_board]\n" => ['active_board'=>false,'disabled_board'=>true,'disabled_too'=>true],
    "[platformio]\n; default_envs = archived_board\n" => ['archived_board'=>true],
    "[platformio]\ndefault_envs = native, firmware ; native is ignored\n" => ['firmware'=>false],
];
foreach($cases as $input=>$expected){
    $actual=[];foreach($method->invoke(null,$input) as $entry)$actual[$entry['environment']]=$entry['disabled'];
    if($actual!==$expected){fwrite(STDERR,"Parser mismatch\nExpected: ".json_encode($expected)."\nActual: ".json_encode($actual)."\n");exit(1);}
}
$matrix=new ReflectionMethod(TargetAnalyzer::class,'parseWorkflowMatrix');$matrix->setAccessible(true);
$rows=$matrix->invoke(null,"strategy:\n  matrix:\n    include:\n      - { fqbn: 'esp32:esp32:d32:PartitionScheme=min_spiffs', flag: 'MARAUDER_V4', name: 'Marauder V4' }\n",'.github/workflows/build.yml');
if(count($rows)!==1||($rows[0]['build_flags']??'')!=='-DMARAUDER_V4'||($rows[0]['evidence']??'')!=='.github/workflows/build.yml'){fwrite(STDERR,"Order-independent workflow matrix parsing failed.\n");exit(1);}
$blockYaml=<<<'YAML'
strategy:
  matrix:
    include:
      - &base
        fqbn: esp32:esp32:esp32s3:PSRAM=enabled,PartitionScheme=min_spiffs
        idf_ver: 3.2.1
        flag: MARAUDER_S3
        name: Marauder S3
      - <<: *base
        flag: MARAUDER_S3_ALT
        name: "Marauder S3 Alternate"
      - name: IDF C6
        idf_target: esp32c6
        sdkconfig_file: configs/sdkconfig.c6
YAML;
$blockRows=$matrix->invoke(null,$blockYaml,'.github/workflows/block.yml');
$byName=[];foreach($blockRows as $row)$byName[$row['name']]=$row;
if(count($byName)!==3||($byName['Marauder S3 Alternate']['fqbn']??'')!=='esp32:esp32:esp32s3:PSRAM=enabled,PartitionScheme=min_spiffs'||($byName['IDF C6']['idf_target']??'')!=='esp32c6'){fwrite(STDERR,"Block matrix or YAML anchor parsing failed: ".json_encode($blockRows)."\n");exit(1);}
$merge=new ReflectionMethod(TargetAnalyzer::class,'mergeTargets');$merge->setAccessible(true);
$merged=$merge->invoke(null,[['id'=>'MARAUDER_V4','name'=>'Config','type'=>'arduino_define','define'=>'MARAUDER_V4','source'=>'config'],['id'=>'MARAUDER_V4','name'=>'Workflow','type'=>'arduino','flag'=>'MARAUDER_V4','source'=>'workflow_metadata']]);
if(count($merged)!==1||$merged[0]['name']!=='Workflow'){fwrite(STDERR,"Target provenance merge failed.\n");exit(1);}
$reconcile=new ReflectionMethod(TargetAnalyzer::class,'reconcileAiTargets');$reconcile->setAccessible(true);
$primary=$reconcile->invoke(null,[['id'=>'ai-v4','name'=>'AI V4','type'=>'arduino','fqbn'=>'esp32:esp32:d32:PartitionScheme=min_spiffs','build_flags'=>'-DMARAUDER_V4','source'=>'ai']],[['id'=>'v4','name'=>'Config V4','type'=>'arduino','fqbn'=>'esp32:esp32:d32:PartitionScheme=min_spiffs','build_flags'=>'-DMARAUDER_V4','source'=>'workflow_metadata','evidence'=>'build.yml']]);
if(count($primary)!==1||empty($primary[0]['ai_primary'])||$primary[0]['name']!=='AI V4'||$primary[0]['source']!=='ai_verified'){fwrite(STDERR,"AI-primary deterministic reconciliation failed.\n");exit(1);}
$unverified=$reconcile->invoke(null,[['id'=>'bad','name'=>'Bad','type'=>'arduino','fqbn'=>'esp32:esp32:d32:PartitionScheme=imaginary','source'=>'ai']],[]);
if($unverified!==[]){fwrite(STDERR,"Unverified AI FQBN was accepted.\n");exit(1);}
$matrixYaml="name: Build\n\"on\":\n  workflow_dispatch: {}\njobs:\n  build:\n    strategy:\n      matrix:\n        include:\n          - {name: \"Board\", flag: \"BOARD_ONE\"}\n    steps:\n      - uses: actions/checkout@08c6903cd8c0fde910a37f88322edcfb5dd907a8\n      - run: make\n";
$filtered=TargetAnalyzer::filterMatrix($matrixYaml,'BOARD_ONE','Board');
if(!str_contains($filtered,'espforge_source_commit:')||!str_contains($filtered,'ref: ${{ inputs.espforge_source_commit || github.event.client_payload.espforge_source_commit }}')){fwrite(STDERR,"Matrix workflow checkout is not pinned to the immutable source revision.\n");exit(1);}
$idfStep=TargetAnalyzer::configurationStep(['type'=>'esp-idf','config_path'=>'configs/sdkconfig.s3'],[]);
if(!str_contains($idfStep,'Apply selected ESP-IDF hardware configuration')||!str_contains($idfStep,'shutil.copyfile')){fwrite(STDERR,"ESP-IDF selected configuration is not applied.\n");exit(1);}
echo "TargetAnalyzer parser tests passed\n";
