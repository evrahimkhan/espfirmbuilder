<?php
declare(strict_types=1);
require __DIR__.'/../src/WorkflowEngine.php';

$workflows=[
    WorkflowEngine::workflow('platformio',['platformio.ini']),
    WorkflowEngine::workflow('esp-idf',['CMakeLists.txt','main/CMakeLists.txt']),
    WorkflowEngine::workflow('arduino',['firmware.ino'],'#include <ArduinoJson.h>'),
];
foreach($workflows as $workflow){
    if(preg_match('/uses:\s+[^\s]+@(?![a-f0-9]{40}(?:\s|$))/i',$workflow)){fwrite(STDERR,"Generated workflow contains a mutable action reference.\n");exit(1);}
    if(preg_match('/curl[^\n|]*\|\s*(?:sh|bash)/i',$workflow)){fwrite(STDERR,"Generated workflow contains a remote shell pipeline.\n");exit(1);}
    if(!str_contains($workflow,"permissions:\n  contents: read")){fwrite(STDERR,"Generated workflow is not read-only.\n");exit(1);}
}
if(!str_contains($workflows[0],'platformio==6.1.19')){fwrite(STDERR,"PlatformIO is not pinned to the compatible release.\n");exit(1);}
if(!str_contains($workflows[0],'python-version: "3.12"')){fwrite(STDERR,"PlatformIO Python is not pinned.\n");exit(1);}
if(!str_contains($workflows[2],'376428d7d45be640c00812a71612e1742edc2f5f9ee3742a2d6da7870e079588')){fwrite(STDERR,"Arduino CLI checksum is missing.\n");exit(1);}
$multiSketch=WorkflowEngine::workflow('arduino',['TestFile.ino','esp32_marauder/esp32_marauder.ino','examples/Demo/Demo.ino'],'');
if(!str_contains($multiSketch,"firmware-output 'esp32_marauder'")){fwrite(STDERR,"Arduino sketch selection chose a test/example instead of the primary sketch.\n");exit(1);}
$namingStep=WorkflowEngine::artifactNamingStep('Marauder CYD 2 USB');
if(!str_contains($namingStep,'ESPFORGE_ARTIFACT_PREFIX: "Marauder-CYD-2-USB"')){fwrite(STDERR,"Hardware-target artifact naming is not sanitized or stable.\n");exit(1);}
echo "Workflow generation tests passed\n";
