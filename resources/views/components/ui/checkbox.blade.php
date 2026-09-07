@props([
    'name',
    'value',
    'label',
    'checked' => false,
])

<label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-md px-1 py-1 hover:bg-plum-light/60">
    <input
        type="checkbox"
        name="{{ $name }}"
        value="{{ $value }}"
        @checked($checked)
        {{ $attributes->class(['form-control form-control-checkbox mt-1']) }}
    >
    <span class="text-sm text-ink">{{ $label }}</span>
</label>
