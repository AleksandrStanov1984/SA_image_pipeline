<?php

namespace App\Support\ImagePipeline;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;

final class ImageService
{
    private ImageManager $manager;

    public function __construct(
        private readonly PathMatcher $matcher,
    ) {
        $driver = (extension_loaded('imagick') && class_exists(\Imagick::class))
            ? new ImagickDriver()
            : new GdDriver();

        $this->manager = new ImageManager($driver);
    }

    public function optimize(string $rootAbsPath, OptimizeOptions $opt): OptimizeReport
    {
        $report = new OptimizeReport();
        $opt->normalize();

        $rootAbsPath = rtrim($rootAbsPath, DIRECTORY_SEPARATOR);
        if (!is_dir($rootAbsPath)) {
            $report->errors++;
            $report->add("Root not found: {$rootAbsPath}");
            return $report;
        }

        // Build initial list
        $files = File::allFiles($rootAbsPath);

        // Purge old webp first (important to avoid duplicates)
        if ($opt->purgeWebp && !$opt->dryRun) {
            foreach ($files as $f) {
                if (strtolower($f->getExtension()) === 'webp') {
                    @unlink($f->getPathname());
                }
            }
            // re-scan after purge
            $files = File::allFiles($rootAbsPath);
        }

        foreach ($files as $file) {
            $abs = $file->getPathname();

            // Always match by PUBLIC-relative path (stable), so profiles work even when rootAbsPath is "public/assets/hero"
            $relFromPublic = $this->relFromPublic($abs);
            $relForMatch   = $relFromPublic;

            $report->checked++;

            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) {
                $report->skipped++;
                continue;
            }

            if ($opt->onlyExtensions) {
                if (!in_array($ext, $opt->onlyExtensions, true)) {
                    $report->skipped++;
                    continue;
                }
            }

            $profile = $opt->forcedProfile
                ? $this->matcher->getByName($opt->forcedProfile)
                : $this->matcher->match($relForMatch);

            if ($profile === null || $profile->skip) {
                $report->skipped++;
                continue;
            }

            if ($opt->onlyProfiles) {
                if (!in_array(strtolower($profile->name), $opt->onlyProfiles, true)) {
                    $report->skipped++;
                    continue;
                }
            }

            // Optional: skip huge sources to avoid memory explosions on GD
            if ($opt->maxSourceMb > 0) {
                $bytes = @filesize($abs) ?: 0;
                if ($bytes > ($opt->maxSourceMb * 1024 * 1024)) {
                    $report->skipped++;
                    $report->add("SKIP {$relForMatch}: source > {$opt->maxSourceMb}MB");
                    continue;
                }
            }

            try {
                $this->processFile($abs, $relForMatch, $profile, $opt, $report);
            } catch (\Throwable $e) {
                $report->errors++;
                $report->add("ERROR {$relForMatch}: " . $e->getMessage());
            }
        }

        if ($opt->reportJsonPath) $report->writeJson($opt->reportJsonPath);
        if ($opt->reportCsvPath)  $report->writeCsv($opt->reportCsvPath);

        return $report;
    }

    private function processFile(string $absFile, string $relFile, Profile $profile, OptimizeOptions $opt, OptimizeReport $report): void
    {
        $beforeBytes = @filesize($absFile) ?: 0;

        // Safety: skip huge sources (GD can OOM). 0 = no limit.
        $maxBytes = $opt->maxSourceBytes();
        if ($maxBytes > 0 && $beforeBytes > $maxBytes) {
            $report->skipped++;
            $report->add("SKIP {$relFile}: source bigger than {$opt->maxSourceMb} MB");
            return;
        }

        // Normalize filename (ASCII + kebab)
        if ($opt->cleanNames) {
            $newAbs = $this->sanitizeFilename($absFile);

            if ($newAbs !== $absFile) {
                if (!$opt->dryRun) {
                    File::move($absFile, $newAbs);
                }

                $absFile = $newAbs;
                $relFile = $this->relFromPublic($newAbs);
            }
        }

        // Exact resize/crop (OG etc) - overwrites same file
        if ($profile->exact) {
            $outputs = $this->optimizeExact($absFile, $profile, $opt);

            $afterBytes = 0;
            foreach ($outputs as $o) $afterBytes += @filesize($o['abs']) ?: 0;

            $report->optimized++;
            $report->bytesBefore += $beforeBytes;
            $report->bytesAfter  += $afterBytes;

            $report->addEntry([
                'profile' => $profile->name,
                'src' => $relFile,
                'outputs' => array_map(fn($o) => $o['rel'], $outputs),
                'before_bytes' => $beforeBytes,
                'after_bytes' => $afterBytes,
            ]);
            return;
        }

        $ext = strtolower(pathinfo($absFile, PATHINFO_EXTENSION));
        if ($ext === 'webp' && !$opt->force) {
            $report->skipped++;
            return;
        }

        $outputs = $this->optimizeToWebp($absFile, $profile, $opt);

        $afterBytes = 0;
        foreach ($outputs as $o) $afterBytes += @filesize($o['abs']) ?: 0;

        // Optional: optimize jpeg sources (if we keep them)
        if (in_array($ext, ['jpg','jpeg'], true)) {
            $this->jpegOptim($absFile, $profile, $opt);
            if ($profile->keepSource) {
                $beforeBytes = max($beforeBytes, @filesize($absFile) ?: 0);
            }
        }

        // Delete source ONLY when explicitly asked, when NOT using hash names, and profile allows it.
        // IMPORTANT: With your current config keep_source=true everywhere, deletion will never happen by design.
        $canDeleteSource =
            $opt->deleteSources
            && !$opt->dryRun
            && !$opt->hashNames
            && !$profile->keepSource
            && in_array($ext, ['jpg','jpeg','png'], true)
            && !empty($outputs);

        // Extra safety: ensure output files exist and are non-empty before deleting the source
        if ($canDeleteSource) {
            $ok = true;
            foreach ($outputs as $o) {
                $p = $o['abs'] ?? null;
                if (!$p || !is_file($p) || (@filesize($p) ?: 0) <= 0) {
                    $ok = false;
                    break;
                }
            }
            if ($ok && is_file($absFile)) {
                @unlink($absFile);
            }
        }

        $report->optimized++;
        $report->bytesBefore += $beforeBytes;
        $report->bytesAfter  += $afterBytes;

        $report->addEntry([
            'profile' => $profile->name,
            'src' => $relFile,
            'outputs' => array_map(fn($o) => $o['rel'], $outputs),
            'before_bytes' => $beforeBytes,
            'after_bytes' => $afterBytes,
        ]);
    }

    /** @return array<int,array{abs:string,rel:string}> */
    private function optimizeExact(string $absFile, Profile $profile, OptimizeOptions $opt): array
    {
        $img = $this->readImage($absFile);

        $w = (int)($profile->exact['w'] ?? 1200);
        $h = (int)($profile->exact['h'] ?? 630);
        $mode = $profile->exactMode(); // cover|contain

        if ($mode === 'contain') {
            if (method_exists($img, 'scaleDown')) {
                $img->scaleDown($w, $h);
                $canvas = $this->createCanvas($w, $h);
                $x = (int)floor(($w - $img->width()) / 2);
                $y = (int)floor(($h - $img->height()) / 2);
                $canvas->place($img, 'top-left', $x, $y);
                $img = $canvas;
            } else {
                $img->resize($w, $h, function ($c) { $c->aspectRatio(); $c->upsize(); });
                $img->resizeCanvas($w, $h, 'center', false, '#ffffff');
            }
        } else {
            if (method_exists($img, 'cover')) {
                $img->cover($w, $h);
            } else {
                $img->fit($w, $h);
            }
        }

        if ($opt->dryRun) {
            return [[ 'abs' => $absFile, 'rel' => $this->relFromPublic($absFile) ]];
        }

        $this->saveByFormat(
            $img,
            $absFile,
            $profile->format ?? strtolower(pathinfo($absFile, PATHINFO_EXTENSION)),
            $profile->quality
        );

        unset($img);
        if (function_exists('gc_collect_cycles')) gc_collect_cycles();

        $ext = strtolower(pathinfo($absFile, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg'], true)) {
            $this->jpegOptim($absFile, $profile, $opt);
        }

        return [[ 'abs' => $absFile, 'rel' => $this->relFromPublic($absFile) ]];
    }

    /** @return array<int,array{abs:string,rel:string}> */
    private function optimizeToWebp(string $absFile, Profile $profile, OptimizeOptions $opt): array
    {
        $sizes = $profile->sizes ?? [1600, 2400];

        $size1 = (int)($sizes[0] ?? 1600);
        $size2 = (int)($sizes[1] ?? ($size1 * 2));

        $out1 = $this->buildOutputPath($absFile, $profile, $opt, false);
        $out2 = $this->buildOutputPath($absFile, $profile, $opt, true);

        if ($opt->dryRun) {
            $out = [
                ['abs' => $out1, 'rel' => $this->relFromPublic($out1)],
            ];
            if ($opt->retina) {
                $out[] = ['abs' => $out2, 'rel' => $this->relFromPublic($out2)];
            }
            return $out;
        }

        // 1x
        $img = $this->readImage($absFile);
        $this->resizeLongEdge($img, $size1);
        $this->saveWebp($img, $out1, $profile->quality);
        unset($img);
        if (function_exists('gc_collect_cycles')) gc_collect_cycles();

        // 2x (optional)
        if ($opt->retina) {
            $img = $this->readImage($absFile);
            $this->resizeLongEdge($img, $size2);
            $this->saveWebp($img, $out2, $profile->quality);
            unset($img);
            if (function_exists('gc_collect_cycles')) gc_collect_cycles();
        }

        if ($profile->maxKb) {
            $this->ensureMaxKb($out1, $profile->maxKb, $profile->quality);
            if ($opt->retina) {
                $this->ensureMaxKb($out2, $profile->maxKb, $profile->quality);
            }
        }

        $this->cwebpReencode($out1, $profile->quality);
        if ($opt->retina) {
            $this->cwebpReencode($out2, $profile->quality);
        }

        $out = [
            ['abs' => $out1, 'rel' => $this->relFromPublic($out1)],
        ];
        if ($opt->retina) {
            $out[] = ['abs' => $out2, 'rel' => $this->relFromPublic($out2)];
        }
        return $out;
    }

    private function buildOutputPath(string $absFile, Profile $profile, OptimizeOptions $opt, bool $retina): string
    {
        $dir = dirname($absFile);
        $name = pathinfo($absFile, PATHINFO_FILENAME);

        $useHash = $opt->hashNames || $profile->hashNames;
        $hash = $useHash ? substr(md5_file($absFile) ?: md5($absFile), 0, 8) : null;

        $suffix = $retina ? '@2x' : '';
        $file = $useHash
            ? "{$name}.{$hash}{$suffix}.webp"
            : "{$name}{$suffix}.webp";

        return $dir . DIRECTORY_SEPARATOR . $file;
    }

    private function resizeLongEdge($img, int $longEdge): void
    {
        $w = $img->width();
        $h = $img->height();

        if (method_exists($img, 'scaleDown')) {
            if ($w >= $h) $img->scaleDown($longEdge, null);
            else         $img->scaleDown(null, $longEdge);
            return;
        }

        if ($w >= $h) {
            $img->resize($longEdge, null, function ($c) { $c->aspectRatio(); $c->upsize(); });
        } else {
            $img->resize(null, $longEdge, function ($c) { $c->aspectRatio(); $c->upsize(); });
        }
    }

    private function saveWebp($img, string $absOut, int $quality): void
    {
        File::ensureDirectoryExists(dirname($absOut));

        if (method_exists($img, 'toWebp')) {
            $img->toWebp($quality)->save($absOut);
            return;
        }

        $img->encode('webp', $quality)->save($absOut);
    }

    private function saveByFormat($img, string $absOut, string $format, int $quality): void
    {
        File::ensureDirectoryExists(dirname($absOut));

        $format = strtolower($format);

        if (method_exists($img, 'toWebp')) {
            if ($format === 'webp') { $img->toWebp($quality)->save($absOut); return; }
            if ($format === 'png')  { $img->toPng()->save($absOut); return; }
            $img->toJpeg($quality)->save($absOut);
            return;
        }

        if ($format === 'webp') { $img->encode('webp', $quality)->save($absOut); return; }
        if ($format === 'png')  { $img->encode('png')->save($absOut); return; }
        $img->encode('jpg', $quality)->save($absOut);
    }

    private function ensureMaxKb(string $absOut, int $maxKb, int $startQ): void
    {
        $maxBytes = $maxKb * 1024;
        $size = @filesize($absOut) ?: 0;
        if ($size <= 0 || $size <= $maxBytes) return;

        $q = $startQ;
        while ($q > 40 && $size > $maxBytes) {
            $q -= 5;
            if (!$this->cwebpReencode($absOut, $q)) {
                $img = $this->readImage($absOut);
                if (method_exists($img, 'toWebp')) $img->toWebp($q)->save($absOut);
                else $img->encode('webp', $q)->save($absOut);
                unset($img);
                if (function_exists('gc_collect_cycles')) gc_collect_cycles();
            }
            $size = @filesize($absOut) ?: 0;
        }
    }

    private function jpegOptim(string $absFile, Profile $profile, OptimizeOptions $opt): bool
    {
        if ($opt->dryRun) return false;

        $ext = strtolower(pathinfo($absFile, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg'], true)) return false;

        $bin = $this->which('jpegoptim');
        if (!$bin) return false;

        $q = max(50, min(95, $profile->quality));
        $cmd = sprintf('"%s" --strip-all --max=%d %s', $bin, $q, escapeshellarg($absFile));
        @exec($cmd);

        return true;
    }

    private function cwebpReencode(string $absWebp, int $quality): bool
    {
        $bin = $this->which('cwebp');
        if (!$bin) return false;

        $tmp = $absWebp . '.tmp.webp';
        $cmd = sprintf(
            '"%s" %s -q %d -m 6 -pass 10 -quiet -o %s',
            $bin,
            escapeshellarg($absWebp),
            max(40, min(95, $quality)),
            escapeshellarg($tmp)
        );

        @exec($cmd, $out, $code);
        if ($code !== 0 || !is_file($tmp)) {
            @unlink($tmp);
            return false;
        }

        @unlink($absWebp);
        @rename($tmp, $absWebp);
        return true;
    }

    private function sanitizeFilename(string $absFile): string
    {
        $dir  = dirname($absFile);
        $base = basename($absFile);

        $clean = Str::of($base)
            ->ascii()
            ->lower()
            ->replace(' ', '-')
            ->replaceMatches('/-+/', '-')
            ->replaceMatches('/[^a-z0-9._-]+/i', '')
            ->trim('-')
            ->toString();

        return $dir . DIRECTORY_SEPARATOR . $clean;
    }

    private function relFromPublic(string $absPath): string
    {
        $pub = str_replace('\\','/', public_path());
        $abs = str_replace('\\','/', $absPath);
        return ltrim(str_replace($pub . '/', '', $abs), '/');
    }

    private function which(string $bin): ?string
    {
        $cmd = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
            ? 'where ' . escapeshellarg($bin)
            : 'command -v ' . escapeshellarg($bin);

        @exec($cmd, $out, $code);
        if ($code !== 0 || empty($out[0])) return null;

        return trim($out[0]);
    }

    private function readImage(string $absFile)
    {
        if (method_exists($this->manager, 'read')) return $this->manager->read($absFile);
        return $this->manager->make($absFile);
    }

    private function createCanvas(int $w, int $h)
    {
        if (method_exists($this->manager, 'create')) return $this->manager->create($w, $h);
        return $this->manager->canvas($w, $h);
    }
}
