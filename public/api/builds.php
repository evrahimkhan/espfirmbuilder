<?php
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/GitHubClient.php';
require __DIR__ . '/../../src/WorkflowEngine.php';
require __DIR__ . '/../../src/TargetAnalyzer.php';
require __DIR__ . '/../../src/AITargetAnalyzer.php';
$user=require_user();
if($_SERVER['REQUEST_METHOD']==='GET'){
 $q=db()->prepare('SELECT b.*,r.full_name FROM builds b JOIN repositories r ON r.id=b.repo_id WHERE r.user_id=? ORDER BY b.id DESC LIMIT 30'); $q->execute([$user['id']]);
 $builds=$q->fetchAll();
 // Reconcile recent builds with GitHub runs while keeping dashboard polling inexpensive.
 if(($_GET['refresh']??'')==='1') try { $client=new GitHubClient(github_token((int)$user['id'])); foreach($builds as &$build){ if(!in_array($build['status'],['queued','in_progress'],true)) continue; $runs=$client->request('GET','/repos/'.$build['full_name'].'/actions/runs?per_page=20'); foreach($runs['workflow_runs']??[] as $run){ if(strtotime($run['created_at'])>=strtotime($build['created_at'])-10){ $q=db()->prepare('UPDATE builds SET github_run_id=?,status=?,conclusion=?,artifact_url=?,completed_at=? WHERE id=?'); $q->execute([$run['id'],$run['status'],$run['conclusion'],$run['html_url'],$run['status']==='completed'?date('Y-m-d H:i:s'):null,$build['id']]); $build['github_run_id']=$run['id'];$build['status']=$run['status'];$build['conclusion']=$run['conclusion'];break; } } } } catch(Throwable $ignored) {}
 json_response(['builds'=>$builds]);
}
verify_csrf(); $data=body(); $repo=(int)($data['repo_id']??0); $q=db()->prepare('SELECT * FROM repositories WHERE id=? AND user_id=?'); $q->execute([$repo,$user['id']]); $repository=$q->fetch(); if(!$repository) json_response(['error'=>'Repository not found'],404);
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
 $inputs=[];
 if($target['type']==='workflow_matrix'){
     $original=$github->file($repository['full_name'],$target['workflow_path'],$repository['default_branch']);
     if(!$original) throw new RuntimeException('The repository hardware workflow could not be read.',404);
     $workflow=TargetAnalyzer::filterMatrix($original,$target['flag'],$target['name'],$target['matrix_field']??'flag');
     $inputs=str_contains($original,'create_release:')?['create_release'=>'false']:[];
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
 }
 $github->putFile($repository['full_name'],'.github/workflows/espforge-build.yml',$repository['default_branch'],$workflow,'ci: configure ESPForge for '.$target['name']);
 $q=db()->prepare('UPDATE repositories SET framework=?,workflow_config=?,status=? WHERE id=?'); $q->execute([$analysis['framework'],$workflow,'workflow_ready',$repo]);
 $workflowFile='espforge-build.yml'; $buildMessage='Building selected model: '.$target['name'];
 // Workflows are disabled by default on many newly created forks.
 // Explicitly enable the generated workflow before dispatching it.
 try { $github->enableWorkflow($repository['full_name'],$workflowFile); }
 catch(RuntimeException $e){ if(!in_array($e->getCode(),[404,422],true)) throw $e; }
 $github->dispatch($repository['full_name'],$workflowFile,$repository['default_branch'],$inputs);
 $q=db()->prepare("INSERT INTO builds(repo_id,status,logs) VALUES(?,'queued',?)"); $q->execute([$repo,$buildMessage]); json_response(['id'=>(int)db()->lastInsertId(),'status'=>'queued','workflow'=>$workflowFile],202);
}
catch(RuntimeException $e){ json_response(['error'=>$e->getMessage()],$e->getCode()>=400&&$e->getCode()<600?$e->getCode():502); }
