<?php

namespace App\Services;

use App\Models\Device;
use App\Models\RfidAssignment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class IsapiClient
{
    private function request(Device $device): PendingRequest
    {
        return Http::withDigestAuth($device->username, $device->password)
            ->acceptJson()->connectTimeout(5)->timeout(30)
            ->withOptions(['verify' => config('hikvision.device_ca_bundle') ?: true, 'allow_redirects' => false]);
    }

    public function events(Device $device, string $from, string $to, int $position, string $searchId): array
    {
        if (! $device->read_enabled) {
            throw new RuntimeException('Lectura ISAPI no validada/habilitada para este dispositivo.');
        }
        $data = $this->request($device)->post($device->base_url.config('hikvision.events_path'), [
            'AcsEventCond' => [
                'searchID' => $searchId, 'searchResultPosition' => $position, 'maxResults' => 100,
                'major' => 5, 'minor' => 0, 'startTime' => $from, 'endTime' => $to,
            ],
        ])->throw()->json('AcsEvent');
        if (! is_array($data) || ! isset($data['totalMatches'], $data['numOfMatches'])
            || filter_var($data['totalMatches'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
            || filter_var($data['numOfMatches'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]) === false
            || (int) $data['numOfMatches'] > (int) $data['totalMatches']) {
            throw new RuntimeException('Respuesta ISAPI de eventos inválida. Valide firmware y capacidades.');
        }
        $data['InfoList'] ??= [];
        if (! is_array($data['InfoList']) || count($data['InfoList']) !== (int) $data['numOfMatches']) {
            throw new RuntimeException('Página ISAPI inconsistente.');
        }

        return $data;
    }

    public function provision(Device $device, RfidAssignment $assignment, string $operation): void
    {
        if (! in_array($operation, ['upsert', 'delete'], true)) {
            throw new RuntimeException('Operación RFID no válida.');
        }
        if (! $device->write_enabled) {
            throw new RuntimeException('Escritura ISAPI no validada/habilitada para este dispositivo.');
        }
        $card = $this->request($device)->post($device->base_url.config('hikvision.card_search_path'), [
            'CardInfoSearchCond' => [
                'searchID' => (string) Str::uuid(), 'searchResultPosition' => 0, 'maxResults' => 1,
                'CardNoList' => [['cardNo' => $assignment->tag->number]],
            ],
        ])->throw()->json('CardInfoSearch');
        if (! is_array($card) || ! isset($card['numOfMatches']) || ! in_array($card['numOfMatches'], [0, 1, '0', '1'], true)) {
            throw new RuntimeException('No se pudo verificar la tarjeta existente en el dispositivo.');
        }
        $matches = (int) $card['numOfMatches'];
        if ($matches === 1 && ((string) ($card['CardInfo'][0]['employeeNo'] ?? '') !== $assignment->person->employee_number
            || (string) ($card['CardInfo'][0]['cardNo'] ?? '') !== $assignment->tag->number)) {
            throw new RuntimeException('La tarjeta del equipo no coincide con esta asignación. Concilie el conflicto antes de sobrescribir o revocar.');
        }
        if ($operation === 'delete') {
            if ($matches === 0) {
                return;
            }
            $this->write($device, 'PUT', config('hikvision.delete_card_path'), [
                'CardInfoDelCond' => ['CardNoList' => [['cardNo' => $assignment->tag->number]]],
            ]);

            return;
        }
        $employee = $assignment->person->employee_number;
        $user = $this->request($device)->post($device->base_url.config('hikvision.user_search_path'), [
            'UserInfoSearchCond' => [
                'searchID' => (string) Str::uuid(),
                'searchResultPosition' => 0, 'maxResults' => 1,
                'EmployeeNoList' => [['employeeNo' => $employee]],
            ],
        ])->throw()->json('UserInfoSearch');
        if (! is_array($user) || (int) ($user['numOfMatches'] ?? 0) !== 1
            || (string) ($user['UserInfo'][0]['employeeNo'] ?? '') !== $employee) {
            throw new RuntimeException('El usuario no existe o no pudo verificarse en el equipo. Sincronice primero el personal desde HikCentral. No se modificaron permisos de acceso.');
        }
        $this->write($device, 'PUT', config('hikvision.card_path'), [
            'CardInfo' => ['employeeNo' => $employee, 'cardNo' => $assignment->tag->number, 'cardType' => 'normalCard'],
        ]);
    }

    private function write(Device $device, string $method, string $path, array $body): void
    {
        $data = $this->request($device)->send($method, $device->base_url.$path, ['json' => $body])->throw()->json();
        if (! is_array($data) || (int) ($data['statusCode'] ?? 0) !== 1) {
            throw new RuntimeException('El dispositivo no confirmó la operación ISAPI. Revise capacidades y configuración.');
        }
    }
}
