<?php
declare(strict_types=1);

final class AITargetAnalyzer
{
    public function __construct(private string $provider,private string $apiKey) {}

    public function discover(GitHubClient $github,string $fullName,string $branch,array $paths): array
    {
        $priority=array_values(array_filter($paths,fn($p)=>preg_match('~(^|/)(readme[^/]*\.md|platformio[^/]*\.ini|sdkconfig[^/]*|boards?[^/]*\.(?:txt|json|ya?ml)|.*(?:board|target|config).*\.(?:h|hpp|json|ya?ml)|\.github/workflows/.*\.ya?ml)$~i',$p)));
        usort($priority,fn($a,$b)=>self::weight($a)<=>self::weight($b));
        $digest="Repository: {$fullName}\nBranch: {$branch}\nFiles:\n".implode("\n",array_slice($paths,0,600))."\n\nRelevant content:\n";
        foreach(array_slice($priority,0,14) as $path){
            $content=$github->file($fullName,$path,$branch); if($content===null) continue;
            $remaining=60000-strlen($digest); if($remaining<=0) break;
            $digest.="\n--- {$path} ---\n".substr($content,0,min(12000,$remaining));
        }
        $prompt=<<<PROMPT
Analyze this ESP firmware repository and identify every independently selectable hardware/ESP model that can be compiled. Return ONLY a JSON array. Do not use markdown.
Each item must be: {"id":"safe-stable-id","name":"human model name","type":"platformio|arduino|esp-idf","environment":"PlatformIO env or empty","fqbn":"Arduino FQBN or empty","idf_target":"esp32/esp32s2/esp32s3/esp32c3/esp32c5/esp32c6 or empty","build_flags":"optional safe compiler defines"}.
Do not list native tests, features, libraries, partition variants, or duplicate aliases as hardware. For PlatformIO, use exact [env:...] identifiers. For Arduino, infer FQBN and required board-selection -D flags from repository configs/workflows. If only one target is proven, return one item. If none are proven, return []. Never invent a model.

{$digest}
PROMPT;
        $text=$this->provider==='google'?$this->gemini($prompt):$this->openRouter($prompt);
        return $this->validate($text);
    }

    private function gemini(string $prompt): string
    {
        $url='https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';
        $response=$this->post($url,['contents'=>[['parts'=>[['text'=>$prompt]]]],'generationConfig'=>['responseMimeType'=>'application/json','temperature'=>0.1]],['x-goog-api-key: '.$this->apiKey]);
        return (string)($response['candidates'][0]['content']['parts'][0]['text']??'');
    }

    private function openRouter(string $prompt): string
    {
        $response=$this->post('https://openrouter.ai/api/v1/chat/completions',['model'=>'google/gemini-2.5-flash','messages'=>[['role'=>'user','content'=>$prompt]],'temperature'=>0.1],['Authorization: Bearer '.$this->apiKey,'HTTP-Referer: https://espforge.alwaysdata.net','X-Title: ESPForge']);
        return (string)($response['choices'][0]['message']['content']??'');
    }

    private function post(string $url,array $payload,array $extraHeaders=[]): array
    {
        $ch=curl_init($url); $headers=array_merge(['Content-Type: application/json','Accept: application/json'],$extraHeaders);$raw='';$maxBytes=2*1024*1024;
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>60,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$raw,$maxBytes):int{if(strlen($raw)+strlen($chunk)>$maxBytes)return 0;$raw.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $error=curl_error($ch); curl_close($ch);
        $decoded=json_decode($raw?:'[]',true);
        if($ok===false||$status<200||$status>=300){
            $detail=(string)($decoded['error']['message']??($error!==''?$error:'HTTP '.$status));error_log('ESPForge AI provider error: '.substr($detail,0,500));
            throw new RuntimeException('The AI provider could not complete target analysis.',502);
        }
        return is_array($decoded)?$decoded:[];
    }

    private function validate(string $text): array
    {
        $text=trim(preg_replace('/^```(?:json)?|```$/m','',trim($text))??$text); $items=json_decode($text,true);
        if(!is_array($items)) throw new RuntimeException('AI returned an invalid target analysis.',502);
        $result=[]; foreach($items as $item){
            if(!is_array($item)||!in_array($item['type']??'',['platformio','arduino','esp-idf'],true)) continue;
            $id=preg_replace('/[^A-Za-z0-9_.-]/','-',(string)($item['id']??'')); $name=trim((string)($item['name']??'')); if(!$id||!$name) continue;
            $target=['id'=>$id,'name'=>substr($name,0,100),'type'=>$item['type'],'source'=>'ai'];
            if($item['type']==='platformio'&&preg_match('/^[A-Za-z0-9_.-]+$/',(string)($item['environment']??''))) $target['environment']=$item['environment'];
            if($item['type']==='arduino'&&preg_match('/^[A-Za-z0-9_.:-]+(?:,[A-Za-z0-9_.=-]+)*$/',(string)($item['fqbn']??''))) $target['fqbn']=$item['fqbn'];
            if($item['type']==='esp-idf'&&preg_match('/^esp32(?:s2|s3|c3|c5|c6)?$/',(string)($item['idf_target']??''))) $target['idf_target']=$item['idf_target'];
            $flags=trim((string)($item['build_flags']??'')); if($flags!==''&&preg_match('/^(?:-D[A-Za-z_][A-Za-z0-9_]*(?:=[A-Za-z0-9_.-]+)?\s*)+$/',$flags)) $target['build_flags']=$flags;
            if(($item['type']==='platformio'&&!isset($target['environment']))||($item['type']==='arduino'&&!isset($target['fqbn']))||($item['type']==='esp-idf'&&!isset($target['idf_target']))) continue;
            $result[$id]=$target;
        }
        return array_values($result);
    }

    private static function weight(string $path): int { return str_starts_with(strtolower($path),'readme')?0:(str_contains($path,'.github/workflows')?1:2); }
}
