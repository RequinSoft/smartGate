<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SmartGate · Trazabilidad de acceso</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body>
    <header class="topbar">
        <a href="{{ route('dashboard') }}" class="brand">Smart<span>Gate</span></a>
        <span class="muted">Control de acceso · Trazabilidad RFID</span>
        @auth
            <div class="account">{{ auth()->user()->name }}
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="secondary">Salir</button></form>
            </div>
        @endauth
    </header>
    <div class="shell">
        @auth
            <nav aria-label="Menú principal">
                <div class="nav-title">OPERACIÓN</div>
                @foreach(\App\Livewire\Workspace::SECTIONS as $key => [$label, $permission])
                    @can($permission)
                        <a href="{{ route($key) }}" @class(['selected' => request()->routeIs($key)])>{{ $label }}</a>
                    @endcan
                @endforeach
                <p class="nav-note">Registro horario en UTC.<br>Operaciones auditadas.</p>
            </nav>
        @endauth
        <main>{{ $slot }}</main>
    </div>
    @livewireScripts
</body>
</html>
