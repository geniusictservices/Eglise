<?php

namespace App\Http\Controllers;

use App\Models\FinanceTransaction;
use App\Support\DocumentIdentity;
use Illuminate\Support\Facades\Gate;

/** Reçu imprimable d'une recette. */
class ReceiptController extends Controller
{
    public function __invoke(FinanceTransaction $transaction)
    {
        abort_unless($transaction->type === 'income', 404);
        abort_unless(Gate::allows('finance.view'), 403);
        // Un reçu nominatif montre le nom du donateur : il faut la permission des contributions.
        abort_if($transaction->member_id && ! Gate::allows('finance.contributions.view'), 403);

        $transaction->load(['account', 'category', 'member', 'department', 'author', 'organization']);
        $identity = $transaction->organization->documentIdentity();
        $format = request('format', $identity->display()['receipt_format']);
        $format = array_key_exists($format, DocumentIdentity::RECEIPT_FORMATS) ? $format : 'a4';

        return view('finances.receipt', [
            't' => $transaction,
            'organization' => $transaction->organization,
            'identity' => $identity,
            'format' => $format,
        ]);
    }
}
