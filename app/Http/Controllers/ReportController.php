<?php

namespace App\Http\Controllers;

use App\Services\MovementQuery;
use App\Support\Audit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        abort_unless($request->user()->can('reports.export'), 403);
        $filters = MovementQuery::filters($request->query());
        Audit::record('report.export', properties: ['filters' => $filters]);

        return response()->streamDownload(function () use ($filters) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Fecha UTC', 'Persona', 'Empleado', 'Vehículo', 'RFID', 'Dispositivo', 'Ubicación', 'Sentido configurado', 'Tipo de evento'], escape: '');
            foreach (MovementQuery::build($filters)->lazy(500) as $movement) {
                $values = [
                    $movement->occurred_at->format('Y-m-d H:i:s'),
                    $movement->person?->name ?? 'Sin identificar', $movement->employee_number,
                    $movement->vehicle?->plate, $movement->card_number, $movement->device->name,
                    $movement->location, $movement->direction, $movement->event_type,
                ];
                fputcsv($stream, array_map(self::safeCell(...), $values), escape: '');
            }
            fclose($stream);
        }, 'movimientos.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public static function safeCell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[\s]*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
