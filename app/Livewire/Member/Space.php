<?php

namespace App\Livewire\Member;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Announcement;
use App\Models\DocumentRequest;
use App\Models\EventRegistration;
use App\Models\FinanceTransaction;
use App\Models\Group;
use App\Models\Pledge;
use App\Models\PrayerRequest;
use App\Services\Calendar;
use App\Services\DocumentTypes;
use App\Services\MemberAccounts;
use App\Services\Pastoral;
use App\Services\Pledges;
use App\Support\AnnouncementAccess;
use App\Support\DepartmentScope;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;
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
            'canWrite' => ! $organization->isReadOnly(),
        ]);
    }
}
