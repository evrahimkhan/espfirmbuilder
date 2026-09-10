<?php
require __DIR__ . '/../../src/bootstrap.php';
$user=require_user();
$id=(int)($_GET['build_id']??0); $kind=(string)($_GET['kind']??'artifact');
if(!in_array($kind,['artifact','logs'],true)) json_response(['error'=>'Invalid download type.'],422);
$q=db()->prepare('SELECT b.*,r.full_name FROM builds b JOIN repositories r ON r.id=b.repo_id WHERE b.id=? AND r.user_id=?');
$q->execute([$id,$user['id']]); $build=$q->fetch();
if(!$build||empty($build['github_run_id'])) json_response(['error'=>'Build run not found.'],404);
$token=github_token((int)$user['id']); $run=(int)$build['github_run_id'];
if($kind==='artifact'){
 $ch=curl_init('https://api.github.com/repos/'.$build['full_name'].'/actions/runs/'.$run.'/artifacts?per_page=100');
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json','Authorization: Bearer '.$token,'User-Agent: ESPForge','X-GitHub-Api-Version: 2022-11-28'],CURLOPT_TIMEOUT=>30]);
 $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch); $data=json_decode($raw?:'[]',true);
 if($status<200||$status>=300||empty($data['artifacts'][0]['id'])) json_response(['error'=>'No build artifact is available yet.'],404);
 $url='https://api.github.com/repos/'.$build['full_name'].'/actions/artifacts/'.(int)$data['artifacts'][0]['id'].'/zip';
 $filename='espforge-build-'.$id.'-artifact.zip';
}else{
 $url='https://api.github.com/repos/'.$build['full_name'].'/actions/runs/'.$run.'/logs';
 $filename='espforge-build-'.$id.'-error-logs.zip';
}
$ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json','Authorization: Bearer '.$token,'User-Agent: ESPForge','X-GitHub-Api-Version: 2022-11-28'],CURLOPT_TIMEOUT=>120]);
$body=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $error=curl_error($ch); curl_close($ch);
if($body===false||$status<200||$status>=300) json_response(['error'=>'Download failed'.($error!==''?': '.$error:'.')],502);
header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.$filename.'"'); header('Content-Length: '.strlen($body)); header('Cache-Control: private, no-store'); echo $body;
