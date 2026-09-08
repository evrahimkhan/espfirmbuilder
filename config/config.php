<?php
$base = require __DIR__ . '/config.example.php';
$base['app']['url'] = getenv('APP_URL') ?: $base['app']['url'];
$base['database']['dsn'] = getenv('DB_DSN') ?: $base['database']['dsn'];
$base['database']['user'] = getenv('DB_USER') ?: $base['database']['user'];
$base['database']['password'] = getenv('DB_PASSWORD') ?: $base['database']['password'];
$base['security']['encryption_key'] = getenv('APP_KEY') ?: $base['security']['encryption_key'];
$base['github']['client_id'] = getenv('GITHUB_CLIENT_ID') ?: '';
$base['github']['client_secret'] = getenv('GITHUB_CLIENT_SECRET') ?: '';
$base['google']['client_id'] = getenv('GOOGLE_CLIENT_ID') ?: '';
$base['google']['client_secret'] = getenv('GOOGLE_CLIENT_SECRET') ?: '';
$base['github']['redirect_uri'] = rtrim($base['app']['url'], '/') . '/api/auth.php?action=github_callback';
$base['google']['redirect_uri'] = rtrim($base['app']['url'], '/') . '/api/auth.php?action=google_callback';
return $base;
