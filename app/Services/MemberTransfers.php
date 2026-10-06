<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Member;
use App\Models\MemberStatusChange;
use App\Models\MemberTransfer;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Le transfert d'un membre entre deux niveaux d'une même dénomination : la
 * paroisse de départ le demande, celle d'arrivée l'accepte. La fiche part
 * avec son parcours ; elle reçoit un nouveau numéro, l'ancien est gardé.
 */
class MemberTransfers
{
    public function __construct(private MemberRegistry $registry, private Notifier $notifier) {}

    public function request(Member $member, Organization $to, ?string $reason): MemberTransfer
    {
        $from = $member->organization()->firstOrFail();
        if ($to->id === $from->id) {
            throw new InvalidArgumentException(__('Choisissez une autre communauté que la sienne.'));
        }
        if ($to->root()->id !== $from->root()->id) {
            throw new InvalidArgumentException(__('Un transfert se fait au sein de la même dénomination.'));
        }
        if (MemberTransfer::where('member_id', $member->id)->where('status', 'pending')->exists()) {
            throw new InvalidArgumentException(__('Un transfert de ce membre attend déjà une réponse.'));
        }
        $transfer = MemberTransfer::create(['member_id' => $member->id, 'from_organization_id' => $from->id, 'to_organization_id' => $to->id,
            'reason' => trim((string) $reason) ?: null, 'old_number' => $member->number, 'requested_by' => auth()->id()]);
        $this->notifier->send($to, $this->recipients($to), "transfer.{$transfer->id}", [
            'title' => __('Transfert à accepter : :n', ['n' => $member->fullName()]), 'body' => __('Depuis :f', ['f' => $from->name]).($transfer->reason ? ' · '.$transfer->reason : ''),
            'url' => route('transfers.index'), 'icon' => 'arrow-left-right']);

        return $transfer;
    }

    public function accept(MemberTransfer $transfer, User $by): Member
    {
        $this->expectPending($transfer);
        $member = Member::withoutOrganizationScope()->findOrFail($transfer->member_id);
        $from = Organization::findOrFail($transfer->from_organization_id);
        $to = Organization::findOrFail($transfer->to_organization_id);
        if ($group = Group::withoutOrganizationScope()->where('leader_member_id', $member->id)->first()) {
            throw new InvalidArgumentException(__(':n est responsable du groupe « :g » : la paroisse de départ doit d’abord lui donner un successeur.', ['n' => $member->fullName(), 'g' => $group->name]));
        }

        return DB::transaction(function () use ($transfer, $member, $from, $to, $by) {
            // Les départements, les groupes et le ménage sont propres à la paroisse de départ.
            $member->departments()->detach();
            DB::table('group_members')->where('member_id', $member->id)->delete();
            $member->forceFill(['organization_id' => $to->id, 'household_id' => null, 'household_role' => null])->save();
            $member->setRelation('organization', $to);
            $this->registry->assignNumber($member, now());
            MemberStatusChange::create(['member_id' => $member->id, 'from_status_id' => $member->status_id, 'to_status_id' => $member->status_id,
                'changed_on' => today(), 'reason' => __('Transféré(e) depuis :f (ancien n° :n)', ['f' => $from->name, 'n' => $transfer->old_number ?? '—'])]);
            // Son espace membre le suit dans sa nouvelle communauté.
            if ($member->user_id) {
                RoleAssignment::where('user_id', $member->user_id)->where('organization_id', $from->id)
                    ->whereHas('role', fn ($q) => $q->where('key', 'membre'))->update(['organization_id' => $to->id]);
                User::find($member->user_id)?->forceFill(['current_organization_id' => $to->id])->saveQuietly();
            }
            $transfer->update(['status' => 'accepted', 'new_number' => $member->number, 'decided_by' => $by->id, 'decided_at' => now()]);
            $this->notifier->settle("transfer.{$transfer->id}");
            $this->tellOrigin($transfer, __('Transfert accepté : :n', ['n' => $member->fullName()]), __('Accueilli(e) à :t sous le n° :n', ['t' => $to->name, 'n' => $member->number]));

            return $member;
        });
    }

    public function refuse(MemberTransfer $transfer, User $by, string $note): void
    {
        $this->expectPending($transfer);
        $transfer->update(['status' => 'refused', 'decision_note' => $note, 'decided_by' => $by->id, 'decided_at' => now()]);
        $this->notifier->settle("transfer.{$transfer->id}");
        $transfer->loadMissing('member');
        $this->tellOrigin($transfer, __('Transfert refusé : :n', ['n' => $transfer->member->fullName()]), $note);
    }

    public function cancel(MemberTransfer $transfer): void
    {
        $this->expectPending($transfer);
        $transfer->update(['status' => 'cancelled']);
        $this->notifier->settle("transfer.{$transfer->id}");
    }

    private function recipients(Organization $organization)
    {
        return $this->notifier->withPermission($organization, 'transfers.manage')->merge($this->notifier->withPermission($organization, 'members.manage'))->unique('id');
    }

    private function tellOrigin(MemberTransfer $transfer, string $title, string $body): void
    {
        $from = Organization::findOrFail($transfer->from_organization_id);
        $this->notifier->send($from, $transfer->requested_by, "transfer.{$transfer->id}.result", ['title' => $title, 'body' => $body, 'url' => route('transfers.index'), 'icon' => 'arrow-left-right']);
    }

    private function expectPending(MemberTransfer $transfer): void
    {
        if ($transfer->status !== 'pending') {
            throw new InvalidArgumentException(__('Ce transfert n’attend plus de réponse.'));
        }
    }
}
