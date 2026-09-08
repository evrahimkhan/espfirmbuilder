<?php
require __DIR__.'/../../src/bootstrap.php';
$action=$_GET['action']??'session';
if($action==='session') json_response(['user'=>$_SESSION['user']??null,'csrf'=>csrf()]);
if($action==='logout'){ verify_csrf(); session_destroy(); json_response(['ok'=>true]); }
if($action==='register' || $action==='login'){
 verify_csrf(); $d=body(); $email=filter_var($d['email']??'',FILTER_VALIDATE_EMAIL); $password=$d['password']??'';
 if(!$email || strlen($password)<8) json_response(['error'=>'Enter a valid email and an 8+ character password.'],422);
 try {
  if($action==='register'){ $name=trim($d['name']??''); if(!$name) json_response(['error'=>'Name is required.'],422); $q=db()->prepare('INSERT INTO users(email,name,password_hash) VALUES(?,?,?)'); $q->execute([$email,$name,password_hash($password,PASSWORD_DEFAULT)]); $id=(int)db()->lastInsertId(); }
  else { $q=db()->prepare('SELECT * FROM users WHERE email=?'); $q->execute([$email]); $u=$q->fetch(); if(!$u || !password_verify($password,$u['password_hash']??'')) json_response(['error'=>'Invalid email or password.'],401); $id=(int)$u['id']; $name=$u['name']; }
  session_regenerate_id(true); $_SESSION['user']=['id'=>$id,'name'=>$name,'email'=>$email]; json_response(['user'=>$_SESSION['user']]);
 } catch(PDOException $e){ json_response(['error'=>$e->getCode()==='23000'?'Email already registered.':'Database unavailable.'],409); }
}
if(in_array($action,['github','google'],true)){
 $provider=$action; if(empty($config[$provider]['client_id'])) json_response(['error'=>ucfirst($provider).' OAuth is not configured yet.'],503);
 $_SESSION['oauth_state']=bin2hex(random_bytes(20)); $base=$provider==='github'?'https://github.com/login/oauth/authorize':'https://accounts.google.com/o/oauth2/v2/auth';
 $scope=$provider==='github'?'read:user user:email repo workflow':'openid email profile';
 $url=$base.'?'.http_build_query(['client_id'=>$config[$provider]['client_id'],'redirect_uri'=>$config[$provider]['redirect_uri'],'scope'=>$scope,'state'=>$_SESSION['oauth_state'],'response_type'=>'code']); header('Location: '.$url); exit;
}
json_response(['error'=>'Unknown action'],404);
