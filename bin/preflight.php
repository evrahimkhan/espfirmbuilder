<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../src/bootstrap.php';

$failures=[];$warnings=[];
foreach(['curl','openssl','pdo_mysql','json'] as $extension) if(!extension_loaded($extension)) $failures[]="Missing PHP extension: {$extension}";
if(!class_exists('ZipArchive')) $failures[]='PHP zip extension is required for secure artifact validation.';
if(!function_exists('sodium_crypto_sign_detached')) $failures[]='PHP sodium extension is required for artifact provenance signatures.';
if(strlen((string)($config['security']['artifact_signing_key']??''))<32||str_starts_with((string)($config['security']['artifact_signing_key']??''),'replace-with-')) $failures[]='ARTIFACT_SIGNING_KEY must contain at least 32 random bytes.';
if(hash_equals((string)($config['security']['encryption_key']??''),(string)($config['security']['artifact_signing_key']??''))) $failures[]='ARTIFACT_SIGNING_KEY must be distinct from APP_KEY.';
if(!is_dir(sys_get_temp_dir())||!is_writable(sys_get_temp_dir())) $failures[]='PHP temporary directory is not writable.';
if(!filter_var((string)($config['mail']['from']??''),FILTER_VALIDATE_EMAIL)) $failures[]='MAIL_FROM/mail.from is not a valid email address.';
if(($config['app']['env']??'')!=='production') $warnings[]='APP_ENV is not production.';
if(($config['app']['env']??'')==='production'&&strlen((string)($config['github']['webhook_secret']??''))<24)$failures[]='GITHUB_WEBHOOK_SECRET must contain at least 24 characters.';

try{
    $pdo=db();$pdo->query('SELECT 1');
    $required=[
        'users'=>['email_verified_at','session_version','ai_key_fingerprint'],
        'repositories'=>['user_id','full_name'],
        'builds'=>['build_uuid','target_id','target_name','source_commit_sha','analyzer_version','target_config_json','workflow_sha256','github_run_id','completed_at'],
        'schema_migrations'=>['migration','checksum','applied_at'],
        'audit_events'=>['event_type','metadata_json'],
        'password_reset_tokens'=>['token_hash','expires_at'],
        'email_verification_tokens'=>['token_hash','expires_at'],
        'flash_events'=>['chip','firmware_size','manifest_verified'],
        'rate_limits'=>['rate_key','request_count','reset_at'],
        'operational_metrics'=>['metric_name','duration_ms','outcome','metadata_json','created_at'],
        'analysis_jobs'=>['repo_id','source_commit_sha','analyzer_version','status','attempts','result_encrypted','available_at'],
        'build_plans'=>['plan_uuid','repo_id','source_commit_sha','target_id','target_config_json','workflow_encrypted','workflow_sha256','materialized_at','plan_sha256','status','approved_by','approved_at'],
        'github_webhook_deliveries'=>['delivery_id','event_name','status','created_at'],
    ];
    foreach($required as $table=>$columns){
        $q=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);$present=$q->fetchAll(PDO::FETCH_COLUMN);
        if(!$present){$failures[]="Missing database table: {$table}";continue;}
        foreach($columns as $column)if(!in_array($column,$present,true))$failures[]="Missing database column: {$table}.{$column}";
    }
    $expectedIndexes=[
        'repositories'=>[['user_id','full_name']],
        'builds'=>[['repo_id','created_at'],['repo_id','source_commit_sha','target_id']],
        'operational_metrics'=>[['metric_name','created_at']],
        'analysis_jobs'=>[['status','available_at','id'],['repo_id','source_commit_sha','analyzer_version']],
        'build_plans'=>[['repo_id','status','created_at'],['repo_id','source_commit_sha','analyzer_version','target_id']],
        'github_webhook_deliveries'=>[['created_at']],
    ];
    foreach($expectedIndexes as $table=>$expectedSequences){$q=$pdo->prepare('SELECT INDEX_NAME,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');$q->execute([$table]);$indexes=[];foreach($q->fetchAll() as $row)$indexes[$row['INDEX_NAME']][]=$row['COLUMN_NAME'];foreach($expectedSequences as $expected)if(!in_array($expected,$indexes,true))$failures[]='Missing ordered database index: '.$table.'('.implode(',',$expected).').';}
    $expectedForeignKeys=[
        'repositories.user_id'=>['users','id','CASCADE'],'builds.repo_id'=>['repositories','id','CASCADE'],
        'audit_events.user_id'=>['users','id','SET NULL'],'flash_events.user_id'=>['users','id','CASCADE'],
        'flash_configs.user_id'=>['users','id','CASCADE'],'email_verification_tokens.user_id'=>['users','id','CASCADE'],
        'password_reset_tokens.user_id'=>['users','id','CASCADE'],'analysis_jobs.repo_id'=>['repositories','id','CASCADE'],
        'build_plans.repo_id'=>['repositories','id','CASCADE'],'build_plans.approved_by'=>['users','id','SET NULL'],
    ];
    foreach($expectedForeignKeys as $identity=>$expected){[$table,$column]=explode('.',$identity,2);$q=$pdo->prepare('SELECT k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.COLUMN_NAME=?');$q->execute([$table,$column]);$actual=$q->fetch();if(!$actual||array_values($actual)!==$expected)$failures[]="Database foreign key mismatch: {$identity}.";}
    $q=$pdo->query("SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'");foreach($q->fetchAll() as $table){if(strtoupper((string)$table['ENGINE'])!=='INNODB')$failures[]='Database table must use InnoDB: '.$table['TABLE_NAME'];if(!str_starts_with(strtolower((string)$table['TABLE_COLLATION']),'utf8mb4_'))$failures[]='Database table must use utf8mb4: '.$table['TABLE_NAME'];}
    $expectedDefaults=['repositories.default_branch'=>'main','repositories.status'=>'connected','builds.status'=>'queued','users.session_version'=>'1'];
    foreach($expectedDefaults as $identity=>$expected){[$table,$column]=explode('.',$identity,2);$q=$pdo->prepare('SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$table,$column]);$actualDefault=(string)$q->fetchColumn();if(trim($actualDefault,"'\"")!==$expected)$failures[]="Database default mismatch: {$identity}.";}
    $migrationDirectory=__DIR__.'/../database/migrations';foreach(glob($migrationDirectory.'/*.sql')?:[] as $file){$name=basename($file,'.sql');$q=$pdo->prepare('SELECT checksum FROM schema_migrations WHERE migration=?');$q->execute([$name]);$recorded=$q->fetchColumn();$actual=hash_file('sha256',$file);if($recorded===false)$failures[]="Unapplied database migration: {$name}";elseif(!is_string($actual)||!hash_equals((string)$recorded,$actual))$failures[]="Migration checksum drift: {$name}";}
}catch(Throwable $error){$failures[]='Database check failed: '.$error->getMessage();}

foreach($warnings as $warning)fwrite(STDOUT,"WARN  {$warning}\n");
foreach($failures as $failure)fwrite(STDERR,"FAIL  {$failure}\n");
if($failures){fwrite(STDERR,"\nPreflight failed with ".count($failures)." blocking issue(s).\n");exit(1);}
fwrite(STDOUT,"PASS  ESPForge production preflight completed successfully.\n");
