<div>
    <x-page-header :title="__('Hiérarchie')" :eyebrow="$organization->level_label.' · '.$organization->name"
                   :description="__('Les niveaux de votre communauté : régions, secteurs, paroisses, annexes. Chaque niveau tient ses propres registres ; les niveaux supérieurs les consolident.')">
        <x-slot:actions>
            @if ($canManage)
                @if ($organization->isRoot())
                    <button type="button" class="btn-secondary" @click="$dispatch('open-modal', { name: 'attach' })"><x-icon name="link" class="size-4" /> {{ __('Rejoindre un siège') }}</button>
                @endif
                <button type="button" class="btn-primary" wire:click="startCreate"><x-icon name="plus" class="size-4" /> {{ __('Ajouter un niveau') }}</button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($ancestors->isNotEmpty())
        <p class="mb-4 flex flex-wrap items-center gap-1 text-sm text-sand-700">
            <x-icon name="network" class="size-4" />
            @foreach ($ancestors as $ancestor)
                <span>{{ $ancestor->name }}</span><x-icon name="chevron-right" class="size-3.5 text-sand-300" />
            @endforeach
            <span class="font-bold text-ink-700">{{ $organization->name }}</span>
        </p>
    @endif

    @if ($incoming->isNotEmpty())
        <section class="card mb-6 border-ochre-300 bg-ochre-50 p-5">
            <h2 class="text-lg font-semibold">{{ __('Demandes de rattachement') }}</h2>
            <ul class="mt-3 space-y-3">
                @foreach ($incoming as $request)
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-4">
                        <div class="min-w-0">
                            <p class="font-bold text-ink-700">{{ $request->organization->name }}</p>
                            <p class="text-sm text-sand-700">{{ __('Demandée par :name, :date', ['name' => $request->requester?->name ?? '—', 'date' => $request->created_at->diffForHumans()]) }}</p>
                            @if ($request->message)<p class="mt-1 text-sm italic">« {{ $request->message }} »</p>@endif
                        </div>
                        @if ($canManage)
                            <div class="flex gap-2">
                                <button class="btn-ghost" wire:click="decide({{ $request->id }}, false)" wire:confirm="{{ __('Refuser cette demande ?') }}">{{ __('Refuser') }}</button>
                                <button class="btn-primary" wire:click="decide({{ $request->id }}, true)" wire:confirm="{{ __('Accepter : :name et ses niveaux rejoindront votre hiérarchie.', ['name' => $request->organization->name]) }}">{{ __('Accepter') }}</button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="card p-3 sm:p-5">
        <div class="mb-2 flex items-center justify-between px-2">
            <p class="text-sm text-sand-700">{{ trans_choice(':count niveau|:count niveaux', $nodeCount) }}</p>
            <p class="text-sm text-sand-700">{{ __('Code de rattachement :') }} <span class="rounded bg-sand-100 px-1.5 py-0.5 font-mono text-ink-700">{{ $organization->slug }}</span></p>
        </div>
        <ul>
            @include('livewire.hierarchy.node', ['node' => $root, 'byParent' => $byParent, 'level' => 0])
        </ul>
    </section>

    @if ($outgoing->isNotEmpty())
        <section class="mt-6">
            <h2 class="mb-2 text-base font-semibold">{{ __('Vos demandes envoyées') }}</h2>
            <ul class="space-y-2">
                @foreach ($outgoing as $request)
                    <li class="flex items-center justify-between rounded-xl border border-sand-200 bg-white px-4 py-3 text-sm">
                        <span>{{ $request->target->name }}</span>
                        <span @class(['badge', 'bg-ochre-100 text-ochre-700' => $request->status === 'pending', 'bg-ink-50 text-ink-600' => $request->status === 'accepted', 'bg-terra-50 text-terra-600' => $request->status === 'refused'])>
                            {{ ['pending' => __('En attente'), 'accepted' => __('Acceptée'), 'refused' => __('Refusée'), 'cancelled' => __('Annulée')][$request->status] }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <x-modal name="create-level" :title="__('Ajouter un niveau')">
        <form wire:submit="create" class="space-y-4">
            <div>
                <label for="level-name" class="label">{{ __('Nom') }}</label>
                <input wire:model="name" id="level-name" class="input" placeholder="{{ __('Paroisse de Himbi') }}" required>
                @error('name') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="level-label" class="label">{{ __('Type de niveau') }}</label>
                <input wire:model="levelLabel" id="level-label" class="input" list="level-suggestions" required>
                <datalist id="level-suggestions">
                    @foreach ($levelSuggestions as $suggestion)<option value="{{ $suggestion }}">@endforeach
                </datalist>
                <p class="hint">{{ __('Utilisez vos propres mots : Région, Secteur, Paroisse, Doyenné, Annexe…') }}</p>
                @error('levelLabel') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="level-city" class="label">{{ __('Ville ou quartier') }} <span class="font-normal text-sand-500">({{ __('facultatif') }})</span></label>
                <input wire:model="city" id="level-city" class="input" placeholder="Goma">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'create-level' })">{{ __('Annuler') }}</button>
                <button type="submit" class="btn-primary">{{ __('Ajouter') }}</button>
            </div>
        </form>
    </x-modal>

    <x-modal name="attach" :title="__('Rejoindre un siège')">
        <form wire:submit="requestAttachment" class="space-y-4">
            <p class="text-sm text-sand-700">{{ __('Si votre église fait partie d’une dénomination déjà inscrite sur Waumini, demandez à rejoindre sa hiérarchie. Le siège, la région ou le secteur concerné doit accepter. Vos données restent les vôtres.') }}</p>
            <div>
                <label for="target" class="label">{{ __('Code de rattachement du niveau à rejoindre') }}</label>
                <input wire:model="targetSlug" id="target" class="input font-mono" placeholder="cep-region-nord-kivu">
                <p class="hint">{{ __('Le responsable du niveau le trouve sur sa page Hiérarchie.') }}</p>
                @error('targetSlug') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="msg" class="label">{{ __('Message') }} <span class="font-normal text-sand-500">({{ __('facultatif') }})</span></label>
                <textarea wire:model="requestMessage" id="msg" rows="3" class="input"></textarea>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="btn-ghost" @click="$dispatch('close-modal', { name: 'attach' })">{{ __('Annuler') }}</button>
                <button type="submit" class="btn-primary">{{ __('Envoyer la demande') }}</button>
            </div>
        </form>
    </x-modal>
</div>
