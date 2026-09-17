<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/AppPolicy.php';
require __DIR__.'/../src/GitHubClient.php';
require __DIR__.'/../src/TargetAnalyzer.php';
require __DIR__.'/../src/AITargetAnalyzer.php';

$limit=max(1,min(10,(int)($argv[1]??3)));$processed=0;
while($processed<$limit){
    $pdo=db();$pdo->beginTransaction();
    $job=$pdo->query("SELECT j.*,r.user_id,r.full_name,r.default_branch,u.github_token,u.ai_provider,u.ai_api_key,u.ai_key_fingerprint FROM analysis_jobs j JOIN repositories r ON r.id=j.repo_id JOIN users u ON u.id=r.user_id WHERE j.status='queued' AND j.available_at<=NOW() ORDER BY j.id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
    if(!$job){$pdo->commit();break;}
    $pdo->prepare("UPDATE analysis_jobs SET status='processing',attempts=attempts+1,started_at=NOW(),error_message=NULL WHERE id=?")->execute([$job['id']]);$pdo->commit();
    $started=microtime(true);
    try{
        $token=decrypt_secret($job['github_token']??null);if(!$token)throw new RuntimeException('GitHub connection is unavailable.');
        $github=new GitHubClient($token);$tree=$github->tree($job['full_name'],$job['source_commit_sha']);$paths=array_column($tree['tree']??[],'path');
        $provider=(string)($job['ai_provider']??'');$key=decrypt_secret($job['ai_api_key']??null);
        $fallback=$key&&in_array($provider,['google','openrouter'],true)?fn()=>(new AITargetAnalyzer($provider,$key,AppPolicy::aiModel($config,$provider)))->discover($github,$job['full_name'],$job['source_commit_sha'],$paths):null;
        $targets=TargetAnalyzer::discover($github,$job['full_name'],$job['source_commit_sha'],$paths,$fallback);
        $payload=json_encode(['targets'=>$targets,'ai'=>(bool)$key,'time'=>time()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);if(strlen($payload)>2*1024*1024)throw new RuntimeException('Analysis result exceeds the storage limit.');
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE build_plans SET status='superseded' WHERE repo_id=? AND source_commit_sha<>? AND status IN ('ready','approved')")->execute([$job['repo_id'],$job['source_commit_sha']]);
        $insert=$pdo->prepare("INSERT INTO build_plans(plan_uuid,repo_id,source_commit_sha,analyzer_version,target_id,target_name,target_config_json,plan_sha256,status) VALUES(?,?,?,?,?,?,?,?,'ready') ON DUPLICATE KEY UPDATE target_name=VALUES(target_name),target_config_json=VALUES(target_config_json),plan_sha256=VALUES(plan_sha256),status=IF(status IN ('dispatched','approved'),status,'ready')");
        foreach($targets as &$target){$configJson=json_encode($target,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);$digest=hash('sha256',$job['source_commit_sha']."\0".AppPolicy::ANALYZER_VERSION."\0".$configJson);$insert->execute([uuid_v4(),$job['repo_id'],$job['source_commit_sha'],AppPolicy::ANALYZER_VERSION,substr((string)$target['id'],0,190),substr((string)$target['name'],0,120),$configJson,$digest]);$q=$pdo->prepare('SELECT id,plan_uuid,plan_sha256 FROM build_plans WHERE repo_id=? AND source_commit_sha=? AND analyzer_version=? AND target_id=?');$q->execute([$job['repo_id'],$job['source_commit_sha'],AppPolicy::ANALYZER_VERSION,$target['id']]);$plan=$q->fetch();$target['plan_id']=(int)$plan['id'];$target['plan_uuid']=$plan['plan_uuid'];$target['plan_sha256']=$plan['plan_sha256'];}
        unset($target);$payload=json_encode(['targets'=>$targets,'ai'=>(bool)$key,'time'=>time()],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $pdo->prepare("UPDATE analysis_jobs SET status='completed',result_encrypted=?,completed_at=NOW() WHERE id=?")->execute([encrypt_secret($payload),$job['id']]);$pdo->commit();
        operational_metric('analysis.job',(int)round((microtime(true)-$started)*1000),'success',['targets'=>count($targets),'attempt'=>(int)$job['attempts']+1]);
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();$attempt=(int)$job['attempts']+1;$retry=$attempt<3;$pdo->prepare("UPDATE analysis_jobs SET status=?,available_at=DATE_ADD(NOW(),INTERVAL ? SECOND),error_message=?,completed_at=IF(?,NULL,NOW()) WHERE id=?")->execute([$retry?'queued':'failed',min(300,15*(2**$attempt)),substr($error->getMessage(),0,500),$retry?1:0,$job['id']]);operational_metric('analysis.job',(int)round((microtime(true)-$started)*1000),$retry?'retry':'failure',['attempt'=>$attempt]);}
    $processed++;
}
fwrite(STDOUT,"Processed {$processed} analysis job(s).\n");
