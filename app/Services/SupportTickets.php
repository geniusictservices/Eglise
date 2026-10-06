<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Les demandes d'aide : la communauté écrit, Genius ICT répond, chacun est
 * prévenu dans ses nouveautés. Une demande reste ouverte jusqu'à ce que
 * l'un ou l'autre la marque comme réglée ; un nouveau message la rouvre.
 */
class SupportTickets
{
    public function __construct(private Notifier $notifier) {}

    public function open(Organization $organization, User $by, string $subject, string $category, string $body): SupportTicket
    {
        $ticket = DB::transaction(function () use ($organization, $by, $subject, $category, $body) {
            $year = now()->year;
            $count = SupportTicket::whereYear('created_at', $year)->lockForUpdate()->count();
            $ticket = SupportTicket::create([
                'organization_id' => $organization->id, 'number' => sprintf('T%d-%04d', $year, $count + 1), 'subject' => trim($subject),
                'category' => array_key_exists($category, SupportTicket::CATEGORIES) ? $category : 'question',
                'opened_by' => $by->id, 'last_activity_at' => now(),
            ]);
            SupportMessage::create(['support_ticket_id' => $ticket->id, 'user_id' => $by->id, 'body' => trim($body)]);

            return $ticket;
        });
        $this->notifier->send(null, $this->notifier->staffWith('admin.support'), "ticket.{$ticket->id}.staff", [
            'title' => __('Nouvelle demande de :c', ['c' => $organization->name]), 'body' => $ticket->number.' · '.$ticket->subject,
            'url' => route('admin.tickets.show', $ticket), 'icon' => 'circle-help']);

        return $ticket;
    }

    public function reply(SupportTicket $ticket, User $by, string $body, bool $fromStaff): SupportMessage
    {
        if (trim($body) === '') {
            throw new InvalidArgumentException(__('Écrivez votre message.'));
        }
        $message = DB::transaction(function () use ($ticket, $by, $body, $fromStaff) {
            $message = SupportMessage::create(['support_ticket_id' => $ticket->id, 'user_id' => $by->id, 'from_staff' => $fromStaff, 'body' => trim($body)]);
            $ticket->update(['status' => $fromStaff ? 'answered' : 'open', 'last_activity_at' => now(), 'closed_at' => null,
                'assigned_to' => $fromStaff ? ($ticket->assigned_to ?? $by->id) : $ticket->assigned_to]);

            return $message;
        });
        $ticket->loadMissing('organization');
        $excerpt = Str::limit(trim($body), 120);
        if ($fromStaff) {
            $this->notifier->settle("ticket.{$ticket->id}.staff");
            $this->notifier->send($ticket->organization, $ticket->opened_by, "ticket.{$ticket->id}.community", [
                'title' => __('Genius ICT vous a répondu'), 'body' => $ticket->subject.' · '.$excerpt, 'url' => route('support.show', $ticket), 'icon' => 'circle-help']);
        } else {
            $this->notifier->settle("ticket.{$ticket->id}.community");
            $this->notifier->send(null, $ticket->assigned_to ? [$ticket->assigned_to] : $this->notifier->staffWith('admin.support'), "ticket.{$ticket->id}.staff", [
                'title' => __(':c a répondu', ['c' => $ticket->organization->name]), 'body' => $ticket->number.' · '.$excerpt,
                'url' => route('admin.tickets.show', $ticket), 'icon' => 'circle-help']);
        }

        return $message;
    }

    public function close(SupportTicket $ticket): void
    {
        $ticket->update(['status' => 'closed', 'closed_at' => now()]);
        $this->notifier->settle("ticket.{$ticket->id}.*");
    }

    public function assign(SupportTicket $ticket, ?User $staff): void
    {
        $ticket->update(['assigned_to' => $staff?->id]);
    }
}
