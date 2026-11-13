<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VehiclePhotoController extends Controller
{
    public function __invoke(Vehicle $vehicle): StreamedResponse
    {
        Gate::authorize('vehicles.manage');
        abort_unless($vehicle->photo_path, 404);
        abort_unless(Storage::disk('local')->exists($vehicle->photo_path), 404);

        return Storage::disk('local')->response($vehicle->photo_path);
    }
}
