<?php
declare(strict_types=1);

final class OfficialMetadata
{
    private array $data; private string $digest;
    private function __construct(array $data,string $digest){$this->data=$data;$this->digest=$digest;}
    public static function pinned(?string $path=null): self
    {
        $path??=dirname(__DIR__).'/config/official-metadata.json';$raw=file_get_contents($path);
        if(!is_string($raw))throw new RuntimeException('Pinned official metadata is unavailable.');
        $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($data)||($data['schema']??null)!==1||empty($data['captured_at'])||count($data['sources']??[])<4)throw new RuntimeException('Pinned official metadata is invalid.');
        return new self($data,hash('sha256',$raw));
    }
    public function digest(): string{return $this->digest;}
    public function validateTarget(array $target): void
    {
        $type=(string)($target['type']??'');
        if(in_array($type,['arduino','arduino_define'],true)){
            $fqbn=(string)($target['fqbn']??'');if(!preg_match('/^esp32:esp32:([A-Za-z0-9_.-]+)/',$fqbn,$m)||!in_array(strtolower($m[1]),$this->data['arduino_esp32']['boards'],true))throw new RuntimeException('Arduino board is absent from pinned official metadata.');
            $version=(string)($target['core_version']??'3.2.1');if(!in_array($version,$this->data['arduino_esp32']['versions'],true))throw new RuntimeException('Arduino ESP32 version is absent from pinned official metadata.');
        }elseif($type==='esp-idf'){
            if(!in_array(strtolower((string)($target['idf_target']??'')),$this->data['esp_idf']['targets'],true))throw new RuntimeException('ESP-IDF target is absent from pinned official metadata.');
        }elseif(in_array($type,['platformio','platformio_disabled'],true)){
            if(!in_array('6.1.19',$this->data['platformio']['versions'],true))throw new RuntimeException('PlatformIO version is absent from pinned official metadata.');
        }elseif($type!=='workflow_matrix')throw new RuntimeException('Target framework is not covered by pinned official metadata.');
    }
    public function reviewedLibraries(array $libraries): array
    {
        return array_values(array_unique(array_filter($libraries,fn($library)=>is_string($library)&&in_array($library,$this->data['libraries'],true))));
    }
    public function validateLibraries(array $libraries): void
    {
        if(count($this->reviewedLibraries($libraries))!==count($libraries))throw new RuntimeException('One or more libraries are absent from pinned official metadata.');
    }
}
