<x-layouts.app>
    <section class="login card">
        <div class="eyebrow">ACCESO SEGURO</div>
        <h1>Bienvenido a SmartGate</h1>
        <p class="muted">Gestión y trazabilidad de personas y vehículos.</p>
        @if($errors->any())<div class="alert error" role="alert">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ url('/login') }}" class="stack">
            @csrf
            <x-input label="Correo electrónico" name="email" type="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input label="Contraseña" name="password" type="password" required autocomplete="current-password" />
            <button>Iniciar sesión</button>
        </form>
        <p class="muted small">Solicite su acceso al administrador. No hay registro público.</p>
    </section>
</x-layouts.app>
