@props(['label'])
<label class="field">
    <span>{{ $label }}</span>
    <select {{ $attributes }}>{{ $slot }}</select>
</label>
