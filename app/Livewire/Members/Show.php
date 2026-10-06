<?php

namespace App\Livewire\Members;

use App\Livewire\Members\Concerns\FindsMember;
use App\Models\Group;
use App\Models\Household;
use App\Models\LifeEvent;
use App\Models\Member;
use App\Models\MemberFunctionTerm;
use App\Models\MemberStatusChange;
use App\Models\MemberTransfer;
use App\Models\Organization;
use App\Models\PastoralCase;
use App\Services\MemberAccounts;
use App\Services\MemberRegistry;
use App\Services\MemberTransfers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Fiche d'un membre : profil, parcours, fonctions, ménage et départements. */
class Show extends Component
{
    use FindsMember;

    public Member $member;

    #[Url(as: 'onglet', except: 'profil')]
    public string $tab = 'profil';

    // Changement de statut
    public string $newStatusId = '';

    public string $statusDate = '';

    public string $statusReason = '';

    // Fonction
    public ?int $termId = null;

    public string $termFunctionId = '';

    public string $termStart = '';

    public string $termEnd = '';

    public string $termNote = '';

    // Étape de vie
    public ?int $eventId = null;

    public array $event = [];

    // Ménage
    public string $householdSearch = '';

    public string $householdRole = 'child';

    public function mount(int $id): void
    {
        $this->member = $this->findMember($id);
        $this->spacePhone = (string) $this->member->phone;
    }

    private function canManage(): bool
    {
        return Gate::allows('members.manage', $this->member->organization) && ! $this->member->organization->isReadOnly();
    }

    // ---------- Statut ----------

    public function openStatus(): void
    {
        $this->authorizeMemberWrite($this->member);
        $this->fill(['newStatusId' => (string) $this->member->status_id, 'statusDate' => now()->format('Y-m-d'), 'statusReason' => '']);
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'status');
    }

    public function changeStatus(MemberRegistry $registry): void
    {
        $this->authorizeMemberWrite($this->member);
        $this->validate([
            'newStatusId' => ['required', Rule::in($registry->statuses($this->member->organization)->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'statusDate' => 'required|date|before_or_equal:today',
            'statusReason' => 'nullable|string|max:255',
        ], attributes: ['newStatusId' => __('statut'), 'statusDate' => __('date')]);

        if ((int) $this->newStatusId !== (int) $this->member->status_id) {
            DB::transaction(function () {
                MemberStatusChange::create([
                    'member_id' => $this->member->id,
                    'from_status_id' => $this->member->status_id,
                    'to_status_id' => (int) $this->newStatusId,
                    'changed_on' => $this->statusDate,
                    'reason' => trim($this->statusReason) ?: null,
                    'user_id' => auth()->id(),
                ]);
                $this->member->update(['status_id' => (int) $this->newStatusId]);
            });
        }

        $this->dispatch('close-modal', name: 'status');
        $this->dispatch('notify', message: __('Statut mis à jour.'), type: 'success');
    }

    // ---------- Fonctions ----------

    public function openTerm(?int $id = null): void
    {
        $this->authorizeMemberWrite($this->member);
        $term = $id ? $this->member->functionTerms()->findOrFail($id) : null;
        $this->fill([
            'termId' => $term?->id,
            'termFunctionId' => (string) ($term->function_id ?? ''),
            'termStart' => $term?->started_on?->format('Y-m-d') ?? '',
            'termEnd' => $term?->ended_on?->format('Y-m-d') ?? '',
            'termNote' => $term->note ?? '',
        ]);
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'term');
    }

    public function saveTerm(MemberRegistry $registry): void
    {
        $this->authorizeMemberWrite($this->member);
        $this->validate([
            'termFunctionId' => ['required', Rule::in($registry->functions($this->member->organization)->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'termStart' => 'nullable|date',
            'termEnd' => 'nullable|date|after_or_equal:termStart',
            'termNote' => 'nullable|string|max:255',
        ], attributes: ['termFunctionId' => __('fonction'), 'termEnd' => __('date de fin'), 'termStart' => __('date de début')]);

        $term = $this->termId ? $this->member->functionTerms()->findOrFail($this->termId) : new MemberFunctionTerm(['member_id' => $this->member->id]);
        $term->fill([
            'function_id' => (int) $this->termFunctionId,
            'started_on' => $this->termStart ?: null,
            'ended_on' => $this->termEnd ?: null,
            'note' => trim($this->termNote) ?: null,
        ])->save();

        $this->dispatch('close-modal', name: 'term');
        $this->dispatch('notify', message: __('Fonction enregistrée.'), type: 'success');
    }

    public function deleteTerm(int $id): void
    {
        $this->authorizeMemberWrite($this->member);
        $this->member->functionTerms()->findOrFail($id)->delete();
        $this->dispatch('notify', message: __('Fonction retirée.'), type: 'success');
    }

    // ---------- Étapes de vie ----------

    public function openEvent(?int $id = null): void
    {
        $this->authorizeMemberWrite($this->member);
        $event = $id ? $this->member->lifeEvents()->findOrFail($id) : null;
        $this->eventId = $event?->id;
        $this->event = [
            'type' => $event->type ?? 'baptism',
            'label' => $event->label ?? '',
            'occurred_on' => $event?->occurred_on?->format('Y-m-d') ?? '',
            'place' => $event->place ?? '',
            'officiant' => $event->officiant ?? '',
            'witnesses' => $event->witnesses ?? '',
            'register_number' => $event->register_number ?? '',
            'notes' => $event->notes ?? '',
        ];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'event');
    }

    public function saveEvent(): void
    {
        $this->authorizeMemberWrite($this->member);
        $data = $this->validate([
            'event.type' => ['required', Rule::in(array_keys(LifeEvent::TYPES))],
            'event.label' => 'nullable|required_if:event.type,other|string|max:80',
            'event.occurred_on' => 'nullable|date|before_or_equal:today',
            'event.place' => 'nullable|string|max:150',
            'event.officiant' => 'nullable|string|max:120',
            'event.witnesses' => 'nullable|string|max:255',
            'event.register_number' => 'nullable|string|max:60',
            'event.notes' => 'nullable|string|max:2000',
        ], attributes: ['event.label' => __('intitulé'), 'event.occurred_on' => __('date')])['event'];

        $event = $this->eventId ? $this->member->lifeEvents()->findOrFail($this->eventId) : new LifeEvent(['member_id' => $this->member->id]);
        $event->fill(collect($data)->map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v)->all())->save();

        $this->dispatch('close-modal', name: 'event');
        $this->dispatch('notify', message: __('Étape enregistrée.'), type: 'success');
    }

    public function deleteEvent(int $id): void
    {
        $this->authorizeMemberWrite($this->member);
        $this->member->lifeEvents()->findOrFail($id)->delete();
        $this->dispatch('notify', message: __('Étape supprimée.'), type: 'success');
    }

    // ---------- Ménage ----------

    public function createHousehold(): void
    {
        $this->authorizeMemberWrite($this->member);
        abort_if($this->member->household_id, 422);

        DB::transaction(function () {
            $household = Household::create([
                'organization_id' => $this->member->organization_id,
                'name' => __('Famille :name', ['name' => $this->member->last_name]),
                ...$this->member->only(['district', 'street', 'house_number', 'city']),
                'phone' => $this->member->phone,
                'head_member_id' => $this->member->id,
            ]);
            $this->member->update(['household_id' => $household->id, 'household_role' => 'head']);
        });

        $this->redirectRoute('households.show', $this->member->household_id);
    }

    public function openJoinHousehold(): void
    {
        $this->authorizeMemberWrite($this->member);
        $this->reset('householdSearch');
        $this->householdRole = 'child';
        $this->dispatch('open-modal', name: 'household');
    }

    public function joinHousehold(int $id): void
    {
        $this->authorizeMemberWrite($this->member);
        $this->validate(['householdRole' => ['required', Rule::in(array_keys(Household::ROLES))]]);
        $household = Household::withoutOrganizationScope()->where('organization_id', $this->member->organization_id)->findOrFail($id);

        $this->member->update(['household_id' => $household->id, 'household_role' => $this->householdRole]);
        if ($this->householdRole === 'head') {
            $household->update(['head_member_id' => $this->member->id]);
        }

        $this->dispatch('close-modal', name: 'household');
        $this->dispatch('notify', message: __('Ajouté(e) au ménage :name.', ['name' => $household->name]), type: 'success');
    }

    // ---------- Suppression ----------

    public function archive(): void
    {
        $this->authorizeMemberWrite($this->member);

        DB::transaction(function () {
            Household::withoutOrganizationScope()->where('head_member_id', $this->member->id)->update(['head_member_id' => null]);
            $this->member->delete();
        });

        session()->flash('status', __('La fiche de :name a été supprimée.', ['name' => $this->member->fullName()]));
        $this->redirectRoute('members.index');
    }

    public string $transferTo = '';

    public string $transferReason = '';

    public function requestTransfer(MemberTransfers $transfers): void
    {
        abort_unless(Gate::any(['transfers.manage', 'members.manage'], $this->member->organization) && ! $this->member->organization->isReadOnly(), 403);
        $this->validate(['transferTo' => 'required|integer', 'transferReason' => 'nullable|string|max:255'], attributes: ['transferTo' => __('communauté d’accueil')]);
        try {
            $transfers->request($this->member, Organization::findOrFail($this->transferTo), $this->transferReason);
        } catch (InvalidArgumentException $e) {
            $this->addError('transferTo', $e->getMessage());

            return;
        }
        $this->transferTo = '';
        $this->transferReason = '';
        $this->dispatch('notify', message: __('Demande de transfert envoyée : la communauté d’accueil doit l’accepter.'), type: 'success');
    }

    public string $spacePhone = '';

    public ?string $spacePassword = null;

    /** Ouvre l'espace du membre : un compte à son téléphone, avec un mot de passe provisoire. */
    public function openSpace(MemberAccounts $accounts): void
    {
        abort_unless(Gate::allows('users.manage', $this->member->organization) && ! $this->member->organization->isReadOnly(), 403);
        $this->validate(['spacePhone' => 'required|string|max:30'], attributes: ['spacePhone' => __('téléphone')]);
        try {
            $result = $accounts->open($this->member, $this->spacePhone);
        } catch (InvalidArgumentException $e) {
            $this->addError('spacePhone', $e->getMessage());

            return;
        }
        $this->member->refresh();
        $this->spacePassword = $result['password'];
        $this->dispatch('notify', message: $result['password'] ? __('Espace ouvert. Communiquez le mot de passe provisoire à la personne.') : __('Espace ouvert avec le compte qui existait déjà à ce numéro.'), type: 'success');
    }

    public function render(MemberRegistry $registry)
    {
        $member = $this->member->fresh(['status', 'organization']);
        $this->member = $member;
        $organization = $member->organization;
        $canSensitive = Gate::allows('members.sensitive', $organization);
        $household = $member->household_id ? Household::withoutOrganizationScope()->with(['members' => fn ($q) => $q->withoutGlobalScope('organization')])->find($member->household_id) : null;

        $households = collect();
        if (trim($this->householdSearch) !== '') {
            $term = '%'.trim($this->householdSearch).'%';
            $households = Household::withoutOrganizationScope()->where('organization_id', $organization->id)
                ->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('district', 'like', $term))
                ->withCount('members')->orderBy('name')->limit(6)->get();
        }

        return view('livewire.members.show', [
            'organization' => $organization,
            'hidden' => $registry->settings($organization)['hidden_fields'],
            'fields' => $registry->fields($organization)->filter(fn ($f) => $canSensitive || ! $f->sensitive),
            'canSensitive' => $canSensitive,
            'canManage' => $this->canManage(),
            'statuses' => $registry->statuses($organization),
            'functions' => $registry->functions($organization),
            'terms' => $member->functionTerms()->with('function')->get(),
            'events' => $member->lifeEvents()->get(),
            'statusChanges' => $member->statusChanges()->with(['from', 'to', 'user'])->get(),
            'transferTargets' => Gate::any(['transfers.manage', 'members.manage']) ? Organization::query()->subtreeOf($organization->root())->whereKeyNot($this->member->organization_id)->orderBy('path')->get() : collect(),
            'pendingTransfer' => MemberTransfer::with('to')->where('member_id', $this->member->id)->where('status', 'pending')->first(),
            'pastoralCases' => Gate::allows('pastoral.view') ? PastoralCase::where('member_id', $member->id)->latest('opened_on')->get() : collect(),
            'departments' => $member->departments()->withoutGlobalScope('organization')->get(),
            'groups' => Group::where('leader_member_id', $member->id)->get()
                ->concat(Group::whereHas('members', fn ($q) => $q->where('members.id', $member->id))->with(['members' => fn ($q) => $q->where('members.id', $member->id)])->get()
                    ->each(fn (Group $g) => $g->setRelation('pivot', $g->members->first()?->pivot))),
            'household' => $household,
            'households' => $households,
        ])->title($member->fullName());
    }
}
