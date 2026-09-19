<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/OfficialMetadata.php';
$metadata=OfficialMetadata::pinned();if(!preg_match('/^[a-f0-9]{64}$/',$metadata->digest())){fwrite(STDERR,"Metadata digest is invalid.\n");exit(1);}
foreach([['type'=>'arduino','fqbn'=>'esp32:esp32:esp32s3'],['type'=>'arduino','fqbn'=>'esp32:esp32:esp32c5','core_version'=>'3.3.4'],['type'=>'arduino','fqbn'=>'esp32:esp32:esp32','core_version'=>'2.0.11'],['type'=>'esp-idf','idf_target'=>'esp32c6'],['type'=>'platformio','environment'=>'esp32dev']] as $target)$metadata->validateTarget($target);
$metadata->validateLibraries(['MicroNMEA@2.0.6','NimBLE-Arduino@1.4.2']);
$reviewed=$metadata->reviewedLibraries(['MicroNMEA@2.0.6','Invented Library@9.9.9']);if($reviewed!==['MicroNMEA@2.0.6']){fwrite(STDERR,"Unreviewed AI library proposal was not safely filtered.\n");exit(1);}
foreach([['type'=>'arduino','fqbn'=>'esp32:esp32:not-a-board'],['type'=>'esp-idf','idf_target'=>'linux']] as $bad){try{$metadata->validateTarget($bad);fwrite(STDERR,"Unknown official target was accepted.\n");exit(1);}catch(RuntimeException){}}
echo "Pinned official metadata checks passed.\n";
