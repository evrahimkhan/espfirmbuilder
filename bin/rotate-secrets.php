<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../src/bootstrap.php';

$pdo=null;$rotated=0;
try{
    $pdo=db();$pdo->beginTransaction();
    $rows=$pdo->query('SELECT id,github_token,ai_api_key FROM users ORDER BY id FOR UPDATE')->fetchAll();
    $update=$pdo->prepare('UPDATE users SET github_token=?,ai_api_key=?,ai_key_fingerprint=? WHERE id=?');
    foreach($rows as $row){
        $github=decrypt_secret($row['github_token']??null);$ai=decrypt_secret($row['ai_api_key']??null);
        if(!empty($row['github_token'])&&$github===null) throw new RuntimeException('Cannot decrypt GitHub token for user '.$row['id'].'. Keep the required previous key configured.');
        if(!empty($row['ai_api_key'])&&$ai===null) throw new RuntimeException('Cannot decrypt AI key for user '.$row['id'].'. Keep the required previous key configured.');
        $update->execute([$github!==null?encrypt_secret($github):null,$ai!==null?encrypt_secret($ai):null,$ai!==null?ai_key_fingerprint($ai):null,$row['id']]);$rotated++;
    }
    $pdo->commit();fwrite(STDOUT,"Rotated encrypted secrets for {$rotated} users. Verify the application before removing APP_PREVIOUS_KEYS.\n");
}catch(Throwable $error){if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,"Secret rotation failed; no changes were committed: {$error->getMessage()}\n");exit(1);}
