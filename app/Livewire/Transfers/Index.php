<?php

namespace App\Livewire\Transfers;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\MemberTransfer;
use App\Services\MemberTransfers;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Les transferts de membres : à accepter, envoyés, et l'historique. */
#[Title('Transferts de membres')]
class Index extends Component
{
    use WritesInOrganization;

    public ?int $refusingId = null;

    public string $note = '';

    public function mount(): void
    {
        abort_unless(Gate::any(['transfers.manage', 'members.manage']), 403);
    }

    private function authorizeAct(): void
    {
        abort_if($this->organization()->isReadOnly(), 403);
        abort_unless(Gate::any(['transfers.manage', 'members.manage']), 403);
    }

    public function accept(MemberTransfers $transfers, int $id): void
    {
        $this->authorizeAct();
        try {
            $member = $transfers->accept(MemberTransfer::where('to_organization_id', $this->organization()->id)->findOrFail($id), auth()->user());
        } catch (InvalidArgumentException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }
        $this->notify(__(':n est accueilli(e), sous le n° :x.', ['n' => $member->fullName(), 'x' => $member->number]));
    }

    public function askRefuse(int $id): void
    {
        $this->authorizeAct();
        $this->refusingId = MemberTransfer::where('to_organization_id', $this->organization()->id)->findOrFail($id)->id;
        $this->note = '';
        $this->dispatch('open-modal', name: 'refuse-transfer');
    }

    public function refuse(MemberTransfers $transfers): void
    {
        $this->authorizeAct();
        $this->validate(['note' => 'required|string|max:255'], attributes: ['note' => __('motif')]);
        $transfers->refuse(MemberTransfer::where('to_organization_id', $this->organization()->id)->findOrFail($this->refusingId), auth()->user(), $this->note);
        $this->dispatch('close-modal', name: 'refuse-transfer');
        $this->notify(__('Transfert refusé ; la paroisse de départ est prévenue.'));
    }

    public function cancel(MemberTransfers $transfers, int $id): void
    {
        $this->authorizeAct();
        $transfers->cancel(MemberTransfer::where('from_organization_id', $this->organization()->id)->findOrFail($id));
        $this->notify(__('Demande de transfert annulée.'));
    }

    public function render()
    {
        $id = $this->organization()->id;
        $with = ['member', 'from', 'to'];

        return view('livewire.transfers.index', [
            'incoming' => MemberTransfer::with($with)->where('to_organization_id', $id)->where('status', 'pending')->oldest()->get(),
            'outgoing' => MemberTransfer::with($with)->where('from_organization_id', $id)->where('status', 'pending')->oldest()->get(),
            'history' => MemberTransfer::with($with)->where(fn ($q) => $q->where('to_organization_id', $id)->orWhere('from_organization_id', $id))
                ->where('status', '!=', 'pending')->latest('updated_at')->limit(30)->get(),
            'canAct' => ! $this->organization()->isReadOnly(),
        ]);
    }
}
