<?php

namespace App\Support\ImagePipeline;

final class PathMatcher
{
    /** @var array<int,array{match:string,profile:Profile}> */
    private array $rules = [];

    /** @var array<string,Profile> */
    private array $byName = [];

    /**
     * Supports two config shapes:
     * 1) New: [ ['match'=>'assets/**_hero/*', 'name'=>'hero', ...], ... ]
     * 2) Legacy: ['profiles' => ['hero' => ['match'=>..., ...], ... ]]
     *
     * @param array<int|mixed,mixed>|null $config
     */
    public function __construct(?array $config = null)
    {
        $config = $config ?? (array) config('image_pipeline.profiles', []);

        // Legacy shape
        if (isset($config['profiles']) && is_array($config['profiles'])) {
            $rows = [];
            foreach ($config['profiles'] as $name => $row) {
                if (!is_array($row)) continue;
                $row['name'] = $row['name'] ?? (string)$name;
                $rows[] = $row;
            }
            $config = $rows;
        }

        foreach ($config as $row) {
            if (!is_array($row)) continue;

            $profile = new Profile(
                name: (string)($row['name'] ?? 'default'),
                skip: (bool)($row['skip'] ?? false),
                format: $row['format'] ?? null,
                sizes: $row['sizes'] ?? null,
                exact: $row['exact'] ?? null,
                quality: (int)($row['quality'] ?? 80),
                maxKb: isset($row['max_kb']) ? (int)$row['max_kb'] : null,
                hashNames: (bool)($row['hash_names'] ?? false),
                keepSource: (bool)($row['keep_source'] ?? true),
            );

            $match = (string)($row['match'] ?? '');
            $this->rules[] = ['match' => $match, 'profile' => $profile];
            $this->byName[$profile->name] = $profile;
        }
    }

    public function match(string $relPath): ?Profile
    {
        $relPath = ltrim(str_replace('\\', '/', $relPath), '/');

        foreach ($this->rules as $rule) {
            $pattern = $rule['match'];
            if ($pattern === '') continue;

            if (fnmatch($pattern, $relPath)) {
                return $rule['profile'];
            }
        }

        return null;
    }

    public function getByName(string $name): ?Profile
    {
        return $this->byName[$name] ?? null;
    }
}
