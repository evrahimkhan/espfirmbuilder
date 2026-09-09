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
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false || $error) throw new RuntimeException('GitHub request failed: ' . $error);
        if ($status === 204) return [];
        $decoded = json_decode($response, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? ($decoded['message'] ?? 'Unknown GitHub error') : $response;
            throw new RuntimeException("GitHub API returned {$status}: {$message}", $status);
        }
        return $raw ? $response : (is_array($decoded) ? $decoded : []);
    }

    public function repository(string $fullName): array { return $this->request('GET', '/repos/' . $fullName); }
    public function tree(string $fullName, string $branch): array { return $this->request('GET', "/repos/{$fullName}/git/trees/" . rawurlencode($branch) . '?recursive=1'); }

    public function file(string $fullName, string $path, string $branch): ?string
    {
        try {
            $file = $this->request('GET', "/repos/{$fullName}/contents/" . implode('/', array_map('rawurlencode', explode('/', $path))) . '?ref=' . rawurlencode($branch));
            return isset($file['content']) ? base64_decode(str_replace("\n", '', $file['content']), true) ?: null : null;
        } catch (RuntimeException $e) {
            if ($e->getCode() === 404) return null;
            throw $e;
        }
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

    public function dispatch(string $fullName, string $workflow, string $branch): void
    {
        $this->request('POST', "/repos/{$fullName}/actions/workflows/" . rawurlencode($workflow) . '/dispatches', ['ref' => $branch]);
    }
}
