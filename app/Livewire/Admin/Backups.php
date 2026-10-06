<?php

namespace App\Livewire\Admin;

use App\Services\Backups as BackupService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/** Les sauvegardes de la plateforme, pour la direction de Genius ICT. */
#[Layout('layouts::admin')]
#[Title('Sauvegardes')]
class Backups extends Component
{
    public function mount(): void
    {
        $this->authorize('admin.staff');
    }

    public function backupNow(BackupService $backups): void
    {
        $this->authorize('admin.staff');
        @set_time_limit(300);
        try {
            $path = $backups->run();
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('notify', message: __('La sauvegarde a échoué : :m', ['m' => $e->getMessage()]), type: 'error');

            return;
        }
        $this->dispatch('notify', message: __('Sauvegarde faite : :n', ['n' => basename($path)]), type: 'success');
    }

    public function render(BackupService $backups)
    {
        return view('livewire.admin.backups', [
            'backups' => $backups->list(),
            'keep' => (int) config('waumini.backups.keep'),
            'encrypted' => (string) config('waumini.backups.password') !== '',
            'remote' => config('waumini.backups.disk'),
        ]);
    }
}
