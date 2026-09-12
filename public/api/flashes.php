<?php
require __DIR__.'/../../src/bootstrap.php';
$user=require_user();
verify_csrf();
if($_SERVER['REQUEST_METHOD']==='GET'){
    rate_limit('flash-stats',60,60);
    $q=db()->prepare("SELECT COUNT(*) FROM flash_events WHERE user_id=? AND created_at>=DATE_FORMAT(CURRENT_DATE,'%Y-%m-01')");$q->execute([$user['id']]);
    json_response(['monthly_total'=>(int)$q->fetchColumn()]);
}
rate_limit('flash-completed',30,3600);$data=body();
$chip=strtolower(preg_replace('/[^a-zA-Z0-9-]/','',(string)($data['chip']??'')));$size=(int)($data['firmware_size']??0);
if(!in_array($chip,['esp32','esp32-s2','esp32-s3','esp32-c3','esp32-c6'],true)||$size<1||$size>16*1024*1024) json_response(['error'=>'Invalid flash completion data.'],422);
$hashMatched=!empty($data['hash_matched']);
$q=db()->prepare('INSERT INTO flash_events(user_id,chip,firmware_size,manifest_verified) VALUES(?,?,?,?)');$q->execute([$user['id'],$chip,$size,$hashMatched?1:0]);
audit_event('firmware.flashed',['chip'=>$chip,'firmware_size'=>$size,'hash_matched'=>$hashMatched]);
json_response(['ok'=>true],201);
