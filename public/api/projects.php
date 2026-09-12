<?php
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/GitHubClient.php';
require __DIR__ . '/../../src/WorkflowEngine.php';
$user = require_user();
rate_limit('projects', $_SERVER['REQUEST_METHOD'] === 'GET' ? 60 : 12, 60);
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q=db()->prepare('SELECT r.*, (SELECT status FROM builds b WHERE b.repo_id=r.id ORDER BY id DESC LIMIT 1) build_status FROM repositories r WHERE user_id=? ORDER BY id DESC');
    $q->execute([$user['id']]); $projects=[];
    foreach($q->fetchAll() as $project){ $key=strtolower((string)$project['full_name']); if(!isset($projects[$key])) $projects[$key]=$project; }
    json_response(['projects'=>array_values($projects)]);
}
verify_csrf(); $data=body();
if(($data['action']??'')==='sync_all'){
    $q=db()->prepare('SELECT * FROM repositories WHERE user_id=? ORDER BY id DESC'); $q->execute([$user['id']]); $repositories=[];
    foreach($q->fetchAll() as $repository){ $key=strtolower((string)$repository['full_name']); if(!isset($repositories[$key])) $repositories[$key]=$repository; }
    $repositories=array_values($repositories); $synced=0; $unchanged=0; $skipped=0; $errors=[];
    try { $github=new GitHubClient(github_token((int)$user['id'])); }
    catch(RuntimeException $e){ if($e instanceof PDOException) throw $e; json_response(['error'=>$e->getMessage()],502); }
    foreach($repositories as $repository){
        try {
            $metadata=$github->repository($repository['full_name']);
            if(empty($metadata['fork'])){ $skipped++; continue; }
            $result=$github->request('POST','/repos/'.$repository['full_name'].'/merge-upstream',['branch'=>$repository['default_branch']]);
            $message=strtolower((string)($result['message']??''));
            if(str_contains($message,'already up to date')||str_contains($message,'not behind')) $unchanged++; else $synced++;
        } catch(RuntimeException $e){ $errors[]=$repository['full_name'].': '.$e->getMessage(); }
    }
    json_response(['ok'=>true,'synced'=>$synced,'unchanged'=>$unchanged,'skipped'=>$skipped,'errors'=>$errors,'message'=>"Sync complete: {$synced} updated, {$unchanged} already current, {$skipped} non-forks skipped, ".count($errors).' failed.']);
}
if(in_array($data['action']??'',['remove','delete','sync'],true)){
    $action=(string)$data['action']; $id=(int)($data['repo_id']??0);
    $q=db()->prepare('SELECT * FROM repositories WHERE id=? AND user_id=?'); $q->execute([$id,$user['id']]); $repository=$q->fetch();
    if(!$repository) json_response(['error'=>'Repository not found.'],404);
    if($action==='remove'){
        $q=db()->prepare('DELETE FROM repositories WHERE user_id=? AND LOWER(full_name)=LOWER(?)'); $q->execute([$user['id'],$repository['full_name']]);
        json_response(['ok'=>true,'message'=>'Repository removed from ESPForge.']);
    }
    try {
        $github=new GitHubClient(github_token((int)$user['id'])); $metadata=$github->repository($repository['full_name']);
        if(empty($metadata['fork'])) json_response(['error'=>'This repository is not a fork, so ESPForge will not modify or delete it.'],422);
        if($action==='delete'){
            require_recent_auth();
            $github->request('DELETE','/repos/'.$repository['full_name']);
            $q=db()->prepare('DELETE FROM repositories WHERE user_id=? AND LOWER(full_name)=LOWER(?)'); $q->execute([$user['id'],$repository['full_name']]); audit_event('repository.fork_deleted',['repository'=>$repository['full_name']]);
            json_response(['ok'=>true,'message'=>'GitHub fork deleted and repository removed from ESPForge.']);
        }
        $result=$github->request('POST','/repos/'.$repository['full_name'].'/merge-upstream',['branch'=>$repository['default_branch']]);
        $message=(string)($result['message']??''); $normalized=strtolower($message);
        if(str_contains($normalized,'already up to date')||str_contains($normalized,'not behind')) $message='Already up to date.';
        elseif($message==='') $message='Repository synchronized with upstream.';
        json_response(['ok'=>true,'message'=>$message]);
    } catch(RuntimeException $e){
        $message=$action==='delete'&&$e->getCode()===403
            ?'GitHub denied repository deletion. Reconnect GitHub from Settings to grant the delete_repo permission, then try again.'
            :$e->getMessage();
        json_response(['error'=>$message],$e->getCode()>=400&&$e->getCode()<600?$e->getCode():502);
    }
}
$url=trim($data['repo_url']??'');
if(!preg_match('~^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$~',$url,$match)) json_response(['error'=>'Enter a valid GitHub repository URL.'],422);
$full=$match[1].'/'.$match[2];
try {
    $github=new GitHubClient(github_token((int)$user['id'])); $metadata=$github->repository($full); $forkedFrom=null;
    if(isset($metadata['permissions']) && empty($metadata['permissions']['push'])) {
        $forkedFrom=$full;
        try {
            $metadata=$github->ensureFork($full,(string)$metadata['name']);
            $full=$metadata['full_name'];
            $url=$metadata['html_url']??('https://github.com/'.$full);
        } catch(RuntimeException $e) {
            $status=$e->getCode()>=400&&$e->getCode()<600?$e->getCode():502;
            $message=$e->getCode()===403
                ? 'Your token cannot create forks. The configured client is probably a GitHub App. Replace it with credentials from Settings → Developer settings → OAuth Apps, revoke the existing ESPForge authorization, and reconnect.'
                : 'ESPForge could not create your fork: '.$e->getMessage();
            json_response(['error'=>$message],$status);
        }
    }
    $branch=$metadata['default_branch']??'main';
    $q=db()->prepare('SELECT id FROM repositories WHERE user_id=? AND LOWER(full_name)=LOWER(?) LIMIT 1');
    $q->execute([$user['id'],$full]);
    if($q->fetch()) json_response(['error'=>'This repository already exists in your ESPForge list.'],409);
    $tree=$github->tree($full,$branch); $entries=$tree['tree']??[]; $paths=array_column($entries,'path'); $analysis=WorkflowEngine::analyze($paths);
    $source=$analysis['framework']==='arduino'?$github->sourceBundle($full,$branch,$entries):'';
    $workflow=WorkflowEngine::workflow($analysis['framework'],$paths,$source);
    try {
        $github->putFile($full,'.github/workflows/espforge-build.yml',$branch,$workflow,'ci: add ESPForge firmware build [skip ci]');
    } catch(RuntimeException $e) {
        if($e->getCode()===403) json_response(['error'=>'GitHub denied workflow creation. Use a repository you can write to, then reconnect GitHub to grant the repo and workflow permissions.'],403);
        throw $e;
    }
    $q=db()->prepare('INSERT INTO repositories(user_id,repo_url,full_name,default_branch,framework,workflow_config,status) VALUES(?,?,?,?,?,?,?)');
    $q->execute([$user['id'],$url,$full,$branch,$analysis['framework'],$workflow,'workflow_ready']);
    json_response(['id'=>(int)db()->lastInsertId(),'full_name'=>$full,'forked_from'=>$forkedFrom,'analysis'=>$analysis],201);
} catch(Throwable $e) { if($e instanceof PDOException) throw $e; json_response(['error'=>$e->getMessage()],$e->getCode()>=400&&$e->getCode()<600?$e->getCode():502); }
