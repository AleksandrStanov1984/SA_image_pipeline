<?php

namespace App\Console\Commands;

use App\Support\ImagePipeline\ImageService;
use App\Support\ImagePipeline\OptimizeOptions;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'images:optimize')]
class ImagesOptimize extends Command
{
    protected $signature = 'images:optimize
       {path? : Public-relative path to process (default: assets)}
       {--dirs= : Comma-separated public-relative directories to process (overrides {path})}
       {--profile= : Force a single profile name for all matched images}
       {--dry-run : Don\'t write anything, only report}
       {--force : Re-generate even if webp/retina already exist}
       {--clean-names : Normalize source file names to kebab-case (ASCII)}
       {--hash-names : Cache-bust generated outputs (add .<hash> in filename)}
       {--retina : Also generate @2x .webp (HiDPI)}
       {--purge-webp : Delete all existing .webp under root BEFORE processing}
       {--delete-sources : Delete jpg/jpeg/png sources when allowed (NOT with --hash-names)}
       {--max-source-mb=40 : Skip sources larger than N MB (0 = no limit)}
       {--only= : Comma-separated extensions to process (jpg,jpeg,png,webp)}
       {--report-json= : Write JSON report to given path}
       {--report-csv= : Write CSV report to given path}
   ';

    protected $description = 'Optimize images under public/assets using folder-based profiles (webp + retina).';

    public function handle(ImageService $service): int
    {
        ini_set('memory_limit', '-1');
        set_time_limit(0);

        // 1) Determine roots
        $dirsOpt = (string)($this->option('dirs') ?? '');
        $roots = [];

        if (trim($dirsOpt) !== '') {
            $roots = array_values(array_filter(array_map(function ($p) {
                $p = ltrim(str_replace('\\', '/', trim($p)), '/');
                return $p !== '' ? $p : null;
            }, explode(',', $dirsOpt))));
        } else {
            $path = $this->argument('path') ?: 'assets';
            $path = ltrim(str_replace('\\', '/', $path), '/');
            $roots = [$path];
        }

        // 2) Parse --only extensions
        $only = (string)($this->option('only') ?? '');
        $onlyExt = array_values(array_filter(array_map('trim', $only ? explode(',', $only) : [])));

        // 3) Base options
        $baseOptions = new OptimizeOptions(
            dryRun: (bool)$this->option('dry-run'),
            force: (bool)$this->option('force'),
            cleanNames: (bool)$this->option('clean-names'),
            hashNames: (bool)$this->option('hash-names'),
            retina: (bool)$this->option('retina'),
            purgeWebp: (bool)$this->option('purge-webp'),
            deleteSources: (bool)$this->option('delete-sources'),
            maxSourceMb: (int)($this->option('max-source-mb') ?? 40),
            onlyExtensions: $onlyExt ?: null,
            forcedProfile: $this->option('profile') ?: null,
            reportJsonPath: $this->option('report-json') ?: null,
            reportCsvPath: $this->option('report-csv') ?: null,
        );

        $multi = count($roots) > 1;

        $aggregate = (object)[
            'checked' => 0,
            'optimized' => 0,
            'skipped' => 0,
            'errors' => 0,
            'bytesBefore' => 0,
            'bytesAfter' => 0,
        ];

        $jsonBase = $baseOptions->reportJsonPath;
        $csvBase  = $baseOptions->reportCsvPath;

        foreach ($roots as $rootRel) {
            $absRoot = public_path($rootRel);

            if (!is_dir($absRoot)) {
                $this->warn("SKIP {$rootRel}: directory not found");
                continue;
            }

            $opt = $baseOptions;

            // If multiple roots and report paths set → avoid overwriting
            if ($multi && ($jsonBase || $csvBase)) {
                $suffix = $this->safeSuffix($rootRel);

                $opt = new OptimizeOptions(
                    dryRun: $baseOptions->dryRun,
                    force: $baseOptions->force,
                    cleanNames: $baseOptions->cleanNames,
                    hashNames: $baseOptions->hashNames,
                    retina: $baseOptions->retina,
                    purgeWebp: $baseOptions->purgeWebp,
                    deleteSources: $baseOptions->deleteSources,
                    maxSourceMb: $baseOptions->maxSourceMb,
                    onlyExtensions: $baseOptions->onlyExtensions,
                    forcedProfile: $baseOptions->forcedProfile,
                    reportJsonPath: $jsonBase ? $this->withSuffix($jsonBase, $suffix) : null,
                    reportCsvPath:  $csvBase  ? $this->withSuffix($csvBase,  $suffix) : null,
                );
            }

            $report = $service->optimize($absRoot, $opt);

            // Aggregate
            $aggregate->checked     += $report->checked;
            $aggregate->optimized   += $report->optimized;
            $aggregate->skipped     += $report->skipped;
            $aggregate->errors      += $report->errors;
            $aggregate->bytesBefore += $report->bytesBefore;
            $aggregate->bytesAfter  += $report->bytesAfter;

            // Per-root output
            $this->line('');
            $this->info('Images optimize report');
            $this->line('Root: ' . $absRoot);
            $this->line('Checked: ' . $report->checked);
            $this->line('Optimized: ' . $report->optimized);
            $this->line('Skipped: ' . $report->skipped);
            $this->line('Errors: ' . $report->errors);
            $this->line('Before: ' . $this->humanBytes($report->bytesBefore));
            $this->line('After:  ' . $this->humanBytes($report->bytesAfter));
            $this->line('Saved:  ' . $this->humanBytes($report->totalSavedBytes()));

            if ($opt->reportJsonPath) $this->line('JSON report: ' . $opt->reportJsonPath);
            if ($opt->reportCsvPath)  $this->line('CSV report:  ' . $opt->reportCsvPath);
            $this->line('');
        }

        if ($multi) {
            $this->line('');
            $this->info('Images optimize summary (all roots)');
            $this->line('Roots: ' . implode(', ', $roots));
            $this->line('Checked: ' . $aggregate->checked);
            $this->line('Optimized: ' . $aggregate->optimized);
            $this->line('Skipped: ' . $aggregate->skipped);
            $this->line('Errors: ' . $aggregate->errors);
            $this->line('Before: ' . $this->humanBytes($aggregate->bytesBefore));
            $this->line('After:  ' . $this->humanBytes($aggregate->bytesAfter));
            $this->line('Saved:  ' . $this->humanBytes($aggregate->bytesBefore - $aggregate->bytesAfter));
            $this->line('');
        }

        return $aggregate->errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function withSuffix(string $path, string $suffix): string
    {
        $path = str_replace('\\', '/', $path);
        $dot = strrpos($path, '.');
        if ($dot === false) return $path . '-' . $suffix;

        $base = substr($path, 0, $dot);
        $ext  = substr($path, $dot);
        return $base . '-' . $suffix . $ext;
    }

    private function safeSuffix(string $rootRel): string
    {
        $s = trim($rootRel, '/');
        $s = str_replace(['\\', '/'], '-', $s);
        $s = preg_replace('~[^a-zA-Z0-9\-_]+~', '-', $s) ?: 'root';
        return trim($s, '-');
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B','KB','MB','GB'];
        $i = 0;
        $v = (float)$bytes;

        while ($v >= 1024 && $i < count($units)-1) {
            $v /= 1024;
            $i++;
        }

        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . ' ' . $units[$i];
    }
}
