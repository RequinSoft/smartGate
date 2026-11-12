<?php

namespace App\Http\Controllers;

use App\Services\ImportInterfaceHDevice;
use App\Services\ImportInterfaceHGroup;
use App\Services\ImportInterfaceHPerson;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InterfaceHController extends Controller
{
    public function connection(Request $request): JsonResponse
    {
        $request->validate([
            'source' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        return response()->json(['success' => true]);
    }

    public function receive(Request $request, ImportInterfaceHPerson $peopleImporter, ImportInterfaceHGroup $groupImporter, ImportInterfaceHDevice $deviceImporter, string $resource): JsonResponse
    {
        $rules = [
            'source' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'data' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'data.*' => ['required', 'array'],
            'data.*.id' => ['required', 'integer', 'min:1'],
        ];
        if ($resource === 'personnel') {
            $rules['data.*.personCode'] = ['required', 'string', 'max:32'];
            $rules['data.*.complete_name'] = ['required', 'string', 'max:255'];
            $rules['data.*.group_id'] = ['nullable', 'integer', 'min:1'];
        }
        if ($resource === 'devices') {
            $rules['data.*.name'] = ['required', 'string', 'max:255'];
            $rules['data.*.ip_address'] = ['nullable', 'string', 'max:45'];
            $rules['data.*.port'] = ['nullable', 'integer', 'between:1,65535'];
            $rules['data.*.code'] = ['nullable', 'string', 'max:128'];
            $rules['data.*.type'] = ['nullable', 'string', 'max:64'];
            $rules['data.*.status'] = ['nullable', 'integer'];
        }
        if ($resource === 'groups') {
            $rules['data.*.name'] = ['required', 'string', 'max:255'];
            $rules['data.*.parent_id'] = ['nullable', 'integer', 'min:1'];
        }
        $data = $request->validate($rules);

        $now = now();
        $records = [];
        foreach ($data['data'] as $row) {
            $records[(string) $row['id']] = [
                'source' => $data['source'],
                'resource' => $resource,
                'external_id' => (string) $row['id'],
                'payload' => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($records, $data, $resource, $peopleImporter, $groupImporter, $deviceImporter): void {
            DB::table('integration_records')->upsert(
                array_values($records),
                ['source', 'resource', 'external_id'],
                ['payload', 'updated_at'],
            );

            if ($resource === 'devices') {
                foreach ($records as $record) {
                    $deviceImporter->import($data['source'], json_decode($record['payload'], true, flags: JSON_THROW_ON_ERROR));
                }

                Audit::record('interfaceh.devices.imported', properties: [
                    'source' => $data['source'],
                    'count' => count($records),
                ]);
            }

            if ($resource === 'groups') {
                foreach ($records as $record) {
                    $payload = json_decode($record['payload'], true, flags: JSON_THROW_ON_ERROR);
                    $groupImporter->import($data['source'], (int) $payload['id'], $payload['name'], isset($payload['parent_id']) ? (int) $payload['parent_id'] : null);
                }

                Audit::record('interfaceh.groups.imported', properties: [
                    'source' => $data['source'],
                    'count' => count($records),
                ]);
            }

            if ($resource === 'personnel') {
                foreach ($records as $record) {
                    $payload = json_decode($record['payload'], true, flags: JSON_THROW_ON_ERROR);
                    $peopleImporter->import(
                        $payload['personCode'],
                        $payload['complete_name'],
                        $data['source'],
                        array_key_exists('group_id', $payload) ? ($payload['group_id'] === null ? null : (int) $payload['group_id']) : false,
                    );
                }

                Audit::record('interfaceh.people.imported', properties: [
                    'source' => $data['source'],
                    'count' => count($records),
                ]);
            }
        });

        return response()->json(['success' => true, 'accepted' => count($data['data'])]);
    }
}
