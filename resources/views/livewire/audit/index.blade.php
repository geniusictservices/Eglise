@php use App\Support\AuditPresenter; @endphp
<div>
    <x-page-header :title="__('Journal d’audit')"
                   :description="__('Qui a fait quoi, quand, avec les valeurs avant et après. Personne ne peut modifier ni effacer une ligne de ce journal.')">
        <x-slot:actions>
            <button type="button" class="btn-secondary" wire:click="verify"><x-icon name="badge-check" class="size-4" /> {{ __('Vérifier l’intégrité') }}</button>
        </x-slot:actions>
    </x-page-header>

    @if ($intact !== null)
        <div @class(['mb-4 flex items-center gap-3 rounded-2xl p-4 text-sm font-bold', 'bg-ink-50 text-ink-700' => $intact, 'bg-terra-50 text-terra-700' => ! $intact])>
            <x-icon :name="$intact ? 'circle-check' : 'triangle-alert'" class="size-5" />
            {{ $intact ? __('Le journal est intact : aucune ligne n’a été modifiée ni supprimée.') : __('Attention : le journal a été altéré en dehors de Waumini. Contactez le support Genius ICT.') }}
        </div>
    @endif

    <div class="mb-4 flex flex-wrap gap-3">
        <select wire:model.live="subject" class="input !w-auto" aria-label="{{ __('Type d’élément') }}">
            <option value="">{{ __('Tous les éléments') }}</option>
            @foreach ($subjects as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
        </select>
        <select wire:model.live="event" class="input !w-auto" aria-label="{{ __('Action') }}">
            <option value="">{{ __('Toutes les actions') }}</option>
            <option value="created">{{ __('Créations') }}</option>
            <option value="updated">{{ __('Modifications') }}</option>
            <option value="deleted">{{ __('Suppressions') }}</option>
        </select>
    </div>

    <ol class="space-y-3">
        @forelse ($logs as $log)
            <li class="card p-4 sm:p-5" x-data="{ open: false }">
                <div class="flex items-start gap-3">
                    <span @class(['mt-1 grid size-8 shrink-0 place-items-center rounded-full',
                        'bg-ink-50 text-ink-600' => $log->event === 'created', 'bg-ochre-50 text-ochre-700' => $log->event === 'updated',
                        'bg-terra-50 text-terra-600' => $log->event === 'deleted', 'bg-sand-100 text-sand-700' => ! in_array($log->event, ['created', 'updated', 'deleted'])])>
                        <x-icon :name="match($log->event) { 'created' => 'plus', 'updated' => 'pencil', 'deleted' => 'trash-2', default => 'history' }" class="size-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm"><span class="font-bold text-ink-700">{{ $log->user?->name ?? __('Système') }}</span> {{ AuditPresenter::sentence($log) }}</p>
                        <p class="mt-0.5 text-xs text-sand-700">
                            <time datetime="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->timezone($organization->timezone)->translatedFormat('j M Y · H:i') }}</time>
                            @if ($log->organization_id !== $organization->id) · {{ $log->organization?->name }}@endif
                            @if ($log->ip_address) · IP {{ $log->ip_address }}@endif
                        </p>
                    </div>
                    @if ($log->old_values || $log->new_values)
                        <button type="button" @click="open = !open" class="shrink-0 rounded-lg px-2 py-1 text-xs font-bold text-ink-600 hover:bg-ink-50" :aria-expanded="open">{{ __('Détails') }}</button>
                    @endif
                </div>
                @if ($log->old_values || $log->new_values)
                    <div x-cloak x-show="open" class="mt-3 overflow-x-auto rounded-xl bg-sand-50">
                        <table class="table">
                            <thead><tr><th>{{ __('Champ') }}</th><th>{{ __('Avant') }}</th><th>{{ __('Après') }}</th></tr></thead>
                            <tbody>
                                @foreach (array_unique(array_merge(array_keys($log->old_values ?? []), array_keys($log->new_values ?? []))) as $field)
                                    @continue(in_array($field, ['id', 'path', 'depth', 'slug', 'created_by', 'granted_by']))
                                    <tr>
                                        <td class="font-bold text-ink-700">{{ AuditPresenter::field($field) }}</td>
                                        <td class="text-sand-700">{{ \Illuminate\Support\Str::limit(AuditPresenter::value($log->old_values[$field] ?? null), 120) }}</td>
                                        <td class="text-ink-900">{{ \Illuminate\Support\Str::limit(AuditPresenter::value($log->new_values[$field] ?? null), 120) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </li>
        @empty
            <li class="card p-8 text-center text-sand-700">{{ __('Aucune ligne pour ces critères.') }}</li>
        @endforelse
    </ol>

    <div class="mt-4">{{ $logs->links() }}</div>
</div>
