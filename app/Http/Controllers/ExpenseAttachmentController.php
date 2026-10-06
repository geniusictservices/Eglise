<?php

namespace App\Http\Controllers;

use App\Models\ExpenseAttachment;
use App\Models\ExpenseRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/** Pièce jointe d'une dépense : la finance, les approbateurs et le demandeur. */
class ExpenseAttachmentController extends Controller
{
    public function __invoke(ExpenseAttachment $attachment)
    {
        // La demande passe par le filtre de la communauté courante.
        $expense = ExpenseRequest::findOrFail($attachment->expense_request_id);
        abort_unless(Gate::any(['finance.view', 'finance.expenses.approve', 'finance.disburse']) || $expense->requested_by === auth()->id(), 403);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->response($attachment->path, $attachment->original_name, ['Cache-Control' => 'private, max-age=3600']);
    }
}
