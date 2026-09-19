<?php
require __DIR__.'/../../src/bootstrap.php';
require __DIR__.'/../../src/GitHubClient.php';
require __DIR__.'/../../src/AppPolicy.php';
require_method('GET');
$user=require_user();verify_csrf();rate_limit('targets',30,60);$repo=(int)($_GET['repo_id']??0);
$q=db()->prepare('SELECT * FROM repositories WHERE id=? AND user_id=?');$q->execute([$repo,$user['id']]);$repository=$q->fetch();
if(!$repository)json_response(['error'=>'Repository not found.'],404);
try{
    // Resolving the current immutable revision is intentionally short; source and
    // AI analysis itself is performed only by bin/analysis-worker.php.
    $github=new GitHubClient(github_token((int)$user['id']));$tree=$github->tree($repository['full_name'],$repository['default_branch']);$commitSha=strtolower((string)($tree['sha']??''));
    if(!preg_match('/^[a-f0-9]{40}$/',$commitSha))json_response(['error'=>'GitHub did not return an immutable source revision.'],502);
    $q=db()->prepare('SELECT * FROM analysis_jobs WHERE repo_id=? AND source_commit_sha=? AND analyzer_version=?');$q->execute([$repo,$commitSha,AppPolicy::ANALYZER_VERSION]);$job=$q->fetch();
    $progress=[];if($job&&is_string($job['progress_encrypted']??null)){$progressJson=decrypt_secret($job['progress_encrypted']);$decodedProgress=is_string($progressJson)?json_decode($progressJson,true):null;if(is_array($decodedProgress))$progress=array_slice($decodedProgress,-80);}
    if(!$job){
        db()->prepare("INSERT INTO analysis_jobs(repo_id,source_commit_sha,analyzer_version,status) VALUES(?,?,?,'queued')")->execute([$repo,$commitSha,AppPolicy::ANALYZER_VERSION]);
        operational_metric('analysis.enqueue',null,'success',['repo_id'=>$repo]);
        json_response(['status'=>'analyzing','retry_after'=>2,'commit_sha'=>$commitSha,'analyzer_version'=>AppPolicy::ANALYZER_VERSION,'schema_version'=>AppPolicy::TARGET_SCHEMA_VERSION],202);
    }
    if(in_array($job['status'],['queued','processing'],true))json_response(['status'=>'analyzing','retry_after'=>4,'commit_sha'=>$commitSha,'analyzer_version'=>AppPolicy::ANALYZER_VERSION,'schema_version'=>AppPolicy::TARGET_SCHEMA_VERSION,'progress'=>$progress],202);
    if($job['status']==='failed'){
        if(($_GET['retry']??'')==='1'){$updated=db()->prepare("UPDATE analysis_jobs SET status='queued',attempts=0,error_message=NULL,result_encrypted=NULL,progress_encrypted=NULL,available_at=NOW(),started_at=NULL,completed_at=NULL WHERE id=? AND status='failed'");$updated->execute([$job['id']]);audit_event('analysis.retry_requested',['repo_id'=>$repo,'job_id'=>(int)$job['id']]);json_response(['status'=>'analyzing','retry_after'=>2,'commit_sha'=>$commitSha,'analyzer_version'=>AppPolicy::ANALYZER_VERSION],202);}
        json_response(['error'=>'Repository analysis failed. Press Build to retry, or synchronize after correcting its GitHub/AI configuration.','code'=>'analysis_failed'],422);
    }
    $json=decrypt_secret($job['result_encrypted']??null);$result=is_string($json)?json_decode($json,true):null;
    if(!is_array($result)){db()->prepare("UPDATE analysis_jobs SET status='queued',result_encrypted=NULL,available_at=NOW() WHERE id=?")->execute([$job['id']]);json_response(['status'=>'analyzing','retry_after'=>2],202);}
    json_response(['status'=>'ready','targets'=>$result['targets']??[],'ai_primary'=>(bool)($result['ai']??false),'cached'=>true,'commit_sha'=>$commitSha,'analyzer_version'=>AppPolicy::ANALYZER_VERSION,'schema_version'=>AppPolicy::TARGET_SCHEMA_VERSION,'policy_versions'=>AppPolicy::versions()]);
}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;json_response(['error'=>$e->getMessage()],$e->getCode()>=400&&$e->getCode()<600?$e->getCode():502);}
