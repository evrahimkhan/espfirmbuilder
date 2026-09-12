<?php
require __DIR__.'/../../src/bootstrap.php';
require __DIR__.'/../../src/GitHubClient.php';
require __DIR__.'/../../src/TargetAnalyzer.php';
require __DIR__.'/../../src/AITargetAnalyzer.php';
$user=require_user(); verify_csrf(); rate_limit('targets',20,60); $repo=(int)($_GET['repo_id']??0);
$q=db()->prepare('SELECT * FROM repositories WHERE id=? AND user_id=?'); $q->execute([$repo,$user['id']]); $repository=$q->fetch();
if(!$repository) json_response(['error'=>'Repository not found.'],404);
try{
    $github=new GitHubClient(github_token((int)$user['id']));
    $tree=$github->tree($repository['full_name'],$repository['default_branch']);
    $paths=array_column($tree['tree']??[],'path'); $record=user_record((int)$user['id']);
    $provider=(string)($record['ai_provider']??''); $key=decrypt_secret($record['ai_api_key']??null);
    $fallback=$key&&in_array($provider,['google','openrouter'],true)?fn()=>(new AITargetAnalyzer($provider,$key))->discover($github,$repository['full_name'],$repository['default_branch'],$paths):null;
    $targets=TargetAnalyzer::discover($github,$repository['full_name'],$repository['default_branch'],$paths,$fallback);
    json_response(['targets'=>$targets,'ai_fallback_available'=>(bool)$key]);
}catch(RuntimeException $e){ if($e instanceof PDOException) throw $e; json_response(['error'=>$e->getMessage()],$e->getCode()>=400&&$e->getCode()<600?$e->getCode():502); }
