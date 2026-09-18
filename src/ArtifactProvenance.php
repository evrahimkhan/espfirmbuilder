<?php
declare(strict_types=1);

final class ArtifactProvenance
{
    private static function canonicalize(mixed $value): mixed
    {
        if(!is_array($value))return $value;
        if(array_is_list($value))return array_map(self::canonicalize(...),$value);
        ksort($value,SORT_STRING);foreach($value as &$item)$item=self::canonicalize($item);unset($item);return $value;
    }
    public static function payload(array $manifest): string
    {
        unset($manifest['provenance']);return json_encode(self::canonicalize($manifest),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    }
    private static function keypair(string $secret): string
    {
        if(strlen($secret)<32)throw new RuntimeException('Artifact signing key is not securely configured.');
        $seed=sodium_crypto_generichash("ESPForge artifact signing v1\0".$secret,'',SODIUM_CRYPTO_SIGN_SEEDBYTES);
        return sodium_crypto_sign_seed_keypair($seed);
    }
    public static function sign(array $manifest,string $secret): array
    {
        $pair=self::keypair($secret);$public=sodium_crypto_sign_publickey($pair);$signature=sodium_crypto_sign_detached(self::payload($manifest),sodium_crypto_sign_secretkey($pair));
        $manifest['provenance']=['algorithm'=>'Ed25519','key_id'=>substr(hash('sha256',$public),0,24),'public_key'=>base64_encode($public),'signature'=>base64_encode($signature)];return $manifest;
    }
    public static function verify(array $manifest,string $secret): bool
    {
        $record=$manifest['provenance']??null;if(!is_array($record)||($record['algorithm']??'')!=='Ed25519')return false;
        $pair=self::keypair($secret);$public=sodium_crypto_sign_publickey($pair);$supplied=base64_decode((string)($record['public_key']??''),true);$signature=base64_decode((string)($record['signature']??''),true);
        return is_string($supplied)&&is_string($signature)&&hash_equals($public,$supplied)&&hash_equals(substr(hash('sha256',$public),0,24),(string)($record['key_id']??''))&&sodium_crypto_sign_verify_detached($signature,self::payload($manifest),$public);
    }
}
