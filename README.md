# SA Image Pipeline

Production-ready image processing pipeline for Laravel 12 projects.

This system provides a structured, safe and optimized image workflow with:

- Inbox-based ingestion
- Safe move (copy → verify → delete)
- Manifest-based early exit (no unnecessary rescans)
- Selective directory optimization
- WebP generation
- Retina (@2x) support
- Exact OG 1200x630 processing
- Laravel Scheduler integration
- JSON and CSV reporting

---

## Architecture Overview
### Server cron (every minute)
↓
php artisan schedule:run
↓
Laravel Scheduler
↓
images:cron
↓
Manifest hash check (early exit)
↓
Move inbox → public/assets
↓
images:optimize (--dirs selective)
↓
WebP + Retina generation


---

## Directory Structure

### Source (Inbox)
storage/app/image-inbox/assets/...

### 
Example: storage/app/image-inbox/assets/hero/hero-01.jpg


All new images must be placed inside the inbox.

---

### Public Output
public/assets/...


After processing, images are automatically moved and optimized here.

---

## Main Commands

### images:cron

Main orchestrator.

- Detects changes via manifest
- Moves images safely
- Runs optimize only for changed directories

Example: php artisan images:cron --retina --delete-sources


Options:

- `--retina`
- `--delete-sources`
- `--force`
- `--clean-names`
- `--hash-names`
- `--purge-webp`
- `--max-source-mb=40`

---

### images:optimize

Direct optimizer (manual run).

Example: php artisan images:optimize --dirs=assets/hero --retina --delete-sources


---

## Profiles

Configured in: config/image_pipeline.php


Profiles include:

- OG images (exact 1200x630, overwrite)
- Hero sections
- Galleries
- Footer images
- Fallback images
- Default catch-all

---

## Production Setup

### 1. Scheduler (Laravel 12)

Add in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('images:cron --retina --delete-sources')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();
```

## Server Cron (one line only)

```php
* * * * * php /path/to/project/artisan schedule:run >> /dev/null 2>&1
```
 Replace /path/to/project with your real path.

## Safety Features

* Manifest-based change detection

* No full rescans without changes

* Copy → verify → delete strategy

* Size limit protection

* Hidden file filtering

* Selective directory optimization

* Automatic Retina generation

* Structured reporting (JSON + CSV)

## Recommended Image Rules

### For best results:

* Use JPEG for photos

* Do not use Cyrillic characters in filenames

* No spaces in filenames

* No double extensions (e.g. image.jpg.jpg)

* No special symbols (# % & ? !)

* Hero images: 2800–3000px long edge

* Galleries: 2400px long edge

* OG images: exactly 1200x630 px

* Higher quality source images always produce better final results.