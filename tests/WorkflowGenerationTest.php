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
    if(!str_contains($workflow,"persist-credentials: false\n          submodules: recursive")){fwrite(STDERR,"Generated workflow does not safely initialize library submodules.\n");exit(1);}
}
if(!str_contains($workflows[0],'platformio==6.1.19')){fwrite(STDERR,"PlatformIO is not pinned to the compatible release.\n");exit(1);}
if(!str_contains($workflows[0],'python-version: "3.12"')){fwrite(STDERR,"PlatformIO Python is not pinned.\n");exit(1);}
if(!str_contains($workflows[2],'376428d7d45be640c00812a71612e1742edc2f5f9ee3742a2d6da7870e079588')){fwrite(STDERR,"Arduino CLI checksum is missing.\n");exit(1);}
$asyncWorkflow=WorkflowEngine::workflow('arduino',['firmware.ino'],'#include <AsyncTCP.h>');
if(!str_contains($asyncWorkflow,'b2e5f4f368b137442f66a9ba7242b8759e6aef59')){fwrite(STDERR,"AsyncTCP is not installed from its pinned upstream commit.\n");exit(1);}
$marauderWorkflow=WorkflowEngine::workflow('arduino',['esp32_marauder/esp32_marauder.ino'],'#define MARAUDER_VERSION "test"');
foreach(['8651c35e977bddfd25bf9ef59a041f6ab243c7f2','6a0912ba05532eab707bd092bd4fa43477e38f5c'] as $sha)if(!str_contains($marauderWorkflow,$sha)){fwrite(STDERR,"Marauder dependency profile is incomplete.\n");exit(1);}
$jsonWorkflow=WorkflowEngine::workflow('arduino',['firmware.ino'],'#include <ArduinoJson.h>');
if(!str_contains($jsonWorkflow,"'ArduinoJson@6.18.2'")){fwrite(STDERR,"ArduinoJson compatibility pin is missing.\n");exit(1);}
if(str_contains($jsonWorkflow,'find . -mindepth 2 -maxdepth 7 -type f -name library.properties')){fwrite(STDERR,"Repository libraries can overwrite pinned dependency versions.\n");exit(1);}
$localWorkflow=WorkflowEngine::workflow('arduino',['firmware.ino','libraries/Local/library.properties'],'');
if(!str_contains($localWorkflow,"if not key or key in installed: continue")){fwrite(STDERR,"Local library precedence protection is missing.\n");exit(1);}
$multiSketch=WorkflowEngine::workflow('arduino',['TestFile.ino','esp32_marauder/esp32_marauder.ino','examples/Demo/Demo.ino'],'');
if(!str_contains($multiSketch,"firmware-output 'esp32_marauder'")){fwrite(STDERR,"Arduino sketch selection chose a test/example instead of the primary sketch.\n");exit(1);}
$compatibility=WorkflowEngine::sourceCompatibilityStep('#define MARAUDER_VERSION "test"\nchar index_html[MAX_HTML_SIZE] = "TEST";\nHardwareSerial Serial2(GPS_SERIAL_INDEX);');
foreach(['EvilPortal.h','operationInProgress','marauder_ieee80211_raw_frame_sanity_check','extern AXP192 axp192_obj','HardwareSerial\\s+Serial2'] as $needle)if(!str_contains($compatibility,$needle)){fwrite(STDERR,"Source compatibility patch is incomplete.\n");exit(1);}
if(str_contains($compatibility,"marauder_ieee80211_raw_frame_sanity_check(', 1)")){fwrite(STDERR,"Raw-frame compatibility rename does not update call sites.\n");exit(1);}
if(!str_contains($compatibility,'#if SOC_UART_NUM <= 2')){fwrite(STDERR,"Serial2 compatibility does not preserve two-UART targets.\n");exit(1);}
$roomyFqbn=WorkflowEngine::compatibleFqbn('esp32:esp32:d32:PartitionScheme=min_spiffs','-DMARAUDER_CYD_2USB','#define MARAUDER_VERSION "test"');
if($roomyFqbn!=='esp32:esp32:d32:PartitionScheme=no_ota'){fwrite(STDERR,"CYD 2 USB does not receive a valid, sufficient application partition.\n");exit(1);}
if(WorkflowEngine::compatibleFqbn('esp32:esp32:d32:PartitionScheme=min_spiffs','-DMARAUDER_V4','#define MARAUDER_VERSION "test"')!=='esp32:esp32:d32:PartitionScheme=no_ota'){fwrite(STDERR,"Marauder V4 does not receive a sufficient application partition.\n");exit(1);}
if(WorkflowEngine::compatibleFqbn('esp32:esp32:d32:PartitionScheme=min_spiffs','-DOTHER_BOARD','#define OTHER_PROJECT')!=='esp32:esp32:d32:PartitionScheme=min_spiffs'){fwrite(STDERR,"Partition compatibility override leaked to a non-Marauder project.\n");exit(1);}
$manifest=WorkflowEngine::manifestStep('arduino','esp32',str_repeat('a',64),'targets-v3');
if(!str_contains($manifest,'ESPFORGE_CONFIG_DIGEST: "'.str_repeat('a',64).'"')||!str_contains($manifest,'"analyzer_version"')){fwrite(STDERR,"Build-plan provenance is missing from the artifact manifest.\n");exit(1);}
if(!str_contains($manifest,"glob('**/flasher_args.json')")||!str_contains($manifest,"glob('**/flash_project_args')")||!str_contains($manifest,'offsets.get(path.name)')){fwrite(STDERR,"Authoritative toolchain flash offsets are not collected.\n");exit(1);}
$namingStep=WorkflowEngine::artifactNamingStep('Marauder CYD 2 USB');
if(!str_contains($namingStep,'ESPFORGE_ARTIFACT_PREFIX: "Marauder-CYD-2-USB"')){fwrite(STDERR,"Hardware-target artifact naming is not sanitized or stable.\n");exit(1);}
foreach(['"bootloader", ".bin"','"partitions", ".bin"','"merged", ".bin"','"application", ".bin"'] as $role)if(!str_contains($namingStep,$role)){fwrite(STDERR,"Artifact role naming is incomplete.\n");exit(1);}
echo "Workflow generation tests passed\n";
