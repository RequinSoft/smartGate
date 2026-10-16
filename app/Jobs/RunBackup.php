<?php

namespace App\Jobs;

use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

class RunBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('backup'))->dontRelease()->expireAfter(1860)];
    }

    public function handle(): void
    {
        try {
            if (! config('backup.backup.password')) {
                throw new RuntimeException('Configure BACKUP_ARCHIVE_PASSWORD antes de generar respaldos.');
            }
            if (Artisan::call('backup:run') !== 0) {
                throw new RuntimeException('Spatie Backup no pudo completar el respaldo. Consulte el registro del servidor.');
            }
            Audit::record('backup.completed');
        } catch (Throwable $exception) {
            Audit::record('backup.failed', properties: ['message' => 'Respaldo fallido; revise failed_jobs y el registro del servidor.']);
            throw $exception;
        }
    }
}
