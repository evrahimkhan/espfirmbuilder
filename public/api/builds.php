<?php
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/GitHubClient.php';
require __DIR__ . '/../../src/WorkflowEngine.php';
$user=require_user();
if($_SERVER['REQUEST_METHOD']==='GET'){
 $q=db()->prepare('SELECT b.*,r.full_name FROM builds b JOIN repositories r ON r.id=b.repo_id WHERE r.user_id=? ORDER BY b.id DESC LIMIT 30'); $q->execute([$user['id']]);
 $builds=$q->fetchAll();
 // Reconcile recent builds with GitHub runs while keeping dashboard polling inexpensive.
 if(($_GET['refresh']??'')==='1') try { $client=new GitHubClient(github_token((int)$user['id'])); foreach($builds as &$build){ if(!in_array($build['status'],['queued','in_progress'],true)) continue; $runs=$client->request('GET','/repos/'.$build['full_name'].'/actions/workflows/espforge-build.yml/runs?event=workflow_dispatch&per_page=10'); foreach($runs['workflow_runs']??[] as $run){ if(strtotime($run['created_at'])>=strtotime($build['created_at'])-10){ $q=db()->prepare('UPDATE builds SET github_run_id=?,status=?,conclusion=?,artifact_url=?,completed_at=? WHERE id=?'); $q->execute([$run['id'],$run['status'],$run['conclusion'],$run['html_url'],$run['status']==='completed'?date('Y-m-d H:i:s'):null,$build['id']]); $build['github_run_id']=$run['id'];$build['status']=$run['status'];$build['conclusion']=$run['conclusion'];break; } } } } catch(Throwable $ignored) {}
 json_response(['builds'=>$builds]);
}
verify_csrf(); $data=body(); $repo=(int)($data['repo_id']??0); $q=db()->prepare('SELECT * FROM repositories WHERE id=? AND user_id=?'); $q->execute([$repo,$user['id']]); $repository=$q->fetch(); if(!$repository) json_response(['error'=>'Repository not found'],404);
try {
 $github=new GitHubClient(github_token((int)$user['id']));
 // Re-analyze and synchronize the workflow before every dispatch. This upgrades
 // projects connected with an older ESPForge generator without manual deletion.
 $tree=$github->tree($repository['full_name'],$repository['default_branch']); $paths=array_column($tree['tree']??[],'path');
 $analysis=WorkflowEngine::analyze($paths); $source='';
 if($analysis['framework']==='arduino') foreach($paths as $path) if(str_ends_with(strtolower($path),'.ino')){ $source=$github->file($repository['full_name'],$path,$repository['default_branch'])??''; break; }
 $workflow=WorkflowEngine::workflow($analysis['framework'],$paths,$source);
 $github->putFile($repository['full_name'],'.github/workflows/espforge-build.yml',$repository['default_branch'],$workflow,'ci: refresh ESPForge firmware build');
 $q=db()->prepare('UPDATE repositories SET framework=?,workflow_config=?,status=? WHERE id=?'); $q->execute([$analysis['framework'],$workflow,'workflow_ready',$repo]);
 $github->dispatch($repository['full_name'],'espforge-build.yml',$repository['default_branch']);
 $q=db()->prepare("INSERT INTO builds(repo_id,status,logs) VALUES(?,'queued','Workflow synchronized and dispatched to GitHub Actions.')"); $q->execute([$repo]); json_response(['id'=>(int)db()->lastInsertId(),'status'=>'queued'],202);
}
catch(RuntimeException $e){ json_response(['error'=>$e->getMessage()],$e->getCode()>=400&&$e->getCode()<600?$e->getCode():502); }
