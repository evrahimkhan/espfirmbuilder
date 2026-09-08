<?php
require __DIR__.'/../../src/bootstrap.php'; $user=require_user();
if($_SERVER['REQUEST_METHOD']==='GET'){
 $q=db()->prepare('SELECT r.*, (SELECT status FROM builds b WHERE b.repo_id=r.id ORDER BY id DESC LIMIT 1) build_status FROM repositories r WHERE user_id=? ORDER BY id DESC'); $q->execute([$user['id']]); json_response(['projects'=>$q->fetchAll()]);
}
verify_csrf(); $d=body(); $url=trim($d['repo_url']??'');
if(!preg_match('~^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$~',$url,$m)) json_response(['error'=>'Enter a valid GitHub repository URL.'],422);
$full=$m[1].'/'.$m[2]; $q=db()->prepare('INSERT INTO repositories(user_id,repo_url,full_name) VALUES(?,?,?)'); $q->execute([$user['id'],$url,$full]); json_response(['id'=>(int)db()->lastInsertId(),'full_name'=>$full],201);
