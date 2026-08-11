<div>
    @php
        use App\Support\Terminology;
    @endphp

    <header class="mb-8 space-y-3">
        <p class="text-sm font-medium uppercase tracking-wide text-stone-500">
            {{ Terminology::giftRecommendations() }}
        </p>
        <h1 class="text-3xl font-semibold tracking-tight text-stone-900 sm:text-4xl">
            Find a Gift
        </h1>
        <p class="max-w-2xl text-base leading-relaxed text-stone-600">
            Choose any details that matter. Everything is optional — the more you share, the better we can match {{ strtolower(Terminology::gifts()) }}.
        </p>
    </header>

    <form wire:submit="submit" class="space-y-8">
        <div class="grid gap-6 sm:grid-cols-2">
            <div class="space-y-2">
                <label for="occasion_id" class="block text-sm font-medium text-stone-800">
                    Occasion <span class="font-normal text-stone-500">(optional)</span>
                </label>
                <select
                    id="occasion_id"
                    wire:model="occasion_id"
                    class="w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-stone-500 focus:outline-none focus:ring-1 focus:ring-stone-500"
                >
                    <option value="">Any occasion</option>
                    @foreach ($occasions as $occasion)
                        <option value="{{ $occasion->id }}">{{ $occasion->name }}</option>
                    @endforeach
                </select>
                @error('occasion_id')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-2">
                <label for="relationship_id" class="block text-sm font-medium text-stone-800">
                    Relationship <span class="font-normal text-stone-500">(optional)</span>
                </label>
                <select
                    id="relationship_id"
                    wire:model="relationship_id"
                    class="w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-stone-500 focus:outline-none focus:ring-1 focus:ring-stone-500"
                >
                    <option value="">Any relationship</option>
                    @foreach ($relationships as $relationship)
                        <option value="{{ $relationship->id }}">{{ $relationship->name }}</option>
                    @endforeach
                </select>
                @error('relationship_id')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-2">
                <label for="recipient_type_id" class="block text-sm font-medium text-stone-800">
                    Recipient <span class="font-normal text-stone-500">(optional)</span>
                </label>
                <select
                    id="recipient_type_id"
                    wire:model="recipient_type_id"
                    class="w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-stone-500 focus:outline-none focus:ring-1 focus:ring-stone-500"
                >
                    <option value="">Any recipient</option>
                    @foreach ($recipientTypes as $recipientType)
                        <option value="{{ $recipientType->id }}">{{ $recipientType->name }}</option>
                    @endforeach
                </select>
                @error('recipient_type_id')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-2">
                <label for="profession_id" class="block text-sm font-medium text-stone-800">
                    Profession <span class="font-normal text-stone-500">(optional)</span>
                </label>
                <select
                    id="profession_id"
                    wire:model="profession_id"
                    class="w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-stone-500 focus:outline-none focus:ring-1 focus:ring-stone-500"
                >
                    <option value="">Any profession</option>
                    @foreach ($professions as $profession)
                        <option value="{{ $profession->id }}">{{ $profession->name }}</option>
                    @endforeach
                </select>
                @error('profession_id')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-2">
                <label for="gift_type_id" class="block text-sm font-medium text-stone-800">
                    Gift Type <span class="font-normal text-stone-500">(optional)</span>
                </label>
                <select
                    id="gift_type_id"
                    wire:model="gift_type_id"
                    class="w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-stone-500 focus:outline-none focus:ring-1 focus:ring-stone-500"
                >
                    <option value="">Any gift type</option>
                    @foreach ($giftTypes as $giftType)
                        <option value="{{ $giftType->id }}">{{ $giftType->name }}</option>
                    @endforeach
                </select>
                @error('gift_type_id')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-2">
                <label for="budget_range_id" class="block text-sm font-medium text-stone-800">
                    Budget <span class="font-normal text-stone-500">(optional)</span>
                </label>
                <select
                    id="budget_range_id"
                    wire:model="budget_range_id"
                    class="w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-stone-500 focus:outline-none focus:ring-1 focus:ring-stone-500"
                >
                    <option value="">Any budget</option>
                    @foreach ($budgetRanges as $budgetRange)
                        <option value="{{ $budgetRange->id }}">{{ $budgetRange->name }}</option>
                    @endforeach
                </select>
                @error('budget_range_id')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <fieldset class="space-y-3">
            <legend class="text-sm font-medium text-stone-800">
                Interests <span class="font-normal text-stone-500">(optional, up to {{ $maxInterests }})</span>
            </legend>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($interests as $interest)
                    <label class="flex items-center gap-2 rounded-md border border-stone-200 bg-white px-3 py-2 text-sm text-stone-800">
                        <input
                            type="checkbox"
                            wire:model="interest_ids"
                            value="{{ $interest->id }}"
                            class="rounded border-stone-300 text-stone-900 focus:ring-stone-500"
                        >
                        <span>{{ $interest->name }}</span>
                    </label>
                @endforeach
            </div>
            @error('interest_ids')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
            @error('interest_ids.*')
                <p class="text-sm text-red-600">{{ $message }}</p>
            @enderror
        </fieldset>

        <div class="flex items-center gap-4">
            <button
                type="submit"
                wire:loading.attr="disabled"
                class="inline-flex items-center justify-center rounded-md bg-stone-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-stone-800 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="submit">Find {{ Terminology::gifts() }}</span>
                <span wire:loading wire:target="submit">Finding {{ strtolower(Terminology::gifts()) }}…</span>
            </button>
        </div>
    </form>
</div>
