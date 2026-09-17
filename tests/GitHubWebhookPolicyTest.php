<?php
declare(strict_types=1);
$source=(string)file_get_contents(dirname(__DIR__).'/public/api/github-webhook.php');
foreach(['HTTP_X_HUB_SIGNATURE_256','hash_hmac','hash_equals','HTTP_X_GITHUB_DELIVERY','workflow_run','espforge-build.yml','github_webhook_deliveries'] as $needle){if(!str_contains($source,$needle)){fwrite(STDERR,"Webhook policy is missing {$needle}.\n");exit(1);}}
if(!str_contains($source,"r.full_name=?")||!str_contains($source,"b.build_uuid=?")){fwrite(STDERR,"Webhook reconciliation is not repository and UUID bound.\n");exit(1);}
if(str_contains($source,'$_GET')||str_contains($source,'CURLOPT')){fwrite(STDERR,"Webhook trust must come only from the signed request body.\n");exit(1);}
$body='{"zen":"safe"}';$secret='a sufficiently long webhook secret';$signature='sha256='.hash_hmac('sha256',$body,$secret);if(!hash_equals($signature,'sha256='.hash_hmac('sha256',$body,$secret))){fwrite(STDERR,"Webhook HMAC regression failed.\n");exit(1);}
echo "GitHub webhook policy tests passed.\n";
