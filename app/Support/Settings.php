<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class Settings
{
    private static ?array $values = null;

    public static function all(): array
    {
        if (self::$values === null) {
            try {
                self::$values = DB::table('settings')->pluck('value', 'key')->all();
            } catch (Throwable) {
                return [];
            }
        }

        return self::$values;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::all()[$key] ?? $default;
    }

    public static function put(array $values): void
    {
        $now = now();
        foreach ($values as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => $now, 'created_at' => $now]);
        }
        self::$values = null;
        // Same signal as `php artisan queue:restart`, so workers reload the new configuration.
        Cache::forever('illuminate:queue:restart', $now->getTimestamp());
    }

    public static function flush(): void
    {
        self::$values = null;
    }

    public static function timezone(): string
    {
        $timezone = self::get('timezone');

        return $timezone && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    /**
     * Stored timestamps are always UTC; this converts them for display only.
     */
    public static function local(DateTimeInterface|string|null $value, string $format = 'Y-m-d H:i:s'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return CarbonImmutable::parse($value, 'UTC')->setTimezone(self::timezone())->format($format);
    }

    public static function smtpPassword(): ?string
    {
        $encrypted = self::get('smtp_password');
        if (! $encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    public static function applyRuntimeConfig(): void
    {
        config(['app.schedule_timezone' => self::timezone()]);

        if (! self::get('smtp_host')) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => self::get('smtp_scheme', 'smtp'),
            'mail.mailers.smtp.host' => self::get('smtp_host'),
            'mail.mailers.smtp.port' => (int) self::get('smtp_port', '587'),
            'mail.mailers.smtp.username' => self::get('smtp_username'),
            'mail.mailers.smtp.password' => self::smtpPassword(),
            'mail.from.address' => self::get('smtp_from_address'),
            'mail.from.name' => self::get('smtp_from_name'),
        ]);

        if (app()->resolved('mail.manager')) {
            app('mail.manager')->purge('smtp');
        }
    }
}
