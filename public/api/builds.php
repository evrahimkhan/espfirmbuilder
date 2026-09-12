<?php
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/GitHubClient.php';
require __DIR__ . '/../../src/WorkflowEngine.php';
require __DIR__ . '/../../src/TargetAnalyzer.php';
require __DIR__ . '/../../src/AITargetAnalyzer.php';
$user=require_user();
if($_SERVER['REQUEST_METHOD']==='GET'){
 rate_limit(($_GET['refresh']??'')==='1'?'build-refresh':'build-list',($_GET['refresh']??'')==='1'?40:60,60);
 if(isset($_GET['details'])){
  $id=(int)$_GET['details']; $q=db()->prepare('SELECT b.*,r.full_name FROM builds b JOIN repositories r ON r.id=b.repo_id WHERE b.id=? AND r.user_id=?'); $q->execute([$id,$user['id']]); $build=$q->fetch();
  if(!$build||empty($build['github_run_id'])) json_response(['error'=>'Build run details are not available.'],404);
  try{$client=new GitHubClient(github_token((int)$user['id']));$jobs=$client->request('GET','/repos/'.$build['full_name'].'/actions/runs/'.$build['github_run_id'].'/jobs?per_page=100');json_response(['jobs'=>array_map(fn($job)=>['name'=>$job['name']??'Build','status'=>$job['status']??'queued','conclusion'=>$job['conclusion']??null,'steps'=>array_map(fn($step)=>['name'=>$step['name']??'Step','status'=>$step['status']??'queued','conclusion'=>$step['conclusion']??null],$job['steps']??[])],$jobs['jobs']??[])]);}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;json_response(['error'=>$e->getMessage()],502);}
 }
 $q=db()->prepare("SELECT COUNT(*) total,SUM(b.status='completed') completed,SUM(b.status='completed' AND b.conclusion='success') successful FROM builds b JOIN repositories r ON r.id=b.repo_id WHERE r.user_id=? AND b.created_at>=DATE_FORMAT(CURRENT_DATE,'%Y-%m-01')");$q->execute([$user['id']]);$monthly=$q->fetch()?:[];$totalBuilds=(int)($monthly['total']??0);$completedBuilds=(int)($monthly['completed']??0);$successRate=$completedBuilds>0?(int)round(100*(int)($monthly['successful']??0)/$completedBuilds):null;
 $q=db()->prepare("SELECT b.*,UNIX_TIMESTAMP(b.created_at) created_epoch,UNIX_TIMESTAMP(b.completed_at) completed_epoch,r.full_name FROM builds b JOIN repositories r ON r.id=b.repo_id WHERE r.user_id=? AND b.status IN ('queued','in_progress') UNION ALL SELECT * FROM (SELECT b.*,UNIX_TIMESTAMP(b.created_at) created_epoch,UNIX_TIMESTAMP(b.completed_at) completed_epoch,r.full_name FROM builds b JOIN repositories r ON r.id=b.repo_id WHERE r.user_id=? AND b.status NOT IN ('queued','in_progress') ORDER BY b.id DESC LIMIT 30) completed ORDER BY id DESC"); $q->execute([$user['id'],$user['id']]);
 $builds=$q->fetchAll();
 if(($_GET['refresh']??'')==='1' && time()-(int)($_SESSION['github_build_refresh']??0)>=5 && time()>=(int)($_SESSION['github_build_backoff_until']??0)) try {
  $_SESSION['github_build_refresh']=time();
  $client=new GitHubClient(github_token((int)$user['id'])); $runsByRepository=[];
  foreach($builds as &$build){
   if(!in_array($build['status'],['queued','in_progress'],true)) continue;
   $repository=$build['full_name'];
   if(!array_key_exists($repository,$runsByRepository)){
    $response=$client->request('GET','/repos/'.$repository.'/actions/workflows/espforge-build.yml/runs?per_page=50');
    $runsByRepository[$repository]=$response['workflow_runs']??[];
   }
   foreach($runsByRepository[$repository] as $run){
    $uuid=(string)($build['build_uuid']??'');
    $runTitle=(string)($run['display_title']??$run['name']??'');
    $matches=$uuid!=='' ? str_contains($runTitle,$uuid) : strtotime($run['created_at'])>=strtotime($build['created_at'])-10;
    if(!$matches) continue;
    $build['github_run_id']=$run['id']; $build['status']=$run['status']; $build['conclusion']=$run['conclusion']; $build['artifact_url']=$run['html_url'];
    $completedAt=$run['status']==='completed'?date('Y-m-d H:i:s',strtotime($run['updated_at']??'now')):null;
    $build['completed_at']=$completedAt; $build['completed_epoch']=$completedAt?strtotime($run['updated_at']??'now'):null;
    $q=db()->prepare('UPDATE builds SET github_run_id=?,status=?,conclusion=?,artifact_url=?,completed_at=? WHERE id=?');
    $q->execute([$run['id'],$run['status'],$run['conclusion'],$run['html_url'],$completedAt,$build['id']]); break;
   }
  }
  unset($build);$_SESSION['github_build_refresh_failures']=0;unset($_SESSION['github_build_backoff_until']);
 } catch(Throwable $error) { $failures=min(6,(int)($_SESSION['github_build_refresh_failures']??0)+1);$_SESSION['github_build_refresh_failures']=$failures;$_SESSION['github_build_backoff_until']=time()+min(300,5*(2**$failures));error_log('ESPForge build refresh failed; backing off: '.$error->getMessage()); }
 json_response(['builds'=>$builds,'total'=>$totalBuilds,'success_rate'=>$successRate]);
}
verify_csrf(); $data=body();
$buildAction=(string)($data['action']??'dispatch');
rate_limit('build-'.$buildAction,$buildAction==='dispatch'?5:20,$buildAction==='dispatch'?600:60);
if(($data['action']??'')==='clear_logs'){
 // Hide completed log entries without deleting build records, so monthly build
 // totals and success-rate calculations remain accurate.
 $q=db()->prepare("UPDATE builds b INNER JOIN repositories r ON r.id=b.repo_id SET b.logs='__CLEARED__' WHERE r.user_id=? AND b.status='completed'");
 $q->execute([$user['id']]); json_response(['ok'=>true,'cleared'=>$q->rowCount()]);
}
if(($data['action']??'')==='cancel_build'){
 $id=(int)($data['build_id']??0);$q=db()->prepare('SELECT b.*,r.full_name FROM builds b JOIN repositories r ON r.id=b.repo_id WHERE b.id=? AND r.user_id=?');$q->execute([$id,$user['id']]);$build=$q->fetch();
 if(!$build||empty($build['github_run_id'])) json_response(['error'=>'The running build could not be found.'],404);
 try{$github=new GitHubClient(github_token((int)$user['id']));$github->request('POST','/repos/'.$build['full_name'].'/actions/runs/'.$build['github_run_id'].'/cancel');db()->prepare("UPDATE builds SET status='completed',conclusion='cancelled',completed_at=NOW() WHERE id=?")->execute([$id]);json_response(['ok'=>true]);}catch(RuntimeException $e){if($e instanceof PDOException)throw $e;json_response(['error'=>$e->getMessage()],$e->getCode()>=400&&$e->getCode()<600?$e->getCode():502);}
}
$repo=(int)($data['repo_id']??0); $q=db()->prepare('SELECT * FROM repositories WHERE id=? AND user_id=?'); $q->execute([$repo,$user['id']]); $repository=$q->fetch(); if(!$repository) json_response(['error'=>'Repository not found'],404);
try {
 $github=new GitHubClient(github_token((int)$user['id']));
 // Re-analyze and synchronize the workflow before every dispatch. This upgrades
 // projects connected with an older ESPForge generator without manual deletion.
 $tree=$github->tree($repository['full_name'],$repository['default_branch']); $entries=$tree['tree']??[]; $paths=array_column($entries,'path');
 $analysis=WorkflowEngine::analyze($paths); $record=user_record((int)$user['id']);
 $provider=(string)($record['ai_provider']??''); $key=decrypt_secret($record['ai_api_key']??null);
 $fallback=$key&&in_array($provider,['google','openrouter'],true)?fn()=>(new AITargetAnalyzer($provider,$key))->discover($github,$repository['full_name'],$repository['default_branch'],$paths):null;
 $targets=TargetAnalyzer::discover($github,$repository['full_name'],$repository['default_branch'],$paths,$fallback);
 $targetId=(string)($data['target_id']??'');
 if(count($targets)>1 && $targetId==='') json_response(['error'=>'Select a hardware model before building.','code'=>'target_required','targets'=>$targets],422);
 $target=TargetAnalyzer::select($targets,$targetId!==''?$targetId:(string)$targets[0]['id']);
 if(!$target) json_response(['error'=>'The selected hardware model is invalid or no longer available.'],422);
 $buildUuid=uuid_v4(); $inputs=['espforge_build_uuid'=>$buildUuid];
 if($target['type']==='workflow_matrix'){
     $original=$github->file($repository['full_name'],$target['workflow_path'],$repository['default_branch']);
     if(!$original) throw new RuntimeException('The repository hardware workflow could not be read.',404);
     $workflow=TargetAnalyzer::filterMatrix($original,$target['flag'],$target['name'],$target['matrix_field']??'flag');
     if(str_contains($original,'create_release:')) $inputs['create_release']='false';
 } else {
     $source=$analysis['framework']==='arduino'?$github->sourceBundle($repository['full_name'],$repository['default_branch'],$entries):'';
     $workflow=WorkflowEngine::workflow($analysis['framework'],$paths,$source);
     if(in_array($target['type'],['platformio','platformio_disabled'],true)) $workflow=str_replace('run: pio run','run: pio run -e '.escapeshellarg($target['environment']),$workflow);
     if(in_array($target['type'],['arduino','arduino_define'],true)&&!empty($target['fqbn'])){
         $replacement='--fqbn "'.$target['fqbn'].'"';
         if(!empty($target['build_flags'])) $replacement.=' --build-property build.extra_flags="'.$target['build_flags'].'"';
         $workflow=preg_replace('/--fqbn "[^"]+"/',$replacement,$workflow,1)??$workflow;
     }
     $step=TargetAnalyzer::configurationStep($target,$targets);
     if($step!==''){
         $marker=str_starts_with($target['type'],'platformio')?'      - name: Build firmware':'      - name: Compile firmware';
         $workflow=str_replace($marker,$step.$marker,$workflow);
     }
     if($target['type']==='esp-idf'&&!empty($target['idf_target'])) $workflow=preg_replace('/target:\s*esp32\b/','target: '.$target['idf_target'],$workflow,1)??$workflow;
     $chip=(string)($target['idf_target']??'');
     if($chip===''&&preg_match('/esp32(?::esp32)?:([a-z0-9]+)/i',(string)($target['fqbn']??''),$chipMatch)) $chip=strtolower($chipMatch[1]);
     if($chip===''&&preg_match('/esp32(?:s2|s3|c3|c5|c6)?/i',(string)($target['id']??''),$chipMatch)) $chip=strtolower($chipMatch[0]);
     if($chip==='') $chip='esp32';
     $uploadMarker='      - uses: actions/upload-artifact@';
     $workflow=str_replace($uploadMarker,WorkflowEngine::manifestStep($analysis['framework'],$chip).$uploadMarker,$workflow);
 }
 $github->putFile($repository['full_name'],'.github/workflows/espforge-build.yml',$repository['default_branch'],$workflow,'ci: configure ESPForge for '.$target['name'].' [skip ci]');
 $q=db()->prepare('UPDATE repositories SET framework=?,workflow_config=?,status=? WHERE id=?'); $q->execute([$analysis['framework'],$workflow,'workflow_ready',$repo]);
 $workflowFile='espforge-build.yml'; $buildMessage='Building selected model: '.$target['name'];
 // Workflows are disabled by default on many newly created forks.
 // Explicitly enable the generated workflow before dispatching it.
 try { $github->enableWorkflow($repository['full_name'],$workflowFile); }
 catch(RuntimeException $e){ if(!in_array($e->getCode(),[404,422],true)) throw $e; }
 $q=db()->prepare("INSERT INTO builds(repo_id,build_uuid,status,logs) VALUES(?,?,'queued',?)"); $q->execute([$repo,$buildUuid,$buildMessage]); $buildId=(int)db()->lastInsertId();
 try { $github->dispatch($repository['full_name'],$workflowFile,$repository['default_branch'],$inputs); }
 catch(RuntimeException $dispatchError){ db()->prepare("UPDATE builds SET status='completed',conclusion='failure',completed_at=NOW(),logs=? WHERE id=?")->execute(['Dispatch failed: '.$dispatchError->getMessage(),$buildId]); throw $dispatchError; }
 audit_event('build.dispatched',['build_id'=>$buildId,'repository'=>$repository['full_name'],'target'=>$target['id']??$target['name'],'uuid'=>$buildUuid]);
 json_response(['id'=>$buildId,'status'=>'queued','workflow'=>$workflowFile],202);
}
catch(RuntimeException $e){ if($e instanceof PDOException) throw $e; json_response(['error'=>$e->getMessage()],$e->getCode()>=400&&$e->getCode()<600?$e->getCode():502); }
