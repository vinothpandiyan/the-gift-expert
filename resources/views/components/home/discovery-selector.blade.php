@props([
    'relationships',
    'occasions',
    'budgetRanges',
])

<form
    method="GET"
    action="{{ \App\Support\DiscoveryUrl::finder() }}"
    class="rounded-xl border border-line bg-surface p-4 shadow-[0_16px_40px_-32px_rgba(38,35,38,0.5)] md:p-5"
>
    <p class="mb-4 text-[15px] font-semibold">I'm looking for a gift for…</p>
    <div class="grid gap-3 md:grid-cols-3">
        <div>
            <label for="hero-recipient" class="mb-1.5 block text-[13px] text-ink-muted">Recipient</label>
            <select
                id="hero-recipient"
                name="relationship"
                class="h-12 w-full rounded-md border border-line bg-surface px-3 text-[15px] outline-none focus:border-plum"
            >
                <option value="">Choose</option>
                @foreach ($relationships as $relationship)
                    <option value="{{ $relationship->slug }}">{{ $relationship->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="hero-occasion" class="mb-1.5 block text-[13px] text-ink-muted">For</label>
            <select
                id="hero-occasion"
                name="occasion"
                class="h-12 w-full rounded-md border border-line bg-surface px-3 text-[15px] outline-none focus:border-plum"
            >
                <option value="">Choose</option>
                @foreach ($occasions as $occasion)
                    <option value="{{ $occasion->slug }}">{{ $occasion->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="hero-budget" class="mb-1.5 block text-[13px] text-ink-muted">My budget is</label>
            <select
                id="hero-budget"
                name="budget"
                class="h-12 w-full rounded-md border border-line bg-surface px-3 text-[15px] outline-none focus:border-plum"
            >
                <option value="">Choose</option>
                @foreach ($budgetRanges as $budget)
                    <option value="{{ $budget->slug }}">{{ $budget->name }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <x-ui.button type="submit" class="mt-4 h-12 w-full px-6 md:w-auto">
        Show me gifts
        <span aria-hidden="true">→</span>
    </x-ui.button>
</form>
