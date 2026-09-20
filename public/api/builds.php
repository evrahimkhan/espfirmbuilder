<?php
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/GitHubClient.php';
require __DIR__ . '/../../src/WorkflowEngine.php';
require __DIR__ . '/../../src/TargetAnalyzer.php';
require __DIR__ . '/../../src/AITargetAnalyzer.php';
require __DIR__ . '/../../src/AppPolicy.php';
require_method('GET','POST');
$user=require_user();
verify_csrf();
// Workflow preparation can involve several GitHub/API calls. Release PHP's
// per-session file lock so status polling and navigation remain responsive.
if($_SERVER['REQUEST_METHOD']==='POST')session_write_close();
if($_SERVER['REQUEST_METHOD']==='GET'){
 $requestBucket=isset($_GET['details'])?'build-details':(($_GET['refresh']??'')==='1'?'build-refresh':'build-list');
 rate_limit($requestBucket,$requestBucket==='build-refresh'?40:60,60);
 if(isset($_GET['details'])){
  $id=(int)$_GET['details']; $q=db()->prepare('SELECT b.*,r.full_name FROM builds b JOIN repositories r ON r.id=b.repo_id WHERE b.id=? AND r.user_id=?'); $q->execute([$id,$user['id']]); $build=$q->fetch();
  if(!$build||empty($build['github_run_id'])) json_response(['error'=>'Build run details are not available.'],404);
  $cached=$_SESSION['build_job_cache'][$id]??null;
  if(is_array($cached)&&time()-(int)($cached['time']??0)<5) json_response(['jobs'=>$cached['jobs']??[],'cached'=>true]);
  try{
   $client=new GitHubClient(github_token((int)$user['id']));$response=$client->request('GET','/repos/'.$build['full_name'].'/actions/runs/'.$build['github_run_id'].'/jobs?per_page=100');
   $jobs=array_map(fn($job)=>['name'=>$job['name']??'Build','status'=>$job['status']??'queued','conclusion'=>$job['conclusion']??null,'steps'=>array_map(fn($step)=>['name'=>$step['name']??'Step','status'=>$step['status']??'queued','conclusion'=>$step['conclusion']??null],$job['steps']??[])],$response['jobs']??[]);
   $_SESSION['build_job_cache'][$id]=['time'=>time(),'jobs'=>$jobs];if(count($_SESSION['build_job_cache'])>20){uasort($_SESSION['build_job_cache'],fn($a,$b)=>($b['time']??0)<=>($a['time']??0));$_SESSION['build_job_cache']=array_slice($_SESSION['build_job_cache'],0,20,true);}json_response(['jobs'=>$jobs,'cached'=>false]);
  }catch(RuntimeException $e){if($e instanceof PDOException)throw $e;json_response(['error'=>$e->getMessage()],502);}
 }
 $q=db()->prepare("SELECT COUNT(*) total,SUM(b.status='completed') completed,SUM(b.status='completed' AND b.conclusion='success') successful FROM builds b JOIN repositories r ON r.id=b.repo_id WHERE r.user_id=? AND b.created_at>=DATE_FORMAT(CURRENT_DATE,'%Y-%m-01')");$q->execute([$user['id']]);$monthly=$q->fetch()?:[];$totalBuilds=(int)($monthly['total']??0);$completedBuilds=(int)($monthly['completed']??0);$successRate=$completedBuilds>0?(int)round(100*(int)($monthly['successful']??0)/$completedBuilds):null;
 $q=db()->prepare("SELECT b.*,UNIX_TIMESTAMP(b.created_at) created_epoch,UNIX_TIMESTAMP(b.completed_at) completed_epoch,r.full_name FROM builds b JOIN repositories r ON r.id=b.repo_id WHERE r.user_id=? AND b.status IN ('queued','in_progress') UNION ALL SELECT * FROM (SELECT b.*,UNIX_TIMESTAMP(b.created_at) created_epoch,UNIX_TIMESTAMP(b.completed_at) completed_epoch,r.full_name FROM builds b JOIN repositories r ON r.id=b.repo_id WHERE r.user_id=? AND b.status NOT IN ('queued','in_progress') ORDER BY b.id DESC LIMIT 30) completed ORDER BY id DESC"); $q->execute([$user['id'],$user['id']]);
 $builds=$q->fetchAll();
 $forcedRefresh=($_GET['force']??'')==='1';
 if(($_GET['refresh']??'')==='1' && ($forcedRefresh||(time()-(int)($_SESSION['github_build_refresh']??0)>=5 && time()>=(int)($_SESSION['github_build_backoff_until']??0)))) try {
  $_SESSION['github_build_refresh']=time();
  $client=new GitHubClient(github_token((int)$user['id'])); $runsByRepository=[];
  foreach($builds as &$build){
   if(!in_array($build['status'],['queued','in_progress'],true)) continue;
   $repository=$build['full_name'];
   // A stored run ID is authoritative. Query that run directly before scanning
   // workflow history so overwritten workflow metadata or pagination cannot leave
   // a completed run stuck in ESPForge's queued state.
   if(!empty($build['github_run_id'])){
    try{
     $run=$client->request('GET','/repos/'.$repository.'/actions/runs/'.(int)$build['github_run_id']);$completedAt=($run['status']??'')==='completed'?date('Y-m-d H:i:s',strtotime($run['updated_at']??'now')):null;
     $build['status']=$run['status']??$build['status'];$build['conclusion']=$run['conclusion']??null;$build['artifact_url']=$run['html_url']??$build['artifact_url'];$build['completed_at']=$completedAt;$build['completed_epoch']=$completedAt?strtotime($run['updated_at']??'now'):null;
     db()->prepare('UPDATE builds SET status=?,conclusion=?,artifact_url=?,completed_at=? WHERE id=?')->execute([$build['status'],$build['conclusion'],$build['artifact_url'],$completedAt,$build['id']]);continue;
    }catch(RuntimeException $runError){if($runError->getCode()!==404)throw $runError;$build['status']='completed';$build['conclusion']='failure';$build['completed_at']=date('Y-m-d H:i:s');$build['completed_epoch']=time();db()->prepare("UPDATE builds SET status='completed',conclusion='failure',completed_at=NOW(),logs='The linked GitHub Actions run is no longer available.' WHERE id=?")->execute([$build['id']]);continue;}
   }
   if(!array_key_exists($repository,$runsByRepository)){
    $response=$client->request('GET','/repos/'.$repository.'/actions/workflows/espforge-build.yml/runs?per_page=50');
    $runsByRepository[$repository]=$response['workflow_runs']??[];
   }
   $matched=false;
   foreach($runsByRepository[$repository] as $run){
    $uuid=(string)($build['build_uuid']??'');$knownRun=(int)($build['github_run_id']??0);
    $runTitle=(string)($run['display_title']??$run['name']??'');
    $matches=$knownRun>0 ? (int)$run['id']===$knownRun : ($uuid!=='' ? str_contains($runTitle,$uuid) : strtotime($run['created_at'])>=strtotime($build['created_at'])-10);
    if(!$matches) continue;
    $matched=true;$build['github_run_id']=$run['id']; $build['status']=$run['status']; $build['conclusion']=$run['conclusion']; $build['artifact_url']=$run['html_url'];
    $completedAt=$run['status']==='completed'?date('Y-m-d H:i:s',strtotime($run['updated_at']??'now')):null;
    $build['completed_at']=$completedAt; $build['completed_epoch']=$completedAt?strtotime($run['updated_at']??'now'):null;
    $q=db()->prepare('UPDATE builds SET github_run_id=?,status=?,conclusion=?,artifact_url=?,completed_at=? WHERE id=?');
    $q->execute([$run['id'],$run['status'],$run['conclusion'],$run['html_url'],$completedAt,$build['id']]); break;
   }
   if(!$matched&&!empty($build['github_run_id'])){
    try{
     $run=$client->request('GET','/repos/'.$repository.'/actions/runs/'.(int)$build['github_run_id']);
     $completedAt=($run['status']??'')==='completed'?date('Y-m-d H:i:s',strtotime($run['updated_at']??'now')):null;
     $build['status']=$run['status']??$build['status'];$build['conclusion']=$run['conclusion']??null;$build['artifact_url']=$run['html_url']??$build['artifact_url'];$build['completed_at']=$completedAt;$build['completed_epoch']=$completedAt?strtotime($run['updated_at']??'now'):null;
     db()->prepare('UPDATE builds SET status=?,conclusion=?,artifact_url=?,completed_at=? WHERE id=?')->execute([$build['status'],$build['conclusion'],$build['artifact_url'],$completedAt,$build['id']]);$matched=true;
    }catch(RuntimeException $runError){
     if($runError->getCode()!==404)throw $runError;$build['status']='completed';$build['conclusion']='failure';$build['completed_at']=date('Y-m-d H:i:s');$build['completed_epoch']=time();db()->prepare("UPDATE builds SET status='completed',conclusion='failure',completed_at=NOW(),logs='The linked GitHub Actions run is no longer available.' WHERE id=?")->execute([$build['id']]);$matched=true;
    }
   }
   if(!$matched&&empty($build['github_run_id'])&&(int)($build['created_epoch']??0)<time()-7200){
    $build['status']='completed';$build['conclusion']='timed_out';$build['completed_at']=date('Y-m-d H:i:s');$build['completed_epoch']=time();
    db()->prepare("UPDATE builds SET status='completed',conclusion='timed_out',completed_at=NOW(),logs='GitHub did not create a matching workflow run within two hours.' WHERE id=?")->execute([$build['id']]);
   }
  }
  unset($build);$_SESSION['github_build_refresh_failures']=0;unset($_SESSION['github_build_backoff_until']);
 } catch(Throwable $error) { $failures=min(6,(int)($_SESSION['github_build_refresh_failures']??0)+1);$_SESSION['github_build_refresh_failures']=$failures;$_SESSION['github_build_backoff_until']=time()+min(300,5*(2**$failures));error_log('ESPForge build refresh failed; backing off: '.$error->getMessage()); }
 json_response(['builds'=>$builds,'total'=>$totalBuilds,'success_rate'=>$successRate]);
}
$data=body();
$buildAction=(string)($data['action']??'dispatch');
$buildLimit=$buildAction==='dispatch'?5:($buildAction==='batch_dispatch'?120:20);$buildWindow=$buildAction==='dispatch'?600:($buildAction==='batch_dispatch'?3600:60);rate_limit('build-'.$buildAction,$buildLimit,$buildWindow);
if(($data['action']??'')==='clear_logs'){
 // Hide completed log entries without deleting build records, so monthly build
 // totals and success-rate calculations remain accurate.
 $q=db()->prepare("UPDATE builds b INNER JOIN repositories r ON r.id=b.repo_id SET b.logs='__CLEARED__' WHERE r.user_id=? AND b.status='completed'");
 $q->execute([$user['id']]); json_response(['ok'=>true,'cleared'=>$q->rowCount()]);
}
if(($data['action']??'')==='cancel_build'){
 $id=(int)($data['build_id']??0);$q=db()->prepare('SELECT b.*,r.full_name FROM builds b JOIN repositories r ON r.id=b.repo_id WHERE b.id=? AND r.user_id=?');$q->execute([$id,$user['id']]);$build=$q->fetch();
 if(!$build||empty($build['github_run_id'])) json_response(['error'=>'The running build could not be found.'],404);
 if(!in_array($build['status'],['queued','in_progress'],true)) json_response(['error'=>'This build is no longer active.'],409);
 try{$github=new GitHubClient(github_token((int)$user['id']));$github->request('POST','/repos/'.$build['full_name'].'/actions/runs/'.$build['github_run_id'].'/cancel');db()->prepare("UPDATE builds SET logs='Cancellation requested.' WHERE id=?")->execute([$id]);audit_event('build.cancellation_requested',['build_id'=>$id,'repository'=>$build['full_name']]);unset($_SESSION['github_build_refresh']);json_response(['ok'=>true,'message'=>'Cancellation requested. GitHub will report the final state shortly.'],202);}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;json_response(['error'=>$e->getMessage()],$e->getCode()>=400&&$e->getCode()<600?$e->getCode():502);}
}
$repo=(int)($data['repo_id']??0); $q=db()->prepare('SELECT * FROM repositories WHERE id=? AND user_id=?'); $q->execute([$repo,$user['id']]); $repository=$q->fetch(); if(!$repository) json_response(['error'=>'Repository not found'],404);
$repositoryOperationLock=operation_lock('repository-config:'.strtolower((string)$repository['full_name']));
try {
 $github=new GitHubClient(github_token((int)$user['id']));
 // Re-analyze and synchronize the workflow before every dispatch. This upgrades
 // projects connected with an older ESPForge generator without manual deletion.
 $commitSha=$github->sourceRevision($repository['full_name'],$repository['default_branch']);$tree=$github->tree($repository['full_name'],$commitSha); $entries=$tree['tree']??[]; $paths=array_column($entries,'path');
 $analysis=WorkflowEngine::analyze($paths); $record=user_record((int)$user['id']);
 $provider=(string)($record['ai_provider']??''); $key=decrypt_secret($record['ai_api_key']??null);$analysisVersion=AppPolicy::ANALYZER_VERSION;$targetSessionKey=$repo.':'.$commitSha.':'.$analysisVersion;$targetPersistentKey=$repository['full_name'].':'.$commitSha.':'.$analysisVersion.':'.$provider.':'.(string)($record['ai_key_fingerprint']??'');
 $q=db()->prepare("SELECT result_encrypted FROM analysis_jobs WHERE repo_id=? AND source_commit_sha=? AND analyzer_version=? AND status='completed'");$q->execute([$repo,$commitSha,$analysisVersion]);$encryptedAnalysis=$q->fetchColumn();
 if(!is_string($encryptedAnalysis))json_response(['error'=>'The immutable build plan is not ready. Analyze targets first.','code'=>'plan_not_ready'],409);
 $analysisPayload=json_decode((string)decrypt_secret($encryptedAnalysis),true);$targets=is_array($analysisPayload)?($analysisPayload['targets']??[]):[];
 $targetId=(string)($data['target_id']??'');
 if(count($targets)>1 && $targetId==='') json_response(['error'=>'Select a hardware model before building.','code'=>'target_required','targets'=>$targets],422);
 $target=TargetAnalyzer::select($targets,$targetId!==''?$targetId:(string)$targets[0]['id']);
 if(!$target) json_response(['error'=>'The selected hardware model is invalid or no longer available.'],422);
 $q=db()->prepare("SELECT * FROM build_plans WHERE repo_id=? AND source_commit_sha=? AND analyzer_version=? AND target_id=? AND status IN ('ready','approved','dispatched') LIMIT 1");$q->execute([$repo,$commitSha,$analysisVersion,$target['id']]);$plan=$q->fetch();if(!$plan)json_response(['error'=>'The selected immutable build plan is unavailable or superseded. Analyze targets again.','code'=>'plan_not_ready'],409);
 $target=json_decode((string)$plan['target_config_json'],true);if(!is_array($target))json_response(['error'=>'Stored build plan is invalid.'],500);db()->prepare("UPDATE build_plans SET status='approved',approved_by=?,approved_at=NOW() WHERE id=? AND status='ready'")->execute([$user['id'],$plan['id']]);
 $targetConfigJson=json_encode($target,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(!is_string($targetConfigJson)||strlen($targetConfigJson)>65535)json_response(['error'=>'The selected hardware plan is too large.'],422);$configDigest=hash('sha256',$targetConfigJson);
 $workflow=decrypt_secret($plan['workflow_encrypted']??null);if(!is_string($workflow)||$workflow===''||!preg_match('/^[a-f0-9]{64}$/',(string)($plan['workflow_sha256']??''))||!hash_equals((string)$plan['workflow_sha256'],hash('sha256',$workflow)))json_response(['error'=>'The worker-materialized workflow is unavailable or failed its digest check. Analyze targets again.','code'=>'plan_not_ready'],409);
 $targetFramework=in_array($target['type'],['arduino','arduino_define'],true)?'arduino':($target['type']==='esp-idf'?'esp-idf':$analysis['framework']);
 $buildUuid=uuid_v4();$inputs=['espforge_build_uuid'=>$buildUuid,'espforge_source_commit'=>$commitSha];if(($target['type']??'')==='workflow_matrix'&&str_contains($workflow,'create_release:'))$inputs['create_release']='false';
 $github->putFile($repository['full_name'],'.github/workflows/espforge-build.yml',$repository['default_branch'],$workflow,'ci: configure ESPForge for '.$target['name'].' [skip ci]');
 $q=db()->prepare('UPDATE repositories SET framework=?,workflow_config=?,status=? WHERE id=?'); $q->execute([$targetFramework,$workflow,'workflow_ready',$repo]);
 $workflowFile='espforge-build.yml'; $buildMessage='Building selected model: '.$target['name'];
 // Workflows are disabled by default on many newly created forks.
 // Explicitly enable the generated workflow before dispatching it.
 try { $github->enableWorkflow($repository['full_name'],$workflowFile); }
 catch(RuntimeException $e){ if(!in_array($e->getCode(),[404,422],true)) throw $e; }
 $workflowDigest=hash('sha256',$workflow);$aiModel=($target['ai_primary']??false)?AppPolicy::aiModel($config,$provider):null;
 $q=db()->prepare("INSERT INTO builds(repo_id,build_uuid,target_id,target_name,source_commit_sha,analyzer_version,ai_model,target_config_json,workflow_sha256,status,logs) VALUES(?,?,?,?,?,?,?,?,?,'queued',?)"); $q->execute([$repo,$buildUuid,substr((string)($target['id']??''),0,190),substr((string)$target['name'],0,120),preg_match('/^[a-f0-9]{40}$/i',$commitSha)?strtolower($commitSha):null,$analysisVersion,$aiModel,$targetConfigJson,$workflowDigest,$buildMessage]); $buildId=(int)db()->lastInsertId();
 try { $github->dispatch($repository['full_name'],$workflowFile,$repository['default_branch'],$inputs,$target['type']!=='workflow_matrix'); }
 catch(RuntimeException $dispatchError){ db()->prepare("UPDATE builds SET status='completed',conclusion='failure',completed_at=NOW(),logs=? WHERE id=?")->execute(['Dispatch failed: '.$dispatchError->getMessage(),$buildId]); throw $dispatchError; }
 db()->prepare("UPDATE build_plans SET status='dispatched',dispatched_at=NOW() WHERE id=? AND approved_by=?")->execute([$plan['id'],$user['id']]);
 audit_event('build.dispatched',['build_id'=>$buildId,'plan_id'=>(int)$plan['id'],'plan_sha256'=>$plan['plan_sha256'],'repository'=>$repository['full_name'],'target'=>$target['id']??$target['name'],'uuid'=>$buildUuid]);
 json_response(['id'=>$buildId,'status'=>'queued','workflow'=>$workflowFile],202);
}
catch(RuntimeException $e){ if($e instanceof PDOException) throw $e; json_response(['error'=>$e->getMessage()],$e->getCode()>=400&&$e->getCode()<600?$e->getCode():502); }
