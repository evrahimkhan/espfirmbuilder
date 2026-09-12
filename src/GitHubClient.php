<?php
declare(strict_types=1);

final class GitHubClient
{
    public function __construct(private string $token) {}

    public function request(string $method, string $path, ?array $payload = null, bool $raw = false): array|string
    {
        $url = str_starts_with($path, 'http') ? $path : 'https://api.github.com' . $path;
        $headers = [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $this->token,
            'User-Agent: ESPForge',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        $ch = curl_init($url); $response=''; $overflow=false; $maxBytes=15*1024*1024;
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => static function($curl,string $chunk)use(&$response,&$overflow,$maxBytes):int {
                if(strlen($response)+strlen($chunk)>$maxBytes){$overflow=true;return 0;}
                $response.=$chunk; return strlen($chunk);
            },
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($ok === false || $error) throw new RuntimeException($overflow?'GitHub response exceeded the safety limit.':'GitHub request failed: ' . $error);
        if ($status === 204) return [];
        $decoded = json_decode($response, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? ($decoded['message'] ?? 'Unknown GitHub error') : 'Unexpected response';
            throw new RuntimeException("GitHub API returned {$status}: ".substr((string)$message,0,500), $status);
        }
        return $raw ? $response : (is_array($decoded) ? $decoded : []);
    }

    public function repository(string $fullName): array { return $this->request('GET', '/repos/' . $fullName); }
    public function tree(string $fullName, string $branch): array {
        $tree=$this->request('GET', "/repos/{$fullName}/git/trees/" . rawurlencode($branch) . '?recursive=1');
        if(!empty($tree['truncated'])) throw new RuntimeException('This repository tree is too large for safe complete analysis. Use a smaller firmware-only repository or subproject.',422);
        return $tree;
    }

    public function file(string $fullName, string $path, string $branch): ?string
    {
        try {
            $file = $this->request('GET', "/repos/{$fullName}/contents/" . implode('/', array_map('rawurlencode', explode('/', $path))) . '?ref=' . rawurlencode($branch));
            if((int)($file['size']??0)>1024*1024) throw new RuntimeException('Repository configuration file exceeds the 1 MB analysis limit.',422);
            if(!isset($file['content'])||($file['encoding']??'base64')!=='base64') return null;
            $decoded=base64_decode(str_replace("\n", '',(string)$file['content']),true);
            return $decoded===false?null:$decoded;
        } catch (RuntimeException $e) {
            if ($e->getCode() === 404) return null;
            throw $e;
        }
    }

    public function sourceBundle(string $fullName, string $branch, array $tree, int $limit = 60): string
    {
        $bundle = ''; $count = 0;
        foreach ($tree as $entry) {
            $path = $entry['path'] ?? '';
            if (($entry['type'] ?? '') !== 'blob' || !preg_match('/\.(?:ino|h|hpp|c|cpp)$/i', $path)) continue;
            if (($entry['size'] ?? 0) > 250000 || $count++ >= $limit) continue;
            $content = $this->file($fullName, $path, $branch);
            if ($content !== null) $bundle .= "\n// ESPForge source: {$path}\n" . $content;
        }
        return $bundle;
    }

    public function putFile(string $fullName, string $path, string $branch, string $content, string $message): array
    {
        $payload = ['message' => $message, 'content' => base64_encode($content), 'branch' => $branch];
        try {
            $current = $this->request('GET', "/repos/{$fullName}/contents/{$path}?ref=" . rawurlencode($branch));
            if (isset($current['content'])) {
                $existing = base64_decode(str_replace("\n", '', $current['content']), true);
                if ($existing !== false && rtrim($existing) === rtrim($content)) return $current;
            }
            if (!empty($current['sha'])) $payload['sha'] = $current['sha'];
        } catch (RuntimeException $e) {
            if ($e->getCode() !== 404) throw $e;
        }
        return $this->request('PUT', "/repos/{$fullName}/contents/{$path}", $payload);
    }

    public function ensureFork(string $sourceFullName, string $repositoryName): array
    {
        $viewer = $this->request('GET', '/user');
        $login = $viewer['login'] ?? null;
        if (!$login) throw new RuntimeException('GitHub did not return the authenticated username.');
        $forkFullName = $login . '/' . $repositoryName;

        // Reuse a fork that the user already owns.
        try {
            $existing = $this->repository($forkFullName);
            if (($existing['fork'] ?? false) && strcasecmp($existing['parent']['full_name'] ?? '', $sourceFullName) === 0) return $existing;
            throw new RuntimeException("A repository named {$repositoryName} already exists in your account and is not a fork of {$sourceFullName}.", 409);
        } catch (RuntimeException $e) {
            if ($e->getCode() !== 404) throw $e;
        }

        // Send an explicit JSON body. Some GitHub API gateways reject a bodyless
        // custom POST before it reaches the repository-fork operation.
        $fork = $this->request('POST', "/repos/{$sourceFullName}/forks", ['default_branch_only' => true]);
        $forkFullName = $fork['full_name'] ?? $forkFullName;

        // GitHub creates forks asynchronously. Keep this bounded for shared-hosting limits.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            usleep(500000);
            try { return $this->repository($forkFullName); }
            catch (RuntimeException $e) { if ($e->getCode() !== 404) throw $e; }
        }
        throw new RuntimeException("Your fork {$forkFullName} was requested but GitHub is still preparing it. Wait a moment and submit the original URL again.", 503);
    }

    public function enableWorkflow(string $fullName, string $workflow): void
    {
        $this->request('PUT', "/repos/{$fullName}/actions/workflows/" . rawurlencode($workflow) . '/enable');
    }

    public function dispatch(string $fullName, string $workflow, string $branch, array $inputs = []): void
    {
        $payload=['ref'=>$branch];
        if($inputs) $payload['inputs']=$inputs;
        $path="/repos/{$fullName}/actions/workflows/" . rawurlencode($workflow) . '/dispatches';
        $lastError=null;
        // GitHub registers a newly committed workflow asynchronously. During that
        // short window dispatch returns 404 or the misleading "no workflow_dispatch"
        // 422 even though the committed YAML contains the trigger.
        for($attempt=0;$attempt<3;$attempt++){
            if($attempt>0){
                try { $this->enableWorkflow($fullName,$workflow); }
                catch(RuntimeException $ignored) {}
            }
            try { $this->request('POST',$path,$payload); return; }
            catch(RuntimeException $e){
                $lastError=$e;
                if(!in_array($e->getCode(),[404,422],true)) throw $e;
                if($attempt<2) usleep(2000000);
            }
        }
        // Some forks keep returning a stale workflow_dispatch capability even after
        // the workflow is updated. Generated ESPForge workflows also listen for this
        // repository event, which uses a separate and more reliable GitHub endpoint.
        if($lastError?->getCode()===422){
            $event=['event_type'=>'espforge_build'];
            if($inputs) $event['client_payload']=$inputs;
            $this->request('POST',"/repos/{$fullName}/dispatches",$event);
            return;
        }
        throw $lastError??new RuntimeException('GitHub did not register the workflow in time.',502);
    }
}
