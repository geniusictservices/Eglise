<?php

namespace App\Livewire\Documents;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\DocumentType;
use App\Services\DocumentTypes;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Les modèles de documents : ceux du siège et ceux de la communauté. */
#[Title('Modèles de documents')]
class Templates extends Component
{
    use WritesInOrganization;

    public function mount(): void
    {
        abort_unless(Gate::any(['documents.templates', 'documents.issue']), 403);
    }

    /** Adapter un modèle d'un niveau supérieur : la copie le remplace dans cette communauté. */
    public function adapt(DocumentTypes $types, int $id)
    {
        $this->authorizeWrite('documents.templates');
        $copy = $types->adapt(DocumentType::findOrFail($id), $this->organization());

        return $this->redirectRoute('documents.templates.edit', $copy);
    }

    public function toggle(int $id): void
    {
        $this->authorizeWrite('documents.templates');
        $type = DocumentType::where('organization_id', $this->organization()->id)->findOrFail($id);
        $type->update(['is_active' => ! $type->is_active]);
        $this->notify($type->is_active ? __('Modèle réactivé.') : __('Modèle mis de côté : il n’est plus proposé.'));
    }

    public function render(DocumentTypes $types)
    {
        $organization = $this->organization();

        return view('livewire.documents.templates', [
            'types' => $types->available($organization, withInactive: true),
            'organization' => $organization,
            'canManage' => Gate::allows('documents.templates') && ! $organization->isReadOnly(),
        ]);
    }
}
