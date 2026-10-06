{{-- Les échanges d'une demande : la communauté à gauche, Genius ICT à droite (ou l'inverse côté Genius ICT). --}}
<ol class="space-y-3">
    @foreach ($messages as $m)
        @php $mine = $m->from_staff === $staffSide; @endphp
        <li @class(['flex', 'justify-end' => $mine])>
            <div @class(['max-w-[88%] rounded-2xl px-4 py-3 sm:max-w-[75%]', 'bg-ink-700 text-white' => $mine, 'border border-sand-200 bg-white text-ink-900' => ! $mine])>
                <p @class(['mb-1 text-xs font-semibold', 'text-ink-100' => $mine, 'text-ochre-700' => ! $mine])>{{ $m->from_staff ? __('Genius ICT') : '' }}{{ $m->from_staff && $m->author ? ' · ' : '' }}{{ $m->author?->name }} · {{ $m->created_at->translatedFormat('j M, H:i') }}</p>
                <div class="space-y-2 text-sm leading-relaxed">@foreach (preg_split('/\R{2,}/', trim($m->body)) as $p)<p>{!! nl2br(e($p)) !!}</p>@endforeach</div>
            </div>
        </li>
    @endforeach
</ol>
