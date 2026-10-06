<?php

namespace App\Http\Controllers;

use App\Models\PaymentDeclaration;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/** Capture d'écran d'un paiement déclaré, réservée à la finance. */
class DeclarationScreenshotController extends Controller
{
    public function __invoke(PaymentDeclaration $declaration)
    {
        abort_unless(Gate::any(['finance.payments.validate', 'finance.income']), 403);
        abort_unless($declaration->screenshot_path && Storage::disk('local')->exists($declaration->screenshot_path), 404);

        return Storage::disk('local')->response($declaration->screenshot_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }
}
