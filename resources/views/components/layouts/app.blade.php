<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SmartGate · Trazabilidad de acceso</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
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
                @foreach(\App\Livewire\Workspace::menuGroupsFor(auth()->user()) as $group => $sections)
                    @php($activeGroup = collect($sections)->contains(fn ($key) => request()->routeIs($key)))
                    <details class="nav-group" @if($activeGroup) open @endif>
                        <summary class="nav-title">
                            <span>{{ $group }}</span>
                            <svg class="nav-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                        </summary>
                        <div class="nav-links">
                            @foreach($sections as $key)
                                <a href="{{ route($key) }}" @class(['selected' => request()->routeIs($key)]) @if(request()->routeIs($key)) aria-current="page" @endif>
                                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ \App\Livewire\Workspace::MENU_ICONS[$key] }}"/></svg>
                                    <span>{{ \App\Livewire\Workspace::SECTIONS[$key][0] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </details>
                @endforeach
                <p class="nav-note">Horario: {{ \App\Support\Settings::timezone() }}.<br>Operaciones auditadas.</p>
            </nav>
        @endauth
        <main>{{ $slot }}</main>
    </div>
    @livewireScripts
</body>
</html>
