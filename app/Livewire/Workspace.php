<?php

namespace App\Livewire;

use App\Jobs\ImportHikCentralPeople;
use App\Jobs\PollDevice;
use App\Jobs\ProvisionRfid;
use App\Jobs\RunBackup;
use App\Models\Device;
use App\Models\Person;
use App\Models\Provisioning;
use App\Models\RfidAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\MovementQuery;
use App\Services\RfidService;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

#[Layout('components.layouts.app')]
class Workspace extends Component
{
    use WithPagination;

    public const SECTIONS = [
        'dashboard' => ['Panel', 'movements.view'],
        'people' => ['Personal', 'people.manage'],
        'vehicles' => ['Vehículos', 'vehicles.manage'],
        'rfid' => ['Asignaciones RFID', 'rfid.manage'],
        'devices' => ['Dispositivos', 'devices.manage'],
        'integrations' => ['HikCentral', 'integrations.manage'],
        'movements' => ['Movimientos y reportes', 'movements.view'],
        'audit' => ['Auditoría', 'audit.view'],
        'users' => ['Usuarios y roles', 'users.manage'],
        'backups' => ['Respaldos', 'backups.manage'],
    ];

    #[Locked]
    public string $section = 'dashboard';

    #[Locked]
    public array $appliedFilters = [];

    public ?int $editingId = null;

    public string $name = '';

    public string $number = '';

    public string $department = '';

    public bool $active = true;

    public string $plate = '';

    public string $description = '';

    public string $personId = '';

    public string $vehicleId = '';

    public array $deviceIds = [];

    public string $model = '';

    public string $baseUrl = '';

    public string $username = '';

    public string $password = '';

    public string $location = '';

    public string $direction = 'unknown';

    public bool $readEnabled = false;

    public bool $writeEnabled = false;

    public string $email = '';

    public string $role = 'Operador';

    public string $search = '';

    public string $from = '';

    public string $to = '';

    public string $person = '';

    public string $vehicle = '';

    public string $device = '';

    public string $card = '';

    public function mount(string $section = 'dashboard'): void
    {
        abort_unless(isset(self::SECTIONS[$section]), 404);
        $this->section = $section;
        Gate::authorize(self::SECTIONS[$section][1]);
        $this->from = now()->subDays(7)->format('Y-m-d');
        $this->to = now()->format('Y-m-d');
        $this->appliedFilters = $this->filters();
    }

    private function notify(string $message): void
    {
        session()->flash('status', $message);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'number', 'department', 'active', 'plate', 'description', 'personId', 'vehicleId', 'deviceIds', 'model', 'baseUrl', 'username', 'password', 'location', 'direction', 'readEnabled', 'writeEnabled', 'email', 'role']);
        $this->resetValidation();
    }

    public function editPerson(int $id): void
    {
        Gate::authorize('people.manage');
        $this->resetForm();
        $person = Person::findOrFail($id);
        $this->editingId = $id;
        $this->name = $person->name;
        $this->number = $person->employee_number;
        $this->department = $person->department ?? '';
        $this->active = $person->active;
    }

    public function savePerson(): void
    {
        Gate::authorize('people.manage');
        $this->validate([
            'name' => 'required|string|max:255',
            'number' => ['required', 'regex:/^[a-zA-Z0-9_-]{1,32}$/', Rule::unique('people', 'employee_number')->ignore($this->editingId)],
            'department' => 'nullable|string|max:255',
        ]);
        DB::transaction(function () {
            $person = $this->editingId ? Person::lockForUpdate()->findOrFail($this->editingId) : new Person;
            if ($person->exists && ($person->employee_number !== $this->number || ! $this->active)
                && $person->assignments()->whereNull('revoked_at')->exists()) {
                $this->addError('number', 'Revoque primero los RFID activos y espere su confirmación en los equipos.');

                return;
            }
            if ($person->exists && Provisioning::whereHas('assignment', fn ($q) => $q->where('person_id', $person->id))
                ->where('operation', 'delete')->where('status', '!=', 'applied')->exists()
                && ($person->employee_number !== $this->number || ! $this->active)) {
                $this->addError('number', 'Existen revocaciones pendientes.');

                return;
            }
            $person->fill(['name' => $this->name, 'employee_number' => $this->number, 'department' => $this->department, 'active' => $this->active])->save();
            $this->resetForm();
            $this->notify('Personal guardado.');
        });
    }

    public function editVehicle(int $id): void
    {
        Gate::authorize('vehicles.manage');
        $this->resetForm();
        $vehicle = Vehicle::findOrFail($id);
        $this->editingId = $id;
        $this->plate = $vehicle->plate;
        $this->description = $vehicle->description;
    }

    public function saveVehicle(): void
    {
        Gate::authorize('vehicles.manage');
        $this->plate = mb_strtoupper(trim($this->plate));
        $this->validate(['plate' => ['required', 'string', 'max:32', Rule::unique('vehicles')->ignore($this->editingId)], 'description' => 'required|string|max:255']);
        DB::transaction(function () {
            $vehicle = $this->editingId ? Vehicle::findOrFail($this->editingId) : new Vehicle;
            $vehicle->fill(['plate' => $this->plate, 'description' => $this->description])->save();
        });
        $this->resetForm();
        $this->notify('Vehículo guardado.');
    }

    public function assign(RfidService $service): void
    {
        Gate::authorize('rfid.manage');
        $this->validate([
            'number' => ['required', 'regex:/^[0-9]{1,32}$/'],
            'personId' => 'required|integer|exists:people,id',
            'vehicleId' => 'nullable|integer|exists:vehicles,id',
            'deviceIds' => 'array',
            'deviceIds.*' => 'integer|distinct|exists:devices,id',
        ]);
        $service->assign($this->number, (int) $this->personId, $this->vehicleId ? (int) $this->vehicleId : null, $this->deviceIds, auth()->user());
        $this->resetForm();
        $this->notify('RFID asignado localmente. Verifique la confirmación de cada dispositivo.');
    }

    public function revoke(int $id, RfidService $service): void
    {
        Gate::authorize('rfid.manage');
        $service->revoke($id);
        $this->notify('Revocación local registrada. Las bajas remotas quedan en cola.');
    }

    public function editDevice(int $id): void
    {
        Gate::authorize('devices.manage');
        $this->resetForm();
        $device = Device::findOrFail($id);
        $this->editingId = $id;
        $this->name = $device->name;
        $this->model = $device->model;
        $this->baseUrl = $device->base_url;
        $this->username = $device->username;
        $this->location = $device->location;
        $this->direction = $device->direction;
        $this->readEnabled = $device->read_enabled;
        $this->writeEnabled = $device->write_enabled;
    }

    public function saveDevice(): void
    {
        Gate::authorize('devices.manage');
        $this->baseUrl = rtrim($this->baseUrl, '/');
        $this->validate([
            'name' => 'required|string|max:255', 'model' => 'required|string|max:255',
            'baseUrl' => ['required', 'url:http,https', 'max:255', Rule::unique('devices', 'base_url')->ignore($this->editingId)],
            'username' => 'required|string|max:255',
            'password' => ($this->editingId ? 'nullable' : 'required').'|string|max:255',
            'location' => 'required|string|max:255',
            'direction' => ['required', Rule::in(['entry', 'exit', 'unknown'])],
        ]);
        $parts = parse_url($this->baseUrl);
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            $this->addError('baseUrl', 'Use solamente esquema, host y puerto, sin credenciales ni ruta.');

            return;
        }
        DB::transaction(function () {
            $device = $this->editingId ? Device::findOrFail($this->editingId) : new Device;
            if ($device->exists && $device->base_url !== $this->baseUrl) {
                $this->addError('baseUrl', 'La dirección identifica al equipo y no se puede cambiar. Registre otro dispositivo.');

                return;
            }
            $values = [
                'name' => $this->name, 'model' => $this->model, 'base_url' => $this->baseUrl,
                'username' => $this->username, 'location' => $this->location, 'direction' => $this->direction,
                'read_enabled' => $this->readEnabled, 'write_enabled' => $this->writeEnabled,
            ];
            if ($this->password !== '') {
                $values['password'] = $this->password;
            }
            $device->fill($values)->save();
            $this->resetForm();
            $this->notify('Dispositivo guardado.');
        });
    }

    public function poll(int $id): void
    {
        Gate::authorize('devices.manage');
        $device = Device::findOrFail($id);
        if (! $device->read_enabled) {
            $this->addError('device', 'Habilite la lectura después de validar ISAPI.');

            return;
        }
        Audit::record('device.events.requested', $device);
        PollDevice::dispatch($id);
        $this->notify('Lectura solicitada. Consulte el estado del equipo y la auditoría.');
    }

    public function retryProvisioning(int $id): void
    {
        Gate::authorize('rfid.manage');
        $item = Provisioning::findOrFail($id);
        abort_unless($item->status === 'failed', 422);
        Audit::record('device.rfid.retry', $item);
        ProvisionRfid::dispatch($id);
        $this->notify('Reintento solicitado.');
    }

    public function importPeople(): void
    {
        Gate::authorize('integrations.manage');
        Audit::record('hikcentral.import.requested');
        ImportHikCentralPeople::dispatch();
        $this->notify('Importación en cola. No elimina personal ni asignaciones locales. Consulte la auditoría para ver el resultado.');
    }

    public function backup(): void
    {
        Gate::authorize('backups.manage');
        Audit::record('backup.requested');
        RunBackup::dispatch();
        $this->notify('Respaldo solicitado. Se mostrará solamente cuando finalice correctamente.');
    }

    public function editUser(int $id): void
    {
        Gate::authorize('users.manage');
        $this->resetForm();
        $user = User::findOrFail($id);
        $this->editingId = $id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->role = $user->roles->first()?->name ?? 'Operador';
    }

    public function saveUser(): void
    {
        Gate::authorize('users.manage');
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->editingId)],
            'password' => ($this->editingId ? 'nullable' : 'required').'|string|min:12|max:128',
            'role' => ['required', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ]);
        DB::transaction(function () {
            $user = $this->editingId ? User::lockForUpdate()->findOrFail($this->editingId) : new User;
            if ($user->id === auth()->id() && $this->role !== 'Administrador') {
                $this->addError('role', 'No puede quitarse su propio acceso administrativo.');

                return;
            }
            $before = $user->exists ? ['name' => $user->name, 'email' => $user->email, 'roles' => $user->getRoleNames()->all()] : null;
            $user->fill(['name' => $this->name, 'email' => $this->email]);
            if ($this->password !== '') {
                $user->password = $this->password;
            }
            $user->save();
            $user->syncRoles([$this->role]);
            Audit::record('user.saved', $user, ['before' => $before, 'after' => ['name' => $user->name, 'email' => $user->email, 'role' => $this->role], 'password_changed' => $this->password !== '']);
            $this->resetForm();
            $this->notify('Usuario y rol guardados.');
        });
    }

    public function applyFilters(): void
    {
        Gate::authorize('movements.view');
        $this->appliedFilters = MovementQuery::filters($this->filters());
        $this->resetPage();
    }

    public function filters(): array
    {
        return $this->only(['from', 'to', 'person', 'vehicle', 'device', 'card']);
    }

    public function render()
    {
        Gate::authorize(self::SECTIONS[$this->section][1]);
        $rows = match ($this->section) {
            'people' => Person::where(fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')->orWhere('employee_number', 'like', '%'.$this->search.'%'))->orderBy('name')->paginate(20),
            'vehicles' => Vehicle::where('plate', 'like', '%'.$this->search.'%')->orderBy('plate')->paginate(20),
            'devices' => Device::orderBy('name')->paginate(20),
            'rfid' => RfidAssignment::with(['person', 'vehicle', 'tag'])->latest('id')->paginate(20),
            'users' => User::with('roles')->orderBy('name')->paginate(20),
            'audit' => Activity::with('causer')->latest('id')->paginate(30),
            'movements', 'dashboard' => MovementQuery::build($this->appliedFilters)->paginate(25),
            default => null,
        };

        return view('livewire.workspace', [
            'rows' => $rows,
            'people' => in_array($this->section, ['rfid', 'movements', 'dashboard']) ? Person::orderBy('name')->get() : collect(),
            'vehicles' => in_array($this->section, ['rfid', 'movements', 'dashboard']) ? Vehicle::orderBy('plate')->get() : collect(),
            'devices' => in_array($this->section, ['rfid', 'movements', 'dashboard']) ? Device::orderBy('name')->get() : collect(),
            'provisionings' => $this->section === 'rfid' ? Provisioning::with(['device', 'assignment.tag'])->latest('id')->limit(100)->get() : collect(),
            'roles' => $this->section === 'users' ? Role::with('permissions')->get() : collect(),
            'backups' => $this->section === 'backups' ? collect(Storage::disk('backups')->allFiles(config('backup.backup.name')))->filter(fn ($file) => str_ends_with($file, '.zip'))->sortDesc() : collect(),
        ]);
    }
}
