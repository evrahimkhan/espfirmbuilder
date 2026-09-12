<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../src/bootstrap.php';

$pdo=null;
try{
    $pdo=db();$pdo->beginTransaction();$counts=[];
    $statements=[
        'password reset tokens'=>"DELETE FROM password_reset_tokens WHERE expires_at<NOW() OR used_at<DATE_SUB(NOW(),INTERVAL 7 DAY)",
        'email verification tokens'=>"DELETE FROM email_verification_tokens WHERE expires_at<NOW() OR used_at<DATE_SUB(NOW(),INTERVAL 7 DAY)",
        'audit events'=>"DELETE FROM audit_events WHERE created_at<DATE_SUB(NOW(),INTERVAL 180 DAY)",
        'flash events'=>"DELETE FROM flash_events WHERE created_at<DATE_SUB(NOW(),INTERVAL 730 DAY)",
    ];
    foreach($statements as $label=>$sql){$counts[$label]=$pdo->exec($sql);}
    $pdo->commit();
    $rateDirectory=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'espforge-rate-limits';$counts['expired rate-limit files']=0;
    foreach(glob($rateDirectory.DIRECTORY_SEPARATOR.'*.json')?:[] as $file){if(!is_file($file)||filemtime($file)>time()-86400)continue;$record=json_decode((string)@file_get_contents($file),true);if(!is_array($record)||(int)($record['reset']??0)<time()){if(@unlink($file))$counts['expired rate-limit files']++;}}
    foreach($counts as $label=>$count)fwrite(STDOUT,"Pruned {$count} {$label}.\n");
}catch(Throwable $error){if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,"Data pruning failed: {$error->getMessage()}\n");exit(1);}
