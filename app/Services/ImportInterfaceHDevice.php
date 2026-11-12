<?php

namespace App\Services;

use App\Models\Device;

class ImportInterfaceHDevice
{
    /**
     * Upserts a HikCentral device reported by interfazH. ISAPI URL, credentials,
     * location, direction and read/write flags remain managed locally.
     */
    public function import(string $source, array $payload): Device
    {
        $device = Device::firstOrNew(['source' => $source, 'external_id' => (int) $payload['id']]);
        $device->name = $payload['name'];
        $device->ip_address = $payload['ip_address'] ?? null;
        $device->port = isset($payload['port']) ? (int) $payload['port'] : null;
        $device->serial = $payload['code'] ?? null;
        $device->type = $payload['type'] ?? null;
        $device->online = isset($payload['status']) ? (int) $payload['status'] === 1 : null;

        if (! $device->exists) {
            $device->model = self::modelFromSerial($device->serial) ?? ($device->type ?? 'Sin modelo');
            $device->location = '';
            $device->read_enabled = false;
            $device->write_enabled = false;
        }

        $device->save();

        return $device;
    }

    /**
     * Hikvision serials look like "DS-K1T341CMFW20240702V030315ENFR3067658": model + build date + firmware.
     */
    public static function modelFromSerial(?string $serial): ?string
    {
        return $serial && preg_match('/^(DS-[A-Z0-9-]+?)\d{8}V/i', $serial, $matches) ? $matches[1] : null;
    }
}
