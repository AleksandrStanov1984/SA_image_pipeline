<?php

namespace App\Support\ImagePipeline;

final class OptimizeReport
{
    public int $checked = 0;
    public int $optimized = 0;
    public int $skipped = 0;
    public int $errors = 0;

    public int $bytesBefore = 0;
    public int $bytesAfter = 0;

    /** @var array<int,array<string,mixed>> */
    public array $entries = [];

    /** @var string[] */
    public array $messages = [];

    public function add(string $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @param array<string,mixed> $entry
     */
    public function addEntry(array $entry): void
    {
        $this->entries[] = $entry;
    }

    public function totalSavedBytes(): int
    {
        return max(0, $this->bytesBefore - $this->bytesAfter);
    }

    public function toArray(): array
    {
        return [
            'checked' => $this->checked,
            'optimized' => $this->optimized,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
            'bytes_before' => $this->bytesBefore,
            'bytes_after' => $this->bytesAfter,
            'bytes_saved' => $this->totalSavedBytes(),
            'entries' => $this->entries,
            'messages' => $this->messages,
        ];
    }

    public function writeJson(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents(
            $path,
            json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    public function writeCsv(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $fh = fopen($path, 'wb');
        if ($fh === false) {
            return;
        }

        // Header
        fputcsv($fh, [
            'profile',
            'src',
            'outputs',
            'before_bytes',
            'after_bytes',
            'saved_bytes',
            'saved_pct',
        ]);

        foreach ($this->entries as $e) {
            $before = (int)($e['before_bytes'] ?? 0);
            $after  = (int)($e['after_bytes'] ?? 0);
            $saved  = max(0, $before - $after);
            $pct    = $before > 0 ? round(($saved / $before) * 100, 2) : 0;

            fputcsv($fh, [
                (string)($e['profile'] ?? ''),
                (string)($e['src'] ?? ''),
                is_array($e['outputs'] ?? null) ? implode('|', $e['outputs']) : (string)($e['outputs'] ?? ''),
                $before,
                $after,
                $saved,
                $pct,
            ]);
        }

        fclose($fh);
    }
}
