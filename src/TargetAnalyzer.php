<?php
declare(strict_types=1);

final class TargetAnalyzer
{
    public static function discover(GitHubClient $github,string $fullName,string $branch,array $paths,?callable $aiFallback=null): array
    {
        $targets=[];
        if(in_array('platformio.ini',$paths,true)){
            $ini=$github->file($fullName,'platformio.ini',$branch)??'';
            preg_match_all('/^\s*\[env:([^\]]+)\]/mi',$ini,$matches);
            foreach($matches[1]??[] as $environment){
                $environment=trim($environment); if(strtolower($environment)==='native') continue;
                $targets[]=['id'=>$environment,'name'=>self::label($environment),'type'=>'platformio','environment'=>$environment];
            }
            if($targets) return $targets;
        }

        // Several mature firmware projects publish their supported hardware as an
        // inline GitHub Actions matrix. Reuse it as the authoritative model list.
        foreach(['.github/workflows/build_parallel.yml','.github/workflows/build.yml','.github/workflows/firmware.yml'] as $path){
            if(!in_array($path,$paths,true)) continue;
            $yaml=$github->file($fullName,$path,$branch)??'';
            foreach(preg_split('/\R/',$yaml) as $line){
                if(!preg_match('/^\s*-\s*\{.*?name:\s*"([^"]+)".*?flag:\s*"([^"]+)".*?(?:fbqn|fqbn):\s*"([^"]+)"/',$line,$match)) continue;
                $targets[]=['id'=>$match[2],'name'=>$match[1],'type'=>'workflow_matrix','fqbn'=>$match[3],'flag'=>$match[2],'workflow_path'=>$path];
            }
            if($targets) return $targets;
        }

        if($aiFallback){
            try { $aiTargets=$aiFallback(); if(is_array($aiTargets)&&$aiTargets) return $aiTargets; }
            catch(Throwable $e){ error_log('ESPForge AI target fallback: '.$e->getMessage()); }
        }
        $source=$github->sourceBundle($fullName,$branch,array_map(fn($path)=>['path'=>$path,'type'=>'blob','size'=>0],$paths),20);
        $s3=preg_match('/^\s*#\s*define\s+BOARD_ESP32_DIV_V2\b/m',$source)===1||preg_match('/\bESP32[-_ ]?S3\b/i',$source)===1;
        return [[
            'id'=>$s3?'esp32s3':'esp32', 'name'=>$s3?'ESP32-S3':'ESP32', 'type'=>'arduino',
            'fqbn'=>$s3?'esp32:esp32:esp32s3:PSRAM=enabled,PartitionScheme=min_spiffs,FlashMode=dio':'esp32:esp32:esp32'
        ]];
    }

    public static function select(array $targets,string $id): ?array
    {
        foreach($targets as $target) if(hash_equals((string)$target['id'],$id)) return $target;
        return null;
    }

    public static function filterMatrix(string $yaml,string $flag,string $name): string
    {
        $lines=[]; $found=false;
        foreach(preg_split('/\R/',$yaml) as $line){
            if(preg_match('/^\s*-\s*\{.*?flag:\s*"([^"]+)"/',$line,$match)){
                if($match[1]!==$flag) continue;
                $found=true;
            }
            $lines[]=$line;
        }
        if(!$found) throw new RuntimeException('The selected hardware model is no longer present in the repository workflow.',409);
        $result=implode("\n",$lines);
        return preg_replace('/^name:.*$/m','name: ESPForge · '.str_replace(["\r","\n"],' ',$name),$result,1)??$result;
    }

    private static function label(string $id): string
    {
        return ucwords(str_replace(['_','-'], ' ', $id));
    }
}
