<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\AuditLog;
use App\Models\Household;
use App\Models\Member;
use App\Models\MemberField;
use App\Models\MemberImport;
use App\Models\User;
use App\Services\MemberSpreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire as LivewireTest;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class MemberImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function community()
    {
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église de la Paix', $admin);
        $eglise->update(['settings' => ['members_code' => 'EP']]);
        $this->actingAs($admin);
        $this->inOrganization($eglise);

        return $eglise;
    }

    /** Fabrique un fichier Excel à partir d'un tableau (première ligne : titres). */
    private function xlsx(array $grid): UploadedFile
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Membres')->fromArray($grid, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'wau').'.xlsx';
        (new Xlsx($book))->save($path);

        return UploadedFile::fake()->createWithContent('ancien-registre.xlsx', file_get_contents($path));
    }

    public function test_the_template_follows_the_community_settings(): void
    {
        $eglise = $this->community();
        MemberField::create(['organization_id' => $eglise->id, 'key' => 'cellule', 'label' => 'Cellule de prière', 'type' => 'select', 'options' => ['Béthanie', 'Siloé']]);
        $eglise->update(['settings' => ['members_code' => 'EP', 'members' => ['hidden_fields' => ['email']]]]);

        $response = $this->get(route('members.template'))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $headers = IOFactory::load($path)->getSheetByName('Membres')->rangeToArray('A1:AF1')[0];

        $this->assertContains('Nom *', $headers);
        $this->assertContains('Cellule de prière', $headers);
        $this->assertNotContains('E-mail', $headers);
    }

    public function test_each_line_is_checked_before_import(): void
    {
        $this->community();
        Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Esther', 'phone' => '+243812345678']);

        $rows = app(MemberSpreadsheet::class)->analyse(current_organization(), [
            ['line' => 2, 'values' => ['last_name' => 'kambale', 'first_name' => 'Jean', 'gender' => 'Homme', 'birth_date' => '07/12/1973', 'phone' => '0991 111 111', 'status' => 'membre']],
            ['line' => 3, 'values' => ['last_name' => '', 'first_name' => 'Sans nom']],
            ['line' => 4, 'values' => ['last_name' => 'PALUKU', 'gender' => 'X', 'birth_date' => '31/02/1990', 'status' => 'Pèlerin']],
            ['line' => 5, 'values' => ['last_name' => 'Kahindo', 'first_name' => 'Esther']],
            ['line' => 6, 'values' => ['last_name' => 'MASIKA', 'birth_date' => '1958', 'phone' => '12']],
        ]);

        $this->assertSame([], $rows[0]['errors']);
        $this->assertSame('KAMBALE', $rows[0]['data']['last_name']);
        $this->assertSame('M', $rows[0]['data']['gender']);
        $this->assertSame('1973-12-07', $rows[0]['data']['birth_date']);
        $this->assertSame('+243991111111', $rows[0]['data']['phone']);
        $this->assertContains('Le nom est obligatoire.', $rows[1]['errors']);
        $this->assertCount(3, $rows[2]['errors']);
        $this->assertNotNull($rows[3]['duplicate']);
        $this->assertSame('1958-01-01', $rows[4]['data']['birth_date']);
        $this->assertCount(1, $rows[4]['warnings']);
        $this->assertCount(1, $rows[4]['errors']);
    }

    public function test_a_file_is_imported_then_cancelled(): void
    {
        $this->community();
        Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Esther']);

        $file = $this->xlsx([
            ['N°', 'Nom', 'Post-nom', 'Prénom', 'Sexe', 'Date de naissance', 'Téléphone', 'Ménage', 'Place dans le ménage', 'Date de baptême', 'Colonne inconnue'],
            ['ANC-12', 'MUMBERE', 'Kasereka', 'Moïse', 'M', '12/03/1988', 812345671, 'Famille MUMBERE', 'Chef de ménage', '1990', 'x'],
            [null, 'KAVIRA', null, 'Rose', 'F', null, null, 'Famille MUMBERE', 'Épouse', null, null],
            [null, 'MUMBERE', null, 'Emmanuel', 'M', null, null, 'Famille MUMBERE', 'Enfant', null, null],
            [null, 'Kahindo', null, 'Esther', 'F', null, null, null, null, null, null],
            [null, null, null, 'Personne', null, null, null, null, null, null, null],
        ]);

        $component = LivewireTest::test(Livewire\Members\Import::class)
            ->set('file', $file)
            ->assertHasNoErrors()
            ->assertSee('Colonne inconnue')
            ->assertSee('Le nom est obligatoire.');

        $import = MemberImport::sole();
        $this->assertSame([5, 3, 1, 1], [$import->total_rows, $import->valid_rows, $import->duplicate_rows, $import->error_rows]);

        $component->call('confirm')->assertSee('3 membres importés');

        $moise = Member::where('first_name', 'Moïse')->sole();
        $this->assertSame('ANC-12', $moise->number);
        $this->assertSame('+243812345671', $moise->phone);
        $this->assertSame('baptism', $moise->lifeEvents()->sole()->type);
        $household = Household::where('name', 'Famille MUMBERE')->sole();
        $this->assertSame($moise->id, $household->head_member_id);
        $this->assertSame(3, $household->members()->count());
        $this->assertSame('spouse', Member::where('first_name', 'Rose')->sole()->household_role);
        $this->assertSame(2, Member::where('last_name', 'MUMBERE')->count());
        $this->assertSame(1, Member::where('last_name', 'KAHINDO')->count()); // le doublon n'est pas importé
        $this->assertSame(1, AuditLog::where('event', 'imported')->count());

        LivewireTest::test(Livewire\Members\Import::class)->call('cancelImport', $import->id);

        $this->assertSame(1, Member::count());
        $this->assertSame(0, Household::count());
        $this->assertSame('cancelled', $import->fresh()->status);
    }

    public function test_the_register_is_exported_in_the_template_format(): void
    {
        $this->community();
        Member::create(['last_name' => 'BAHATI', 'first_name' => 'Isaac', 'phone' => '+243812345678', 'birth_date' => '1985-04-02']);
        Member::create(['last_name' => 'MASIKA', 'first_name' => 'Rebecca', 'gender' => 'F']);

        $response = LivewireTest::test(Livewire\Members\Index::class)->set('gender', 'F')->call('export');
        $response->assertFileDownloaded();

        $test = LivewireTest::test(Livewire\Members\Index::class)->call('export');
        $test->assertFileDownloaded();
    }

    public function test_import_requires_the_permission(): void
    {
        $eglise = $this->createCommunity();
        $tresorier = User::factory()->create();
        $this->assign($tresorier, $this->role($eglise, 'tresorier'), $eglise);
        $this->actingAs($tresorier);

        $this->get(route('members.import'))->assertForbidden();
        $this->get(route('members.template'))->assertForbidden();
    }
}
