<?php

namespace App\Livewire\Audit;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Services\AuditLogger;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Journal d’audit')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $event = '';

    #[Url(except: '')]
    public string $subject = '';

    public ?bool $intact = null;

    public function mount(): void
    {
        $this->authorize('audit.view');
    }

    public function updating($property): void
    {
        if (in_array($property, ['event', 'subject'], true)) {
            $this->resetPage();
        }
    }

    public function verify(AuditLogger $logger): void
    {
        $this->intact = $logger->verify() === null;
    }

    public function render()
    {
        $organization = current_organization();
        $ids = Organization::query()->subtreeOf($organization)->pluck('id');

        $logs = AuditLog::with(['user', 'organization'])
            ->whereIn('organization_id', $ids)
            ->when($this->event, fn ($q) => $q->where('event', $this->event))
            ->when($this->subject, fn ($q) => $q->where('subject_type', $this->subject))
            ->latest('id')
            ->paginate(30);

        return view('livewire.audit.index', [
            'logs' => $logs,
            'organization' => $organization,
            'subjects' => [
                'organization' => __('Communauté'), 'user' => __('Utilisateurs'), 'role' => __('Rôles'),
                'role_assignment' => __('Attributions de rôles'), 'exchange_rate' => __('Taux de change'),
                'organization_currency' => __('Devises'), 'attachment_request' => __('Rattachements'),
            ],
        ]);
    }
}
