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
use App\Support\Settings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Throwable;

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
        'interfaceh' => ['API interfazH', 'integrations.manage'],
        'movements' => ['Movimientos y reportes', 'movements.view'],
        'audit' => ['Auditoría', 'audit.view'],
        'users' => ['Usuarios y roles', 'users.manage'],
        'backups' => ['Respaldos', 'backups.manage'],
        'smtp' => ['Correo SMTP', 'settings.manage'],
        'timezone' => ['Zona horaria', 'settings.manage'],
    ];

    public const MENU_GROUPS = [
        'Operativos' => ['dashboard', 'people', 'vehicles', 'rfid', 'movements'],
        'Administrativos' => ['devices', 'integrations', 'interfaceh', 'audit', 'users'],
        'Configuración' => ['backups', 'smtp', 'timezone'],
    ];

    public const MENU_ICONS = [
        'dashboard' => 'M3 10.5 12 3l9 7.5M5 9v12h14V9M9 21v-7h6v7',
        'people' => 'M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2m6-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6-7.5a4 4 0 0 1 0 7.75M20 21v-2a4 4 0 0 0-3-3.87',
        'vehicles' => 'M3 13h18l-2-6H5l-2 6Zm0 0v5h18v-5M6 18v2m12-2v2M6 13v-2m12 2v-2',
        'rfid' => 'M12 3 5 6v5c0 4.5 2.9 8.5 7 10 4.1-1.5 7-5.5 7-10V6l-7-3Zm-3 9h6m-3-3v6',
        'devices' => 'M4 4h16v13H4zM8 21h8m-4-4v4m-5-13h8m-8 4h5',
        'movements' => 'M4 19V5m0 14h16M7 15l4-4 3 2 5-6m-3 0h3v3',
        'integrations' => 'M8 7H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h3m8-12h3a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-3M8 12h8m-5-3 3 3-3 3',
        'interfaceh' => 'M5 4h14v16H5zM8 8h8m-8 4h8m-8 4h5',
        'audit' => 'M8 4h11v17H5V7h3m0-3v6m-3-3h6m-1 6h6m-6 4h6',
        'users' => 'M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2m6-10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6 1 2 2 4-4',
        'backups' => 'M12 3v12m-5-5 5 5 5-5M5 17v4h14v-4',
        'smtp' => 'M3 6h18v12H3zm0 1 9 6 9-6',
        'timezone' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-14v5l3 2',
    ];

    #[Locked]
    public string $section = 'dashboard';

    #[Locked]
    public array $appliedFilters = [];

    #[Locked]
    public string $generatedInterfaceHToken = '';

    public ?int $editingId = null;

    public string $name = '';

    public string $number = '';

    public string $department = '';

    public bool $active = true;

    public bool $showPersonModal = false;

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

    public string $smtpHost = '';

    public string $smtpPort = '587';

    public string $smtpScheme = 'smtp';

    public string $smtpUsername = '';

    public string $smtpPassword = '';

    public string $smtpFromAddress = '';

    public string $smtpFromName = '';

    public string $smtpTestTo = '';

    #[Locked]
    public bool $smtpPasswordSet = false;

    public string $timezone = 'UTC';

    public function mount(string $section = 'dashboard'): void
    {
        abort_unless(isset(self::SECTIONS[$section]), 404);
        $this->section = $section;
        Gate::authorize(self::SECTIONS[$section][1]);
        $this->from = now(Settings::timezone())->subDays(7)->format('Y-m-d');
        $this->to = now(Settings::timezone())->format('Y-m-d');
        $this->appliedFilters = $this->filters();
        if ($section === 'smtp') {
            $this->loadSmtp();
        }
        $this->timezone = Settings::timezone();
    }

    private function loadSmtp(): void
    {
        $this->smtpHost = Settings::get('smtp_host', '');
        $this->smtpPort = Settings::get('smtp_port', '587');
        $this->smtpScheme = Settings::get('smtp_scheme', 'smtp');
        $this->smtpUsername = Settings::get('smtp_username', '');
        $this->smtpPassword = '';
        $this->smtpPasswordSet = Settings::get('smtp_password') !== null;
        $this->smtpFromAddress = Settings::get('smtp_from_address', '');
        $this->smtpFromName = Settings::get('smtp_from_name', config('app.name'));
        $this->smtpTestTo = auth()->user()->email;
    }

    public function saveSmtp(): void
    {
        Gate::authorize('settings.manage');
        $this->validate([
            'smtpHost' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'smtpPort' => 'required|integer|between:1,65535',
            'smtpScheme' => ['required', Rule::in(['smtp', 'smtps'])],
            'smtpUsername' => 'nullable|string|max:255',
            'smtpPassword' => 'nullable|string|max:255',
            'smtpFromAddress' => 'required|email|max:255',
            'smtpFromName' => 'required|string|max:255',
        ]);
        $values = [
            'smtp_host' => $this->smtpHost,
            'smtp_port' => (string) (int) $this->smtpPort,
            'smtp_scheme' => $this->smtpScheme,
            'smtp_username' => $this->smtpUsername !== '' ? $this->smtpUsername : null,
            'smtp_from_address' => $this->smtpFromAddress,
            'smtp_from_name' => $this->smtpFromName,
        ];
        if ($this->smtpUsername === '') {
            $values['smtp_password'] = null;
        } elseif ($this->smtpPassword !== '') {
            $values['smtp_password'] = Crypt::encryptString($this->smtpPassword);
        }
        Settings::put($values);
        Settings::applyRuntimeConfig();
        Audit::record('settings.smtp.updated', properties: [
            'host' => $this->smtpHost,
            'port' => (int) $this->smtpPort,
            'scheme' => $this->smtpScheme,
            'from' => $this->smtpFromAddress,
            'password_changed' => array_key_exists('smtp_password', $values),
        ]);
        $this->loadSmtp();
        $this->notify('Configuración SMTP guardada.');
    }

    public function sendTestMail(): void
    {
        Gate::authorize('settings.manage');
        $this->validate(['smtpTestTo' => 'required|email|max:255']);
        if (! Settings::get('smtp_host')) {
            $this->addError('smtpTestTo', 'Guarde la configuración SMTP antes de enviar una prueba.');

            return;
        }
        try {
            Mail::mailer('smtp')->raw('Correo de prueba de SmartGate. La configuración SMTP funciona correctamente.', function ($message) {
                $message->to($this->smtpTestTo)->subject('Prueba SMTP · SmartGate');
            });
        } catch (Throwable $exception) {
            Audit::record('settings.smtp.test_failed', properties: ['to' => $this->smtpTestTo]);
            $this->addError('smtpTestTo', 'No se pudo enviar el correo: '.Str::limit($exception->getMessage(), 200));

            return;
        }
        Audit::record('settings.smtp.tested', properties: ['to' => $this->smtpTestTo]);
        $this->notify('Correo de prueba enviado a '.$this->smtpTestTo.'.');
    }

    public function saveTimezone(): void
    {
        Gate::authorize('settings.manage');
        $this->validate(['timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())]]);
        $previous = Settings::timezone();
        Settings::put(['timezone' => $this->timezone]);
        Settings::applyRuntimeConfig();
        Audit::record('settings.timezone.updated', properties: ['before' => $previous, 'after' => $this->timezone]);
        $this->notify('Zona horaria guardada.');
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

    public function createPerson(): void
    {
        Gate::authorize('people.manage');
        $this->resetForm();
        $this->showPersonModal = true;
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
        $this->showPersonModal = true;
    }

    public function closePersonModal(): void
    {
        $this->showPersonModal = false;
        $this->resetForm();
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
            $this->showPersonModal = false;
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
        $this->baseUrl = $device->base_url ?? ($device->ip_address ? 'http://'.$device->ip_address : '');
        $this->username = $device->username ?? '';
        $this->location = $device->location ?? '';
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
            if ($device->exists && $device->base_url !== null && $device->base_url !== $this->baseUrl) {
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
            } elseif ($device->password === null) {
                $this->addError('password', 'Capture la contraseña del equipo.');

                return;
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

    public function generateInterfaceHToken(): void
    {
        Gate::authorize('integrations.manage');
        $token = bin2hex(random_bytes(32));
        $now = now();

        DB::transaction(function () use ($token, $now): void {
            DB::table('interfaceh_api_tokens')->upsert([[
                'id' => 1,
                'token_hash' => hash('sha256', $token),
                'active' => true,
                'created_by' => auth()->id(),
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['id'], ['token_hash', 'active', 'created_by', 'created_at', 'updated_at']);
            Audit::record('interfaceh.token.generated');
        });

        $this->generatedInterfaceHToken = $token;
        $this->notify('Token generado. Cópielo ahora y configúrelo en interfazH; no podrá volver a consultarlo.');
    }

    public function revokeInterfaceHToken(): void
    {
        Gate::authorize('integrations.manage');
        $this->generatedInterfaceHToken = '';
        $revoked = DB::transaction(function (): int {
            $revoked = DB::table('interfaceh_api_tokens')->where('id', 1)->where('active', true)
                ->update(['active' => false, 'updated_at' => now()]);
            if ($revoked) {
                Audit::record('interfaceh.token.revoked');
            }

            return $revoked;
        });

        if ($revoked) {
            $this->notify('Token revocado. La API ya no aceptará ese acceso.');

            return;
        }

        $this->notify('No hay un token activo para revocar.');
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
            'people' => Person::with('group')->where(fn ($q) => $q->where('name', 'like', '%'.$this->search.'%')->orWhere('employee_number', 'like', '%'.$this->search.'%'))->orderBy('name')->paginate(20),
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
            'interfaceHTokenActive' => $this->section === 'interfaceh'
                && DB::table('interfaceh_api_tokens')->where('id', 1)->where('active', true)->exists(),
            'people' => in_array($this->section, ['rfid', 'movements', 'dashboard']) ? Person::orderBy('name')->get() : collect(),
            'vehicles' => in_array($this->section, ['rfid', 'movements', 'dashboard']) ? Vehicle::orderBy('plate')->get() : collect(),
            'devices' => in_array($this->section, ['rfid', 'movements', 'dashboard']) ? Device::orderBy('name')->get() : collect(),
            'provisionings' => $this->section === 'rfid' ? Provisioning::with(['device', 'assignment.tag'])->latest('id')->limit(100)->get() : collect(),
            'roles' => $this->section === 'users' ? Role::with('permissions')->get() : collect(),
            'timezones' => $this->section === 'timezone' ? timezone_identifiers_list() : [],
            'backups' => $this->section === 'backups' ? collect(Storage::disk('backups')->allFiles(config('backup.backup.name')))->filter(fn ($file) => str_ends_with($file, '.zip'))->sortDesc() : collect(),
        ]);
    }

    public static function menuGroupsFor(User $user): array
    {
        return array_filter(array_map(
            fn (array $sections) => array_filter(
                $sections,
                fn (string $section) => $user->can(self::SECTIONS[$section][1]),
            ),
            self::MENU_GROUPS,
        ));
    }
}
