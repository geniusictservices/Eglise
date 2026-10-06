@php use App\Models\DocumentType; @endphp
<div>
    <x-page-header :title="__('Modèles de documents')" :description="__('Le texte de chaque attestation, lettre ou ordre de mission, avec ses variables. Les modèles du siège valent pour toutes ses paroisses ; chacune peut les adapter ou créer les siens.')">
        <x-slot:actions>
            <a href="{{ route('documents.index') }}" class="btn-secondary"><x-icon name="file-text" class="size-4" /> {{ __('Documents délivrés') }}</a>
            @if ($canManage)<a href="{{ route('documents.templates.create') }}" class="btn-primary"><x-icon name="plus" class="size-4" /> {{ __('Nouveau modèle') }}</a>@endif
        </x-slot:actions>
    </x-page-header>

    <ul class="grid gap-3 md:grid-cols-2">
        @foreach ($types as $t)
            @php $own = $t->organization_id === $organization->id; @endphp
            <li wire:key="t-{{ $t->id }}" @class(['card flex flex-col gap-3 p-4', 'opacity-60' => ! $t->is_active])>
                <div class="flex items-start gap-3">
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-ink-50 font-mono text-xs font-semibold text-ink-700">{{ $t->code }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-semibold text-ink-800">{{ $t->name }}</span>
                        <span class="block text-sm text-sand-700">{{ __(DocumentType::SUBJECTS[$t->subject]) }}@if ($t->customFields()) · {{ trans_choice(':count champ à remplir|:count champs à remplir', count($t->customFields())) }}@endif</span>
                    </span>
                    @if (! $own)<span class="badge bg-ink-50 text-ink-700">{{ __('Du :n', ['n' => $t->organization->level_label ?? __('siège')]) }}</span>
                    @elseif ($t->replaces_id)<span class="badge bg-ochre-100 text-ochre-700">{{ __('Adapté') }}</span>@endif
                    @unless ($t->is_active)<span class="badge bg-sand-100 text-sand-700">{{ __('Mis de côté') }}</span>@endunless
                </div>
                <p class="line-clamp-3 text-sm text-sand-700">{{ str_replace('**', '', $t->body) }}</p>
                @if ($canManage)
                    <div class="mt-auto flex flex-wrap gap-2 border-t border-sand-100 pt-3">
                        @if ($own)
                            <a href="{{ route('documents.templates.edit', $t) }}" class="btn-ghost !min-h-0 !py-1.5 text-sm"><x-icon name="pencil" class="size-4" /> {{ __('Modifier') }}</a>
                            <button type="button" wire:click="toggle({{ $t->id }})" class="btn-ghost !min-h-0 !py-1.5 text-sm"><x-icon :name="$t->is_active ? 'archive' : 'archive-restore'" class="size-4" /> {{ $t->is_active ? __('Mettre de côté') : __('Réactiver') }}</button>
                        @else
                            <button type="button" wire:click="adapt({{ $t->id }})" class="btn-ghost !min-h-0 !py-1.5 text-sm"><x-icon name="pencil" class="size-4" /> {{ __('Adapter pour notre communauté') }}</button>
                        @endif
                    </div>
                @endif
            </li>
        @endforeach
    </ul>
</div>
