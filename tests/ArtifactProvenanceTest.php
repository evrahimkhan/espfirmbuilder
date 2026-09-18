<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/ArtifactProvenance.php';
$key='test-signing-key-distinct-and-long-enough-123456';$manifest=['version'=>1,'chip'=>'esp32s3','commit'=>str_repeat('a',40),'files'=>[['path'=>'firmware.bin','size'=>4,'sha256'=>hash('sha256','test'),'offset'=>65536]]];
$signed=ArtifactProvenance::sign($manifest,$key);if(!ArtifactProvenance::verify($signed,$key)){fwrite(STDERR,"Valid provenance signature failed.\n");exit(1);}
$signed['files'][0]['size']=5;if(ArtifactProvenance::verify($signed,$key)){fwrite(STDERR,"Modified manifest retained a valid signature.\n");exit(1);}
$signed=ArtifactProvenance::sign($manifest,$key);$signed['provenance']['signature']=base64_encode(str_repeat("x",64));if(ArtifactProvenance::verify($signed,$key)){fwrite(STDERR,"Forged provenance signature was accepted.\n");exit(1);}
echo "Artifact provenance checks passed.\n";
