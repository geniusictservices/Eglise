<?php

namespace App\Livewire\Registers;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Register;
use App\Models\RegisterEntry;
use App\Services\Registers;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Les registres officiels de la communauté, et la recherche dans tous leurs actes. */
#[Title('Registres')]
class Index extends Component
{
    use WritesInOrganization;

    #[Url(as: 'q')]
    public string $search = '';

    public array $form = [];

    public function mount(): void
    {
        abort_unless(Gate::any(['registers.manage', 'documents.issue']), 403);
    }

    public function create(): void
    {
        $this->authorizeWrite('registers.manage');
        $this->form = ['kind' => 'baptism', 'name' => '', 'from_year' => '', 'to_year' => '', 'notes' => ''];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'register');
    }

    public function save(Registers $registers)
    {
        $this->authorizeWrite('registers.manage');
        $this->validate([
            'form.kind' => ['required', Rule::in(array_keys(Register::KINDS))],
            'form.name' => 'required|string|max:120',
            'form.from_year' => 'nullable|integer|between:1850,2100',
            'form.to_year' => 'nullable|integer|between:1850,2100|gte:form.from_year',
            'form.notes' => 'nullable|string|max:1000',
        ], attributes: ['form.name' => __('nom'), 'form.from_year' => __('début'), 'form.to_year' => __('fin')]);

        return $this->redirectRoute('registers.show', $registers->create($this->organization(), $this->form));
    }

    public function render()
    {
        $term = trim($this->search);
        $words = $term === '' ? [] : preg_split('/\s+/', $term);

        return view('livewire.registers.index', [
            'registers' => Register::withCount(['entries', 'entries as linked_count' => fn ($q) => $q->whereNotNull('member_id')])->orderBy('kind')->orderBy('from_year')->get(),
            'results' => $words ? RegisterEntry::with('register')->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->where(fn ($q) => $q->where('last_name', 'like', "%{$word}%")->orWhere('first_name', 'like', "%{$word}%")
                        ->orWhere('middle_name', 'like', "%{$word}%")->orWhere('partner_name', 'like', "%{$word}%")
                        ->orWhere('father', 'like', "%{$word}%")->orWhere('mother', 'like', "%{$word}%")->orWhere('entry_number', $word));
                }
            })->orderBy('last_name')->limit(30)->get() : collect(),
            'canManage' => Gate::allows('registers.manage') && ! $this->organization()->isReadOnly(),
        ]);
    }
}
