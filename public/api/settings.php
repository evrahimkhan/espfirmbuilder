<?php
require __DIR__ . '/../../src/bootstrap.php';
$user = require_user();
try { ensure_ai_key_ownership(); }
catch(Throwable $e){ error_log('ESPForge API key ownership migration failed: '.$e->getMessage()); json_response(['error'=>'API key ownership storage could not be initialized.'],503); }
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
if (($data['action'] ?? '') === 'remove_ai_key') {
    $q=db()->prepare('UPDATE users SET ai_api_key=NULL,ai_key_fingerprint=NULL WHERE id=?'); $q->execute([$user['id']]);
    json_response(['ok'=>true,'message'=>'AI API key removed from this account.']);
}
if ($key !== '') {
    $fingerprint=ai_key_fingerprint($key); $q=db()->prepare('SELECT id FROM users WHERE ai_key_fingerprint=? AND id<>? LIMIT 1'); $q->execute([$fingerprint,$user['id']]);
    if($q->fetch()) json_response(['error'=>'This API key is already bound to another ESPForge account. Use a different API key.'],409);
}
if (($data['action'] ?? '') === 'test_ai_key') {
    $record = user_record((int)$user['id']);
    $provider = $provider ?: ($record['ai_provider'] ?? null);
    if (!$provider || !in_array($provider, ['google', 'openrouter'], true)) json_response(['error' => 'Select an AI provider first.'], 422);
    if ($key === '') $key = decrypt_secret($record['ai_api_key'] ?? null) ?? '';
    if ($key === '') json_response(['error' => 'Enter or save an AI API key first.'], 422);

    $url = $provider === 'google'
        ? 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash'
        : 'https://openrouter.ai/api/v1/auth/key';
    $headers = ['Accept: application/json', 'User-Agent: ESPForge'];
    if ($provider === 'google') $headers[] = 'x-goog-api-key: ' . $key;
    else $headers[] = 'Authorization: Bearer ' . $key;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false]);
    $response = curl_exec($ch); $status = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $error = curl_error($ch); curl_close($ch);
    if ($response === false || $error !== '') json_response(['error'=>'Could not contact the AI provider: '.$error],502);
    $payload = json_decode($response,true);
    $providerMessage = trim((string)($payload['error']['message'] ?? $payload['message'] ?? ''));
    if ($provider === 'google' && stripos($providerMessage,'location is not supported') !== false) {
        json_response([
            'ok'=>true,
            'valid'=>true,
            'usable'=>false,
            'message'=>'The API key was accepted, but Google Gemini blocks requests from this hosting server location. Select OpenRouter to use AI analysis from this server.'
        ]);
    }
    if ($status === 400 || $status === 401 || $status === 403) {
        $detail=$providerMessage!==''?' Provider response: '.substr($providerMessage,0,300):'';
        json_response(['error'=>($provider === 'google'?'Google Gemini':'OpenRouter').' rejected the API key.'.$detail],422);
    }
    if ($status < 200 || $status >= 300) {
        $detail=$providerMessage!==''?' '.substr($providerMessage,0,300):'';
        json_response(['error'=>'The AI provider returned HTTP '.$status.'.'.$detail],502);
    }
    json_response(['ok'=>true,'valid'=>true,'message'=>($provider === 'google' ? 'Google Gemini' : 'OpenRouter').' API key is valid for this account.']);
}
if ($key !== '') {
    $current=user_record((int)$user['id']); $currentFingerprint=(string)($current['ai_key_fingerprint']??'');
    if($currentFingerprint!==''&&!hash_equals($currentFingerprint,ai_key_fingerprint($key))) json_response(['error'=>'Remove the currently bound API key before saving a different key.'],409);
    $q = db()->prepare('UPDATE users SET ai_provider=?, ai_api_key=?, ai_key_fingerprint=? WHERE id=?');
    $q->execute([$provider, encrypt_secret($key), ai_key_fingerprint($key), $user['id']]);
} elseif ($provider !== null) {
    $q = db()->prepare('UPDATE users SET ai_provider=? WHERE id=?');
    $q->execute([$provider, $user['id']]);
}
json_response(['ok' => true]);
