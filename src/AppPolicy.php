<?php
declare(strict_types=1);

/** Versioned policy identifiers included in caches, plans, APIs, and manifests. */
final class AppPolicy
{
    public const TARGET_SCHEMA_VERSION = '1.0';
    public const ANALYZER_VERSION = 'targets-v10';
    public const PROMPT_VERSION = 'target-prompt-v2';
    public const LIBRARY_PROMPT_VERSION = 'library-prompt-v2';
    public const WORKFLOW_POLICY_VERSION = 'workflow-v3';
    public const PROFILE_VERSION = 'profiles-v1';
    public const PACKAGE_MAP_VERSION = 'arduino-packages-v1';

    public static function aiModel(array $config,string $provider): string
    {
        $configured=(string)($config['ai']['models'][$provider]??'');
        if($configured!=='')return $configured;
        return $provider==='google'?'gemini-2.5-flash':'google/gemini-2.5-flash';
    }

    public static function versions(): array
    {
        return [
            'target_schema'=>self::TARGET_SCHEMA_VERSION,
            'analyzer'=>self::ANALYZER_VERSION,
            'prompt'=>self::PROMPT_VERSION,
            'library_prompt'=>self::LIBRARY_PROMPT_VERSION,
            'workflow'=>self::WORKFLOW_POLICY_VERSION,
            'profile'=>self::PROFILE_VERSION,
            'package_map'=>self::PACKAGE_MAP_VERSION,
        ];
    }
}
