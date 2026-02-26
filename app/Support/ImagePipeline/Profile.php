<?php

namespace App\Support\ImagePipeline;

final class Profile
{
    /**
     * @param array<int,int>|null $sizes   [longEdge1x, longEdge2x]
     * @param array{w:int,h:int,mode?:string}|null $exact  Exact output size (e.g. OG 1200x630). mode: cover|contain (default cover)
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $skip = false,
        public readonly ?string $format = null,   // webp|jpg|png|null
        public readonly ?array $sizes = null,
        public readonly ?array $exact = null,
        public readonly int $quality = 80,
        public readonly ?int $maxKb = null,

        // New
        public readonly bool $hashNames = false,   // cache-busting for generated outputs
        public readonly bool $keepSource = false,   // keep original file (recommended when hashNames=true)
    ) {}

    public function exactMode(): string
    {
        return $this->exact['mode'] ?? 'cover';
    }
}
