<?php

namespace App\Livewire\Members;

use App\Models\Department;
use App\Models\Household;
use App\Models\Member;
use App\Models\Organization;
use App\Services\MemberRegistry;
use App\Services\MemberSpreadsheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Registre des membres : recherche, filtres et effectifs. */
#[Title('Membres')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'statut', except: '')]
    public string $status = '';

    #[Url(as: 'sexe', except: '')]
    public string $gender = '';

    #[Url(as: 'quartier', except: '')]
    public string $district = '';

    #[Url(as: 'departement', except: '')]
    public string $department = '';

    /** « ici » : ce niveau seulement ; « tout » : avec les niveaux inférieurs. */
    #[Url(as: 'portee', except: 'ici')]
    public string $scope = 'ici';

    public function mount(): void
    {
        $this->authorize('members.view');

        // Un siège sans fidèles inscrits chez lui voit d'emblée ceux de ses paroisses.
        $organization = current_organization();
        if (! request()->has('portee') && ! Member::exists() && Organization::where('parent_id', $organization->id)->exists()) {
            $this->scope = 'tout';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'gender', 'district', 'department', 'scope'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'gender', 'district', 'department');
        $this->resetPage();
    }

    /** Niveaux dont l'utilisateur peut voir les membres. */
    private function organizationIds(Organization $organization): array
    {
        if ($this->scope !== 'tout') {
            return [$organization->id];
        }

        $user = auth()->user();

        return Organization::query()->subtreeOf($organization)->get()
            ->filter(fn (Organization $o) => $user->hasPermission('members.view', $o))
            ->pluck('id')->all();
    }

    private function baseQuery(array $organizationIds): Builder
    {
        return Member::withoutOrganizationScope()->whereIn('members.organization_id', $organizationIds);
    }

    /** Les membres qui correspondent à la recherche et aux filtres. */
    private function filteredQuery(array $organizationIds): Builder
    {
        return $this->baseQuery($organizationIds)
            ->search($this->search)
            ->when($this->status !== '', fn ($q) => $this->status === 'aucun' ? $q->whereNull('status_id') : $q->where('status_id', $this->status))
            ->when($this->gender !== '', fn ($q) => $q->where('gender', $this->gender))
            ->when($this->district !== '', fn ($q) => $q->where('district', $this->district))
            ->when($this->department !== '', fn ($q) => $q->whereHas('departments', fn ($q) => $q->where('departments.id', $this->department)));
    }

    /** Export Excel des membres affichés (mêmes colonnes que le modèle d'import). */
    public function export(MemberSpreadsheet $spreadsheet)
    {
        $this->authorize('members.export');
        $organization = current_organization();
        $book = $spreadsheet->export($organization, $this->filteredQuery($this->organizationIds($organization)), Gate::allows('members.sensitive'));

        return response()->streamDownload(
            fn () => (new Xlsx($book))->save('php://output'),
            'registre-'.Str::slug($organization->displayName()).'-'.now()->format('Y-m-d').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function render(MemberRegistry $registry)
    {
        $organization = current_organization();
        $ids = $this->organizationIds($organization);
        $statuses = $registry->statuses($organization);
        $countingIds = $statuses->where('counts_as_member', true)->pluck('id');

        $members = $this->filteredQuery($ids)
            ->with(['status', 'organization', 'household'])
            ->orderBy('last_name')->orderBy('middle_name')->orderBy('first_name')
            ->paginate(25);

        $counting = $this->baseQuery($ids)->whereIn('status_id', $countingIds);

        return view('livewire.members.index', [
            'organization' => $organization,
            'members' => $members,
            'statuses' => $statuses,
            'hasChildren' => Organization::where('parent_id', $organization->id)->exists(),
            'districts' => $this->baseQuery($ids)->whereNotNull('district')->where('district', '!=', '')->distinct()->orderBy('district')->pluck('district'),
            'departments' => Department::withoutOrganizationScope()->whereIn('organization_id', $ids)->orderBy('name')->get(['id', 'name']),
            'filtered' => $this->search !== '' || $this->status !== '' || $this->gender !== '' || $this->district !== '' || $this->department !== '',
            'stats' => [
                'total' => (clone $counting)->count(),
                'women' => (clone $counting)->where('gender', 'F')->count(),
                'men' => (clone $counting)->where('gender', 'M')->count(),
                'new' => $this->baseQuery($ids)->where(fn ($q) => $q->whereYear('joined_on', now()->year)
                    ->orWhere(fn ($q) => $q->whereNull('joined_on')->whereYear('created_at', now()->year)))->count(),
                'households' => Household::withoutOrganizationScope()->whereIn('organization_id', $ids)->count(),
                'all' => $this->baseQuery($ids)->count(),
            ],
            'canManage' => Gate::allows('members.manage') && ! $organization->isReadOnly(),
        ]);
    }
}
