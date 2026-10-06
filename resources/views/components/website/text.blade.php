@props(['text'])
{{-- Un texte saisi par la communauté : une ligne vide sépare les paragraphes. --}}
<div {{ $attributes->merge(['class' => 'space-y-3 leading-relaxed']) }}>
    @foreach (preg_split('/\R{2,}/', trim((string) $text)) as $paragraph)
        <p>{!! nl2br(e($paragraph)) !!}</p>
    @endforeach
</div>
