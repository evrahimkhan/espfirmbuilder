<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/MarauderProfile.php';
require dirname(__DIR__).'/src/WorkflowEngine.php';
if(MarauderProfile::matches('ordinary firmware')||!MarauderProfile::matches('#define MARAUDER_CYD_2USB')){fwrite(STDERR,"Marauder profile detection failed.\n");exit(1);}
if(MarauderProfile::compatibilityStep('ordinary firmware')!==''||!str_contains(MarauderProfile::compatibilityStep('MARAUDER_VERSION'),'Fix source compatibility')){fwrite(STDERR,"Marauder compatibility profile isolation failed.\n");exit(1);}
if(WorkflowEngine::compatibleFqbn('esp32:esp32:d32:PartitionScheme=min_spiffs','-DMARAUDER_CYD_2USB','MARAUDER_VERSION')!=='esp32:esp32:d32:PartitionScheme=no_ota'){fwrite(STDERR,"Marauder FQBN profile failed.\n");exit(1);}
$engine=(string)file_get_contents(dirname(__DIR__).'/src/WorkflowEngine.php');
if(str_contains($engine,'HardwareSerial Serial2(GPS_SERIAL_INDEX)')||str_contains($engine,'marauder_ieee80211_raw_frame_sanity_check')){fwrite(STDERR,"Repository-specific patch code leaked into WorkflowEngine.\n");exit(1);}
echo "Project profile tests passed.\n";
