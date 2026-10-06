<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Démonstration du suivi pastoral de Himbi (malade, deuil, catéchumène,
 * nouvelle venue, une note confidentielle) et de l'espace membre de Grâce
 * Kambale, avec ses demandes.
 */
class DemoPastoral
{
    public function build(Organization $himbi, string $memberPhone, string $passwordHash, array $userFlags = []): void
    {
        app(CurrentOrganization::class)->within($himbi, function () use ($himbi, $memberPhone, $passwordHash, $userFlags) {
            $pastoral = app(Pastoral::class);
            $user = fn (string $name) => User::where('name', $name)->whereHas('roleAssignments', fn ($q) => $q->where('organization_id', $himbi->id))->firstOrFail();
            [$pasteur, $secretaire] = [$user('Pasteur Daniel Paluku'), $user('Esther Kavira')];
            $member = fn (string $last, string $first) => Member::where('last_name', $last)->where('first_name', $first)->first();
            $previous = Auth::user();
            $at = function (int $daysAgo, User $who, callable $action) {
                Carbon::setTestNow(now()->subDays($daysAgo)->setTime(16, 0));
                Auth::setUser($who);
                $result = $action();
                Carbon::setTestNow();

                return $result;
            };

            $rebecca = $at(12, $pasteur, fn () => $pastoral->open($himbi, ['member_id' => $member('MASIKA', 'Rebecca')?->id, 'kind' => 'sick',
                'title' => 'Hospitalisée à l’hôpital Heal Africa (paludisme)', 'next_on' => today()->addDays(2)->toDateString(), 'assigned_to' => $pasteur->id]));
            $at(11, $pasteur, fn () => $pastoral->addNote($rebecca, $pasteur, ['kind' => 'visit', 'happened_on' => today()->toDateString(),
                'body' => 'Visite à l’hôpital avec Maman Marthe. Fièvre en baisse ; elle demande qu’on prie pour sa petite-fille Neema, qui s’occupe d’elle.', 'next_on' => today()->addDays(4)->toDateString()], true));
            $at(6, $pasteur, fn () => $pastoral->addNote($rebecca, $pasteur, ['kind' => 'note', 'happened_on' => today()->toDateString(), 'is_confidential' => true,
                'body' => 'Elle m’a confié des difficultés pour payer les soins. Voir discrètement avec la diaconie, sans en parler au conseil.', 'next_on' => today()->subDay()->toDateString()], true));

            $deuil = $at(20, $pasteur, fn () => $pastoral->open($himbi, ['member_id' => $member('PALUKU', 'Samuel')?->id, 'kind' => 'bereavement',
                'title' => 'Décès de son frère à Butembo', 'next_on' => today()->addDays(5)->toDateString(), 'assigned_to' => $pasteur->id]));
            $at(19, $pasteur, fn () => $pastoral->addNote($deuil, $pasteur, ['kind' => 'visit', 'happened_on' => today()->toDateString(),
                'body' => 'Veillée chez la famille avec la chorale. Les obsèques auront lieu à Butembo ; la paroisse participe au transport.', 'next_on' => today()->addDays(24)->toDateString()], true));

            $at(30, $pasteur, fn () => $pastoral->open($himbi, ['member_id' => $member('MASIKA', 'Neema')?->id, 'kind' => 'catechumen',
                'title' => 'Préparation au baptême de décembre', 'next_on' => today()->addDays(4)->toDateString(), 'assigned_to' => $pasteur->id]));
            $at(2, $secretaire, fn () => $pastoral->open($himbi, ['person_name' => 'Jeanne Furaha', 'person_phone' => '+243991223344', 'kind' => 'newcomer',
                'title' => 'Venue au culte, invitée par Esther Kahindo', 'next_on' => today()->addDays(3)->toDateString(), 'assigned_to' => $pasteur->id]));

            $at(3, $secretaire, fn () => $pastoral->pray($himbi, ['requester_name' => 'Maman Divine Masika', 'subject' => 'Un travail pour son mari',
                'body' => 'Il cherche du travail depuis huit mois.']));
            $answered = $at(9, $secretaire, fn () => $pastoral->pray($himbi, ['member_id' => $member('KAVIRA', 'Bénédicte')?->id, 'subject' => 'Examen d’État de son fils Eliel']));
            $at(5, $pasteur, fn () => $pastoral->answer($answered, 'Nous avons prié pour Eliel dimanche. Courage à lui !'));

            // L'espace membre de Grâce Kambale : sa carte, ses dons, ses demandes.
            $grace = $member('KAMBALE', 'Grâce');
            if ($grace) {
                Auth::setUser($secretaire);
                $account = app(MemberAccounts::class)->open($grace, $memberPhone)['user'];
                $account->forceFill(['password' => $passwordHash, 'must_change_password' => false] + $userFlags)->save();
                $at(1, $account, fn () => $pastoral->pray($himbi, ['member_id' => $grace->id, 'subject' => 'Mon voyage pour les études à Kinshasa']));
                $type = app(DocumentTypes::class)->available($himbi)->firstWhere('key', 'membership');
                $at(1, $account, fn () => app(MemberAccounts::class)->requestDocument($grace, $type, 'Inscription à l’université de Kinshasa'));
                $convention = Event::where('title', 'like', 'Convention des jeunes%')->first();
                if ($convention && ! $convention->registrations()->where('member_id', $grace->id)->exists()) {
                    Auth::setUser($account);
                    app(Calendar::class)->register($convention, $convention->starts_on, $grace->id);
                }
            }

            $previous ? Auth::setUser($previous) : Auth::logout();
        });
    }
}
