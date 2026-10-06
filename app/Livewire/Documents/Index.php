<?php

namespace App\Livewire\Documents;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\IssuedDocument;
use App\Services\Documents;
use App\Services\DocumentTypes;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Le registre des documents délivrés : chacun garde son numéro, même annulé. */
#[Title('Documents délivrés')]
class Index extends Component
{
    use WithPagination, WritesInOrganization;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'modele')]
    public string $typeCode = '';

    public ?int $cancelling = null;

    public string $reason = '';

    public function mount(): void
    {
        abort_unless(Gate::any(['documents.issue', 'registers.manage']), 403);
    }

    public function updating($name): void
    {
        if (in_array($name, ['search', 'typeCode'], true)) {
            $this->resetPage();
        }
    }

    public function askCancel(int $id): void
    {
        $this->authorizeWrite('documents.issue');
        $this->cancelling = IssuedDocument::findOrFail($id)->id;
        $this->reason = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'cancel-document');
    }

    public function cancel(Documents $documents): void
    {
        $this->authorizeWrite('documents.issue');
        $this->validate(['reason' => 'required|string|max:255'], attributes: ['reason' => __('motif')]);
        try {
            $documents->cancel(IssuedDocument::findOrFail($this->cancelling), $this->reason);
        } catch (InvalidArgumentException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }
        $this->dispatch('close-modal', name: 'cancel-document');
        $this->notify(__('Document annulé : son QR code l’indique désormais.'));
    }

    public function render(DocumentTypes $types)
    {
        $term = trim($this->search);
        $documents = IssuedDocument::with(['type', 'issuer'])
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q->where('number', 'like', "%{$term}%")->orWhere('beneficiary', 'like', "%{$term}%")))
            ->when($this->typeCode !== '', fn ($q) => $q->whereHas('type', fn ($q) => $q->where('code', $this->typeCode)))
            ->latest('issued_on')->latest('id')->paginate(20);

        return view('livewire.documents.index', [
            'documents' => $documents,
            'types' => $types->available($this->organization()),
            'canIssue' => Gate::allows('documents.issue') && ! $this->organization()->isReadOnly(),
            'thisYear' => IssuedDocument::where('year', now()->year)->count(),
        ]);
    }
}
