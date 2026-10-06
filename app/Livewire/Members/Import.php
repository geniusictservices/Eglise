<?php

namespace App\Livewire\Members;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\MemberImport;
use App\Services\MemberSpreadsheet;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Import guidé du registre : télécharger le modèle, envoyer le fichier,
 * vérifier ligne par ligne, importer, et annuler si besoin.
 */
#[Title('Importer des membres')]
class Import extends Component
{
    use WithFileUploads, WritesInOrganization;

    public $file = null;

    public ?int $importId = null;

    #[Url(as: 'lignes', except: 'toutes')]
    public string $filter = 'toutes';

    public int $shown = 50;

    public bool $includeDuplicates = false;

    public function mount(): void
    {
        $this->authorize('members.import');
    }

    private function current(): ?MemberImport
    {
        return $this->importId ? MemberImport::findOrFail($this->importId) : null;
    }

    private function results(MemberImport $import): array
    {
        return json_decode(Storage::disk('local')->get($import->workPath()) ?? '{}', true) ?: ['results' => [], 'unknown' => []];
    }

    /** Dès que le fichier arrive : lecture et vérification de chaque ligne. */
    public function updatedFile(MemberSpreadsheet $spreadsheet): void
    {
        $this->authorizeWrite('members.import');
        $this->validate(['file' => 'required|file|max:10240|mimes:xlsx,xls,csv,ods'], [
            'file.mimes' => __('Envoyez un fichier Excel (.xlsx, .xls), OpenDocument (.ods) ou CSV.'),
        ], ['file' => __('fichier')]);

        @set_time_limit(300);
        $organization = $this->organization();

        try {
            $read = $spreadsheet->read($this->file->getRealPath(), $organization);
        } catch (\Throwable $e) {
            report($e);
            $this->addError('file', __('Ce fichier n’a pas pu être lu. Enregistrez-le au format Excel (.xlsx) et réessayez.'));

            return;
        }

        if (! in_array('last_name', $read['columns'], true)) {
            $this->addError('file', __('La colonne « Nom » est introuvable. Utilisez le modèle Waumini, sans changer les titres de la première ligne.'));

            return;
        }
        if ($read['rows'] === []) {
            $this->addError('file', __('Le fichier ne contient aucune ligne à importer.'));

            return;
        }
        if (count($read['rows']) > 5000) {
            $this->addError('file', __('Pas plus de 5 000 lignes par import : découpez le fichier en plusieurs parties.'));

            return;
        }

        $results = $spreadsheet->analyse($organization, $read['rows']);
        $summary = MemberSpreadsheet::summary($results);

        $import = MemberImport::create([
            'user_id' => auth()->id(),
            'file_name' => mb_substr($this->file->getClientOriginalName(), 0, 255),
            'total_rows' => $summary['total'],
            'valid_rows' => $summary['valid'],
            'error_rows' => $summary['errors'],
            'duplicate_rows' => $summary['duplicates'],
        ]);
        Storage::disk('local')->put($import->workPath(), json_encode(['results' => $results, 'unknown' => $read['unknown'], 'columns' => $read['columns']]));

        $this->importId = $import->id;
        $this->filter = $summary['errors'] ? 'erreurs' : 'toutes';
        $this->reset('file', 'shown', 'includeDuplicates');
    }

    public function showMore(): void
    {
        $this->shown += 100;
    }

    public function restart(): void
    {
        if ($import = $this->current()) {
            if ($import->status === 'analysed') {
                Storage::disk('local')->delete($import->workPath());
                $import->delete();
            }
        }
        $this->reset('importId', 'file', 'filter', 'shown', 'includeDuplicates');
    }

    public function confirm(MemberSpreadsheet $spreadsheet): void
    {
        $this->authorizeWrite('members.import');
        $import = $this->current();
        abort_unless($import && $import->status === 'analysed', 404);

        @set_time_limit(600);
        $count = $spreadsheet->import($import, $this->results($import)['results'], $this->includeDuplicates);
        Storage::disk('local')->delete($import->workPath());

        $this->notify(trans_choice(':count membre importé.|:count membres importés.', $count));
    }

    public function cancelImport(int $id, MemberSpreadsheet $spreadsheet): void
    {
        $this->authorizeWrite('members.import');
        $import = MemberImport::findOrFail($id);
        abort_unless($import->canBeCancelled(), 403);

        $count = $spreadsheet->cancel($import);
        $this->notify(trans_choice('Import annulé : :count membre retiré.|Import annulé : :count membres retirés.', $count));
    }

    public function render()
    {
        $import = $this->current();
        $data = $import && $import->status === 'analysed' ? $this->results($import) : null;

        return view('livewire.members.import', [
            'import' => $import,
            'summary' => $data ? MemberSpreadsheet::summary($data['results']) : null,
            'rows' => $data ? MemberSpreadsheet::preview($data['results'], $this->filter) : collect(),
            'unknown' => $data['unknown'] ?? [],
            'history' => MemberImport::with('user')->where('status', '!=', 'analysed')->latest()->limit(10)->get(),
        ]);
    }
}
