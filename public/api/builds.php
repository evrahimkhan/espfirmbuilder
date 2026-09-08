<?php
require __DIR__.'/../../src/bootstrap.php'; $user=require_user();
if($_SERVER['REQUEST_METHOD']==='GET'){
 $q=db()->prepare('SELECT b.*,r.full_name FROM builds b JOIN repositories r ON r.id=b.repo_id WHERE r.user_id=? ORDER BY b.id DESC LIMIT 30'); $q->execute([$user['id']]); json_response(['builds'=>$q->fetchAll()]);
}
verify_csrf(); $d=body(); $repo=(int)($d['repo_id']??0); $q=db()->prepare('SELECT * FROM repositories WHERE id=? AND user_id=?'); $q->execute([$repo,$user['id']]); $r=$q->fetch(); if(!$r) json_response(['error'=>'Repository not found'],404);
// Production: decrypt the user's GitHub token and POST /repos/{owner}/{repo}/actions/workflows/{workflow}/dispatches.
$q=db()->prepare("INSERT INTO builds(repo_id,status,logs) VALUES(?,'queued','Build queued. Waiting for GitHub Actions…')"); $q->execute([$repo]); json_response(['id'=>(int)db()->lastInsertId(),'status'=>'queued'],202);
