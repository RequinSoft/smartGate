<?php

namespace App\Jobs;

use App\Services\ImportPeople;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ImportHikCentralPeople implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('hikcentral-import'))->dontRelease()->expireAfter(1860)];
    }

    public function handle(ImportPeople $import): void
    {
        try {
            $import->run();
        } catch (Throwable $exception) {
            Audit::record('hikcentral.import.failed', properties: ['message' => 'Importación incompleta; revise failed_jobs y el registro del servidor.']);
            throw $exception;
        }
    }
}
