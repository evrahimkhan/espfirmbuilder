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
if(!str_contains($workflows[0],'platformio==6.1.18')){fwrite(STDERR,"PlatformIO is not pinned.\n");exit(1);}
if(!str_contains($workflows[2],'376428d7d45be640c00812a71612e1742edc2f5f9ee3742a2d6da7870e079588')){fwrite(STDERR,"Arduino CLI checksum is missing.\n");exit(1);}
echo "Workflow generation tests passed\n";
