<?php
declare(strict_types=1);
require __DIR__.'/../src/TargetAnalyzer.php';

$assert=new ReflectionMethod(TargetAnalyzer::class,'assertCompileOnlyWorkflow');$assert->setAccessible(true);
$decorate=new ReflectionMethod(TargetAnalyzer::class,'addBuildCorrelation');$decorate->setAccessible(true);
$safe="name: Build\n'on':\n  workflow_dispatch: {}\njobs:\n  build:\n    steps:\n      - uses: actions/checkout@08c6903cd8c0fde910a37f88322edcfb5dd907a8\n      - run: make\n";
$assert->invoke(null,$safe);$decorated=$decorate->invoke(null,$safe);
if(!str_contains($decorated,"permissions:\n  contents: read")||!str_contains($decorated,'espforge_build_uuid')){fwrite(STDERR,"Safe workflow was not hardened.\n");exit(1);}
$unsafe=[
    str_replace('@08c6903cd8c0fde910a37f88322edcfb5dd907a8','@v4',$safe),
    $safe."\npermissions: write-all\n",
    $safe."\n      - run: echo \${{ secrets.DEPLOY_KEY }}\n",
];
foreach($unsafe as $yaml){try{$assert->invoke(null,$yaml);fwrite(STDERR,"Unsafe workflow was accepted.\n");exit(1);}catch(ReflectionException $error){throw $error;}catch(Throwable $expected){}}
echo "Workflow safety tests passed\n";
