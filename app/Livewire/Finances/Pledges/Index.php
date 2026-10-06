<?php

namespace App\Livewire\Finances\Pledges;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Campaign;
use App\Models\FinanceCategory;
use App\Models\Pledge;
use App\Services\Ledger;
use App\Services\Pledges;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Campagnes et promesses : qui a promis quoi, ce qui est reçu, qui relancer. */
#[Title('Promesses')]
class Index extends Component
{
    use WithPagination, WritesInOrganization;

    #[Url(as: 'campagne', except: '')]
    public string $campaign = '';

    #[Url(as: 'etat', except: '')]
    public string $state = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public ?int $campaignId = null;

    public array $form = [];

    public function mount(): void
    {
        $this->authorize('finance.view');
        abort_unless(Gate::any(['finance.pledges', 'finance.contributions.view']), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['campaign', 'state', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function editCampaign(Ledger $ledger, ?int $id = null): void
    {
        $this->authorizeWrite('finance.pledges');
        $c = $id ? Campaign::findOrFail($id) : null;
        $this->campaignId = $c?->id;
        $this->form = [
            'name' => $c->name ?? '', 'kind' => $c->kind ?? 'project', 'description' => (string) ($c->description ?? ''),
            'goal_amount' => $c?->goal_amount ? (string) (float) $c->goal_amount : '', 'goal_currency' => $c->goal_currency ?? 'USD',
            'starts_on' => $c?->starts_on?->toDateString() ?? today()->toDateString(), 'ends_on' => $c?->ends_on?->toDateString() ?? '',
            'status' => $c->status ?? 'active',
        ];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'campaign');
    }

    public function saveCampaign(Ledger $ledger): void
    {
        $this->authorizeWrite('finance.pledges');
        $data = $this->validate([
            'form.name' => 'required|string|max:150',
            'form.kind' => ['required', Rule::in(array_keys(Campaign::KINDS))],
            'form.description' => 'nullable|string|max:2000',
            'form.goal_amount' => 'nullable|numeric|min:0',
            'form.goal_currency' => ['required', Rule::in($ledger->currencies($this->organization()))],
            'form.starts_on' => 'nullable|date',
            'form.ends_on' => 'nullable|date|after_or_equal:form.starts_on',
            'form.status' => 'required|in:active,closed',
        ], attributes: ['form.name' => __('nom'), 'form.ends_on' => __('date de fin')])['form'];

        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $campaign = $this->campaignId ? Campaign::findOrFail($this->campaignId) : new Campaign;
        $campaign->fill($data);
        // Chaque campagne a sa catégorie de recettes, pour la suivre dans les rapports.
        if (! $campaign->category_id) {
            $campaign->category_id = FinanceCategory::firstOrCreate(['type' => 'income', 'name' => $data['name']], ['nature' => 'personal', 'position' => 60])->id;
        }
        $campaign->save();

        $this->campaign = (string) $campaign->id;
        $this->dispatch('close-modal', name: 'campaign');
        $this->notify(__('Campagne enregistrée.'));
    }

    public function render(Pledges $pledges)
    {
        $canSeeNames = Gate::any(['finance.pledges', 'finance.contributions.view']);
        $list = Pledge::with(['campaign', 'member', 'household', 'department'])
            ->when($this->campaign !== '', fn ($q) => $q->where('campaign_id', $this->campaign))
            ->when(in_array($this->state, ['active', 'fulfilled', 'cancelled'], true), fn ($q) => $q->where('status', $this->state))
            ->when($this->state === 'retard', fn ($q) => $q->where('status', 'active'))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(fn ($q) => $q->where('pledger_name', 'like', $term)
                    ->orWhereHas('member', fn ($q) => $q->search(trim($this->search)))
                    ->orWhereHas('household', fn ($q) => $q->where('name', 'like', $term)));
            })
            ->latest('pledged_on')->latest('id')->get()
            ->map(fn ($p) => ['pledge' => $p, 'progress' => $pledges->progress($p)])
            ->when($this->state === 'retard', fn ($c) => $c->filter(fn ($r) => $r['progress']['late']->isPositive()))
            ->values();

        $page = $this->getPage();
        $perPage = 25;

        return view('livewire.finances.pledges.index', [
            'campaigns' => Campaign::orderByRaw("status = 'active' DESC")->latest()->get()->map(fn ($c) => ['campaign' => $c, 'totals' => $pledges->campaignTotals($c)]),
            'current' => $this->campaign !== '' ? Campaign::find($this->campaign) : null,
            'rows' => $list->forPage($page, $perPage),
            'total' => $list->count(),
            'pages' => (int) ceil($list->count() / $perPage),
            'page' => $page,
            'lateCount' => $list->filter(fn ($r) => $r['progress']['late']->isPositive())->count(),
            'canSeeNames' => $canSeeNames,
            'canManage' => Gate::allows('finance.pledges') && ! $this->organization()->isReadOnly(),
            'currencies' => app(Ledger::class)->currencies($this->organization()),
        ]);
    }
}
