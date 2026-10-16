<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

class Audit
{
    public static function record(string $action, ?Model $subject = null, array $properties = []): void
    {
        $logger = activity('smartgate')
            ->withProperties($properties + [
                'ip' => app()->runningInConsole() ? null : request()->ip(),
                'source' => app()->runningInConsole() ? 'console/queue' : 'web',
            ]);

        if ($subject) {
            $logger->performedOn($subject);
        }

        $logger->log($action);
    }
}
