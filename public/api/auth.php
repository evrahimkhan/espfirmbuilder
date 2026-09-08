<?php
require __DIR__ . '/../../src/bootstrap.php';
$action = $_GET['action'] ?? 'session';

if ($action === 'session') json_response(['user' => $_SESSION['user'] ?? null, 'csrf' => csrf()]);
if ($action === 'logout') { verify_csrf(); session_destroy(); json_response(['ok' => true]); }

if ($action === 'register' || $action === 'login') {
    verify_csrf(); $data = body();
    $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL); $password = $data['password'] ?? '';
    if (!$email || strlen($password) < 8) json_response(['error' => 'Enter a valid email and an 8+ character password.'], 422);
    try {
        if ($action === 'register') {
            $name = trim($data['name'] ?? ''); if (!$name) json_response(['error' => 'Name is required.'], 422);
            $q = db()->prepare('INSERT INTO users(email,name,password_hash) VALUES(?,?,?)');
            $q->execute([$email, $name, password_hash($password, PASSWORD_DEFAULT)]); $id = (int)db()->lastInsertId();
        } else {
            $q = db()->prepare('SELECT * FROM users WHERE email=?'); $q->execute([$email]); $record = $q->fetch();
            if (!$record || !password_verify($password, $record['password_hash'] ?? '')) json_response(['error' => 'Invalid email or password.'], 401);
            $id = (int)$record['id']; $name = $record['name'];
        }
        session_regenerate_id(true); $_SESSION['user'] = ['id' => $id, 'name' => $name, 'email' => $email];
        json_response(['user' => $_SESSION['user']]);
    } catch (PDOException $e) { json_response(['error' => $e->getCode() === '23000' ? 'Email already registered.' : 'Database unavailable.'], 409); }
}

if (in_array($action, ['github', 'google'], true)) {
    $provider = $action;
    if (empty($config[$provider]['client_id'])) json_response(['error' => ucfirst($provider) . ' OAuth is not configured.'], 503);
    $_SESSION['oauth_state'] = bin2hex(random_bytes(20)); $_SESSION['oauth_provider'] = $provider;
    $base = $provider === 'github' ? 'https://github.com/login/oauth/authorize' : 'https://accounts.google.com/o/oauth2/v2/auth';
    $scope = $provider === 'github' ? 'read:user user:email repo workflow' : 'openid email profile';
    $params = ['client_id'=>$config[$provider]['client_id'], 'redirect_uri'=>$config[$provider]['redirect_uri'], 'scope'=>$scope, 'state'=>$_SESSION['oauth_state'], 'response_type'=>'code'];
    if ($provider === 'google') $params['access_type'] = 'online';
    header('Location: ' . $base . '?' . http_build_query($params)); exit;
}

if (in_array($action, ['github_callback', 'google_callback'], true)) {
    $provider = str_replace('_callback', '', $action);
    if (!hash_equals($_SESSION['oauth_state'] ?? '', $_GET['state'] ?? '') || ($_SESSION['oauth_provider'] ?? '') !== $provider) json_response(['error'=>'Invalid OAuth state.'], 400);
    if (empty($_GET['code'])) json_response(['error'=>'Authorization was cancelled.'], 400);
    $tokenUrl = $provider === 'github' ? 'https://github.com/login/oauth/access_token' : 'https://oauth2.googleapis.com/token';
    $payload = ['client_id'=>$config[$provider]['client_id'], 'client_secret'=>$config[$provider]['client_secret'], 'code'=>$_GET['code'], 'redirect_uri'=>$config[$provider]['redirect_uri']];
    if ($provider === 'google') $payload['grant_type'] = 'authorization_code';
    $ch=curl_init($tokenUrl); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($payload),CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_TIMEOUT=>20]);
    $tokenData=json_decode(curl_exec($ch) ?: '[]',true); curl_close($ch); $token=$tokenData['access_token']??null;
    if(!$token) json_response(['error'=>'OAuth token exchange failed.'],502);
    $api=$provider==='github'?'https://api.github.com/user':'https://openidconnect.googleapis.com/v1/userinfo';
    $ch=curl_init($api); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json','User-Agent: ESPForge'],CURLOPT_TIMEOUT=>20]); $profile=json_decode(curl_exec($ch)?:'[]',true); curl_close($ch);
    $providerId=(string)($profile['id']??$profile['sub']??''); $email=$profile['email']??null;
    if($provider==='github' && !$email){ $ch=curl_init('https://api.github.com/user/emails'); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/vnd.github+json','User-Agent: ESPForge']]); $emails=json_decode(curl_exec($ch)?:'[]',true); curl_close($ch); foreach($emails as $item) if(!empty($item['primary'])&& !empty($item['verified'])){$email=$item['email'];break;} }
    if(!$providerId || !$email) json_response(['error'=>'The provider did not return a verified email.'],422);
    $idColumn=$provider.'_id'; $q=db()->prepare("SELECT * FROM users WHERE {$idColumn}=? OR email=? LIMIT 1"); $q->execute([$providerId,$email]); $record=$q->fetch();
    $name=$profile['name']??$profile['login']??explode('@',$email)[0];
    if($record){ $id=(int)$record['id']; $sql="UPDATE users SET {$idColumn}=?, name=?"; $values=[$providerId,$name]; if($provider==='github'){ $sql.=', github_token=?'; $values[]=encrypt_secret($token); } $sql.=' WHERE id=?'; $values[]=$id; db()->prepare($sql)->execute($values); }
    else { $githubToken=$provider==='github'?encrypt_secret($token):null; $q=db()->prepare("INSERT INTO users(email,name,{$idColumn},github_token) VALUES(?,?,?,?)"); $q->execute([$email,$name,$providerId,$githubToken]); $id=(int)db()->lastInsertId(); }
    unset($_SESSION['oauth_state'],$_SESSION['oauth_provider']); session_regenerate_id(true); $_SESSION['user']=['id'=>$id,'name'=>$name,'email'=>$email]; header('Location: ../dashboard.html'); exit;
}
json_response(['error' => 'Unknown action'], 404);
