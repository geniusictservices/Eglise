{{-- Apparaît quand le navigateur propose d'installer l'application (Android, Windows, ordinateur). --}}
<button type="button" x-data x-cloak x-show="$store.pwa.canInstall" @click="$store.pwa.install()"
        {{ $attributes->merge(['class' => 'btn-accent']) }}>
    <x-icon name="download" class="size-4" /> {{ __('Installer') }}
</button>
