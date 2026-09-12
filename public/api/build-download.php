<?php
require __DIR__ . '/../../src/bootstrap.php';
$user=require_user();
rate_limit('build-download',10,300);
$id=(int)($_GET['build_id']??0); $kind=(string)($_GET['kind']??'artifact');
if(!in_array($kind,['artifact','logs'],true)) json_response(['error'=>'Invalid download type.'],422);
$q=db()->prepare('SELECT b.*,r.full_name FROM builds b JOIN repositories r ON r.id=b.repo_id WHERE b.id=? AND r.user_id=?');
$q->execute([$id,$user['id']]); $build=$q->fetch();
if(!$build||empty($build['github_run_id'])) json_response(['error'=>'Build run not found.'],404);
$token=github_token((int)$user['id']); $run=(int)$build['github_run_id'];
$headers=['Accept: application/vnd.github+json','Authorization: Bearer '.$token,'User-Agent: ESPForge','X-GitHub-Api-Version: 2022-11-28'];
if($kind==='artifact'){
 $ch=curl_init('https://api.github.com/repos/'.$build['full_name'].'/actions/runs/'.$run.'/artifacts?per_page=100');
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false]);
 $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch); $data=json_decode($raw?:'[]',true);
 $artifacts=array_values(array_filter($data['artifacts']??[],fn($artifact)=>empty($artifact['expired'])));
 if($status<200||$status>=300||empty($artifacts[0]['id'])) json_response(['error'=>'No build artifact is available yet.'],404);
 if((int)($artifacts[0]['size_in_bytes']??0)>100*1024*1024) json_response(['error'=>'The artifact exceeds the 100 MB download safety limit.'],413);
 $url='https://api.github.com/repos/'.$build['full_name'].'/actions/artifacts/'.(int)$artifacts[0]['id'].'/zip';
 $filename='espforge-build-'.$id.'-artifact.zip';
}else{
 $url='https://api.github.com/repos/'.$build['full_name'].'/actions/runs/'.$run.'/logs';
 $filename='espforge-build-'.$id.'-error-logs.zip';
}

// Stream GitHub into a bounded temporary file. Never buffer an untrusted archive
// in PHP memory, and abort immediately if redirects exceed the byte budget.
$maxBytes=100*1024*1024; $received=0; $temporary=tempnam(sys_get_temp_dir(),'espforge-download-');
if($temporary===false) json_response(['error'=>'Download storage is unavailable.'],503);
$file=fopen($temporary,'w+b'); if($file===false){@unlink($temporary);json_response(['error'=>'Download storage is unavailable.'],503);}
$ch=curl_init($url);
curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>120,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use($file,&$received,$maxBytes):int{$length=strlen($chunk);$received+=$length;if($received>$maxBytes)return 0;return fwrite($file,$chunk)===false?0:$length;}]);
$ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $error=curl_error($ch); curl_close($ch); fflush($file); fclose($file);
if($ok===false||$status<200||$status>=300||$received>$maxBytes){@unlink($temporary);json_response(['error'=>$received>$maxBytes?'Download exceeded the 100 MB safety limit.':'Download failed'.($error!==''?': '.$error:'.')],$received>$maxBytes?413:502);}

$outputPath=$temporary;
if($kind==='artifact'&&class_exists('ZipArchive')){
 $zip=new ZipArchive();
 if($zip->open($temporary)===true){
  $innerIndex=null; $zipCount=0;
  for($index=0;$index<$zip->numFiles;$index++){
   $stat=$zip->statIndex($index); if(!$stat||str_ends_with((string)$stat['name'],'/'))continue;
   $size=(int)($stat['size']??0);$compressed=max(1,(int)($stat['comp_size']??1));
   if($size>$maxBytes||$size/$compressed>100){$zip->close();@unlink($temporary);json_response(['error'=>'Artifact archive failed decompression safety checks.'],422);}
   if(str_ends_with(strtolower((string)$stat['name']),'.zip')){$innerIndex=$index;$zipCount++;$filename=basename((string)$stat['name']);}
  }
  if($zipCount===1&&$innerIndex!==null){
   $stat=$zip->statIndex($innerIndex);$innerPath=tempnam(sys_get_temp_dir(),'espforge-inner-');$input=$zip->getStream((string)$stat['name']);$output=$innerPath!==false?fopen($innerPath,'w+b'):false;
   if($input&&$output){$copied=stream_copy_to_stream($input,$output,$maxBytes+1);fclose($input);fclose($output);if($copied!==false&&$copied<=$maxBytes)$outputPath=$innerPath;else @unlink((string)$innerPath);}
  }
  $zip->close();
 }
}
$filename=preg_replace('/[^A-Za-z0-9_.-]/','_',$filename)?:'espforge-download.zip';$size=filesize($outputPath);
header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Content-Length: '.$size);header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
$stream=fopen($outputPath,'rb');if($stream!==false){while(!feof($stream)){echo fread($stream,1024*1024);flush();}fclose($stream);}
if($outputPath!==$temporary)@unlink($outputPath);@unlink($temporary);
