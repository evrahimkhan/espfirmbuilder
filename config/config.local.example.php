<?php
// Copy to config.local.php on the server. config.local.php is ignored by Git.
return [
    'app' => [
        'url' => 'https://YOUR_ACCOUNT.alwaysdata.net',
        'env' => 'production',
    ],
    'database' => [
        'dsn' => 'mysql:host=mysql-YOUR_ACCOUNT.alwaysdata.net;port=3306;dbname=YOUR_DATABASE;charset=utf8mb4',
        'user' => 'YOUR_DATABASE_USER',
        'password' => 'YOUR_DATABASE_PASSWORD',
    ],
    'security' => [
        'encryption_key' => 'GENERATE_A_UNIQUE_64_CHARACTER_HEX_SECRET',
    ],
    'github' => [
        'client_id' => 'YOUR_GITHUB_OAUTH_CLIENT_ID',
        'client_secret' => 'YOUR_GITHUB_OAUTH_CLIENT_SECRET',
    ],
    'google' => [
        'client_id' => 'YOUR_GOOGLE_OAUTH_CLIENT_ID',
        'client_secret' => 'YOUR_GOOGLE_OAUTH_CLIENT_SECRET',
    ],
];
