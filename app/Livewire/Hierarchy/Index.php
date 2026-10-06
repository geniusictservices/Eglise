<?php

namespace App\Livewire\Hierarchy;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\AttachmentRequest;
use App\Models\Organization;
use App\Services\AuditLogger;
use App\Services\MemberRegistry;
use App\Services\OrganizationProvisioner;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Hiérarchie')]
class Index extends Component
{
    use WritesInOrganization;

    // Ajout d'un niveau
    public ?int $parentId = null;

    public string $name = '';

    public string $levelLabel = '';

    public string $city = '';

    // Demande de rattachement
    public string $targetSlug = '';

    public string $requestMessage = '';

    public function mount(): void
    {
        $this->authorize('organization.view');
    }

    public function startCreate(?int $parentId = null): void
    {
        $this->authorizeWrite('organization.hierarchy');
        $this->parentId = $parentId ?? $this->organization()->id;
        $parent = $this->subtree()->firstWhere('id', $this->parentId);
        $this->levelLabel = $this->suggestLevel($parent);
        $this->reset('name', 'city');
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'create-level');
    }

    public function create(OrganizationProvisioner $provisioner): void
    {
        $this->authorizeWrite('organization.hierarchy');

        $this->validate([
            'name' => 'required|string|max:150',
            'levelLabel' => 'required|string|max:60',
            'city' => 'nullable|string|max:100',
        ], attributes: ['name' => __('nom'), 'levelLabel' => __('niveau')]);

        $parent = $this->subtree()->firstWhere('id', $this->parentId) ?? abort(404);

        $provisioner->createChild($parent, [
            'name' => $this->name,
            'level_label' => $this->levelLabel,
            'city' => $this->city ?: null,
        ]);

        $this->dispatch('close-modal', name: 'create-level');
        $this->notify(__(':level « :name » ajouté.', ['level' => $this->levelLabel, 'name' => $this->name]));
        $this->reset('name', 'city');
    }

    public function requestAttachment(): void
    {
        $organization = $this->organization();
        $this->authorizeWrite('organization.hierarchy');
        abort_unless($organization->isRoot(), 403);

        $this->validate(['targetSlug' => 'required|string'], attributes: ['targetSlug' => __('code')]);

        $target = Organization::where('slug', trim($this->targetSlug))->first();

        if (! $target || $target->isSelfOrDescendantOf($organization)) {
            $this->addError('targetSlug', __('Aucune communauté ne correspond à ce code.'));

            return;
        }

        AttachmentRequest::create([
            'organization_id' => $organization->id,
            'target_id' => $target->id,
            'message' => $this->requestMessage ?: null,
            'requested_by' => auth()->id(),
        ]);

        $this->reset('targetSlug', 'requestMessage');
        $this->dispatch('close-modal', name: 'attach');
        $this->notify(__('Demande envoyée à :name.', ['name' => $target->name]));
    }

    public function decide(int $requestId, bool $accept): void
    {
        $organization = $this->organization();
        $request = AttachmentRequest::with(['organization', 'target'])->where('status', 'pending')->findOrFail($requestId);
        abort_unless($request->target->isSelfOrDescendantOf($organization), 404);
        $this->authorizeWrite('organization.hierarchy', $request->target);

        DB::transaction(function () use ($request, $accept) {
            $request->update(['status' => $accept ? 'accepted' : 'refused', 'decided_by' => auth()->id(), 'decided_at' => now()]);

            if ($accept) {
                $request->organization->moveUnder($request->target);
                app(MemberRegistry::class)->harmonize($request->organization->fresh());
                app(AuditLogger::class)->record('attached', $request->organization, [], ['parent_id' => $request->target_id],
                    __(':child a rejoint :parent', ['child' => $request->organization->name, 'parent' => $request->target->name]), $request->target_id);
            }
        });

        $this->notify($accept
            ? __(':name fait maintenant partie de votre hiérarchie.', ['name' => $request->organization->name])
            : __('Demande refusée.'));
    }

    private function subtree()
    {
        return Organization::query()->subtreeOf($this->organization())->orderBy('depth')->orderBy('name')->get();
    }

    private function suggestLevel(?Organization $parent): string
    {
        $existing = $parent?->children()->value('level_label');

        if ($existing) {
            return $existing;
        }

        $suggestions = config('waumini.level_suggestions');
        $index = array_search($parent?->level_label, $suggestions, true);

        return $index === false ? __('Paroisse') : ($suggestions[$index + 1] ?? __('Annexe'));
    }

    public function render()
    {
        $organization = $this->organization();
        $nodes = $this->subtree();
        $byParent = $nodes->groupBy('parent_id');

        return view('livewire.hierarchy.index', [
            'organization' => $organization,
            'root' => $nodes->firstWhere('id', $organization->id),
            'byParent' => $byParent,
            'ancestors' => $organization->ancestors(),
            'nodeCount' => $nodes->count(),
            'incoming' => AttachmentRequest::with(['organization', 'requester'])
                ->where('status', 'pending')
                ->whereIn('target_id', $nodes->pluck('id'))
                ->latest()->get(),
            'outgoing' => AttachmentRequest::with('target')
                ->where('organization_id', $organization->id)
                ->latest()->limit(5)->get(),
            'levelSuggestions' => config('waumini.level_suggestions'),
            'canManage' => auth()->user()->can('organization.hierarchy') && ! $organization->isReadOnly(),
        ]);
    }
}
