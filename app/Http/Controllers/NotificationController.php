<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /** Ouvre une nouveauté : dans sa communauté, à la page concernée. */
    public function open(Request $request, string $id)
    {
        $notification = DatabaseNotification::where('notifiable_type', 'user')->where('notifiable_id', $request->user()->id)->findOrFail($id);
        $notification->markAsRead();

        $organization = $notification->organization_id ? Organization::find($notification->organization_id) : null;
        if ($organization && $organization->id !== $request->user()->current_organization_id) {
            if (! $request->user()->canAccess($organization)) {
                return redirect()->route('notifications.index')->with('status', __('Vous n’avez plus accès à cette communauté.'));
            }
            $request->user()->forceFill(['current_organization_id' => $organization->id])->saveQuietly();
        }

        return redirect($notification->path);
    }

    /** Ce téléphone recevra les nouveautés. */
    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => 'required|url|max:2000',
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
            'contentEncoding' => 'nullable|in:aesgcm,aes128gcm',
        ]);

        PushSubscription::updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'user_id' => $request->user()->id, 'endpoint' => $data['endpoint'],
            'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth'],
            'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
            'device' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
        ]);

        return response()->noContent();
    }

    public function unsubscribe(Request $request)
    {
        $request->validate(['endpoint' => 'required|string']);
        PushSubscription::where('user_id', $request->user()->id)->where('endpoint_hash', hash('sha256', $request->input('endpoint')))->delete();

        return response()->noContent();
    }
}
