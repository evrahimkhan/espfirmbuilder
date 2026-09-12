<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';
$isProduction = strtolower((string)($config['app']['env'] ?? 'production')) === 'production';
ini_set('display_errors', $isProduction ? '0' : '1');
ini_set('display_startup_errors', $isProduction ? '0' : '1');
ini_set('log_errors', '1');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $error) use ($isProduction): never {
    $requestId = bin2hex(random_bytes(6));
    error_log("ESPForge unhandled exception [{$requestId}]: {$error}");
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    $payload = ['error' => 'An unexpected server error occurred.', 'request_id' => $requestId];
    if (!$isProduction) $payload['detail'] = $error->getMessage();
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
});

if($isProduction){
    $appUrl=(string)($config['app']['url']??'');$encryptionKey=(string)($config['security']['encryption_key']??'');
    if(!str_starts_with(strtolower($appUrl),'https://')) throw new RuntimeException('Production APP_URL must use HTTPS.');
    if(strlen($encryptionKey)<32||str_contains($encryptionKey,'replace-with')) throw new RuntimeException('Production APP_KEY is missing or unsafe.');
    if(!str_starts_with((string)($config['database']['dsn']??''),'mysql:')) throw new RuntimeException('Production database configuration is invalid.');
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
$isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
if ($isProduction || $isHttps) ini_set('session.cookie_secure', '1');
session_name((string)$config['security']['session_name']);
session_start();
$now=time();
if(!empty($_SESSION['user'])&&($now-(int)($_SESSION['last_activity']??$now)>7200||$now-(int)($_SESSION['authenticated_at']??$now)>86400)){
    $_SESSION=[]; if(ini_get('session.use_cookies')){ $params=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$params['path'],$params['domain'],$params['secure'],$params['httponly']); } session_destroy(); session_start();
}
$_SESSION['last_activity']=$now;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(self), serial=(self)');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self' https://github.com https://accounts.google.com; frame-ancestors 'none'; object-src 'none'; script-src 'self' https://cdn.jsdelivr.net; style-src 'self' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; upgrade-insecure-requests");

function json_response(array $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
function body(): array { $max=65536;$declared=(int)($_SERVER['CONTENT_LENGTH']??0);if($declared>$max)json_response(['error'=>'Request body is too large.'],413);$raw=file_get_contents('php://input',false,null,0,$max+1);if($raw===false)json_response(['error'=>'Request body could not be read.'],400);if(strlen($raw)>$max)json_response(['error'=>'Request body is too large.'],413);if(trim($raw)===''||str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/x-www-form-urlencoded'))return is_array($_POST)?$_POST:[];try{$decoded=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(JsonException){json_response(['error'=>'Request body must contain valid JSON.'],400);}if(!is_array($decoded)||str_starts_with(ltrim($raw),'['))json_response(['error'=>'Request body must be a JSON object.'],400);return $decoded; }
function csrf(): string { if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function verify_csrf(): void { $h=$_SERVER['HTTP_X_CSRF_TOKEN']??''; if(!is_string($h) || !hash_equals($_SESSION['csrf']??'', $h)) json_response(['error'=>'Invalid CSRF token'],419); }
function require_user(): array { if(empty($_SESSION['user'])) json_response(['error'=>'Authentication required'],401); $q=db()->prepare('SELECT session_version FROM users WHERE id=?');$q->execute([$_SESSION['user']['id']]);$version=$q->fetchColumn();if($version===false||(int)$version!==(int)($_SESSION['session_version']??0)){$_SESSION=[];session_regenerate_id(true);json_response(['error'=>'Your session is no longer valid. Please sign in again.'],401);} return $_SESSION['user']; }
function require_recent_auth(int $maxAge=1800): void { if(time()-(int)($_SESSION['authenticated_at']??0)>$maxAge) json_response(['error'=>'For your security, sign out and sign in again before performing this action.','code'=>'recent_auth_required'],428); }

/** Fixed-window, file-backed limiter suitable for a single shared-hosting instance. */
function rate_limit(string $bucket, int $limit, int $windowSeconds, ?string $identity = null): void {
    $identity ??= isset($_SESSION['user']['id']) ? 'user:'.(int)$_SESSION['user']['id'] : 'ip:'.($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key=hash_hmac('sha256',$bucket.'|'.$identity,(string)($GLOBALS['config']['security']['encryption_key']??'espforge'));
    $directory=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'espforge-rate-limits';
    if(!is_dir($directory) && !@mkdir($directory,0700,true) && !is_dir($directory)) json_response(['error'=>'Rate limiter unavailable.'],503);
    $handle=@fopen($directory.DIRECTORY_SEPARATOR.$key.'.json','c+');
    if($handle===false) json_response(['error'=>'Rate limiter unavailable.'],503);
    try {
        if(!flock($handle,LOCK_EX)) json_response(['error'=>'Rate limiter unavailable.'],503);
        $raw=stream_get_contents($handle); $record=json_decode($raw?:'[]',true); $now=time();
        if(!is_array($record) || ($record['reset']??0)<=$now) $record=['count'=>0,'reset'=>$now+$windowSeconds];
        $record['count']=(int)$record['count']+1;
        ftruncate($handle,0); rewind($handle); fwrite($handle,json_encode($record)); fflush($handle);
        $remaining=max(0,$limit-$record['count']);
        header('X-RateLimit-Limit: '.$limit); header('X-RateLimit-Remaining: '.$remaining); header('X-RateLimit-Reset: '.$record['reset']);
        if($record['count']>$limit){ header('Retry-After: '.max(1,$record['reset']-$now)); json_response(['error'=>'Too many requests. Please wait and try again.'],429); }
    } finally { flock($handle,LOCK_UN); fclose($handle); }
}

function encrypt_secret(string $value): string { global $config; $key=hash('sha256',$config['security']['encryption_key'],true); $iv=random_bytes(12); $tag=''; $cipher=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag); if($cipher===false) throw new RuntimeException('Secret encryption failed'); return base64_encode($iv.$tag.$cipher); }
function decrypt_secret(?string $value): ?string { global $config; if(!$value) return null; $data=base64_decode($value,true); if($data===false || strlen($data)<29) return null; $keys=array_merge([(string)$config['security']['encryption_key']],$config['security']['previous_encryption_keys']??[]); foreach(array_unique($keys) as $candidate){$key=hash('sha256',$candidate,true);$plain=openssl_decrypt(substr($data,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($data,0,12),substr($data,12,16));if($plain!==false)return $plain;} return null; }
function ai_key_fingerprints(string $value): array { global $config; $keys=array_merge([(string)$config['security']['encryption_key']],$config['security']['previous_encryption_keys']??[]); return array_values(array_unique(array_map(fn($key)=>hash_hmac('sha256',$value,(string)$key),array_filter($keys,fn($key)=>(string)$key!=='')))); }
function ai_key_fingerprint(string $value): string { $fingerprints=ai_key_fingerprints($value); if(!$fingerprints) throw new RuntimeException('API key fingerprinting is not configured.'); return $fingerprints[0]; }
function uuid_v4(): string { $bytes=random_bytes(16); $bytes[6]=chr((ord($bytes[6])&0x0f)|0x40); $bytes[8]=chr((ord($bytes[8])&0x3f)|0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4)); }
function audit_event(string $type, array $metadata=[]): void { try { global $config; $userId=isset($_SESSION['user']['id'])?(int)$_SESSION['user']['id']:null; $ip=(string)($_SERVER['REMOTE_ADDR']??''); $ipHash=$ip!==''?hash_hmac('sha256',$ip,(string)$config['security']['encryption_key']):null; $q=db()->prepare('INSERT INTO audit_events(user_id,event_type,ip_hash,metadata_json) VALUES(?,?,?,?)'); $q->execute([$userId,substr($type,0,80),$ipHash,$metadata?json_encode($metadata,JSON_UNESCAPED_SLASHES):null]); } catch(Throwable $error){ error_log('ESPForge audit event failed: '.$error->getMessage()); } }
function user_record(int $id): array { $q=db()->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$id]); $user=$q->fetch(); if(!$user) json_response(['error'=>'User not found'],404); return $user; }
function github_token(int $userId): string { $token=decrypt_secret(user_record($userId)['github_token']??null); if(!$token) json_response(['error'=>'Connect GitHub in Settings before continuing.'],409); return $token; }
function db(): PDO { global $config; static $pdo; if(!$pdo) $pdo=new PDO($config['database']['dsn'],$config['database']['user'],$config['database']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); return $pdo; }
