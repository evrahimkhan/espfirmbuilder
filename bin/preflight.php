<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../src/bootstrap.php';

$failures=[];$warnings=[];
foreach(['curl','openssl','pdo_mysql','json'] as $extension) if(!extension_loaded($extension)) $failures[]="Missing PHP extension: {$extension}";
if(!class_exists('ZipArchive')) $failures[]='PHP zip extension is required for secure artifact validation.';
if(!is_dir(sys_get_temp_dir())||!is_writable(sys_get_temp_dir())) $failures[]='PHP temporary directory is not writable.';
if(!filter_var((string)($config['mail']['from']??''),FILTER_VALIDATE_EMAIL)) $failures[]='MAIL_FROM/mail.from is not a valid email address.';
if(($config['app']['env']??'')!=='production') $warnings[]='APP_ENV is not production.';

try{
    $pdo=db();$pdo->query('SELECT 1');
    $required=[
        'users'=>['email_verified_at','session_version','ai_key_fingerprint'],
        'repositories'=>['user_id','full_name'],
        'builds'=>['build_uuid','github_run_id','completed_at'],
        'audit_events'=>['event_type','metadata_json'],
        'password_reset_tokens'=>['token_hash','expires_at'],
        'email_verification_tokens'=>['token_hash','expires_at'],
        'flash_events'=>['chip','firmware_size','manifest_verified'],
        'rate_limits'=>['rate_key','request_count','reset_at'],
    ];
    foreach($required as $table=>$columns){
        $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);$present=$q->fetchAll(PDO::FETCH_COLUMN);
        if(!$present){$failures[]="Missing database table: {$table}";continue;}
        foreach($columns as $column)if(!in_array($column,$present,true))$failures[]="Missing database column: {$table}.{$column}";
    }
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='repositories' AND INDEX_NAME='uq_repositories_user_full_name'");$q->execute();if((int)$q->fetchColumn()===0)$failures[]='Missing repository uniqueness index.';
}catch(Throwable $error){$failures[]='Database check failed: '.$error->getMessage();}

foreach($warnings as $warning)fwrite(STDOUT,"WARN  {$warning}\n");
foreach($failures as $failure)fwrite(STDERR,"FAIL  {$failure}\n");
if($failures){fwrite(STDERR,"\nPreflight failed with ".count($failures)." blocking issue(s).\n");exit(1);}
fwrite(STDOUT,"PASS  ESPForge production preflight completed successfully.\n");
