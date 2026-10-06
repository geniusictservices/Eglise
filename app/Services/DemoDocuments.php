<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/** Démonstration des documents délivrés à Himbi : attestations, lettre, ordre de mission, une annulation. */
class DemoDocuments
{
    public function build(Organization $himbi): void
    {
        app(CurrentOrganization::class)->within($himbi, function () use ($himbi) {
            $documents = app(Documents::class);
            $types = app(DocumentTypes::class)->available($himbi)->keyBy('key');
            $previous = Auth::user();
            Auth::setUser(User::where('name', 'Esther Kavira')->whereHas('roleAssignments', fn ($q) => $q->where('organization_id', $himbi->id))->firstOrFail());
            $member = fn (string $last, string $first) => Member::where('last_name', $last)->where('first_name', $first)->first();
            $sign = ['signatory' => 'Daniel Paluku', 'signatory_title' => 'Pasteur'];

            $issue = function (int $daysAgo, string $key, array $data) use ($documents, $types, $himbi, $sign) {
                Carbon::setTestNow(now()->subDays($daysAgo)->setTime(10, 15));
                $document = $documents->issue($himbi, $types[$key], $data + $sign + ['issued_on' => today()->toDateString()]);
                Carbon::setTestNow();

                return $document;
            };

            $jeanPaul = $member('KAMBALE', 'Jean-Paul');
            $esther = $member('KAHINDO', 'Esther');
            $samuel = $member('PALUKU', 'Samuel');
            $wrong = $issue(40, 'membership', ['member_id' => $esther?->id]);
            $documents->cancel($wrong, 'Erreur sur la date d’adhésion, corrigée dans la fiche');
            $issue(39, 'membership', ['member_id' => $esther?->id]);
            $issue(25, 'baptism', ['member_id' => $jeanPaul?->id]);
            $issue(12, 'recommendation', ['member_id' => $member('KAVIRA', 'Bénédicte')?->id, 'fields' => ['destinataire' => 'la CEP Kadutu, Bukavu']]);
            $issue(6, 'mission', ['member_id' => $samuel?->id, 'fields' => ['destination' => 'Bukavu', 'objet' => 'représenter la paroisse au synode régional',
                'du' => today()->addDays(10)->toDateString(), 'au' => today()->addDays(13)->toDateString()]]);
            $issue(2, 'letter', ['beneficiary' => 'Monsieur le Bourgmestre de la commune de Goma', 'fields' => ['objet' => 'Demande d’autorisation pour la convention des jeunes',
                'message' => "Monsieur le Bourgmestre,\n\nNous avons l’honneur de solliciter votre autorisation pour la convention des jeunes de notre paroisse, au stade de l’Unité, à la fin du mois.\n\nNous vous prions d’agréer l’expression de notre haute considération."]]);

            $previous ? Auth::setUser($previous) : Auth::logout();
        });
    }
}
