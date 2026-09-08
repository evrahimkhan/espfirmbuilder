<?php
declare(strict_types=1);
$config = require __DIR__ . '/../config/config.php';
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS'])) ini_set('session.cookie_secure', '1');
session_name($config['security']['session_name']);
session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
function json_response(array $data, int $status=200): never { http_response_code($status); header('Content-Type: application/json'); echo json_encode($data); exit; }
function body(): array { $raw=file_get_contents('php://input'); return json_decode($raw ?: '[]', true) ?: $_POST; }
function csrf(): string { if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function verify_csrf(): void { $h=$_SERVER['HTTP_X_CSRF_TOKEN']??''; if(!hash_equals($_SESSION['csrf']??'', $h)) json_response(['error'=>'Invalid CSRF token'],419); }
function require_user(): array { if(empty($_SESSION['user'])) json_response(['error'=>'Authentication required'],401); return $_SESSION['user']; }
function encrypt_secret(string $value): string { global $config; $key=hash('sha256',$config['security']['encryption_key'],true); $iv=random_bytes(12); $tag=''; $cipher=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag); if($cipher===false) throw new RuntimeException('Secret encryption failed'); return base64_encode($iv.$tag.$cipher); }
function decrypt_secret(?string $value): ?string { global $config; if(!$value) return null; $data=base64_decode($value,true); if($data===false || strlen($data)<29) return null; $key=hash('sha256',$config['security']['encryption_key'],true); $plain=openssl_decrypt(substr($data,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($data,0,12),substr($data,12,16)); return $plain===false?null:$plain; }
function user_record(int $id): array { $q=db()->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$id]); $user=$q->fetch(); if(!$user) json_response(['error'=>'User not found'],404); return $user; }
function github_token(int $userId): string { $token=decrypt_secret(user_record($userId)['github_token']??null); if(!$token) json_response(['error'=>'Connect GitHub in Settings before continuing.'],409); return $token; }
function db(): PDO { global $config; static $pdo; if(!$pdo) $pdo=new PDO($config['database']['dsn'],$config['database']['user'],$config['database']['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]); return $pdo; }
