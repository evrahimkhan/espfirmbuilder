<?php
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/GitHubClient.php';
$user=require_user();
if($_SERVER['REQUEST_METHOD']==='GET'){
 $q=db()->prepare('SELECT b.*,r.full_name FROM builds b JOIN repositories r ON r.id=b.repo_id WHERE r.user_id=? ORDER BY b.id DESC LIMIT 30'); $q->execute([$user['id']]);
 $builds=$q->fetchAll();
 // Reconcile recent builds with GitHub runs while keeping dashboard polling inexpensive.
 if(($_GET['refresh']??'')==='1') try { $client=new GitHubClient(github_token((int)$user['id'])); foreach($builds as &$build){ if(!in_array($build['status'],['queued','in_progress'],true)) continue; $runs=$client->request('GET','/repos/'.$build['full_name'].'/actions/workflows/espforge-build.yml/runs?event=workflow_dispatch&per_page=10'); foreach($runs['workflow_runs']??[] as $run){ if(strtotime($run['created_at'])>=strtotime($build['created_at'])-10){ $q=db()->prepare('UPDATE builds SET github_run_id=?,status=?,conclusion=?,artifact_url=?,completed_at=? WHERE id=?'); $q->execute([$run['id'],$run['status'],$run['conclusion'],$run['html_url'],$run['status']==='completed'?date('Y-m-d H:i:s'):null,$build['id']]); $build['github_run_id']=$run['id'];$build['status']=$run['status'];$build['conclusion']=$run['conclusion'];break; } } } } catch(Throwable $ignored) {}
 json_response(['builds'=>$builds]);
}
verify_csrf(); $data=body(); $repo=(int)($data['repo_id']??0); $q=db()->prepare('SELECT * FROM repositories WHERE id=? AND user_id=?'); $q->execute([$repo,$user['id']]); $repository=$q->fetch(); if(!$repository) json_response(['error'=>'Repository not found'],404);
try { $github=new GitHubClient(github_token((int)$user['id'])); $github->dispatch($repository['full_name'],'espforge-build.yml',$repository['default_branch']); $q=db()->prepare("INSERT INTO builds(repo_id,status,logs) VALUES(?,'queued','Workflow dispatched to GitHub Actions.')"); $q->execute([$repo]); json_response(['id'=>(int)db()->lastInsertId(),'status'=>'queued'],202); }
catch(RuntimeException $e){ json_response(['error'=>$e->getMessage()],$e->getCode()>=400&&$e->getCode()<600?$e->getCode():502); }
