<x-layouts.simple :title="__('Hors connexion')">
    <div class="mx-auto max-w-md py-10 text-center">
        <span class="mx-auto grid size-16 place-items-center rounded-full bg-ochre-100 text-ochre-700"><x-icon name="wifi-off" class="size-8" /></span>
        <h1 class="page-title mt-6">{{ __('Pas de connexion') }}</h1>
        <p class="mt-3 text-sand-700">{{ __('Waumini a besoin d’Internet pour afficher cette page. Vérifiez vos données mobiles ou le Wi-Fi, puis réessayez.') }}</p>
        <button type="button" onclick="location.reload()" class="btn-primary mt-8"><x-icon name="refresh-cw" class="size-4" /> {{ __('Réessayer') }}</button>
    </div>
</x-layouts.simple>
