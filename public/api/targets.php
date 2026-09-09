<?php
require __DIR__.'/../../src/bootstrap.php';
require __DIR__.'/../../src/GitHubClient.php';
require __DIR__.'/../../src/TargetAnalyzer.php';
$user=require_user(); $repo=(int)($_GET['repo_id']??0);
$q=db()->prepare('SELECT * FROM repositories WHERE id=? AND user_id=?'); $q->execute([$repo,$user['id']]); $repository=$q->fetch();
if(!$repository) json_response(['error'=>'Repository not found.'],404);
try{
    $github=new GitHubClient(github_token((int)$user['id']));
    $tree=$github->tree($repository['full_name'],$repository['default_branch']);
    $targets=TargetAnalyzer::discover($github,$repository['full_name'],$repository['default_branch'],array_column($tree['tree']??[],'path'));
    json_response(['targets'=>$targets]);
}catch(RuntimeException $e){ json_response(['error'=>$e->getMessage()],$e->getCode()>=400&&$e->getCode()<600?$e->getCode():502); }
