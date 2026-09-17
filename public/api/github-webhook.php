<?php
declare(strict_types=1);
require __DIR__.'/../../src/bootstrap.php';
require_method('POST');
$secret=(string)($config['github']['webhook_secret']??'');
if(strlen($secret)<24)json_response(['error'=>'Webhook receiver is not configured.'],503);
$declared=(int)($_SERVER['CONTENT_LENGTH']??0);if($declared>2*1024*1024)json_response(['error'=>'Webhook payload is too large.'],413);
$raw=file_get_contents('php://input',false,null,0,2*1024*1024+1);if(!is_string($raw)||strlen($raw)>2*1024*1024)json_response(['error'=>'Webhook payload is too large.'],413);
$signature=(string)($_SERVER['HTTP_X_HUB_SIGNATURE_256']??'');$expected='sha256='.hash_hmac('sha256',$raw,$secret);
if(!hash_equals($expected,$signature)){operational_metric('github.webhook',null,'invalid_signature');json_response(['error'=>'Invalid webhook signature.'],401);}
$delivery=trim((string)($_SERVER['HTTP_X_GITHUB_DELIVERY']??''));$event=trim((string)($_SERVER['HTTP_X_GITHUB_EVENT']??''));
if(!preg_match('/^[A-Za-z0-9-]{1,100}$/',$delivery)||!preg_match('/^[A-Za-z0-9_]{1,80}$/',$event))json_response(['error'=>'Invalid webhook metadata.'],400);
try{$payload=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(JsonException){json_response(['error'=>'Invalid webhook JSON.'],400);}
try{db()->prepare("INSERT INTO github_webhook_deliveries(delivery_id,event_name,status) VALUES(?,?,'accepted')")->execute([$delivery,$event]);}catch(PDOException $error){if($error->getCode()==='23000')json_response(['ok'=>true,'duplicate'=>true]);throw $error;}
if($event==='ping')json_response(['ok'=>true]);
if($event!=='workflow_run')json_response(['ok'=>true,'ignored'=>true]);
$run=$payload['workflow_run']??[];$repository=(string)($payload['repository']['full_name']??'');$path=(string)($run['path']??'');
if(!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/',$repository)||!str_ends_with($path,'.github/workflows/espforge-build.yml'))json_response(['ok'=>true,'ignored'=>true]);
$runId=(int)($run['id']??0);$status=(string)($run['status']??'');$allowedStatus=['queued','in_progress','completed'];if($runId<1||!in_array($status,$allowedStatus,true))json_response(['error'=>'Invalid workflow run.'],422);
$conclusion=$status==='completed'?(string)($run['conclusion']??'failure'):null;$allowedConclusions=['success','failure','cancelled','timed_out','action_required','neutral','skipped','stale'];if($conclusion!==null&&!in_array($conclusion,$allowedConclusions,true))$conclusion='failure';
$title=(string)($run['display_title']??'');$uuid=preg_match('/\b[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i',$title,$match)?strtolower($match[0]):null;
$sql="UPDATE builds b JOIN repositories r ON r.id=b.repo_id SET b.github_run_id=?,b.status=?,b.conclusion=?,b.artifact_url=?,b.completed_at=IF(?='completed',FROM_UNIXTIME(?),NULL) WHERE r.full_name=? AND ";$params=[$runId,$status,$conclusion,substr((string)($run['html_url']??''),0,1000),$status,max(1,strtotime((string)($run['updated_at']??'now'))),$repository];
if($uuid!==null){$sql.='b.build_uuid=?';$params[]=$uuid;}else{$sql.='b.github_run_id=?';$params[]=$runId;}
$q=db()->prepare($sql);$q->execute($params);db()->prepare("UPDATE github_webhook_deliveries SET status=? WHERE delivery_id=?")->execute([$q->rowCount()>0?'applied':'unmatched',$delivery]);operational_metric('github.webhook',null,$q->rowCount()>0?'success':'unmatched',['event'=>'workflow_run','status'=>$status]);
json_response(['ok'=>true,'updated'=>$q->rowCount()]);
