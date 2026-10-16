<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class HikCentralClient
{
    public function post(string $path, array $body): array
    {
        $config = config('hikvision.hikcentral');
        if (! $config['url'] || ! $config['key'] || ! $config['secret']) {
            throw new RuntimeException('Configure HIKCENTRAL_URL, HIKCENTRAL_KEY y HIKCENTRAL_SECRET.');
        }
        if (! str_starts_with($config['url'], 'https://')) {
            throw new RuntimeException('HikCentral requiere HTTPS y un certificado de confianza.');
        }

        $canonical = "POST\n*/*\napplication/json\nx-ca-key:{$config['key']}\n/artemis{$path}";
        $signature = base64_encode(hash_hmac('sha256', $canonical, $config['secret'], true));
        $response = Http::connectTimeout(5)->timeout(30)
            ->withOptions(['verify' => $config['ca_bundle'] ?: true, 'allow_redirects' => false])
            ->withHeaders([
                'Accept' => '*/*',
                'Content-Type' => 'application/json',
                'x-ca-key' => $config['key'],
                'x-ca-signature' => $signature,
                'x-ca-signature-headers' => 'x-ca-key',
            ])->post(rtrim($config['url'], '/').'/artemis'.$path, $body)->throw()->json();

        if (! is_array($response) || (string) ($response['code'] ?? '') !== '0' || ! is_array($response['data'] ?? null)) {
            throw new RuntimeException('HikCentral devolvió una respuesta inválida o un código de error.');
        }

        return $response['data'];
    }
}
