<?php

namespace App\Livewire\Departments;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Department;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Départements de la communauté : ministères et services administratifs. */
#[Title('Départements')]
class Index extends Component
{
    use WritesInOrganization;

    #[Url(as: 'type', except: '')]
    public string $kind = '';

    public array $form = [];

    public function mount(): void
    {
        $this->authorize('members.view');
    }

    public static function rules(?int $ignoreId = null): array
    {
        return [
            'form.name' => ['required', 'string', 'max:120', function ($a, $value, $fail) use ($ignoreId) {
                if (Department::where('name', trim($value))->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
                    $fail(__('Un département porte déjà ce nom.'));
                }
            }],
            'form.kind' => 'required|in:'.implode(',', array_keys(Department::KINDS)),
            'form.description' => 'nullable|string|max:500',
            'form.color' => 'required|in:'.implode(',', array_keys(config('waumini.registry.colors'))),
        ];
    }

    public function create(string $name = '', string $kind = 'ministry'): void
    {
        $this->authorizeWrite('departments.manage');
        $this->form = ['name' => $name, 'kind' => $kind, 'description' => '', 'color' => $kind === 'administrative' ? 'ink' : 'ochre'];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'department');
    }

    public function save()
    {
        $this->authorizeWrite('departments.manage');
        $data = $this->validate(self::rules(), attributes: ['form.name' => __('nom')])['form'];

        $department = Department::create(['name' => trim($data['name']), 'kind' => $data['kind'],
            'description' => trim((string) $data['description']) ?: null, 'color' => $data['color']]);

        return $this->redirectRoute('departments.show', $department);
    }

    public function render()
    {
        $departments = Department::query()
            ->when($this->kind !== '', fn ($q) => $q->where('kind', $this->kind))
            ->withCount('members')
            ->with(['leaders'])
            ->orderByDesc('is_active')->orderByDesc('is_system')->orderBy('name')
            ->get();

        $existing = Department::pluck('name')->map(fn ($n) => mb_strtolower($n))->all();

        return view('livewire.departments.index', [
            'departments' => $departments,
            'canManage' => Gate::allows('departments.manage') && ! $this->organization()->isReadOnly(),
            'suggestions' => collect(config('waumini.department_suggestions'))
                ->map(fn ($names) => array_values(array_filter($names, fn ($n) => ! in_array(mb_strtolower(__($n)), $existing, true)))),
            'colors' => config('waumini.registry.colors'),
        ]);
    }
}
