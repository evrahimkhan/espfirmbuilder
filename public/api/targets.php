<?php
require __DIR__.'/../../src/bootstrap.php';
require __DIR__.'/../../src/GitHubClient.php';
require __DIR__.'/../../src/TargetAnalyzer.php';
require __DIR__.'/../../src/AITargetAnalyzer.php';
require __DIR__.'/../../src/AppPolicy.php';
require_method('GET');
$user=require_user(); verify_csrf(); rate_limit('targets',20,60); $repo=(int)($_GET['repo_id']??0);
$q=db()->prepare('SELECT * FROM repositories WHERE id=? AND user_id=?'); $q->execute([$repo,$user['id']]); $repository=$q->fetch();
if(!$repository) json_response(['error'=>'Repository not found.'],404);
try{
    $github=new GitHubClient(github_token((int)$user['id']));
    $tree=$github->tree($repository['full_name'],$repository['default_branch']);
    $commitSha=(string)($tree['sha']??'');$record=user_record((int)$user['id']);$analysisVersion=AppPolicy::ANALYZER_VERSION;$cacheKey=$repository['full_name'].':'.$commitSha.':'.$analysisVersion.':'.(string)($record['ai_provider']??'').':'.(string)($record['ai_key_fingerprint']??'');$sessionKey=$repo.':'.$commitSha.':'.$analysisVersion;$cached=$_SESSION['target_analysis_cache'][$sessionKey]??analysis_cache_get('targets',$cacheKey,86400);
    if($commitSha!==''&&is_array($cached)&&time()-(int)($cached['time']??0)<86400)json_response(['targets'=>$cached['targets']??[],'ai_fallback_available'=>(bool)($cached['ai']??false),'cached'=>true,'commit_sha'=>$commitSha,'analyzer_version'=>$analysisVersion,'schema_version'=>AppPolicy::TARGET_SCHEMA_VERSION,'policy_versions'=>AppPolicy::versions()]);
    $paths=array_column($tree['tree']??[],'path');
    $provider=(string)($record['ai_provider']??''); $key=decrypt_secret($record['ai_api_key']??null);
    $fallback=$key&&in_array($provider,['google','openrouter'],true)?fn()=>(new AITargetAnalyzer($provider,$key,AppPolicy::aiModel($config,$provider)))->discover($github,$repository['full_name'],$repository['default_branch'],$paths):null;
    $targets=TargetAnalyzer::discover($github,$repository['full_name'],$repository['default_branch'],$paths,$fallback);
    if($commitSha!==''){$entry=['time'=>time(),'targets'=>$targets,'ai'=>(bool)$key];$_SESSION['target_analysis_cache'][$sessionKey]=$entry;analysis_cache_set('targets',$cacheKey,$entry);if(count($_SESSION['target_analysis_cache'])>20)$_SESSION['target_analysis_cache']=array_slice($_SESSION['target_analysis_cache'],-20,null,true);}
    json_response(['targets'=>$targets,'ai_fallback_available'=>(bool)$key,'cached'=>false,'commit_sha'=>$commitSha,'analyzer_version'=>$analysisVersion,'schema_version'=>AppPolicy::TARGET_SCHEMA_VERSION,'policy_versions'=>AppPolicy::versions()]);
}catch(RuntimeException $e){ if($e instanceof PDOException) throw $e; json_response(['error'=>$e->getMessage()],$e->getCode()>=400&&$e->getCode()<600?$e->getCode():502); }
