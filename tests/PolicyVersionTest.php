<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/AppPolicy.php';

$versions=AppPolicy::versions();
foreach(['target_schema','analyzer','prompt','library_prompt','workflow','profile','package_map'] as $name){
    if(empty($versions[$name])||!preg_match('/^[A-Za-z0-9._-]+$/',$versions[$name])){
        fwrite(STDERR,"Missing or invalid policy version: {$name}\n");exit(1);
    }
}
$config=['ai'=>['models'=>['google'=>'gemini-test','openrouter'=>'vendor/model-test']]];
if(AppPolicy::aiModel($config,'google')!=='gemini-test'||AppPolicy::aiModel($config,'openrouter')!=='vendor/model-test'){
    fwrite(STDERR,"Configured AI models were not honored.\n");exit(1);
}
$buildApi=(string)file_get_contents(dirname(__DIR__).'/public/api/builds.php');
if(!str_contains($buildApi,"require __DIR__ . '/../../src/AppPolicy.php';")){
    fwrite(STDERR,"Build API uses AppPolicy without loading it.\n");exit(1);
}
echo "Policy version tests passed.\n";
