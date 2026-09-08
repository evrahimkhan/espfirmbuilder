<?php
require __DIR__ . '/../../src/bootstrap.php';
$user = require_user();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $record = user_record((int)$user['id']);
    json_response(['settings' => [
        'github_connected' => !empty($record['github_token']),
        'ai_provider' => $record['ai_provider'],
        'ai_key_configured' => !empty($record['ai_api_key']),
    ]]);
}
verify_csrf();
$data = body();
$provider = $data['ai_provider'] ?? null;
if ($provider !== null && !in_array($provider, ['google', 'openrouter'], true)) json_response(['error' => 'Unsupported AI provider.'], 422);
$key = trim((string)($data['ai_api_key'] ?? ''));
if ($key !== '') {
    $q = db()->prepare('UPDATE users SET ai_provider=?, ai_api_key=? WHERE id=?');
    $q->execute([$provider, encrypt_secret($key), $user['id']]);
} elseif ($provider !== null) {
    $q = db()->prepare('UPDATE users SET ai_provider=? WHERE id=?');
    $q->execute([$provider, $user['id']]);
}
json_response(['ok' => true]);
