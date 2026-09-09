<?php
require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/../../src/GitHubClient.php';
require __DIR__ . '/../../src/WorkflowEngine.php';
$user = require_user();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q=db()->prepare('SELECT r.*, (SELECT status FROM builds b WHERE b.repo_id=r.id ORDER BY id DESC LIMIT 1) build_status FROM repositories r WHERE user_id=? ORDER BY id DESC');
    $q->execute([$user['id']]); json_response(['projects'=>$q->fetchAll()]);
}
verify_csrf(); $data=body(); $url=trim($data['repo_url']??'');
if(!preg_match('~^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$~',$url,$match)) json_response(['error'=>'Enter a valid GitHub repository URL.'],422);
$full=$match[1].'/'.$match[2];
try {
    $github=new GitHubClient(github_token((int)$user['id'])); $metadata=$github->repository($full); $branch=$metadata['default_branch']??'main';
    if(isset($metadata['permissions']) && empty($metadata['permissions']['push'])) {
        json_response(['error'=>'This repository is read-only for your GitHub account. Fork it or use a repository where you have write access, then connect the fork URL.'],403);
    }
    $tree=$github->tree($full,$branch); $paths=array_column($tree['tree']??[],'path'); $analysis=WorkflowEngine::analyze($paths); $workflow=WorkflowEngine::workflow($analysis['framework']);
    try {
        $github->putFile($full,'.github/workflows/espforge-build.yml',$branch,$workflow,'ci: add ESPForge firmware build');
    } catch(RuntimeException $e) {
        if($e->getCode()===403) json_response(['error'=>'GitHub denied workflow creation. Use a repository you can write to, then reconnect GitHub to grant the repo and workflow permissions.'],403);
        throw $e;
    }
    $q=db()->prepare('INSERT INTO repositories(user_id,repo_url,full_name,default_branch,framework,workflow_config,status) VALUES(?,?,?,?,?,?,?)');
    $q->execute([$user['id'],$url,$full,$branch,$analysis['framework'],$workflow,'workflow_ready']);
    json_response(['id'=>(int)db()->lastInsertId(),'full_name'=>$full,'analysis'=>$analysis],201);
} catch(RuntimeException $e) { json_response(['error'=>$e->getMessage()],$e->getCode()>=400&&$e->getCode()<600?$e->getCode():502); }
