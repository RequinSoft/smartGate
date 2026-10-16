@props(['label', 'type' => 'text'])
<label class="field">
    <span>{{ $label }}</span>
    <input type="{{ $type }}" {{ $attributes }}>
</label>
