<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'images:cron')]
class ImagesCron extends Command
{
    protected $signature = 'images:cron
        {--dry-run : Don\'t move or optimize anything, only log what would happen}
        {--force : Pass --force to images:optimize}
        {--retina : Pass --retina to images:optimize (generate @2x)}
        {--clean-names : Pass --clean-names to images:optimize}
        {--hash-names : Pass --hash-names to images:optimize}
        {--purge-webp : Pass --purge-webp to images:optimize}
        {--delete-sources : Pass --delete-sources to images:optimize}
        {--max-source-mb= : Override max source MB for optimizer}
    ';

    protected $description = 'Orchestrate inbox -> public/assets move + run images:optimize only for changed dirs (manifest early-exit).';

    public function handle(): int
    {
        ini_set('memory_limit', '-1');
        set_time_limit(0);

        $cfg = (array) config('image_pipeline', []);

        $inboxDir = (string)($cfg['inbox_dir'] ?? 'image-inbox');
        $inboxAbs = storage_path('app/' . trim($inboxDir, '/'));

        $expectedPrefix = trim((string)($cfg['inbox_expected_prefix'] ?? 'assets'), '/');
        $manifestRel = (string)($cfg['manifest_path'] ?? ($inboxDir . '/.manifest.json'));
        $manifestAbs = storage_path('app/' . ltrim($manifestRel, '/'));

        $reportsDir = (string)($cfg['reports_dir'] ?? 'reports');
        $reportJson = storage_path('app/' . trim($reportsDir, '/') . '/images.json');
        $reportCsv  = storage_path('app/' . trim($reportsDir, '/') . '/images.csv');

        $cronCfg = (array)($cfg['cron'] ?? []);
        $moveStrategy = (string)($cronCfg['move_strategy'] ?? 'copy_then_delete');
        $allowExt = array_map('strtolower', (array)($cronCfg['allow_ext'] ?? ['jpg','jpeg','png','webp']));
        $ignoreHidden = (bool)($cronCfg['ignore_hidden'] ?? true);
        $fingerprintMode = (string)($cronCfg['fingerprint'] ?? 'size_mtime');
        $maxInboxMb = (int)($cronCfg['max_source_mb'] ?? 40);

        $defaultOpt = (array)($cfg['default_optimize_args'] ?? []);
        $defaultRetina = (bool)($defaultOpt['retina'] ?? true);
        $defaultClean  = (bool)($defaultOpt['clean_names'] ?? false);
        $defaultHash   = (bool)($defaultOpt['hash_names'] ?? false);
        $defaultPurge  = (bool)($defaultOpt['purge_webp'] ?? false);

        $dryRun = (bool)$this->option('dry-run');

        if (!is_dir($inboxAbs)) {
            $this->warn("Inbox dir not found: {$inboxAbs}");
            return self::SUCCESS;
        }

        // 1) Early-exit by fingerprint
        $currentHash = $this->fingerprintInbox(
            $inboxAbs,
            $allowExt,
            $ignoreHidden,
            $fingerprintMode,
            $maxInboxMb
        );

        $prevHash = null;
        if (is_file($manifestAbs)) {
            $prev = json_decode((string)@file_get_contents($manifestAbs), true);
            $prevHash = is_array($prev) ? ($prev['hash'] ?? null) : null;
        }

        if ($prevHash && $prevHash === $currentHash) {
            $this->info('No changes detected in inbox. Early exit.');
            return self::SUCCESS;
        }

        $assetsInboxAbs = rtrim($inboxAbs, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $expectedPrefix;

        if (!is_dir($assetsInboxAbs)) {
            $this->warn("Inbox has no '{$expectedPrefix}/' folder. Updating manifest and exiting.");
            if (!$dryRun) $this->writeManifest($manifestAbs, $currentHash);
            return self::SUCCESS;
        }

        $movedCount = 0;
        $changedDirs = [];

        $files = File::allFiles($assetsInboxAbs);

        foreach ($files as $file) {
            $abs = $file->getPathname();
            $ext = strtolower($file->getExtension());

            if (!in_array($ext, $allowExt, true)) continue;
            if ($ignoreHidden && str_starts_with($file->getBasename(), '.')) continue;

            if ($maxInboxMb > 0) {
                $bytes = @filesize($abs) ?: 0;
                if ($bytes > ($maxInboxMb * 1024 * 1024)) continue;
            }

            $relUnderAssets = $this->relFrom($assetsInboxAbs, $abs);
            $relUnderAssets = ltrim(str_replace('\\','/', $relUnderAssets), '/');

            $dstAbs = public_path($expectedPrefix . '/' . $relUnderAssets);

            // 🔥 FIX: track directory up to 3 levels deep
            $dir = $this->dirDepth($relUnderAssets, 3);
            $changedDirs[$expectedPrefix . '/' . $dir] = true;

            if ($dryRun) {
                $this->line("[dry-run] MOVE {$expectedPrefix}/{$relUnderAssets}");
                $movedCount++;
                continue;
            }

            File::ensureDirectoryExists(dirname($dstAbs));
            File::copy($abs, $dstAbs);
            @unlink($abs);
            $movedCount++;
        }

        if ($movedCount === 0) {
            if (!$dryRun) $this->writeManifest($manifestAbs, $currentHash);
            return self::SUCCESS;
        }

        $dirs = array_keys($changedDirs);
        sort($dirs);

        $this->info("Moved {$movedCount} file(s). Changed dirs: " . implode(', ', $dirs));

        $args = [
            '--dirs' => implode(',', $dirs),
            '--report-json' => $reportJson,
            '--report-csv'  => $reportCsv,
        ];

        if ($this->option('retina') || $defaultRetina) $args['--retina'] = true;
        if ($this->option('clean-names') || $defaultClean) $args['--clean-names'] = true;
        if ($this->option('hash-names') || $defaultHash) $args['--hash-names'] = true;
        if ($this->option('purge-webp') || $defaultPurge) $args['--purge-webp'] = true;
        if ($this->option('force')) $args['--force'] = true;
        if ($this->option('delete-sources')) $args['--delete-sources'] = true;
        if ($this->option('max-source-mb') !== null)
            $args['--max-source-mb'] = (int)$this->option('max-source-mb');

        if ($dryRun) {
            $this->line('[dry-run] Would call: images:optimize');
            return self::SUCCESS;
        }

        $exit = $this->call('images:optimize', $args);

        if ($exit === self::SUCCESS) {
            $this->writeManifest($manifestAbs, $currentHash);
        } else {
            $this->error('images:optimize failed. Manifest NOT updated.');
        }

        return $exit;
    }

    private function dirDepth(string $rel, int $depth): string
    {
        $rel = trim(str_replace('\\','/', $rel), '/');
        $parts = explode('/', $rel);

        if (count($parts) <= 1) return $parts[0];

        array_pop($parts);
        $parts = array_slice($parts, 0, max(1, $depth));

        return implode('/', $parts);
    }

    private function fingerprintInbox(string $inboxAbs, array $allowExt, bool $ignoreHidden, string $mode, int $maxMb): string
    {
        $parts = [];
        $files = File::allFiles($inboxAbs);

        foreach ($files as $file) {
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, $allowExt, true)) continue;
            if ($ignoreHidden && str_starts_with($file->getBasename(), '.')) continue;

            $abs = $file->getPathname();
            $rel = $this->relFrom($inboxAbs, $abs);

            $size = @filesize($abs) ?: 0;
            $mtime = @filemtime($abs) ?: 0;

            $parts[] = $rel . '|' . $size . '|' . $mtime;
        }

        sort($parts);
        return hash('sha256', implode("\n", $parts));
    }

    private function writeManifest(string $manifestAbs, string $hash): void
    {
        File::ensureDirectoryExists(dirname($manifestAbs));
        file_put_contents($manifestAbs, json_encode([
            'hash' => $hash,
            'updated_at' => now()->toDateTimeString(),
        ], JSON_PRETTY_PRINT));
    }

    private function relFrom(string $baseAbs, string $abs): string
    {
        $baseAbs = rtrim(str_replace('\\','/', $baseAbs), '/');
        $abs = str_replace('\\','/', $abs);
        return ltrim(str_replace($baseAbs . '/', '', $abs), '/');
    }
}
