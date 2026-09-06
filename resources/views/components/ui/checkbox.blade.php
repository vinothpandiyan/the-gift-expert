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
        {{ $attributes->merge(['class' => 'mt-1 size-4 shrink-0 rounded border-line text-plum focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-plum']) }}
    >
    <span class="text-sm text-ink">{{ $label }}</span>
</label>
