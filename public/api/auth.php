<?php
require __DIR__ . '/../../src/bootstrap.php';
$action = $_GET['action'] ?? 'session';
function oauth_dashboard_error(string $message): never { $page=!empty($_SESSION['user'])?'dashboard.html':'index.html'; $fragment=$page==='dashboard.html'?'#settings':''; header('Location: ../'.$page.'?oauth_error='.rawurlencode($message).$fragment); exit; }

if ($action === 'session') json_response(['user' => $_SESSION['user'] ?? null, 'csrf' => csrf()]);
if ($action === 'logout') { verify_csrf(); audit_event('auth.logout'); $_SESSION=[]; if(ini_get('session.use_cookies')){$params=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$params['path'],$params['domain'],$params['secure'],$params['httponly']);} session_destroy(); json_response(['ok' => true]); }

if ($action === 'register' || $action === 'login') {
    rate_limit('auth-'.$action, 8, 900);
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
            if(password_needs_rehash((string)$record['password_hash'],PASSWORD_DEFAULT)) db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($password,PASSWORD_DEFAULT),$id]);
        }
        session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(24)); $_SESSION['user'] = ['id' => $id, 'name' => $name, 'email' => $email]; $_SESSION['authenticated_at']=time(); audit_event('auth.'.$action);
        json_response(['user' => $_SESSION['user']]);
    } catch (PDOException $e) { json_response(['error' => $e->getCode() === '23000' ? 'Email already registered.' : 'Database unavailable.'], 409); }
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
    $scope = $provider === 'github' ? 'read:user user:email repo workflow delete_repo' : 'openid email profile';
    $params = ['client_id'=>$config[$provider]['client_id'], 'redirect_uri'=>$config[$provider]['redirect_uri'], 'scope'=>$scope, 'state'=>$_SESSION['oauth_state'], 'response_type'=>'code'];
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
    if(!$providerId || !$email) oauth_dashboard_error('The provider did not return a verified, usable account identity.');
    $idColumn=$provider.'_id';
    $q=db()->prepare("SELECT * FROM users WHERE {$idColumn}=? LIMIT 1"); $q->execute([$providerId]); $providerRecord=$q->fetch();
    $q=db()->prepare('SELECT * FROM users WHERE email=? LIMIT 1'); $q->execute([$email]); $emailRecord=$q->fetch();
    $name=$profile['name']??$profile['login']??explode('@',$email)[0];
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
            $id=(int)$emailRecord['id']; $sessionEmail=$emailRecord['email'];
            $linkedProviderId=(string)($emailRecord[$idColumn]??'');
            if($linkedProviderId!=='' && !hash_equals($linkedProviderId,$providerId)){
                oauth_dashboard_error('An ESPForge account with this email is already connected to a different '.ucfirst($provider).' identity. Existing accounts cannot be replaced.');
            }
            $sql="UPDATE users SET {$idColumn}=?, name=?"; $values=[$providerId,$name];
            if($provider==='github'){ $sql.=', github_token=?'; $values[]=encrypt_secret($token); }
            $sql.=' WHERE id=?'; $values[]=$id; db()->prepare($sql)->execute($values);
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
    unset($_SESSION['oauth_link_users'][$oauthState],$_SESSION['oauth_state'],$_SESSION['oauth_provider'],$_SESSION['oauth_link_user_id']); session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(24)); $_SESSION['user']=['id'=>$id,'name'=>$name,'email'=>$sessionEmail]; $_SESSION['authenticated_at']=time(); audit_event('auth.oauth',['provider'=>$provider]); header('Location: ../dashboard.html'); exit;
}
json_response(['error' => 'Unknown action'], 404);
