<?php
require __DIR__ . '/../../src/bootstrap.php';
$action = $_GET['action'] ?? 'session';
function oauth_dashboard_error(string $message): never { $page=!empty($_SESSION['user'])?'dashboard.html':'index.html'; $fragment=$page==='dashboard.html'?'#settings':''; header('Location: ../'.$page.'?oauth_error='.rawurlencode($message).$fragment); exit; }
function auth_landing_message(string $message): never { header('Location: ../index.html?auth_message='.rawurlencode($message)); exit; }
function send_verification_email(array $record): bool { global $config; $from=(string)($config['mail']['from']??''); if(!filter_var($from,FILTER_VALIDATE_EMAIL)) return false; $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);db()->prepare('DELETE FROM email_verification_tokens WHERE user_id=? OR expires_at<NOW()')->execute([$record['id']]);db()->prepare('INSERT INTO email_verification_tokens(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))')->execute([$record['id'],$hash]);$link=rtrim((string)$config['app']['url'],'/').'/api/auth.php?action=verify_email&token='.rawurlencode($token);$name=preg_replace('/[\r\n]+/',' ',(string)$record['name']);$text="Hello {$name},\n\nVerify your ESPForge email within 24 hours:\n{$link}\n\nIf you did not create this account, ignore this email.";return @mail((string)$record['email'],'Verify your ESPForge email',$text,['From'=>$from,'Content-Type'=>'text/plain; charset=UTF-8']); }

if ($action === 'session') { if(!empty($_SESSION['user'])) require_user(); json_response(['user' => $_SESSION['user'] ?? null, 'csrf' => csrf()]); }
if ($action === 'logout') { verify_csrf(); audit_event('auth.logout'); $_SESSION=[]; if(ini_get('session.use_cookies')){$params=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$params['path'],$params['domain'],$params['secure'],$params['httponly']);} session_destroy(); json_response(['ok' => true]); }

if ($action === 'verify_email') {
    rate_limit('email-verification-complete',12,3600);$token=(string)($_GET['token']??'');
    if(!preg_match('/^[a-f0-9]{64}$/',$token)) auth_landing_message('The email verification link is invalid.');
    $pdo=db();$pdo->beginTransaction();
    try{$q=$pdo->prepare('SELECT * FROM email_verification_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE');$q->execute([hash('sha256',$token)]);$verification=$q->fetch();if(!$verification){$pdo->rollBack();auth_landing_message('This email verification link is invalid or has expired.');}$pdo->prepare('UPDATE users SET email_verified_at=NOW() WHERE id=?')->execute([$verification['user_id']]);$pdo->prepare('UPDATE email_verification_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$verification['user_id']]);$pdo->commit();audit_event('email.verified',['user_id'=>(int)$verification['user_id']]);auth_landing_message('Email verified. You can now sign in.');}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

if ($action === 'resend_verification') {
    rate_limit('email-verification-request',4,3600);verify_csrf();$data=body();$email=filter_var($data['email']??'',FILTER_VALIDATE_EMAIL);if($email){$q=db()->prepare('SELECT id,name,email,email_verified_at FROM users WHERE email=? LIMIT 1');$q->execute([$email]);$record=$q->fetch();if($record&&empty($record['email_verified_at'])&&!send_verification_email($record))error_log('ESPForge verification mail delivery failed for user '.$record['id']);}json_response(['ok'=>true,'message'=>'If that address belongs to an unverified account, a new verification link has been sent.']);
}

if ($action === 'request_password_reset') {
    rate_limit('password-reset-request',5,3600); verify_csrf(); $data=body();
    $email=filter_var($data['email']??'',FILTER_VALIDATE_EMAIL);
    $message='If an account can receive mail at that address, a password reset link has been sent.';
    if($email){
        $q=db()->prepare('SELECT id,name,email FROM users WHERE email=? LIMIT 1');$q->execute([$email]);$record=$q->fetch();
        if($record){
            $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);
            db()->prepare('DELETE FROM password_reset_tokens WHERE user_id=? OR expires_at<NOW()')->execute([$record['id']]);
            db()->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 30 MINUTE))')->execute([$record['id'],$hash]);
            $from=(string)($config['mail']['from']??'');
            if(filter_var($from,FILTER_VALIDATE_EMAIL)){
                $link=rtrim((string)$config['app']['url'],'/').'/?reset_token='.rawurlencode($token);
                $subject='Reset your ESPForge password';$bodyText="Hello {$record['name']},\n\nUse this one-time link within 30 minutes to reset your ESPForge password:\n{$link}\n\nIf you did not request this, ignore this email.";
                $sent=@mail((string)$record['email'],$subject,$bodyText,['From'=>$from,'Content-Type'=>'text/plain; charset=UTF-8']);
                if(!$sent) error_log('ESPForge password reset mail delivery failed for user '.$record['id']);
            } else error_log('ESPForge MAIL_FROM is not configured; password reset mail not sent.');
            audit_event('password_reset.requested',['user_id'=>(int)$record['id']]);
        }
    }
    json_response(['ok'=>true,'message'=>$message]);
}

if ($action === 'reset_password') {
    rate_limit('password-reset-complete',8,3600); verify_csrf(); $data=body();
    $token=(string)($data['token']??'');$password=(string)($data['password']??'');
    if(!preg_match('/^[a-f0-9]{64}$/',$token)||strlen($password)<8||strlen($password)>1024) json_response(['error'=>'The reset link is invalid, or the password length is outside the allowed range.'],422);
    $hash=hash('sha256',$token);$pdo=db();$pdo->beginTransaction();
    try{$q=$pdo->prepare('SELECT * FROM password_reset_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE');$q->execute([$hash]);$reset=$q->fetch();
        if(!$reset){$pdo->rollBack();json_response(['error'=>'This password reset link is invalid or has expired.'],422);}
        $pdo->prepare('UPDATE users SET password_hash=?,session_version=session_version+1,email_verified_at=COALESCE(email_verified_at,NOW()) WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$reset['user_id']]);
        $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=? AND used_at IS NULL')->execute([$reset['user_id']]);$pdo->commit();
        audit_event('password_reset.completed',['user_id'=>(int)$reset['user_id']]);json_response(['ok'=>true,'message'=>'Password updated. You can now sign in.']);
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

if ($action === 'register' || $action === 'login') {
    rate_limit('auth-'.$action, 8, 900);
    verify_csrf(); $data = body();
    $email = filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL); $password = $data['password'] ?? '';
    if (!$email || strlen((string)$email)>255 || strlen($password)<8 || strlen($password)>1024) json_response(['error' => 'Enter a valid email and a password between 8 and 1024 characters.'], 422);
    try {
        if ($action === 'register') {
            $name = trim((string)($data['name'] ?? '')); if($name===''||strlen($name)>120) json_response(['error' => 'Name is required and must be 120 characters or fewer.'], 422);
            if(!filter_var((string)($config['mail']['from']??''),FILTER_VALIDATE_EMAIL)) json_response(['error'=>'Account registration is temporarily unavailable because email delivery is not configured.'],503);
            $q = db()->prepare('INSERT INTO users(email,name,password_hash) VALUES(?,?,?)');
            $q->execute([$email, $name, password_hash($password, PASSWORD_DEFAULT)]); $id = (int)db()->lastInsertId();
            $record=['id'=>$id,'name'=>$name,'email'=>$email];if(!send_verification_email($record)){db()->prepare('DELETE FROM users WHERE id=?')->execute([$id]);json_response(['error'=>'The verification email could not be sent. Please try again later.'],503);}audit_event('email.verification_sent',['user_id'=>$id]);json_response(['verification_required'=>true,'message'=>'Account created. Check your email and verify it before signing in.'],201);
        } else {
            $q = db()->prepare('SELECT * FROM users WHERE email=?'); $q->execute([$email]); $record = $q->fetch();
            $hasPassword=!empty($record['password_hash']);$passwordHash=$hasPassword?(string)$record['password_hash']:'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';$passwordValid=$hasPassword&&password_verify($password,$passwordHash);if(!$hasPassword)password_verify($password,$passwordHash);
            if (!$record || !$passwordValid){audit_event('auth.login_failed',['email_hash'=>audit_identifier((string)$email)]);json_response(['error' => 'Invalid email or password.'], 401);}
            if(empty($record['email_verified_at'])){audit_event('auth.login_blocked_unverified',['user_id'=>(int)$record['id']]);json_response(['error'=>'Verify your email before signing in. Use “Resend verification email” to request a new link.','code'=>'email_unverified'],403);}
            $id = (int)$record['id']; $name = $record['name'];
            if(password_needs_rehash((string)$record['password_hash'],PASSWORD_DEFAULT)) db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
        }
        session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(24)); $_SESSION['user'] = ['id' => $id, 'name' => $name, 'email' => $email]; $_SESSION['session_version']=(int)(user_record($id)['session_version']??1); $_SESSION['authenticated_at']=time(); audit_event('auth.'.$action);
        json_response(['user' => $_SESSION['user']]);
    } catch (PDOException $e) { if($action==='register'&&$e->getCode()==='23000')audit_event('auth.registration_conflict',['email_hash'=>audit_identifier((string)$email)]); json_response(['error' => $e->getCode() === '23000' ? 'Email already registered.' : 'Database unavailable.'], $e->getCode()==='23000'?409:503); }
}

if (in_array($action, ['github', 'google'], true)) {
    $provider = $action;
    if (empty($config[$provider]['client_id'])) json_response(['error' => ucfirst($provider) . ' OAuth is not configured.'], 503);
    $_SESSION['oauth_state'] = bin2hex(random_bytes(20)); $_SESSION['oauth_provider'] = $provider;
    $currentUserId=isset($_SESSION['user']['id'])?(int)$_SESSION['user']['id']:0;
    $_SESSION['oauth_link_user_id']=$currentUserId?:null;
    // Bind account linking to this exact OAuth state. This survives the external
    // redirect more reliably than a single mutable session value.
    $_SESSION['oauth_link_users'][$_SESSION['oauth_state']]=$currentUserId;
    $base = $provider === 'github' ? 'https://github.com/login/oauth/authorize' : 'https://accounts.google.com/o/oauth2/v2/auth';
    $elevatedDelete=$provider==='github'&&($_GET['capability']??'')==='delete_fork';
    $scope = $provider === 'github' ? 'read:user user:email repo workflow'.($elevatedDelete?' delete_repo':'') : 'openid email profile';
    $params = ['client_id'=>$config[$provider]['client_id'], 'redirect_uri'=>$config[$provider]['redirect_uri'], 'scope'=>$scope, 'state'=>$_SESSION['oauth_state'], 'response_type'=>'code'];
    $_SESSION['oauth_delete_states'][$_SESSION['oauth_state']]=$elevatedDelete;
    if ($provider === 'google') $params['access_type'] = 'online';
    header('Location: ' . $base . '?' . http_build_query($params)); exit;
}

if (in_array($action, ['github_callback', 'google_callback'], true)) {
    $provider = str_replace('_callback', '', $action); $oauthState=(string)($_GET['state']??'');
    if (!hash_equals($_SESSION['oauth_state'] ?? '', $oauthState) || ($_SESSION['oauth_provider'] ?? '') !== $provider) oauth_dashboard_error('The sign-in request expired or failed its security check. Please try connecting again.');
    if (empty($_GET['code'])) oauth_dashboard_error('Authorization was cancelled. No account changes were made.');
    $tokenUrl = $provider === 'github' ? 'https://github.com/login/oauth/access_token' : 'https://oauth2.googleapis.com/token';
    $payload = ['client_id'=>$config[$provider]['client_id'], 'client_secret'=>$config[$provider]['client_secret'], 'code'=>$_GET['code'], 'redirect_uri'=>$config[$provider]['redirect_uri']];
    if ($provider === 'google') $payload['grant_type'] = 'authorization_code';
    $ch=curl_init($tokenUrl); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($payload),CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_TIMEOUT=>20]);
    $tokenResponse=curl_exec($ch); $curlError=curl_error($ch); $tokenStatus=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    if($tokenResponse===false){ error_log('ESPForge OAuth connection failed: '.($curlError?:'outbound request failed')); oauth_dashboard_error('The OAuth provider could not be reached. Please try again shortly.'); }
    $tokenData=json_decode($tokenResponse,true)?:[]; $token=$tokenData['access_token']??null;
    if(!$token){
        $detail=$tokenData['error_description']??$tokenData['error']??('Provider returned HTTP '.$tokenStatus);
        error_log('ESPForge OAuth token exchange failed for '.$provider.': '.$detail);
        oauth_dashboard_error('The OAuth provider rejected the authorization request. Please connect again.');
    }
    if($provider==='github'){
        $granted=array_filter(preg_split('/[\s,]+/',strtolower((string)($tokenData['scope']??''))));
        if(!in_array('repo',$granted,true) || !in_array('workflow',$granted,true)){
            oauth_dashboard_error('GitHub did not grant repository and workflow access. Reconnect using the configured GitHub OAuth App and approve the requested permissions.');
        }
    }
    $api=$provider==='github'?'https://api.github.com/user':'https://openidconnect.googleapis.com/v1/userinfo';
    $ch=curl_init($api); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/json','User-Agent: ESPForge'],CURLOPT_TIMEOUT=>20]); $profile=json_decode(curl_exec($ch)?:'[]',true); curl_close($ch);
    $providerId=(string)($profile['id']??$profile['sub']??''); $email=$profile['email']??null;
    if($provider==='github' && !$email){
        $ch=curl_init('https://api.github.com/user/emails'); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Accept: application/vnd.github+json','User-Agent: ESPForge'],CURLOPT_TIMEOUT=>20]);
        $emailResponse=curl_exec($ch); $emailStatus=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch); $emails=json_decode($emailResponse?:'[]',true);
        if(is_array($emails)){
            foreach($emails as $item) if(is_array($item)&&!empty($item['primary'])&&!empty($item['verified'])&&!empty($item['email'])){$email=$item['email'];break;}
            if(!$email) foreach($emails as $item) if(is_array($item)&&!empty($item['verified'])&&!empty($item['email'])){$email=$item['email'];break;}
        }
        // GitHub accounts may deliberately expose no email. The stable, provider-scoped
        // noreply address lets OAuth login proceed without inventing personal information.
        if(!$email && !empty($profile['login']) && $providerId) $email=$providerId.'+'.preg_replace('/[^A-Za-z0-9-]/','',$profile['login']).'@users.noreply.github.com';
        if(!$email) error_log('ESPForge GitHub email lookup failed with HTTP '.$emailStatus);
    }
    $email=is_string($email)?filter_var($email,FILTER_VALIDATE_EMAIL):false;
    if(!$providerId||!$email||strlen($email)>255) oauth_dashboard_error('The provider did not return a verified, usable account identity.');
    $idColumn=$provider.'_id';
    $q=db()->prepare("SELECT * FROM users WHERE {$idColumn}=? LIMIT 1"); $q->execute([$providerId]); $providerRecord=$q->fetch();
    $q=db()->prepare('SELECT * FROM users WHERE email=? LIMIT 1'); $q->execute([$email]); $emailRecord=$q->fetch();
    $name=trim(preg_replace('/[\x00-\x1F\x7F]+/u',' ',(string)($profile['name']??$profile['login']??explode('@',$email)[0]))??'');$name=$name!==''?$name:'ESPForge user';$name=preg_replace('/^(.{0,120}).*$/us','$1',$name)??'ESPForge user';
    $linkRecord=null;
    $linkId=(int)($_SESSION['oauth_link_users'][$oauthState]??$_SESSION['oauth_link_user_id']??$_SESSION['user']['id']??0);
    if($linkId){ $q=db()->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$linkId]); $linkRecord=$q->fetch(); }
    try {
        if($linkRecord){
            $id=(int)$linkRecord['id']; $sessionEmail=$linkRecord['email'];
            // Provider identities are exclusive. Never merge, transfer, or replace
            // another workspace merely because an authenticated user tries to link it.
            if($providerRecord && (int)$providerRecord['id']!==$id){
                oauth_dashboard_error(ucfirst($provider).' is already connected to another ESPForge account. Sign in with that account or choose a different provider account.');
            }
            $linkedProviderId=(string)($linkRecord[$idColumn]??'');
            if($linkedProviderId!=='' && !hash_equals($linkedProviderId,$providerId)){
                oauth_dashboard_error('This ESPForge account is already connected to a different '.ucfirst($provider).' account. Disconnecting or replacing linked identities is not allowed.');
            }
            if($emailRecord && (int)$emailRecord['id']!==$id){
                oauth_dashboard_error('The provider email belongs to another ESPForge account. Accounts and saved data cannot be merged or replaced.');
            }
            $sql="UPDATE users SET {$idColumn}=?, name=?"; $values=[$providerId,$name];
            if($provider==='github'){ $sql.=', github_token=?'; $values[]=encrypt_secret($token); }
            $sql.=' WHERE id=?'; $values[]=$id; db()->prepare($sql)->execute($values);
        } elseif($providerRecord){
            // Always preserve the existing provider identity. An email/password account may
            // already own the newly disclosed email, so do not move it implicitly.
            $id=(int)$providerRecord['id'];
            $sessionEmail=(!$emailRecord || (int)$emailRecord['id']===$id)?$email:$providerRecord['email'];
            $sql="UPDATE users SET {$idColumn}=?, name=?, email=?"; $values=[$providerId,$name,$sessionEmail];
            if($provider==='github'){ $sql.=', github_token=?'; $values[]=encrypt_secret($token); }
            $sql.=' WHERE id=?'; $values[]=$id; db()->prepare($sql)->execute($values);
        } elseif($emailRecord){
            // Matching email is not proof that this provider identity owns the
            // existing ESPForge workspace. Require the workspace owner to sign in
            // first and deliberately link from Settings; never merge on login.
            oauth_dashboard_error('An ESPForge account already uses this email. Sign in to that workspace first, then connect '.ucfirst($provider).' from Settings. Accounts are never merged automatically.');
        } else {
            $githubToken=$provider==='github'?encrypt_secret($token):null;
            $q=db()->prepare("INSERT INTO users(email,name,{$idColumn},github_token) VALUES(?,?,?,?)");
            $q->execute([$email,$name,$providerId,$githubToken]); $id=(int)db()->lastInsertId(); $sessionEmail=$email;
        }
    } catch(PDOException $e) {
        if(db()->inTransaction()) db()->rollBack();
        error_log('ESPForge OAuth account link failed: '.$e->getMessage());
        oauth_dashboard_error('This provider identity is already linked to another account. Sign out and use the originally linked account.');
    }
    db()->prepare('UPDATE users SET email_verified_at=COALESCE(email_verified_at,NOW()) WHERE id=?')->execute([$id]);$deleteScope=!empty($_SESSION['oauth_delete_states'][$oauthState]);
    unset($_SESSION['oauth_link_users'][$oauthState],$_SESSION['oauth_delete_states'][$oauthState],$_SESSION['oauth_state'],$_SESSION['oauth_provider'],$_SESSION['oauth_link_user_id']); session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(24)); $_SESSION['user']=['id'=>$id,'name'=>$name,'email'=>$sessionEmail]; $_SESSION['session_version']=(int)(user_record($id)['session_version']??1); $_SESSION['authenticated_at']=time(); audit_event('auth.oauth',['provider'=>$provider,'delete_scope'=>$deleteScope]); header('Location: ../dashboard.html'); exit;
}
json_response(['error' => 'Unknown action'], 404);
