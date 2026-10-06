<?php

namespace App\Livewire\Member;

use App\Livewire\Concerns\WritesInOrganization;
use App\Livewire\Finances\Declarations\Index as Declarations;
use App\Models\Announcement;
use App\Models\CashAccount;
use App\Models\DocumentRequest;
use App\Models\EventRegistration;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Group;
use App\Models\PaymentDeclaration;
use App\Models\Pledge;
use App\Models\PrayerRequest;
use App\Services\Calendar;
use App\Services\CircuitNotices;
use App\Services\DocumentTypes;
use App\Services\MemberAccounts;
use App\Services\Pastoral;
use App\Services\Pledges;
use App\Support\AnnouncementAccess;
use App\Support\DepartmentScope;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Component;

/** L'espace de chaque membre : sa fiche, sa carte, ses dons et reçus, ses promesses, le programme, les annonces, ses demandes. */
#[Title('Mon espace')]
class Space extends Component
{
    use WritesInOrganization;

    public array $prayer = ['subject' => '', 'body' => ''];

    public array $request = ['type' => '', 'message' => ''];

    public array $gift = [];

    public function mount(): void
    {
        abort_unless(Gate::any(['member.space', 'organization.view']), 403);
    }

    private function member()
    {
        return DepartmentScope::member(auth()->user(), $this->organization());
    }

    public function askPrayer(Pastoral $pastoral): void
    {
        abort_if($this->organization()->isReadOnly(), 403);
        $member = $this->member() ?? abort(403);
        $this->validate(['prayer.subject' => 'required|string|max:160', 'prayer.body' => 'nullable|string|max:2000'], attributes: ['prayer.subject' => __('sujet')]);
        $pastoral->pray($this->organization(), $this->prayer + ['member_id' => $member->id, 'is_private' => true]);
        $this->prayer = ['subject' => '', 'body' => ''];
        $this->dispatch('close-modal', name: 'prayer');
        $this->notify(__('Votre demande est confiée à l’équipe pastorale. Elle reste confidentielle.'));
    }

    public function askDocument(MemberAccounts $accounts, DocumentTypes $types): void
    {
        abort_if($this->organization()->isReadOnly(), 403);
        $member = $this->member() ?? abort(403);
        $this->validate(['request.type' => 'required|integer', 'request.message' => 'nullable|string|max:500'], attributes: ['request.type' => __('document')]);
        $type = $this->requestable($types)->firstWhere('id', (int) $this->request['type']) ?? abort(422);
        try {
            $accounts->requestDocument($member, $type, $this->request['message']);
        } catch (InvalidArgumentException $e) {
            $this->addError('request.type', $e->getMessage());

            return;
        }
        $this->request = ['type' => '', 'message' => ''];
        $this->dispatch('close-modal', name: 'document');
        $this->notify(__('Demande envoyée au secrétariat. Vous serez prévenu quand le document sera prêt.'));
    }

    public function openGift(): void
    {
        $this->gift = ['amount' => '', 'currency' => 'USD', 'operator' => Declarations::OPERATORS[0], 'reference' => '', 'paid_on' => today()->toDateString(), 'category_id' => '', 'pledge_id' => '', 'message' => ''];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'gift');
    }

    /** Le membre déclare un don envoyé par mobile money ; la finance le vérifie, puis le valide ou le rejette. */
    public function declareGift(CircuitNotices $notices): void
    {
        abort_if($this->organization()->isReadOnly(), 403);
        $member = $this->member() ?? abort(403);
        $this->validate([
            'gift.amount' => 'required|numeric|min:0.01|max:100000000', 'gift.currency' => ['required', Rule::in(['USD', 'CDF'])],
            'gift.operator' => ['required', Rule::in(Declarations::OPERATORS)], 'gift.reference' => 'required|string|max:100',
            'gift.paid_on' => 'required|date|before_or_equal:today|after:-60 days', 'gift.message' => 'nullable|string|max:255',
        ], attributes: ['gift.amount' => __('montant'), 'gift.reference' => __('ID de la transaction'), 'gift.paid_on' => __('date')]);
        $pledge = $this->gift['pledge_id'] ? Pledge::where('member_id', $member->id)->find((int) $this->gift['pledge_id']) : null;
        $category = $this->gift['category_id'] ? FinanceCategory::where('type', 'income')->find((int) $this->gift['category_id']) : null;
        $declaration = PaymentDeclaration::create([
            'member_id' => $member->id, 'declarant_name' => $member->fullName(), 'declarant_phone' => $member->phone,
            'amount' => $this->gift['amount'], 'currency' => $pledge?->currency ?? $this->gift['currency'], 'operator' => $this->gift['operator'],
            'transaction_reference' => $this->gift['reference'], 'paid_on' => $this->gift['paid_on'],
            'category_id' => $pledge ? null : $category?->id, 'pledge_id' => $pledge?->id,
            'message' => trim((string) $this->gift['message']) ?: null, 'source' => 'member', 'created_by' => auth()->id(),
        ]);
        $notices->declarationReceived($declaration);
        $this->dispatch('close-modal', name: 'gift');
        $this->notify(__('Don déclaré : la trésorerie va le vérifier avec l’ID de la transaction.'));
    }

    /** Les documents qu'un membre peut demander pour lui-même. */
    private function requestable(DocumentTypes $types)
    {
        return $types->available($this->organization())->whereIn('subject', ['member', 'entry'])->reject(fn ($t) => $t->customFields() !== [] && collect($t->customFields())->contains('required', true))->values();
    }

    public function render(Calendar $calendar, Pledges $pledges, DocumentTypes $types)
    {
        $organization = $this->organization();
        $member = $this->member();
        if (! $member) {
            return view('livewire.member.space', ['member' => null]);
        }
        $member->loadMissing(['status', 'household']);
        $groups = Group::where('leader_member_id', $member->id)->orWhereHas('members', fn ($q) => $q->where('members.id', $member->id))->pluck('id')->all();
        $departments = $member->departments()->pluck('departments.id')->all();

        $gifts = FinanceTransaction::where('member_id', $member->id)->where('type', 'income')->whereNull('cancelled_at')
            ->with('category')->latest('occurred_on')->latest('id')->limit(30)->get();
        $year = $gifts->filter(fn ($t) => $t->occurred_on->year === now()->year)->groupBy('currency')
            ->map(fn ($ts, $currency) => Money::format($ts->sum('amount'), $currency))->values();

        return view('livewire.member.space', [
            'member' => $member,
            'departments' => $member->departments()->get(),
            'groups' => Group::whereIn('id', $groups)->get(),
            'gifts' => $gifts,
            'yearTotals' => $year,
            'pledges' => Pledge::where('member_id', $member->id)->where('status', '!=', 'cancelled')->latest('pledged_on')->get()
                ->map(fn (Pledge $p) => ['pledge' => $p, 'progress' => $pledges->progress($p)]),
            'agenda' => $calendar->agenda($organization, today(), today()->addDays(14))
                ->filter(fn ($o) => $o['event']->audience === 'all' || in_array($o['event']->department_id, $departments, true) || in_array($o['event']->group_id, $groups, true))
                ->take(8),
            'registrations' => EventRegistration::with('event')->where('member_id', $member->id)->whereDate('occurs_on', '>=', today())->orderBy('occurs_on')->get(),
            'announcements' => Announcement::current()->where(AnnouncementAccess::visibleQuery(auth()->user(), $organization))->orderByDesc('pinned')->latest('published_at')->limit(4)->get(),
            'prayers' => PrayerRequest::where('member_id', $member->id)->latest()->limit(5)->get(),
            'requests' => DocumentRequest::with('type')->where('member_id', $member->id)->latest()->limit(5)->get(),
            'requestable' => $this->requestable($types),
            'declarations' => PaymentDeclaration::where('member_id', $member->id)->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($q) => $q->where('status', 'rejected')->where('reviewed_at', '>=', now()->subDays(30))))->latest()->get(),
            'mobileAccounts' => CashAccount::where('is_active', true)->where('kind', 'mobile')->whereNotNull('account_number')->orderBy('position')->get(),
            'giftCategories' => FinanceCategory::where('type', 'income')->where('nature', 'personal')->orderBy('position')->get(),
            'operators' => Declarations::OPERATORS,
            'canWrite' => ! $organization->isReadOnly(),
        ]);
    }
}
