<div>
    <x-page-header :title="__('Sauvegardes')" :description="__('Chaque nuit à 2 h 15, Waumini sauvegarde la base et les fichiers envoyés (logos, photos, justificatifs, audios). Les :n plus récentes sont gardées.', ['n' => $keep])">
        <x-slot:actions><button type="button" wire:click="backupNow" wire:loading.attr="disabled" class="btn-primary"><x-icon name="save" class="size-4" /> <span wire:loading.remove wire:target="backupNow">{{ __('Sauvegarder maintenant') }}</span><span wire:loading wire:target="backupNow">{{ __('Sauvegarde en cours…') }}</span></button></x-slot:actions>
    </x-page-header>

    <div class="mb-5 grid gap-3 sm:grid-cols-2">
        <p @class(['rounded-2xl p-4 text-sm', 'bg-leaf-50 text-leaf-700' => $encrypted, 'bg-ochre-50 text-ochre-700' => ! $encrypted])><x-icon name="lock" class="mr-1 inline size-4" /> {{ $encrypted ? __('Archives chiffrées (AES-256) avec le mot de passe BACKUP_PASSWORD.') : __('Archives non chiffrées : réglez BACKUP_PASSWORD dans le fichier .env du serveur.') }}</p>
        <p @class(['rounded-2xl p-4 text-sm', 'bg-leaf-50 text-leaf-700' => $remote, 'bg-ochre-50 text-ochre-700' => ! $remote])><x-icon name="upload" class="mr-1 inline size-4" /> {{ $remote ? __('Copie hors du serveur sur le disque « :d ».', ['d' => $remote]) : __('Pas de copie hors du serveur : téléchargez une sauvegarde chaque semaine, ou réglez BACKUP_DISK.') }}</p>
    </div>

    <section class="card overflow-hidden">
        <ul class="divide-y divide-sand-100">
            @forelse ($backups as $b)
                <li class="flex flex-wrap items-center gap-3 px-5 py-3">
                    <x-icon name="archive" class="size-5 text-ink-500" />
                    <span class="min-w-0 flex-1 basis-56"><span class="block font-mono text-sm font-semibold text-ink-800">{{ $b['name'] }}</span><span class="text-sm text-sand-700">{{ $b['date']->translatedFormat('l j F Y à H:i') }} · {{ number_format($b['size'] / 1048576, 1, ',', ' ') }} Mo</span></span>
                    <a href="{{ route('admin.backups.download', $b['name']) }}" class="btn-secondary !min-h-0 !py-1.5 text-sm"><x-icon name="download" class="size-4" /> {{ __('Télécharger') }}</a>
                </li>
            @empty
                <li class="px-5 py-10 text-center text-sand-700">{{ __('Aucune sauvegarde pour l’instant.') }}</li>
            @endforelse
        </ul>
    </section>
    <p class="mt-4 text-sm text-sand-700">{{ __('Pour restaurer : importer database.sql dans une base vide (phpMyAdmin ou mysql), puis replacer le dossier « fichiers » dans storage/app/private. Le guide de déploiement détaille chaque étape.') }}</p>
</div>
