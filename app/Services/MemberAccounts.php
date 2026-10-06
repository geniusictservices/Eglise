<?php

namespace App\Services;

use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\IssuedDocument;
use App\Models\Member;
use App\Models\Role;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** L'espace membre : ouvrir le compte d'un membre, et ses demandes d'attestation. */
class MemberAccounts
{
    public function __construct(private OrganizationProvisioner $provisioner, private Notifier $notifier) {}

    /**
     * Ouvre l'espace d'un membre : un compte à son numéro de téléphone, avec un
     * mot de passe provisoire à changer à la première connexion.
     *
     * @return array{user: User, password: ?string}
     */
    public function open(Member $member, string $phone): array
    {
        $phone = Phone::normalize($phone) ?? throw new InvalidArgumentException(__('Ce numéro de téléphone n’est pas valable.'));
        if ($member->user_id) {
            throw new InvalidArgumentException(__('Ce membre a déjà son espace.'));
        }
        $organization = $member->organization()->firstOrFail();
        $existing = User::where('phone', $phone)->first();
        if ($existing && Member::withoutOrganizationScope()->where('organization_id', $organization->id)->where('user_id', $existing->id)->exists()) {
            throw new InvalidArgumentException(__('Ce numéro est déjà celui d’une autre fiche de la communauté.'));
        }

        return DB::transaction(function () use ($member, $phone, $organization, $existing) {
            $password = null;
            $user = $existing;
            if (! $user) {
                $password = Str::upper(Str::random(3)).'-'.random_int(1000, 9999);
                $user = User::create(['name' => $member->fullName(), 'phone' => $phone, 'password' => $password, 'must_change_password' => true,
                    'locale' => $member->preferred_language ?: 'fr']);
            }
            $root = $organization->root();
            $role = Role::where('organization_id', $root->id)->where('key', 'membre')->first()
                ?? Role::create(['organization_id' => $root->id, 'key' => 'membre', 'name' => 'Membre', 'permissions' => ['member.space'],
                    'description' => config('waumini.role_templates.membre.description')]);
            if (! $user->canAccess($organization)) {
                $this->provisioner->assign($user, $role, $organization);
            }
            $member->forceFill(['user_id' => $user->id])->save();

            return ['user' => $user, 'password' => $password];
        });
    }

    public function requestDocument(Member $member, DocumentType $type, ?string $message): DocumentRequest
    {
        if (DocumentRequest::withoutOrganizationScope()->where('member_id', $member->id)->where('document_type_id', $type->id)->where('status', 'pending')->exists()) {
            throw new InvalidArgumentException(__('Vous avez déjà demandé ce document : le secrétariat le prépare.'));
        }
        $organization = $member->organization()->firstOrFail();
        $request = DocumentRequest::create(['organization_id' => $organization->id, 'member_id' => $member->id, 'document_type_id' => $type->id,
            'message' => trim((string) $message) ?: null, 'requested_by' => auth()->id()]);
        $this->notifier->send($organization, $this->notifier->withPermission($organization, 'documents.issue'), "docrequest.{$request->id}", [
            'title' => __('Demande d’attestation : :t', ['t' => $type->name]), 'body' => $member->fullName().($request->message ? ' · '.$request->message : ''),
            'url' => route('documents.index'), 'icon' => 'file-text']);

        return $request;
    }

    public function fulfil(DocumentRequest $request, IssuedDocument $document): void
    {
        $request->update(['status' => 'issued', 'issued_document_id' => $document->id, 'handled_by' => auth()->id(), 'handled_at' => now()]);
        $this->notifier->settle("docrequest.{$request->id}");
        $this->tell($request, __('Votre :t est prête', ['t' => mb_strtolower($request->type->name)]), __('Passez la retirer au secrétariat, signée et cachetée.'));
    }

    public function refuse(DocumentRequest $request, string $reason): void
    {
        $request->update(['status' => 'refused', 'refusal_reason' => $reason, 'handled_by' => auth()->id(), 'handled_at' => now()]);
        $this->notifier->settle("docrequest.{$request->id}");
        $this->tell($request, __('Votre demande de :t n’a pas abouti', ['t' => mb_strtolower($request->type->name)]), $reason);
    }

    private function tell(DocumentRequest $request, string $title, string $body): void
    {
        $request->loadMissing(['member', 'type']);
        if ($request->member?->user_id) {
            $this->notifier->send($request->organization()->firstOrFail(), $request->member->user_id, "docrequest.{$request->id}.result",
                ['title' => $title, 'body' => $body, 'url' => route('member.space'), 'icon' => 'file-text']);
        }
    }
}
