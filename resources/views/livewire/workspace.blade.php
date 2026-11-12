<div>
    <div class="page-heading">
        <div><div class="eyebrow">SMARTGATE / GESTIÓN</div><h1>{{ \App\Livewire\Workspace::SECTIONS[$section][0] }}</h1></div>
        <span class="badge">{{ \App\Support\Settings::timezone() }} · {{ \App\Support\Settings::local(now(), 'Y-m-d') }}</span>
    </div>
    @if(session('status'))<div role="status" class="alert success">{{ session('status') }}</div>@endif
    @if($errors->any())
        <div role="alert" class="alert error"><strong>No se pudo completar la operación.</strong>
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    <div wire:loading class="alert">Procesando solicitud…</div>

    @if($section === 'people')
        <section class="card">
            <div class="toolbar">
                <x-input label="Buscar por nombre o número" wire:model.live.debounce.400ms="search" />
                <button type="button" wire:click="createPerson">+ Añadir persona</button>
            </div>
            <div class="table-wrap"><table><thead><tr><th>Empleado</th><th>Nombre</th><th>Grupo</th><th>Departamento</th><th>HikCentral ID</th><th>Estado</th><th></th></tr></thead><tbody>
                @forelse($rows as $row)
                    <tr wire:key="person-{{ $row->id }}"><td>{{ $row->employee_number }}</td><td>{{ $row->name }}</td><td>{{ $row->group?->name ?? '—' }}</td><td>{{ $row->department }}</td><td>{{ $row->hikcentral_id ?? 'Local' }}</td><td><span class="badge">{{ $row->active ? 'Activo' : 'Inactivo' }}</span></td><td><button class="secondary" wire:click="editPerson({{ $row->id }})">Editar</button></td></tr>
                @empty<tr><td colspan="7" class="empty">No hay personal registrado.</td></tr>@endforelse
            </tbody></table></div>{{ $rows->links() }}
        </section>
        @if($showPersonModal)
            <div class="modal-backdrop" wire:click.self="closePersonModal" wire:keydown.escape.window="closePersonModal">
                <div class="modal" role="dialog" aria-modal="true" aria-labelledby="person-modal-title">
                    <div class="modal-header">
                        <h2 id="person-modal-title">{{ $editingId ? 'Editar persona' : 'Añadir persona' }}</h2>
                        <button type="button" class="modal-close" wire:click="closePersonModal" aria-label="Cerrar">&times;</button>
                    </div>
                    <form wire:submit="savePerson">
                        @if($errors->any())<div role="alert" class="alert error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                        <div class="form-grid">
                            <x-input label="Nombre completo" wire:model="name" required maxlength="255" autofocus />
                            <x-input label="Número de empleado" wire:model="number" required maxlength="32" />
                            <x-input label="Departamento / organización" wire:model="department" maxlength="255" />
                            <label class="check"><input type="checkbox" wire:model="active"> Persona activa</label>
                        </div>
                        <div class="actions"><button wire:loading.attr="disabled">Guardar</button><button type="button" class="secondary" wire:click="closePersonModal">Cancelar</button></div>
                    </form>
                </div>
            </div>
        @endif
    @elseif($section === 'vehicles')
        <section class="card">
            <h2>{{ $editingId ? 'Editar vehículo' : 'Registrar vehículo' }}</h2>
            <form wire:submit="saveVehicle">
                <div class="form-grid">
                    <x-input label="Placa / matrícula" wire:model="plate" required maxlength="32" />
                    <x-input label="Descripción" wire:model="description" required maxlength="255" />
                </div>
                <div class="actions"><button>Guardar</button><button type="button" class="secondary" wire:click="resetForm">Limpiar</button></div>
            </form>
        </section>
        <section class="card">
            <x-input label="Buscar placa" wire:model.live.debounce.400ms="search" />
            <div class="table-wrap"><table><thead><tr><th>Placa</th><th>Descripción</th><th></th></tr></thead><tbody>
                @forelse($rows as $row)<tr wire:key="vehicle-{{ $row->id }}"><td>{{ $row->plate }}</td><td>{{ $row->description }}</td><td><button class="secondary" wire:click="editVehicle({{ $row->id }})">Editar</button></td></tr>
                @empty<tr><td colspan="3" class="empty">No hay vehículos registrados.</td></tr>@endforelse
            </tbody></table></div>{{ $rows->links() }}
        </section>
    @elseif($section === 'rfid')
        <section class="card">
            <h2>Asignar RFID</h2>
            <p class="muted">El número se conserva como texto, incluidos los ceros iniciales. Una tarjeta solo puede tener una asignación activa. Para enviarla, el número de empleado debe existir en el equipo: SmartGate no altera planes ni concede permisos de apertura.</p>
            <form wire:submit="assign">
                <div class="form-grid">
                    <x-input label="Número de tarjeta RFID (decimal)" wire:model="number" required pattern="[0-9]{1,32}" maxlength="32" />
                    <x-select label="Persona responsable" wire:model="personId" required>
                        <option value="">Seleccionar…</option>
                        @foreach($people->where('active', true) as $item)<option value="{{ $item->id }}">{{ $item->employee_number }} · {{ $item->name }}</option>@endforeach
                    </x-select>
                    <x-select label="Vehículo (opcional)" wire:model="vehicleId"><option value="">Sin vehículo</option>@foreach($vehicles as $item)<option value="{{ $item->id }}">{{ $item->plate }}</option>@endforeach</x-select>
                </div>
                <fieldset><legend>Enviar a dispositivos habilitados</legend>
                    @forelse($devices->where('write_enabled', true) as $item)
                        <label class="check"><input type="checkbox" wire:model="deviceIds" value="{{ $item->id }}">{{ $item->name }} · {{ $item->location }}</label>
                    @empty<p class="muted">Sin dispositivos habilitados: la asignación será solamente local.</p>@endforelse
                </fieldset>
                <button wire:loading.attr="disabled">Asignar RFID</button>
            </form>
        </section>
        <section class="card">
            <h2>Historial de asignaciones</h2>
            <div class="table-wrap"><table><thead><tr><th>RFID</th><th>Persona</th><th>Vehículo</th><th>Desde</th><th>Revocada</th><th></th></tr></thead><tbody>
                @forelse($rows as $row)<tr wire:key="assignment-{{ $row->id }}"><td>{{ $row->tag->number }}</td><td>{{ $row->person->name }}</td><td>{{ $row->vehicle?->plate ?? '—' }}</td><td>{{ \App\Support\Settings::local($row->assigned_at) }}</td><td>{{ $row->revoked_at ? \App\Support\Settings::local($row->revoked_at) : 'Activa' }}</td><td>@unless($row->revoked_at)<button class="danger" wire:click="revoke({{ $row->id }})" wire:confirm="¿Revocar este RFID y solicitar la baja en todos sus equipos?">Revocar</button>@endunless</td></tr>
                @empty<tr><td colspan="6" class="empty">No hay asignaciones.</td></tr>@endforelse
            </tbody></table></div>{{ $rows->links() }}
        </section>
        <section class="card">
            <h2>Confirmaciones por dispositivo · últimas 100 operaciones</h2>
            <p class="muted">pending = en cola · applied = confirmado · failed = error · cancelled = alta cancelada por revocación.</p>
            <div class="table-wrap"><table><thead><tr><th>RFID</th><th>Equipo</th><th>Operación</th><th>Estado</th><th>Detalle</th><th></th></tr></thead><tbody>
                @forelse($provisionings as $item)<tr wire:key="provisioning-{{ $item->id }}"><td>{{ $item->assignment->tag->number }}</td><td>{{ $item->device->name }}</td><td>{{ $item->operation }}</td><td><span class="badge">{{ $item->status }}</span></td><td>{{ $item->last_error }}</td><td>@if($item->status === 'failed')<button class="secondary" wire:click="retryProvisioning({{ $item->id }})">Reintentar</button>@endif</td></tr>
                @empty<tr><td colspan="6" class="empty">No hay operaciones remotas.</td></tr>@endforelse
            </tbody></table></div>
        </section>
    @elseif($section === 'devices')
        <div class="alert">ISAPI requiere validación por modelo y firmware. No active lectura/escritura hasta probar en un equipo de ensayo. El DS-K2801 puede requerir otro protocolo o SDK; su compatibilidad no está confirmada.</div>
        <section class="card">
            <h2>{{ $editingId ? 'Editar dispositivo' : 'Registrar dispositivo' }}</h2>
            <form wire:submit="saveDevice">
                <div class="form-grid">
                    <x-input label="Nombre del equipo" wire:model="name" required />
                    <x-input label="Modelo y firmware" wire:model="model" list="device-models" required />
                    <datalist id="device-models"><option>DS-K1T671TM-3XF</option><option>DS-K1T342MFWX-E1</option><option>DS-K1T341CMFW</option><option>DS-K280120220621V</option></datalist>
                    <x-input label="URL base (https://host:puerto)" wire:model="baseUrl" type="url" required />
                    <x-input label="Usuario del equipo" wire:model="username" autocomplete="off" required />
                    <x-input :label="$editingId ? 'Contraseña (vacía = sin cambios)' : 'Contraseña'" wire:model="password" type="password" autocomplete="new-password" />
                    <x-input label="Ubicación / punto de acceso" wire:model="location" required />
                    <x-select label="Sentido del punto de lectura" wire:model="direction"><option value="unknown">No determinado</option><option value="entry">Entrada</option><option value="exit">Salida</option></x-select>
                </div>
                <label class="check"><input type="checkbox" wire:model="readEnabled"> Lectura ISAPI validada y habilitada</label>
                <label class="check"><input type="checkbox" wire:model="writeEnabled"> Escritura ISAPI validada y habilitada</label>
                <div class="actions"><button>Guardar</button><button class="secondary" type="button" wire:click="resetForm">Limpiar</button></div>
            </form>
        </section>
        <section class="card">
            <div class="table-wrap"><table><thead><tr><th>Equipo</th><th>Modelo</th><th>IP / estado</th><th>Ubicación</th><th>Lectura / escritura</th><th>Última lectura</th><th>Error</th><th></th></tr></thead><tbody>
                @forelse($rows as $row)<tr wire:key="device-{{ $row->id }}"><td>{{ $row->name }}<br><small>{{ $row->base_url ?? 'Sin URL ISAPI' }}</small>@if($row->source)<br><span class="badge">interfazH</span>@endif</td><td>{{ $row->model }}@if($row->serial)<br><small>{{ $row->serial }}</small>@endif</td><td>{{ $row->ip_address ? $row->ip_address.($row->port ? ':'.$row->port : '') : '—' }}@if($row->online !== null)<br><small>{{ $row->online ? 'En línea' : 'Fuera de línea' }}</small>@endif</td><td>{{ $row->location }} · {{ $row->direction }}</td><td>{{ $row->read_enabled ? 'Sí' : 'No' }} / {{ $row->write_enabled ? 'Sí' : 'No' }}</td><td>{{ $row->last_polled_at ? \App\Support\Settings::local($row->last_polled_at) : 'Sin lectura' }}</td><td>{{ $row->last_error }}</td><td><div class="actions"><button class="secondary" wire:click="editDevice({{ $row->id }})">Editar</button>@if($row->read_enabled)<button wire:click="poll({{ $row->id }})">Leer eventos</button>@endif</div></td></tr>
                @empty<tr><td colspan="8" class="empty">Registre sus puntos de acceso.</td></tr>@endforelse
            </tbody></table></div>{{ $rows->links() }}
        </section>
    @elseif($section === 'integrations')
        <section class="card">
            <h2>Importación inicial · HikCentral Professional 3.0.0</h2>
            <p>Importa nombre, número de empleado, identificador de HikCentral y organización mediante OpenAPI Artemis.</p>
            <dl><dt>URL configurada</dt><dd>{{ config('hikvision.hikcentral.url') ?: 'No configurada' }}</dd><dt>Credenciales</dt><dd>{{ config('hikvision.hikcentral.key') && config('hikvision.hikcentral.secret') ? 'Configuradas (ocultas)' : 'Pendientes' }}</dd></dl>
            <p class="muted">Configure las variables HIKCENTRAL_URL, HIKCENTRAL_KEY y HIKCENTRAL_SECRET en el servidor. Para certificados internos configure HIKCENTRAL_CA_BUNDLE; no se omite la validación TLS.</p>
            <div class="alert">La importación conserva personId y personCode por separado. Una respuesta incompatible o un conflicto detiene la ejecución; las páginas anteriores permanecen guardadas y puede repetirla. No importa tarjetas ni concede accesos.</div>
            <button wire:click="importPeople" wire:loading.attr="disabled" wire:confirm="¿Importar o actualizar el personal desde HikCentral?">Importar personal</button>
        </section>
    @elseif($section === 'interfaceh')
        <section class="card">
            <h2>Token de acceso de interfazH</h2>
            <p>Este token autoriza a interfazH a enviar dispositivos, puertas, grupos, personal y eventos a SmartGate. Se guarda únicamente su hash y no puede recuperarse después de generarlo.</p>
            <p>El personal se integra automáticamente al recibirlo: <code>personCode</code> se guarda como número de empleado, conservando ceros iniciales, y <code>complete_name</code> como nombre. Un registro existente con el mismo número conserva su departamento, su vínculo con HikCentral, su estado y sus asignaciones. Los demás recursos permanecen en la bandeja de integraciones.</p>
            <p><span class="badge">{{ $interfaceHTokenActive ? 'Token activo' : 'Sin token activo' }}</span></p>
            @if($generatedInterfaceHToken !== '')
                <div role="alert" class="alert success">
                    <strong>Copie y guarde este token ahora.</strong> Al salir de esta pantalla no podrá volver a verlo.
                    <code class="token-value">{{ $generatedInterfaceHToken }}</code>
                </div>
            @endif
            <div class="actions">
                <button wire:click="generateInterfaceHToken" wire:loading.attr="disabled" wire:confirm="Generar un token nuevo revocará el token anterior. ¿Continuar?">
                    {{ $interfaceHTokenActive ? 'Rotar token' : 'Generar token' }}
                </button>
                @if($interfaceHTokenActive)
                    <button class="danger" wire:click="revokeInterfaceHToken" wire:loading.attr="disabled" wire:confirm="¿Revocar el token? interfazH perderá acceso hasta que configure uno nuevo.">Revocar token</button>
                @endif
            </div>
            <p class="muted small">Configure el token generado como token Bearer de la conexión SmartGate en interfazH. Use HTTPS y no lo incluya en el repositorio ni en tickets o bitácoras.</p>
            <p class="muted small">La API recibe lotes en <code>POST /api/devices</code>, <code>/api/doors</code>, <code>/api/groups</code>, <code>/api/personnel</code> y <code>/api/events</code>. La prueba de conexión es <code>POST /api/connection</code>.</p>
        </section>
    @elseif(in_array($section, ['movements', 'dashboard']))
        @if($section === 'dashboard')
            <div class="stats"><section class="card"><span>Eventos en el período</span><strong>{{ $rows->total() }}</strong></section><section class="card"><span>Personal registrado</span><strong>{{ $people->count() }}</strong></section><section class="card"><span>Vehículos registrados</span><strong>{{ $vehicles->count() }}</strong></section></div>
        @endif
        <section class="card">
            <h2>Seguimiento por persona, RFID y vehículo</h2>
            <form wire:submit="applyFilters">
                <div class="form-grid">
                    <x-input label="Desde" type="date" wire:model="from" required />
                    <x-input label="Hasta (inclusive)" type="date" wire:model="to" required />
                    <x-select label="Persona" wire:model="person"><option value="">Todas</option>@foreach($people as $item)<option value="{{ $item->id }}">{{ $item->employee_number }} · {{ $item->name }}</option>@endforeach</x-select>
                    <x-select label="Vehículo" wire:model="vehicle"><option value="">Todos</option>@foreach($vehicles as $item)<option value="{{ $item->id }}">{{ $item->plate }}</option>@endforeach</x-select>
                    <x-select label="Dispositivo" wire:model="device"><option value="">Todos</option>@foreach($devices as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</x-select>
                    <x-input label="RFID exacto" wire:model="card" maxlength="32" />
                </div>
                <div class="actions"><button>Consultar</button>@can('reports.export')<a class="button secondary" href="{{ route('reports.movements', $appliedFilters) }}">Exportar CSV</a>@endcan</div>
            </form>
            <p class="muted small">Se muestran eventos de acceso, incluidos intentos rechazados. El tipo mayor:menor es el código original, no una confirmación de paso físico. El sentido corresponde a la configuración del punto; un RFID no identificado no se atribuye al propietario actual.</p>
            <div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Persona</th><th>Vehículo</th><th>RFID</th><th>Equipo / ubicación</th><th>Sentido</th><th>Tipo</th></tr></thead><tbody>
                @forelse($rows as $row)<tr wire:key="movement-{{ $row->id }}"><td>{{ \App\Support\Settings::local($row->occurred_at) }}</td><td>{{ $row->person?->name ?? 'Sin identificar' }}<br><small>{{ $row->employee_number }}</small></td><td>{{ $row->vehicle?->plate ?? '—' }}</td><td>{{ $row->card_number ?? '—' }}</td><td>{{ $row->device->name }}<br><small>{{ $row->location }}</small></td><td>{{ ['entry' => 'Entrada', 'exit' => 'Salida', 'unknown' => 'No determinado'][$row->direction] }}</td><td>{{ $row->event_type }}</td></tr>
                @empty<tr><td colspan="7" class="empty">No hay movimientos para los filtros seleccionados.</td></tr>@endforelse
            </tbody></table></div>{{ $rows->links() }}
        </section>
    @elseif($section === 'audit')
        <section class="card">
            <h2>Bitácora de operaciones</h2>
            <p class="muted">Incluye autenticaciones, cambios, solicitudes, confirmaciones y fallos. Sin edición ni eliminación desde la aplicación. Un administrador de base de datos sí puede alterarla: para evidencia inmutable configure un destino externo de solo anexado.</p>
            <div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Actor</th><th>Acción</th><th>Entidad</th><th>Detalle</th></tr></thead><tbody>
                @forelse($rows as $row)<tr wire:key="audit-{{ $row->id }}"><td>{{ \App\Support\Settings::local($row->created_at) }}</td><td>{{ $row->causer?->name ?? 'Sistema / no autenticado' }}</td><td>{{ $row->description }}</td><td>{{ class_basename($row->subject_type ?? '') }} #{{ $row->subject_id }}</td><td><details><summary>Ver cambios</summary><pre>{{ json_encode($row->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details></td></tr>
                @empty<tr><td colspan="5" class="empty">Sin registros.</td></tr>@endforelse
            </tbody></table></div>{{ $rows->links() }}
        </section>
    @elseif($section === 'users')
        <section class="card">
            <h2>{{ $editingId ? 'Editar usuario y rol' : 'Crear usuario' }}</h2>
            <form wire:submit="saveUser">
                <div class="form-grid">
                    <x-input label="Nombre" wire:model="name" required />
                    <x-input label="Correo" wire:model="email" type="email" required autocomplete="off" />
                    <x-input :label="$editingId ? 'Nueva contraseña (opcional)' : 'Contraseña (mínimo 12 caracteres)'" wire:model="password" type="password" autocomplete="new-password" />
                    <x-select label="Rol" wire:model="role">@foreach($roles as $item)<option value="{{ $item->name }}">{{ $item->name }}</option>@endforeach</x-select>
                </div>
                <div class="actions"><button>Guardar usuario</button><button type="button" class="secondary" wire:click="resetForm">Limpiar</button></div>
            </form>
        </section>
        <section class="card">
            <div class="table-wrap"><table><thead><tr><th>Nombre</th><th>Correo</th><th>Roles</th><th></th></tr></thead><tbody>
                @foreach($rows as $row)<tr wire:key="user-{{ $row->id }}"><td>{{ $row->name }}</td><td>{{ $row->email }}</td><td>{{ $row->getRoleNames()->join(', ') }}</td><td><button class="secondary" wire:click="editUser({{ $row->id }})">Editar</button></td></tr>@endforeach
            </tbody></table></div>{{ $rows->links() }}
            <h3>Permisos por rol (Spatie Permission)</h3>
            @foreach($roles as $item)<details><summary>{{ $item->name }}</summary><p>{{ $item->permissions->pluck('name')->join(' · ') }}</p></details>@endforeach
        </section>
    @elseif($section === 'backups')
        <section class="card">
            <h2>Spatie Backup</h2>
            <p>Respaldo de la base de datos y archivos privados, con archivo ZIP cifrado. Programación diaria a las 02:00 ({{ \App\Support\Settings::timezone() }}); limpieza a las 03:00 y monitoreo a las 04:00.</p>
            <div class="alert">Requiere mysqldump, worker de colas, scheduler y BACKUP_ARCHIVE_PASSWORD. Conserve APP_KEY y la contraseña del respaldo fuera del servidor. Configure notificaciones y una copia externa antes de producción.</div>
            <button wire:click="backup" wire:loading.attr="disabled">Solicitar respaldo</button>
            <h3>Archivos completados · almacenamiento privado</h3>
            <ul>@forelse($backups as $file)<li>{{ basename($file) }}</li>@empty<li>Aún no hay respaldos completados.</li>@endforelse</ul>
            <p class="muted">El acceso a los archivos y la restauración se realizan mediante administración del servidor, no mediante enlaces públicos.</p>
        </section>
    @elseif($section === 'smtp')
        <section class="card">
            <h2>Servidor de correo saliente</h2>
            <p class="muted">Se usa para las notificaciones del sistema. Mientras no se configure, los correos se escriben en el log.</p>
            <form wire:submit="saveSmtp">
                <div class="form-grid">
                    <x-input label="Servidor SMTP" wire:model="smtpHost" required maxlength="255" placeholder="smtp.ejemplo.com" />
                    <x-input label="Puerto" wire:model="smtpPort" type="number" min="1" max="65535" required />
                    <x-select label="Seguridad" wire:model="smtpScheme"><option value="smtp">STARTTLS / sin cifrado (587, 25)</option><option value="smtps">SSL/TLS implícito (465)</option></x-select>
                    <x-input label="Usuario (vacío = sin autenticación)" wire:model="smtpUsername" autocomplete="off" maxlength="255" />
                    <x-input :label="$smtpPasswordSet ? 'Contraseña (vacía = sin cambios)' : 'Contraseña'" wire:model="smtpPassword" type="password" autocomplete="new-password" maxlength="255" />
                    <x-input label="Correo remitente" wire:model="smtpFromAddress" type="email" required maxlength="255" />
                    <x-input label="Nombre remitente" wire:model="smtpFromName" required maxlength="255" />
                </div>
                <div class="actions"><button wire:loading.attr="disabled">Guardar</button></div>
            </form>
        </section>
        <section class="card">
            <h2>Enviar correo de prueba</h2>
            <form wire:submit="sendTestMail">
                <div class="form-grid"><x-input label="Destinatario" wire:model="smtpTestTo" type="email" required maxlength="255" /></div>
                <div class="actions"><button wire:loading.attr="disabled">Enviar prueba</button></div>
            </form>
        </section>
    @elseif($section === 'timezone')
        <section class="card">
            <h2>Zona horaria del sistema</h2>
            <p class="muted">Los registros se guardan siempre en UTC. Esta zona se usa para mostrar fechas, filtrar movimientos por día, exportar reportes y programar tareas como los respaldos.</p>
            <form wire:submit="saveTimezone">
                <div class="form-grid">
                    <x-select label="Zona horaria" wire:model="timezone">@foreach($timezones as $zone)<option value="{{ $zone }}">{{ $zone }}</option>@endforeach</x-select>
                </div>
                <p>Hora actual en esta zona: <strong>{{ \App\Support\Settings::local(now()) }}</strong></p>
                <div class="actions"><button wire:loading.attr="disabled">Guardar</button></div>
            </form>
        </section>
    @endif
</div>
