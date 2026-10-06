<?php

namespace App\Livewire\Admin\Legal;

use App\Models\LegalDocument;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::admin')]
#[Title('Textes juridiques')]
class Index extends Component
{
    public function mount(): void
    {
        $this->authorize('admin.legal');
    }

    public function render()
    {
        $documents = collect(LegalDocument::KEYS)->map(function ($label, $key) {
            LegalDocument::ensureSeeded($key);

            return [
                'key' => $key,
                'label' => $label,
                'current' => LegalDocument::current($key),
                'draft' => LegalDocument::draft($key),
                'versions' => LegalDocument::with('author')->published()->where('key', $key)->orderByDesc('version')->get(),
            ];
        });

        return view('livewire.admin.legal.index', ['documents' => $documents]);
    }
}
