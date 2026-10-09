<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Organization;
use App\Models\PastoralCase;
use App\Models\PastoralNote;
use App\Models\PrayerRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Le suivi pastoral : les personnes accompagnées, leurs visites et notes, les demandes de prière, les anniversaires. */
class Pastoral
{
    public function __construct(private Notifier $notifier) {}

    public function open(Organization $organization, array $data): PastoralCase
    {
        $member = ($data['member_id'] ?? null) ? Member::withoutOrganizationScope()->where('organization_id', $organization->id)->find($data['member_id']) : null;
        if (! $member && trim((string) ($data['person_name'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Choisissez le membre, ou écrivez le nom de la personne.'));
        }
        $case = PastoralCase::create([
            'organization_id' => $organization->id, 'member_id' => $member?->id,
            'person_name' => $member ? null : trim($data['person_name']), 'person_phone' => $member ? null : (trim((string) ($data['person_phone'] ?? '')) ?: null),
            'kind' => array_key_exists($data['kind'] ?? '', PastoralCase::KINDS) ? $data['kind'] : 'other', 'title' => trim($data['title']),
            'opened_on' => today()->toDateString(), 'next_on' => ($data['next_on'] ?? null) ?: null, 'source' => ($data['source'] ?? 'app') === 'website' ? 'website' : 'app',
            'assigned_to' => ($data['assigned_to'] ?? null) ?: null, 'created_by' => auth()->id(),
        ]);
        $this->announce($case);

        return $case;
    }

    public function assign(PastoralCase $case, ?int $userId): void
    {
        $case->update(['assigned_to' => $userId]);
        $this->announce($case);
    }

    /** Une visite, un appel ou une note ; seule une personne autorisée écrit une note confidentielle. */
    public function addNote(PastoralCase $case, User $author, array $data, bool $mayBeConfidential): PastoralNote
    {
        if (trim((string) ($data['body'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Écrivez la note.'));
        }

        return DB::transaction(function () use ($case, $author, $data, $mayBeConfidential) {
            $note = PastoralNote::create([
                'pastoral_case_id' => $case->id, 'author_id' => $author->id,
                'kind' => array_key_exists($data['kind'] ?? '', PastoralNote::KINDS) ? $data['kind'] : 'note',
                'happened_on' => ($data['happened_on'] ?? null) ?: today()->toDateString(), 'body' => trim($data['body']),
                'is_confidential' => $mayBeConfidential && (bool) ($data['is_confidential'] ?? false),
            ]);
            $case->update(['next_on' => ($data['next_on'] ?? null) ?: null]);
            $this->notifier->settle("pastoral.{$case->id}.due");

            return $note;
        });
    }

    public function close(PastoralCase $case): void
    {
        $case->update(['status' => 'closed', 'closed_on' => today(), 'next_on' => null]);
        $this->notifier->settle("pastoral.{$case->id}.*");
    }

    public function reopen(PastoralCase $case): void
    {
        $case->update(['status' => 'open', 'closed_on' => null]);
    }

    // Demandes de prière -------------------------------------------------------------

    public function pray(Organization $organization, array $data): PrayerRequest
    {
        $member = ($data['member_id'] ?? null) ? Member::withoutOrganizationScope()->where('organization_id', $organization->id)->find($data['member_id']) : null;
        if (trim((string) ($data['subject'] ?? '')) === '' || (! $member && trim((string) ($data['requester_name'] ?? '')) === '')) {
            throw new InvalidArgumentException(__('Indiquez le sujet de prière et la personne qui le confie.'));
        }
        $request = PrayerRequest::create([
            'organization_id' => $organization->id, 'member_id' => $member?->id,
            'requester_name' => $member ? null : trim($data['requester_name']), 'subject' => trim($data['subject']),
            'requester_phone' => $member ? null : (trim((string) ($data['requester_phone'] ?? '')) ?: null),
            'body' => trim((string) ($data['body'] ?? '')) ?: null, 'is_private' => (bool) ($data['is_private'] ?? true), 'created_by' => auth()->id(),
            'source' => ($data['source'] ?? 'app') === 'website' ? 'website' : 'app',
        ]);
        $this->notifier->send($organization, $this->notifier->withPermission($organization, 'pastoral.view'), "prayer.{$request->id}", [
            'title' => __('Demande de prière : :s', ['s' => $request->subject]), 'body' => $request->requesterName(),
            'url' => route('pastoral.index', ['onglet' => 'priere']), 'icon' => 'heart-handshake']);

        return $request;
    }

    /** L'équipe a prié ; un mot peut être envoyé à la personne si elle a un compte. */
    public function answer(PrayerRequest $request, ?string $answer): void
    {
        $request->update(['status' => 'answered', 'answer' => trim((string) $answer) ?: null, 'handled_by' => auth()->id(), 'handled_at' => now()]);
        $this->notifier->settle("prayer.{$request->id}");
        if ($request->member?->user_id && $request->answer) {
            $this->notifier->send($request->organization()->firstOrFail(), $request->member->user_id, "prayer.{$request->id}.answer", [
                'title' => __('Nous avons prié pour vous'), 'body' => $request->answer, 'url' => route('member.space'), 'icon' => 'heart-handshake']);
        }
    }

    // Anniversaires --------------------------------------------------------------------

    /**
     * Les membres dont l'anniversaire tombe entre deux dates (sur moins d'un an).
     *
     * @return Collection<int, array{member: Member, date: Carbon, age: int}>
     */
    public function birthdays(Organization $organization, Carbon $from, Carbon $to): Collection
    {
        return Member::withoutOrganizationScope()->where('organization_id', $organization->id)->whereNotNull('birth_date')
            ->where(fn ($q) => $q->whereNull('status_id')->orWhereHas('status', fn ($q) => $q->where('counts_as_member', true)))->get()
            ->map(function (Member $m) use ($from) {
                $next = $m->birth_date->copy()->year($from->year);
                if ($m->birth_date->format('m-d') === '02-29' && ! $next->isLeapYear()) {
                    $next = $next->setDate($from->year, 2, 28);
                }
                if ($next->lt($from->copy()->startOfDay())) {
                    // L'an prochain : un 29 février retrouve son jour quand l'année est bissextile.
                    $year = $from->year + 1;
                    $next = $m->birth_date->format('m-d') === '02-29'
                        ? $next->setDate($year, 2, Carbon::create($year)->isLeapYear() ? 29 : 28)
                        : $next->addYear();
                }

                // L'âge en années révolues ce jour-là (un 29 février fêté le 28 compte aussi).
                return ['member' => $m, 'date' => $next, 'age' => $next->year - $m->birth_date->year];
            })
            ->filter(fn ($b) => $b['date']->lte($to))->sortBy(fn ($b) => $b['date']->format('Y-m-d').$b['member']->last_name)->values();
    }

    private function announce(PastoralCase $case): void
    {
        if (! $case->assigned_to) {
            return;
        }
        $this->notifier->send($case->organization()->firstOrFail(), $case->assigned_to, "pastoral.{$case->id}.assigned", [
            'title' => __('Suivi confié : :p', ['p' => $case->personName()]), 'body' => __(PastoralCase::KINDS[$case->kind]).' · '.$case->title,
            'url' => route('pastoral.show', $case), 'icon' => PastoralCase::ICONS[$case->kind]]);
    }
}
