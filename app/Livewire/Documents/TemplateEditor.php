<?php

namespace App\Livewire\Documents;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\DocumentType;
use App\Models\LifeEvent;
use App\Services\DocumentTypes;
use App\Support\DocumentTemplate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Component;

/** L'éditeur d'un modèle : son texte à variables, ses champs, son numéro, avec l'aperçu sur une vraie fiche. */
class TemplateEditor extends Component
{
    use WritesInOrganization;

    public ?DocumentType $type = null;

    public array $form = [];

    public function mount(?DocumentType $type = null): void
    {
        $this->authorizeWrite('documents.templates');
        $type = $type?->exists ? $type : null;
        abort_if($type && $type->organization_id !== $this->organization()->id, 403);
        $this->type = $type;
        $this->form = $type ? $type->only(['name', 'title', 'code', 'subject', 'body', 'number_format']) + [
            'life_event_type' => $type->life_event_type ?? '', 'signatory_title' => (string) $type->signatory_title,
            'fields' => $type->customFields(),
        ] : [
            'name' => '', 'title' => '', 'code' => '', 'subject' => 'member', 'life_event_type' => '', 'signatory_title' => __('Pasteur'),
            'number_format' => '{CODE}/{SIGLE}/{ANNEE}/{NUMERO}', 'fields' => [],
            'body' => __("Je soussigné(e), **{signataire}**, {qualite_signataire} de {communaute}, atteste que **{civilite} {nom_officiel}**, {né} le {date_naissance} à {lieu_naissance}, …\n\nEn foi de quoi, la présente attestation lui est délivrée pour servir et valoir ce que de droit."),
        ];
    }

    public function addField(): void
    {
        $this->form['fields'][] = ['label' => '', 'type' => 'text', 'required' => true];
    }

    public function removeField(int $index): void
    {
        unset($this->form['fields'][$index]);
        $this->form['fields'] = array_values($this->form['fields']);
    }

    public function save(DocumentTypes $types)
    {
        $this->authorizeWrite('documents.templates');
        $this->validate([
            'form.name' => 'required|string|max:120',
            'form.title' => 'required|string|max:160',
            'form.code' => 'required|alpha_num|max:12',
            'form.subject' => ['required', Rule::in(array_keys(DocumentType::SUBJECTS))],
            'form.life_event_type' => ['nullable', Rule::in(array_keys(LifeEvent::TYPES))],
            'form.signatory_title' => 'nullable|string|max:80',
            'form.number_format' => 'required|string|max:60',
            'form.body' => 'required|string|max:8000',
            'form.fields.*.label' => 'nullable|string|max:60',
        ], attributes: ['form.name' => __('nom'), 'form.title' => __('titre imprimé'), 'form.code' => __('code'), 'form.body' => __('texte'), 'form.number_format' => __('format du numéro')]);

        try {
            $this->type = $types->save($this->organization(), $this->form, $this->type);
        } catch (InvalidArgumentException $e) {
            $this->addError(str_contains($e->getMessage(), '{NUMERO}') ? 'form.number_format' : 'form.fields', $e->getMessage());

            return null;
        }
        session()->flash('status', __('Modèle enregistré.'));

        return $this->redirectRoute('documents.templates');
    }

    public function delete()
    {
        $this->authorizeWrite('documents.templates');
        abort_unless($this->type, 404);
        $this->type->delete();
        session()->flash('status', __('Modèle supprimé. Les documents déjà délivrés restent valables.'));

        return $this->redirectRoute('documents.templates');
    }

    public function render(DocumentTypes $types)
    {
        $organization = $this->organization();
        $draft = new DocumentType([
            'name' => $this->form['name'], 'title' => $this->form['title'] ?: __('Titre du document'), 'code' => strtoupper($this->form['code'] ?: 'XXX'),
            'subject' => $this->form['subject'], 'life_event_type' => $this->form['life_event_type'] ?: null, 'body' => $this->form['body'],
            'number_format' => str_contains($this->form['number_format'], '{NUMERO}') ? $this->form['number_format'] : '{CODE}/{NUMERO}',
            'signatory_title' => $this->form['signatory_title'],
            'fields' => collect($this->form['fields'])->filter(fn ($f) => trim($f['label'] ?? '') !== '')
                ->map(fn ($f) => $f + ['key' => DocumentTemplate::fieldKey($f['label'])])->values()->all(),
        ]);
        $values = $types->sample($draft, $organization);

        return view('livewire.documents.template-editor', [
            'variables' => DocumentTemplate::variables(),
            'customKeys' => collect($draft->customFields())->pluck('label', 'key')->all(),
            'unknown' => array_values(array_diff(DocumentTemplate::used($this->form['body']), array_keys($values))),
            'preview' => DocumentTemplate::render($this->form['body'], $values),
            'draft' => $draft,
            'values' => $values,
            'identity' => $organization->documentIdentity(),
            'organization' => $organization,
        ])->title($this->type ? $this->type->name : __('Nouveau modèle'));
    }
}
