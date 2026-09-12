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
echo "TargetAnalyzer parser tests passed\n";
