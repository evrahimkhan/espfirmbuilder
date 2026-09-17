<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../src/bootstrap.php';

function column_matches(PDO $pdo,string $statement): bool {
    if(!preg_match('/ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+ADD\s+COLUMN\s+`?([A-Za-z0-9_]+)`?\s+([A-Za-z]+(?:\([0-9,]+\))?)(.*)$/is',$statement,$m))return false;
    $q=$pdo->prepare('SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$m[1],$m[2]]);$row=$q->fetch();if(!$row)return false;
    $expectedType=strtolower(preg_replace('/\s+/','',$m[3]));$actualType=strtolower(preg_replace('/\s+/','',(string)$row['COLUMN_TYPE']));$expectedNullable=stripos($m[4],'NOT NULL')===false;
    // MariaDB implements JSON as LONGTEXT with an automatic JSON_VALID check.
    $typeMatches=$expectedType===$actualType||($expectedType==='json'&&in_array($actualType,['json','longtext'],true));
    return $typeMatches&&(($row['IS_NULLABLE']==='YES')===$expectedNullable);
}
function index_matches(PDO $pdo,string $statement): bool {
    if(!preg_match('/CREATE\s+(UNIQUE\s+)?INDEX\s+`?([A-Za-z0-9_]+)`?\s+ON\s+`?([A-Za-z0-9_]+)`?\s*\(([^)]+)\)/i',$statement,$m))return false;
    $expected=array_map(fn($v)=>trim($v," `\t\r\n"),explode(',',$m[4]));$q=$pdo->prepare('SELECT COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX');$q->execute([$m[3],$m[2]]);$rows=$q->fetchAll();$actual=array_column($rows,'COLUMN_NAME');$unique=!empty($rows)&&(int)$rows[0]['NON_UNIQUE']===0;
    return $actual===$expected&&($unique===(trim((string)$m[1])!==''));
}
function current_schema_matches(PDO $pdo): bool {
    $required=['users'=>['id'=>['bigint',null,true],'email'=>['varchar',255,false]],'repositories'=>['id'=>['bigint',null,true],'full_name'=>['varchar',255,false]],'builds'=>['source_commit_sha'=>['char',40,false],'analyzer_version'=>['varchar',40,false],'target_config_json'=>['json',null,false],'workflow_sha256'=>['char',64,false]],'schema_migrations'=>['migration'=>['varchar',190,false],'checksum'=>['char',64,false]]];
    foreach($required as $table=>$columns)foreach($columns as $column=>$expect){$q=$pdo->prepare('SELECT COLUMN_TYPE,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$q->execute([$table,$column]);$row=$q->fetch();if(!$row)return false;$dataType=strtolower((string)$row['DATA_TYPE']);$columnType=strtolower((string)$row['COLUMN_TYPE']);[$type,$length,$unsigned]=$expect;$typeMatches=$type==='json'?in_array($dataType,['json','longtext'],true):$dataType===$type;if(!$typeMatches||($length!==null&&(int)$row['CHARACTER_MAXIMUM_LENGTH']!==$length)||($unsigned&&!str_contains($columnType,'unsigned')))return false;}
    return true;
}

$pdo=db();$directory=__DIR__.'/../database/migrations';$files=glob($directory.'/*.sql')?:[];sort($files,SORT_STRING);
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (migration VARCHAR(190) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if((int)$pdo->query("SELECT GET_LOCK('espforge-schema-migrations',30)")->fetchColumn()!==1){fwrite(STDERR,"Could not acquire migration lock.\n");exit(1);}
try{
    $applied=[];foreach($pdo->query('SELECT migration,checksum FROM schema_migrations')->fetchAll() as $row)$applied[$row['migration']]=$row['checksum'];
    $baseline=null;$baselineSchema=in_array('--baseline-schema',$argv,true);foreach($argv as $argument)if(str_starts_with($argument,'--baseline-through='))$baseline=substr($argument,19);
    if(!$applied&&$baselineSchema){if(!current_schema_matches($pdo))throw new RuntimeException('Current schema does not match the expected fresh schema; refusing full baseline.');$baseline=basename(end($files),'.sql');}
    if(!$applied&&$baseline===null){$hasUsers=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'")->fetchColumn()>0;if($hasUsers){fwrite(STDERR,"Existing database has no migration ledger. Use --baseline-schema for a verified fresh schema, or --baseline-through=VERSION after verifying a legacy schema.\n");exit(2);}}
    foreach($files as $file){
        $name=basename($file,'.sql');$checksum=hash_file('sha256',$file);if($checksum===false)throw new RuntimeException("Cannot checksum {$name}");
        if(isset($applied[$name])){if(!hash_equals($applied[$name],$checksum))throw new RuntimeException("Checksum drift detected for applied migration {$name}");echo "verified {$name}\n";continue;}
        if($baseline!==null&&strcmp($name,$baseline)<=0){$q=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');$q->execute([$name,$checksum]);echo "baselined {$name}\n";continue;}
        $sql=file_get_contents($file);if($sql===false)throw new RuntimeException("Cannot read {$name}");$statements=array_values(array_filter(array_map('trim',preg_split('/;\s*(?:\R|$)/',$sql)?:[])));
        foreach($statements as $statement)try{$pdo->exec($statement);}catch(PDOException $error){$driver=(int)($error->errorInfo[1]??0);$verified=$driver===1060?column_matches($pdo,$statement):($driver===1061?index_matches($pdo,$statement):false);if(!$verified)throw new RuntimeException("Migration {$name} encountered a conflicting existing object: ".$error->getMessage(),0,$error);echo "verified existing object in {$name}\n";}
        $q=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');$q->execute([$name,$checksum]);echo "applied {$name}\n";
    }
    echo "Database schema is current.\n";
}finally{$pdo->query("SELECT RELEASE_LOCK('espforge-schema-migrations')");}
