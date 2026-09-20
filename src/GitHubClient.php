<?php
declare(strict_types=1);

final class GitHubClient
{
    public function __construct(private string $token) {}

    public function request(string $method, string $path, ?array $payload = null, bool $raw = false): array|string
    {
        if(!str_starts_with($path,'/')) throw new InvalidArgumentException('GitHub API paths must be relative.');
        $url = 'https://api.github.com' . $path;
        $headers = [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $this->token,
            'User-Agent: ESPForge',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        $ch = curl_init($url); $response=''; $overflow=false; $maxBytes=15*1024*1024;$responseHeaders=[];$started=microtime(true);
        curl_setopt_array($ch, [
            CURLOPT_HEADERFUNCTION => static function($curl,string $line)use(&$responseHeaders):int{$length=strlen($line);if(str_contains($line,':')){[$name,$value]=explode(':',$line,2);$responseHeaders[strtolower(trim($name))]=trim($value);}return $length;},
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
        $duration=(int)round((microtime(true)-$started)*1000);$remaining=isset($responseHeaders['x-ratelimit-remaining'])?(int)$responseHeaders['x-ratelimit-remaining']:null;$retryAfter=isset($responseHeaders['retry-after'])?max(0,(int)$responseHeaders['retry-after']):null;
        if(function_exists('operational_metric'))operational_metric('github.api',$duration,($status>=200&&$status<300)?'success':'failure',['method'=>$method,'route_hash'=>substr(hash('sha256',strtok($path,'?')),0,16),'http_status'=>$status,'rate_remaining'=>$remaining,'retry_after'=>$retryAfter]);
        if ($ok === false || $error) throw new RuntimeException($overflow?'GitHub response exceeded the safety limit.':'GitHub request failed: ' . $error);
        if ($status === 204) return [];
        $decoded = json_decode($response, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? ($decoded['message'] ?? 'Unknown GitHub error') : 'Unexpected response';
            $retryMessage=$retryAfter!==null?' Retry after '.$retryAfter.' seconds.':($remaining===0?' GitHub rate limit is exhausted.':'');
            throw new RuntimeException("GitHub API returned {$status}: ".substr((string)$message,0,500).$retryMessage, $status);
        }
        return $raw ? $response : (is_array($decoded) ? $decoded : []);
    }

    public function repository(string $fullName): array { return $this->request('GET', '/repos/' . $fullName); }
    public function sourceRevision(string $fullName,string $branch,?string $knownSourceCommit=null): string {
        $head=$this->request('GET','/repos/'.$fullName.'/commits/'.rawurlencode($branch));$headSha=strtolower((string)($head['sha']??''));
        if(!preg_match('/^[a-f0-9]{40}$/',$headSha))throw new RuntimeException('GitHub did not return an immutable source revision.',502);
        if(is_string($knownSourceCommit)&&preg_match('/^[a-f0-9]{40}$/i',$knownSourceCommit)&&hash_equals($headSha,strtolower($knownSourceCommit)))return $headSha;
        if(is_string($knownSourceCommit)&&preg_match('/^[a-f0-9]{40}$/i',$knownSourceCommit)&&!hash_equals($headSha,strtolower($knownSourceCommit))){
            try{$comparison=$this->request('GET','/repos/'.$fullName.'/compare/'.strtolower($knownSourceCommit).'...'.rawurlencode($headSha));$files=$comparison['files']??[];$onlyWorkflow=in_array($comparison['status']??'',['ahead','identical'],true)&&(count($files)===0||(count($files)===1&&($files[0]['filename']??'')==='.github/workflows/espforge-build.yml'));if($onlyWorkflow)return strtolower($knownSourceCommit);}catch(RuntimeException $comparisonError){if(!in_array($comparisonError->getCode(),[404,422],true))throw $comparisonError;}
        }
        $commit=$head;
        for($depth=0;$depth<50;$depth++){
            $sha=strtolower((string)($commit['sha']??''));$message=(string)($commit['commit']['message']??'');$files=$commit['files']??[];$parents=$commit['parents']??[];
            $espforgeOnly=str_starts_with($message,'ci: configure ESPForge for ')&&count($files)===1&&($files[0]['filename']??'')==='.github/workflows/espforge-build.yml'&&isset($parents[0]['sha']);
            if(!$espforgeOnly)return $sha;$parent=strtolower((string)$parents[0]['sha'];$commit=$this->request('GET','/repos/'.$fullName.'/commits/'.$parent);
        }
        throw new RuntimeException('ESPForge could not resolve the underlying source revision safely.',409);
    }

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

    public function sourceBundle(string $fullName, string $branch, array $tree, int $limit = 140): string
    {
        $sources=array_values(array_filter($tree,fn($entry)=>($entry['type']??'')==='blob'&&preg_match('/\.(?:ino|h|hpp|c|cpp)$/i',(string)($entry['path']??''))&&($entry['size']??0)<=250000));
        $primaryDirectories=[];
        foreach($sources as $entry){$path=(string)$entry['path'];if(!preg_match('/\.ino$/i',$path)||preg_match('~(?:^|/)(?:test|tests|example|examples|demo|demos)(?:/|$)|(?:test|example|demo)[^/]*\.ino$~i',$path))continue;$directory=dirname($path);if($directory!=='.')$primaryDirectories[$directory]=true;}
        usort($sources,static function(array $left,array $right)use($primaryDirectories):int{$score=static function(array $entry)use($primaryDirectories):int{$path=(string)$entry['path'];$score=0;foreach($primaryDirectories as $directory=>$_)if(str_starts_with($path,$directory.'/')){$score-=1000;break;}if(preg_match('/\.ino$/i',$path))$score-=100;if(preg_match('~(?:^|/)(?:test|tests|example|examples|demo|demos)(?:/|$)~i',$path))$score+=2000;return $score;};return $score($left)<=>$score($right)?:strcasecmp((string)$left['path'],(string)$right['path']);});
        $sources=array_slice($sources,0,$limit);$bundle='';$maxBytes=6*1024*1024;
        // Git trees already provide immutable blob SHAs. Retrieve bounded blobs in
        // small parallel batches rather than making up to 140 serial API calls.
        foreach(array_chunk($sources,10) as $batch){
            $started=microtime(true);$contents=$this->blobBatch($fullName,$batch);$fallbacks=0;
            foreach($batch as $entry){if(strlen($bundle)>=$maxBytes)break 2;$path=(string)$entry['path'];$content=$contents[$path]??null;if($content===null){$fallbacks++;$content=$this->file($fullName,$path,$branch);}if($content!==null)$bundle.="\n// ESPForge source: {$path}\n".substr($content,0,max(0,$maxBytes-strlen($bundle)));}
            if(function_exists('operational_metric'))operational_metric('github.blob_batch',(int)round((microtime(true)-$started)*1000),$fallbacks===0?'success':'fallback',['requested'=>count($batch),'parallel'=>count($contents),'fallbacks'=>$fallbacks]);
        }
        return $bundle;
    }

    private function blobBatch(string $fullName,array $entries): array
    {
        if(!function_exists('curl_multi_init'))return [];$multi=curl_multi_init();$handles=[];$buffers=[];$responses=[];
        foreach($entries as $entry){$sha=(string)($entry['sha']??'');$path=(string)($entry['path']??'');if(!preg_match('/^[a-f0-9]{40}$/i',$sha)||$path==='')continue;$key=count($handles);$buffers[$key]='';$url='https://api.github.com/repos/'.$fullName.'/git/blobs/'.$sha;$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Accept: application/vnd.github+json','Authorization: Bearer '.$this->token,'User-Agent: ESPForge','X-GitHub-Api-Version: 2022-11-28'],CURLOPT_TIMEOUT=>30,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$buffers,$key):int{if(strlen($buffers[$key]) + strlen($chunk)>1024*1024)return 0;$buffers[$key].=$chunk;return strlen($chunk);}]);$handles[$key]=['handle'=>$ch,'path'=>$path];curl_multi_add_handle($multi,$ch);}
        do{$status=curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,1.0);}while($running&&$status===CURLM_OK);
        foreach($handles as $key=>$item){$ch=$item['handle'];if((int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE)===200){$decoded=json_decode($buffers[$key],true);if(is_array($decoded)&&($decoded['encoding']??'')==='base64'){$content=base64_decode(str_replace("\n",'',(string)($decoded['content']??'')),true);if($content!==false)$responses[$item['path']]=$content;}}curl_multi_remove_handle($multi,$ch);curl_close($ch);}curl_multi_close($multi);return $responses;
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

    public function dispatch(string $fullName, string $workflow, string $branch, array $inputs = [], bool $repositoryFallback = true): void
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
        if($repositoryFallback && $lastError?->getCode()===422){
            $event=['event_type'=>'espforge_build'];
            if($inputs) $event['client_payload']=$inputs;
            $this->request('POST',"/repos/{$fullName}/dispatches",$event);
            return;
        }
        throw $lastError??new RuntimeException('GitHub did not register the workflow in time.',502);
    }
}
