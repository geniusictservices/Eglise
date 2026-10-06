<div class="grid gap-3 sm:grid-cols-2">
    <div>
        <label for="roleId" class="sr-only">{{ __('Rôle') }}</label>
        <select wire:model="roleId" id="roleId" class="input">
            <option value="">{{ __('Choisir un rôle…') }}</option>
            @foreach ($roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach
        </select>
        @error('roleId') <p class="error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="scopeId" class="sr-only">{{ __('Niveau') }}</label>
        <select wire:model="scopeId" id="scopeId" class="input">
            @foreach ($nodes as $node)
                <option value="{{ $node->id }}">{{ str_repeat('— ', $node->depth - $organization->depth) }}{{ $node->name }}</option>
            @endforeach
        </select>
    </div>
</div>
@if ($nodes->count() > 1)
    <label class="mt-3 flex items-center gap-3 text-sm">
        <input wire:model="includesDescendants" type="checkbox" class="size-5 rounded border-sand-300 text-ink-700">
        {{ __('S’applique aussi aux niveaux inférieurs') }}
    </label>
@endif
