<?php

namespace App\Http\Middleware;

use App\Services\Notifier;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Ouvrir une page, d'où qu'on vienne, marque comme lues les nouveautés qui y mènent. */
class MarkNotificationsOpened
{
    public function __construct(private Notifier $notifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->user() && ! $request->ajax()) {
            $this->notifier->opened($request->user(), $request->getPathInfo(), app(CurrentOrganization::class)->get());
        }

        return $next($request);
    }
}
