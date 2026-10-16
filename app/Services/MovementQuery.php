<?php

namespace App\Services;

use App\Models\Movement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

class MovementQuery
{
    public static function filters(array $input): array
    {
        return Validator::make($input, [
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'person' => 'nullable|integer|exists:people,id',
            'vehicle' => 'nullable|integer|exists:vehicles,id',
            'device' => 'nullable|integer|exists:devices,id',
            'card' => 'nullable|string|max:32',
        ])->validate();
    }

    public static function build(array $filters): Builder
    {
        return Movement::with(['person', 'vehicle', 'device'])
            ->where('occurred_at', '>=', $filters['from'].' 00:00:00')
            ->where('occurred_at', '<', CarbonImmutable::parse($filters['to'])->addDay())
            ->when($filters['person'] ?? null, fn ($q, $id) => $q->where('person_id', $id))
            ->when($filters['vehicle'] ?? null, fn ($q, $id) => $q->where('vehicle_id', $id))
            ->when($filters['device'] ?? null, fn ($q, $id) => $q->where('device_id', $id))
            ->when($filters['card'] ?? null, fn ($q, $card) => $q->where('card_number', $card))
            ->orderByDesc('occurred_at')->orderByDesc('id');
    }
}
