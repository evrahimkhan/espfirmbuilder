<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../src/bootstrap.php';

$pdo=db();$directory=__DIR__.'/../database/migrations';$files=glob($directory.'/*.sql')?:[];sort($files,SORT_STRING);
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (migration VARCHAR(190) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if((int)$pdo->query("SELECT GET_LOCK('espforge-schema-migrations',30)")->fetchColumn()!==1){fwrite(STDERR,"Could not acquire migration lock.\n");exit(1);}
try{
    $applied=[];foreach($pdo->query('SELECT migration,checksum FROM schema_migrations')->fetchAll() as $row)$applied[$row['migration']]=$row['checksum'];
    $baseline=null;foreach($argv as $argument)if(str_starts_with($argument,'--baseline-through='))$baseline=substr($argument,19);
    if(!$applied&&$baseline===null){
        $hasUsers=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'")->fetchColumn()>0;
        if($hasUsers){fwrite(STDERR,"Existing database has no migration ledger. Run once with --baseline-through=YYYYMMDD_NNN after verifying that migration is already applied.\n");exit(2);}
    }
    foreach($files as $file){
        $name=basename($file,'.sql');$checksum=hash_file('sha256',$file);if($checksum===false)throw new RuntimeException("Cannot checksum {$name}");
        if(isset($applied[$name])){if(!hash_equals($applied[$name],$checksum))throw new RuntimeException("Checksum drift detected for applied migration {$name}");echo "verified {$name}\n";continue;}
        if($baseline!==null&&strcmp($name,$baseline)<=0){$q=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');$q->execute([$name,$checksum]);echo "baselined {$name}\n";continue;}
        $sql=file_get_contents($file);if($sql===false)throw new RuntimeException("Cannot read {$name}");
        // MySQL DDL commits implicitly; the advisory lock and ledger ensure one
        // migrator and make partial completion visible instead of pretending DDL
        // is transactionally rollback-safe.
        $pdo->exec($sql);$q=$pdo->prepare('INSERT INTO schema_migrations(migration,checksum) VALUES(?,?)');$q->execute([$name,$checksum]);echo "applied {$name}\n";
    }
    echo "Database schema is current.\n";
}finally{$pdo->query("SELECT RELEASE_LOCK('espforge-schema-migrations')");}
