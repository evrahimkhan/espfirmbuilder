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
if (($data['action'] ?? '') === 'test_ai_key') {
    $record = user_record((int)$user['id']);
    $provider = $provider ?: ($record['ai_provider'] ?? null);
    if (!$provider || !in_array($provider, ['google', 'openrouter'], true)) json_response(['error' => 'Select an AI provider first.'], 422);
    if ($key === '') $key = decrypt_secret($record['ai_api_key'] ?? null) ?? '';
    if ($key === '') json_response(['error' => 'Enter or save an AI API key first.'], 422);

    $url = $provider === 'google'
        ? 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($key)
        : 'https://openrouter.ai/api/v1/auth/key';
    $headers = ['Accept: application/json', 'User-Agent: ESPForge'];
    if ($provider === 'openrouter') $headers[] = 'Authorization: Bearer ' . $key;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false]);
    $response = curl_exec($ch); $status = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $error = curl_error($ch); curl_close($ch);
    if ($response === false || $error !== '') json_response(['error'=>'Could not contact the AI provider: '.$error],502);
    $payload = json_decode($response,true);
    if ($status === 401 || $status === 403 || ($provider === 'google' && $status === 400)) json_response(['error'=>'The '.$provider.' API key is invalid or unauthorized.'],422);
    if ($status < 200 || $status >= 300) json_response(['error'=>'The AI provider returned HTTP '.$status.'. Please try again later.'],502);
    $detail = $provider === 'openrouter' && isset($payload['data']['label']) ? ' · '.$payload['data']['label'] : '';
    json_response(['ok'=>true,'valid'=>true,'message'=>($provider === 'google' ? 'Google Gemini' : 'OpenRouter').' API key is valid'.$detail.'.']);
}
if ($key !== '') {
    $q = db()->prepare('UPDATE users SET ai_provider=?, ai_api_key=? WHERE id=?');
    $q->execute([$provider, encrypt_secret($key), $user['id']]);
} elseif ($provider !== null) {
    $q = db()->prepare('UPDATE users SET ai_provider=? WHERE id=?');
    $q->execute([$provider, $user['id']]);
}
json_response(['ok' => true]);
