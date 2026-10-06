<x-layouts.simple :title="__('Aucune communauté')">
    <div class="mx-auto max-w-md py-10 text-center">
        <span class="mx-auto grid size-16 place-items-center rounded-full bg-sand-100 text-sand-700"><x-icon name="building-2" class="size-8" /></span>
        <h1 class="page-title mt-6">{{ __('Aucune communauté') }}</h1>
        <p class="mt-3 text-sand-700">{{ __('Votre compte n’a encore de rôle dans aucune communauté. Demandez à l’administrateur de votre église de vous en attribuer un.') }}</p>
        <form method="POST" action="{{ route('logout') }}" class="mt-8">
            @csrf
            <button type="submit" class="btn-secondary"><x-icon name="log-out" class="size-4" /> {{ __('Se déconnecter') }}</button>
        </form>
    </div>
</x-layouts.simple>
