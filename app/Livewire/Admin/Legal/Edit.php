<?php

namespace App\Livewire\Admin\Legal;

use App\Models\LegalDocument;
use App\Services\AuditLogger;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Rédaction d'une nouvelle version des conditions ou de la politique de
 * confidentialité. Chaque publication crée une version datée ; les
 * anciennes restent consultables.
 */
#[Layout('layouts::admin')]
class Edit extends Component
{
    public string $key;

    public string $title = '';

    public string $body = '';

    public string $summary = '';

    public bool $preview = false;

    public function mount(string $key): void
    {
        $this->authorize('admin.legal');
        abort_unless(array_key_exists($key, LegalDocument::KEYS), 404);
        $this->key = $key;

        $source = LegalDocument::draft($key) ?? LegalDocument::current($key);
        $this->title = $source->title;
        $this->body = $source->body;
        $this->summary = $source->published_at ? '' : (string) $source->summary;
    }

    private function validated(): array
    {
        return $this->validate([
            'title' => 'required|string|max:150',
            'body' => 'required|string|min:200|max:100000',
            'summary' => 'required|string|max:255',
        ], ['summary.required' => __('Résumez en une phrase ce qui change : il s’affiche dans l’historique.')],
            ['title' => __('titre'), 'body' => __('texte'), 'summary' => __('résumé des changements')]);
    }

    public function saveDraft(): void
    {
        $this->authorize('admin.legal');
        $data = $this->validated();
        $draft = LegalDocument::draft($this->key) ?? new LegalDocument([
            'key' => $this->key,
            'version' => (int) LegalDocument::where('key', $this->key)->max('version') + 1,
        ]);
        $draft->fill($data + ['created_by' => auth()->id()])->save();

        $this->dispatch('notify', message: __('Brouillon enregistré. Il n’est pas encore visible du public.'), type: 'success');
    }

    public function publish()
    {
        $this->authorize('admin.legal');
        $this->saveDraft();
        $draft = LegalDocument::draft($this->key);
        $draft->update(['published_at' => now()]);
        app(AuditLogger::class)->record('published', $draft, [], ['version' => $draft->version],
            __('a publié la version :v de « :t »', ['v' => $draft->version, 't' => $draft->title]));

        session()->flash('status', __('Version :v publiée.', ['v' => $draft->version]));

        return $this->redirectRoute('admin.legal');
    }

    public function discardDraft()
    {
        $this->authorize('admin.legal');
        LegalDocument::draft($this->key)?->delete();

        return $this->redirectRoute('admin.legal');
    }

    public function render()
    {
        return view('livewire.admin.legal.edit', [
            'label' => LegalDocument::KEYS[$this->key],
            'html' => $this->preview ? Str::markdown($this->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]) : null,
            'hasDraft' => (bool) LegalDocument::draft($this->key),
            'current' => LegalDocument::current($this->key),
        ])->title(__('Modifier : :t', ['t' => LegalDocument::KEYS[$this->key]]));
    }
}
