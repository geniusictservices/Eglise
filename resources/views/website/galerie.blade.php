@extends('website.layout', ['title' => __('Galerie photos')])

@section('content')
    @include('website.partials.title', ['title' => __('Galerie photos'), 'intro' => __('La vie de la communauté en images : cultes, baptêmes, conventions, sorties.')])
    <div class="mx-auto max-w-6xl px-4 pt-10 sm:px-6">
        @if ($photos->isEmpty())
            <p class="text-sand-700">{{ __('Les premières photos arrivent bientôt.') }}</p>
        @else
            <ul class="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3 lg:grid-cols-4">
                @foreach ($photos as $photo)
                    <li>
                        <a href="{{ route('website.photo', [$organization->slug, $photo->id, 'grande']) }}" class="group block overflow-hidden rounded-2xl bg-sand-100" data-photo data-caption="{{ $photo->caption }}">
                            <img src="{{ route('website.photo', [$organization->slug, $photo->id, 'vignette']) }}" alt="{{ $photo->caption }}" loading="lazy" class="aspect-square w-full object-cover transition group-hover:scale-105">
                        </a>
                        @if ($photo->caption)<p class="mt-1.5 line-clamp-2 text-sm text-ink-800">{{ $photo->caption }}</p>@endif
                    </li>
                @endforeach
            </ul>
        @endif

        {{-- La photo en grand (sans JavaScript, le lien ouvre simplement la photo) --}}
        <dialog id="photo" class="m-auto max-h-none max-w-none bg-transparent p-0 backdrop:bg-ink-900/95">
            <form method="dialog" class="flex min-h-dvh w-screen flex-col items-center justify-center p-4">
                <button class="absolute right-4 top-4 rounded-full bg-white/15 p-2 text-white hover:bg-white/25" aria-label="{{ __('Fermer') }}"><x-icon name="x" class="size-6" /></button>
                <img alt="" class="max-h-[80vh] max-w-full rounded-xl object-contain">
                <p class="mt-3 max-w-2xl text-center text-white/90"></p>
            </form>
        </dialog>
        <script>
            (() => {
                const dialog = document.getElementById('photo');
                document.querySelectorAll('[data-photo]').forEach((link) => link.addEventListener('click', (e) => {
                    e.preventDefault();
                    dialog.querySelector('img').src = link.href;
                    dialog.querySelector('img').alt = link.dataset.caption;
                    dialog.querySelector('p').textContent = link.dataset.caption;
                    dialog.showModal();
                }));
                dialog.addEventListener('click', (e) => { if (e.target.tagName !== 'IMG') dialog.close(); });
            })();
        </script>
    </div>
@endsection
