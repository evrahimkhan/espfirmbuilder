<?php
return [
    'app' => ['name' => 'ESPForge', 'url' => 'http://localhost:8080', 'env' => 'production'],
    'database' => ['dsn' => 'mysql:host=localhost;dbname=espforge;charset=utf8mb4', 'user' => 'root', 'password' => ''],
    'security' => ['encryption_key' => 'replace-with-32-byte-random-secret', 'previous_encryption_keys' => [], 'session_name' => 'espforge_session'],
    'github' => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => 'http://localhost:8080/api/auth.php?action=github_callback'],
    'google' => ['client_id' => '', 'client_secret' => '', 'redirect_uri' => 'http://localhost:8080/api/auth.php?action=google_callback'],
    'mail' => ['from' => 'no-reply@example.com'],
];
