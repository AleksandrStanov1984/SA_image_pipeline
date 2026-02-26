<?php

namespace App\Support\ImagePipeline;

final class OptimizeOptions
{
    /**
     * @param string[]|null $onlyProfiles
     * @param string[]|null $onlyExtensions
     */
    public function __construct(
        public bool $dryRun = false,
        public bool $backup = false,
        public bool $force = false,
        public bool $cleanNames = false,
        public bool $hashNames = false,

        // Output variants
        // If true, generate an additional "@2x" file for HiDPI/retina screens.
        public bool $retina = false,

        public bool $purgeWebp = false,
        public bool $deleteSources = false,
        public int $maxSourceMb = 40,

        public ?array $onlyProfiles = null,
        public ?array $onlyExtensions = null,
        public ?string $forcedProfile = null,

        public ?string $reportJsonPath = null,
        public ?string $reportCsvPath = null,
    ) {}

    public function normalize(): self
    {
        if (is_array($this->onlyExtensions)) {
            $this->onlyExtensions = array_values(array_unique(array_map('strtolower', $this->onlyExtensions)));
        }

        if (is_array($this->onlyProfiles)) {
            $this->onlyProfiles = array_values(array_unique(array_map('strtolower', $this->onlyProfiles)));
        }

        if (is_string($this->forcedProfile) && $this->forcedProfile !== '') {
            $this->forcedProfile = strtolower($this->forcedProfile);
        }

        $this->maxSourceMb = max(0, (int)$this->maxSourceMb);

        return $this;
    }

    public function maxSourceBytes(): int
    {
        return $this->maxSourceMb > 0 ? $this->maxSourceMb * 1024 * 1024 : 0;
    }
}
