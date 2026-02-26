<?php

namespace App\Support\ImagePipeline;

/**
 * Folder-based profile resolver.
 *
 * Source of truth:
 *  - config('image_pipeline.profiles')
 *
 * Note: the Profile object itself does not contain matchers;
 * matchers live in config. This resolver mirrors ImageService::detectProfile().
 */
final class ProfileResolver
{
    /** @var array<string, Profile> */
    private array $profiles = [];

    /** @var array<string, mixed> */
    private array $raw;

    /**
     * @param array<string, Profile> $profiles
     * @param array<string, mixed>|null $rawConfig
     */
    public function __construct(array $profiles, ?array $rawConfig = null)
    {
        $this->profiles = $profiles;
        $this->raw = $rawConfig ?? (array) config('image_pipeline.profiles', []);
    }

    public function resolve(string $publicRelPath): ?Profile
    {
        $publicRelPath = ltrim(str_replace('\\', '/', $publicRelPath), '/');

        foreach ($this->raw as $name => $cfg) {
            foreach ((array)($cfg['match'] ?? []) as $pattern) {
                if (PathMatcher::matches($pattern, $publicRelPath)) {
                    return $this->profiles[(string) $name] ?? null;
                }
            }
        }

        return null;
    }
}
