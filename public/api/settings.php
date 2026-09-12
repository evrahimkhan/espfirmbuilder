<?php
require __DIR__ . '/../../src/bootstrap.php';
require_method('GET','POST');
$user = require_user();
verify_csrf();
rate_limit('settings', $_SERVER['REQUEST_METHOD'] === 'GET' ? 60 : 15, 60);
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $record = user_record((int)$user['id']);
    json_response(['settings' => [
        'github_connected' => !empty($record['github_token']),
        'ai_provider' => $record['ai_provider'],
        'ai_key_configured' => !empty($record['ai_api_key']),
    ]]);
}
$data = body();
$provider = $data['ai_provider'] ?? null;
if ($provider !== null && !in_array($provider, ['google', 'openrouter'], true)) json_response(['error' => 'Unsupported AI provider.'], 422);
$key = trim((string)($data['ai_api_key'] ?? ''));
if (($data['action'] ?? '') === 'remove_ai_key') {
    require_recent_auth();
    $q=db()->prepare('UPDATE users SET ai_api_key=NULL,ai_key_fingerprint=NULL WHERE id=?'); $q->execute([$user['id']]); audit_event('ai_key.removed');
    json_response(['ok'=>true,'message'=>'AI API key removed from this account.']);
}
if ($key !== '') {
    $candidateFingerprints=ai_key_fingerprints($key);$placeholders=implode(',',array_fill(0,count($candidateFingerprints),'?'));
    $q=db()->prepare("SELECT id FROM users WHERE ai_key_fingerprint IN ({$placeholders}) AND id<>? LIMIT 1");$q->execute([...$candidateFingerprints,$user['id']]);
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
        : 'https://openrouter.ai/api/v1/key';
    $headers = ['Accept: application/json', 'User-Agent: ESPForge'];
    if ($provider === 'google') $headers[] = 'x-goog-api-key: ' . $key;
    else $headers[] = 'Authorization: Bearer ' . $key;
    $ch = curl_init($url);$response='';$overflow=false;$maxBytes=1024*1024;
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$response,&$overflow,$maxBytes):int{if(strlen($response)+strlen($chunk)>$maxBytes){$overflow=true;return 0;}$response.=$chunk;return strlen($chunk);}]);
    $ok = curl_exec($ch); $status = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $error = curl_error($ch); curl_close($ch);
    if ($ok === false || $error !== '') json_response(['error'=>$overflow?'AI provider response exceeded the safety limit.':'Could not contact the AI provider.'],502);
    $payload = json_decode($response,true);if(!is_array($payload))json_response(['error'=>'The AI provider returned an invalid response.'],502);
    $providerMessage = trim((string)($payload['error']['message'] ?? $payload['message'] ?? ''));
    if ($provider === 'google' && stripos($providerMessage,'location is not supported') !== false) {
        $_SESSION['validated_ai_key']=['fingerprint'=>ai_key_fingerprint($key),'time'=>time()];
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
    $_SESSION['validated_ai_key']=['fingerprint'=>ai_key_fingerprint($key),'time'=>time()];
    json_response(['ok'=>true,'valid'=>true,'message'=>($provider === 'google' ? 'Google Gemini' : 'OpenRouter').' API key is valid for this account.']);
}
if ($key !== '') {
    $current=user_record((int)$user['id']); $currentFingerprint=(string)($current['ai_key_fingerprint']??'');$candidateFingerprints=ai_key_fingerprints($key);
    $validated=$_SESSION['validated_ai_key']??[];if($currentFingerprint===''&&(!is_array($validated)||time()-(int)($validated['time']??0)>600||!in_array((string)($validated['fingerprint']??''),$candidateFingerprints,true)))json_response(['error'=>'Check and validate this API key before saving it.'],422);
    if($currentFingerprint!==''&&!in_array($currentFingerprint,$candidateFingerprints,true)) json_response(['error'=>'Remove the currently bound API key before saving a different key.'],409);
    $q = db()->prepare('UPDATE users SET ai_provider=?, ai_api_key=?, ai_key_fingerprint=? WHERE id=?');
    try{$q->execute([$provider, encrypt_secret($key), ai_key_fingerprint($key), $user['id']]);}catch(PDOException $error){if($error->getCode()==='23000')json_response(['error'=>'This API key is already bound to another ESPForge account.'],409);throw $error;}unset($_SESSION['validated_ai_key']);
} elseif ($provider !== null) {
    $q = db()->prepare('UPDATE users SET ai_provider=? WHERE id=?');
    $q->execute([$provider, $user['id']]);
}
json_response(['ok' => true]);
