<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/** Envoie une nouveauté sur les téléphones et ordinateurs de son destinataire. */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public string $notificationId) {}

    public function handle(): void
    {
        $notification = DatabaseNotification::find($this->notificationId);
        $config = config('waumini.push');
        // Déjà ouverte (ou rangée) entre-temps : rien à envoyer.
        if (! $notification || $notification->read_at || ! $config['public_key'] || ! $config['private_key']) {
            return;
        }
        $subscriptions = PushSubscription::where('user_id', $notification->notifiable_id)->get();
        if ($subscriptions->isEmpty()) {
            return;
        }

        $unread = DatabaseNotification::where('notifiable_type', 'user')->where('notifiable_id', $notification->notifiable_id)->whereNull('read_at')->count();
        $payload = json_encode([
            'title' => $notification->data['title'],
            'body' => $notification->data['body'] ?? '',
            'url' => route('notifications.open', $notification->id),
            'tag' => $notification->key ?: $notification->id,
            'count' => $unread,
        ]);

        $push = new WebPush(['VAPID' => ['subject' => $config['subject'], 'publicKey' => $config['public_key'], 'privateKey' => $config['private_key']]], ['TTL' => 86400]);
        foreach ($subscriptions as $subscription) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint, 'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token, 'contentEncoding' => $subscription->content_encoding,
            ]), $payload);
        }

        foreach ($push->flush() as $report) {
            // Le téléphone s'est désabonné (ou l'application a été désinstallée).
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint_hash', hash('sha256', $report->getEndpoint()))->delete();
            }
        }
    }
}
