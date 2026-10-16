<?php

namespace App\Services;

use App\Models\Person;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class ImportPeople
{
    public function __construct(private HikCentralClient $client) {}

    public function run(): int
    {
        $count = 0;
        $total = null;
        for ($page = 1; $page <= 10000; $page++) {
            $data = $this->client->post(config('hikvision.hikcentral.people_path'), ['pageNo' => $page, 'pageSize' => 100]);
            Validator::make($data, ['total' => 'required|integer|min:0', 'list' => 'present|array'])->validate();
            $total ??= (int) $data['total'];
            if ((int) $data['total'] !== $total) {
                throw new RuntimeException('El personal cambió durante la importación. Repita la operación.');
            }
            foreach ($data['list'] as $row) {
                Validator::make($row, [
                    'personId' => 'required|string|max:255',
                    'personCode' => 'required|string|max:32',
                    'personName' => 'required|string|max:255',
                ])->validate();
                DB::transaction(function () use ($row) {
                    $person = Person::where('hikcentral_id', $row['personId'])->first();
                    $byNumber = Person::where('employee_number', $row['personCode'])->first();
                    if ($byNumber && ($person?->id !== $byNumber->id) && $byNumber->hikcentral_id !== null) {
                        throw new RuntimeException('Conflicto entre personId y personCode; revise el personal antes de continuar.');
                    }
                    $person ??= $byNumber ?? new Person;
                    if ($person->exists && $person->employee_number !== $row['personCode']
                        && $person->assignments()->whereNull('revoked_at')->exists()) {
                        throw new RuntimeException('No se puede cambiar un número de empleado con RFID activo.');
                    }
                    $person->fill([
                        'hikcentral_id' => $row['personId'],
                        'employee_number' => $row['personCode'],
                        'name' => $row['personName'],
                        'department' => $row['orgIndexCode'] ?? null,
                    ])->save();
                });
                $count++;
            }
            if ($count === $total) {
                Audit::record('hikcentral.import.completed', properties: ['count' => $count]);

                return $count;
            }
            if ($count > $total || count($data['list']) === 0) {
                throw new RuntimeException('Paginación incompleta o inconsistente de HikCentral.');
            }
        }
        throw new RuntimeException('Se alcanzó el límite de páginas de importación.');
    }
}
