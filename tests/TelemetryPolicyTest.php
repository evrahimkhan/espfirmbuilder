<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$github=(string)file_get_contents($root.'/src/GitHubClient.php');
$ai=(string)file_get_contents($root.'/src/AITargetAnalyzer.php');
$download=(string)file_get_contents($root.'/public/api/build-download.php');
foreach([
    'github API latency'=>'github.api',
    'blob fallback telemetry'=>'github.blob_batch',
    'rate-limit telemetry'=>'x-ratelimit-remaining',
    'retry telemetry'=>'retry-after',
] as $label=>$needle){if(!str_contains($github,$needle)){fwrite(STDERR,"Missing {$label}.\n");exit(1);}}
if(!str_contains($ai,"operational_metric('ai.provider'")){fwrite(STDERR,"Missing AI provider telemetry.\n");exit(1);}
if(!str_contains($download,"operational_metric('build.download'")){fwrite(STDERR,"Missing download telemetry.\n");exit(1);}
if(str_contains($github,"'route'=>")||!str_contains($github,"'route_hash'=>")){fwrite(STDERR,"GitHub metrics must store only a route hash.\n");exit(1);}
echo "Telemetry policy tests passed.\n";
