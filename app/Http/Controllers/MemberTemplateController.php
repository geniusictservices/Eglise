<?php

namespace App\Http\Controllers;

use App\Services\MemberSpreadsheet;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Modèle Excel du registre, adapté aux réglages de la communauté. */
class MemberTemplateController extends Controller
{
    public function __invoke(MemberSpreadsheet $spreadsheet)
    {
        Gate::authorize('members.import');
        $organization = current_organization();
        $book = $spreadsheet->template($organization);

        return response()->streamDownload(
            fn () => (new Xlsx($book))->save('php://output'),
            'modele-registre-'.Str::slug($organization->displayName()).'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
