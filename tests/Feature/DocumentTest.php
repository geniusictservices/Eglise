<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\DocumentType;
use App\Models\IssuedDocument;
use App\Models\LifeEvent;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Services\Documents;
use App\Services\DocumentTypes;
use App\Services\MemberRegistry;
use App\Support\DocumentTemplate;
use App\Support\MemberPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $siege;

    private Organization $paroisse;

    private User $admin;

    private Member $esther;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::factory()->create();
        $this->siege = $this->createCommunity('Communauté de la Paix', $this->admin);
        $this->paroisse = $this->createChild($this->siege, 'Paroisse de Himbi');
        $this->paroisse->update(['city' => 'Goma']);
        $this->admin->forceFill(['current_organization_id' => $this->paroisse->id])->save();
        $this->actingAs($this->admin);
        $this->inOrganization($this->paroisse);
        $this->esther = Member::create(['last_name' => 'Kahindo', 'first_name' => 'Esther', 'middle_name' => 'Vagheni', 'gender' => 'F',
            'birth_date' => '1982-03-14', 'birth_place' => 'Butembo', 'joined_on' => '2015-06-07']);
        $this->esther->forceFill(['number' => 'HIM-2015-0007'])->save();
        LifeEvent::create(['member_id' => $this->esther->id, 'type' => 'baptism', 'occurred_on' => '1998-08-16', 'place' => 'Lac Kivu',
            'officiant' => 'Pasteur Daniel Mumbere', 'register_number' => 'B-301']);
    }

    private function type(string $key): DocumentType
    {
        return app(DocumentTypes::class)->available($this->paroisse)->firstWhere('key', $key);
    }

    public function test_the_template_language(): void
    {
        $html = DocumentTemplate::render("**{civilite} {nom}**, {né} le {date}, inscrit{e}.\n\n<script>{inconnu}", ['civilite' => 'Madame', 'nom' => 'KAHINDO', 'né' => 'née', 'e' => 'e', 'date' => null]);

        $this->assertStringContainsString('<strong>Madame KAHINDO</strong>, née le <span class="blank">', $html);
        $this->assertStringContainsString('inscrite.', $html);
        $this->assertStringContainsString('<p>&lt;script&gt;<span class="blank">', $html);
        $this->assertSame(['civilite', 'nom', 'né'], DocumentTemplate::used('{civilite} {nom} {né} {nom}'));
    }

    public function test_the_headquarters_templates_serve_every_parish_which_can_adapt_them(): void
    {
        $types = app(DocumentTypes::class);
        $baptism = $this->type('baptism');
        $this->assertSame($this->siege->id, $baptism->organization_id);
        $this->assertCount(9, $types->available($this->paroisse));

        $copy = $types->adapt($baptism, $this->paroisse);
        $this->assertSame($baptism->id, $copy->replaces_id);
        $available = $types->available($this->paroisse);
        $this->assertCount(9, $available);
        $this->assertSame($copy->id, $available->firstWhere('key', 'baptism')->id);
        // Le siège garde son modèle.
        $this->assertSame($baptism->id, $types->available($this->siege)->firstWhere('key', 'baptism')->id);

        $this->expectException(InvalidArgumentException::class);
        $types->save($this->paroisse, $baptism->toArray(), $baptism);
    }

    public function test_the_secretary_writes_a_template_with_its_own_fields(): void
    {
        LivewireTest::test(Livewire\Documents\TemplateEditor::class)
            ->set('form.name', 'Attestation de choriste')->set('form.title', 'Attestation de choriste')->set('form.code', 'ACH')
            ->call('addField')->set('form.fields.0.label', 'Voix')
            ->set('form.body', '{civilite} {nom_officiel} chante la voix de {voix}.')
            ->assertSee('chante la voix de Voix')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('documents.templates'));

        $type = DocumentType::where('code', 'ACH')->sole();
        $this->assertSame($this->paroisse->id, $type->organization_id);
        $this->assertSame([['key' => 'voix', 'label' => 'Voix', 'type' => 'text', 'required' => true]], $type->customFields());

        LivewireTest::test(Livewire\Documents\TemplateEditor::class, ['type' => $type])
            ->set('form.number_format', '{CODE}-{ANNEE}')->call('save')->assertHasErrors('form.number_format');
    }

    public function test_issuing_a_baptism_certificate_and_verifying_it(): void
    {
        $this->siege->update(['legal' => ['legal_name' => 'CEP ASBL']]);

        LivewireTest::withQueryParams(['modele' => $this->type('baptism')->id, 'membre' => $this->esther->id])
            ->test(Livewire\Documents\Issue::class)
            ->assertSee('reçu le baptême le')
            ->assertDontSee('Informations absentes')
            ->set('signatory', 'Daniel Paluku')
            ->call('issue')
            ->assertHasNoErrors();

        $document = IssuedDocument::sole();
        $this->assertSame('ABA/'.app(MemberRegistry::class)->code($this->paroisse).'/'.now()->year.'/0001', $document->number);
        $this->assertSame('KAHINDO Vagheni Esther', $document->beneficiary);
        $this->assertStringContainsString('<strong>Madame KAHINDO Vagheni Esther</strong>, née le 14 mars 1982 à Butembo', $document->body);
        $this->assertStringContainsString('<strong>16 août 1998</strong> à Lac Kivu', $document->body);
        $this->assertStringContainsString('sous la référence B-301', $document->body);
        $this->assertSame('Daniel Paluku', $document->signatory);

        $this->get(route('documents.print', $document))->assertOk()->assertSee('Attestation de baptême')->assertSee($document->number)->assertSee('<svg', false)
            // Un certificat s'imprime en paysage, dans le style de l'église (Prestige par défaut), le nom mis en valeur.
            ->assertSee('size: A4 landscape', false)->assertSee('wd-cert wd-s-prestige', false)->assertSee('Kahindo Vagheni Esther');
        $this->assertSame('KAHINDO Vagheni Esther', $document->data['headline']);
        $this->get(route('documents.index'))->assertOk()->assertSee('KAHINDO Vagheni Esther');

        // L'église choisit un autre style ; un modèle peut garder le sien, et une lettre reste en portrait.
        $this->paroisse->update(['settings' => ['documents' => ['style' => 'moderne']]]);
        $this->get(route('documents.print', $document))->assertSee('wd-s-moderne', false);
        $document->type->update(['style' => 'solennel']);
        $this->get(route('documents.print', $document->fresh()))->assertSee('wd-s-solennel', false);
        $this->assertSame('portrait', $this->type('recommendation')->orientation);

        // Le texte délivré est figé : modifier le modèle ne le change pas.
        $this->esther->update(['birth_place' => 'Goma']);
        $this->assertStringContainsString('à Butembo', $document->fresh()->body);

        auth()->logout();
        $this->get(route('documents.verify', $document->token))->assertOk()->assertSee('Document authentique')->assertSee('KAHINDO Vagheni Esther')->assertDontSee('Butembo');
        $this->get(route('documents.verify', str_repeat('a', 32)))->assertNotFound()->assertSee('Document inconnu');
    }

    public function test_numbers_follow_each_other_and_a_cancelled_document_says_so(): void
    {
        $documents = app(Documents::class);
        $type = $this->type('membership');
        $first = $documents->issue($this->paroisse, $type, ['member_id' => $this->esther->id]);
        $second = $documents->issue($this->paroisse, $type, ['member_id' => $this->esther->id]);
        $this->assertSame([1, 2], [$first->sequence, $second->sequence]);
        $this->assertStringEndsWith('/0002', $second->number);

        LivewireTest::test(Livewire\Documents\Index::class)
            ->call('askCancel', $first->id)->call('cancel')->assertHasErrors('reason')
            ->set('reason', 'Erreur de date')->call('cancel')->assertHasNoErrors();
        $this->assertTrue($first->fresh()->isCancelled());
        $this->get(route('documents.verify', $first->token))->assertOk()->assertSee('Document annulé');

        // Un ordre de mission exige ses champs ; une lettre va à un destinataire libre.
        try {
            $documents->issue($this->paroisse, $this->type('mission'), ['member_id' => $this->esther->id, 'fields' => ['destination' => 'Bukavu']]);
            $this->fail('Champ obligatoire manquant');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Objet de la mission', $e->getMessage());
        }
        $letter = $documents->issue($this->paroisse, $this->type('letter'), ['beneficiary' => 'Mairie de Goma', 'fields' => ['objet' => 'Salle', 'message' => 'Bonjour']]);
        $this->assertSame('Mairie de Goma', $letter->beneficiary);
        $this->assertNull($letter->member_id);

        Carbon::setTestNow(now()->addYear());
        $this->assertStringEndsWith('/0001', $documents->issue($this->paroisse, $type, ['member_id' => $this->esther->id])->number);
        Carbon::setTestNow();
    }

    public function test_only_those_allowed_issue_documents(): void
    {
        $tresorier = User::factory()->create(['current_organization_id' => $this->paroisse->id]);
        $this->assign($tresorier, $this->role($this->siege, 'tresorier'), $this->paroisse);
        $this->actingAs($tresorier);

        $this->get(route('documents.index'))->assertForbidden();
        $this->get(route('documents.issue'))->assertForbidden();
        $this->get(route('documents.templates'))->assertForbidden();
    }

    public function test_the_member_photo_is_printed_frozen_and_shown_when_verifying(): void
    {
        Storage::fake('local');
        $image = UploadedFile::fake()->image('esther.jpg', 600, 800);
        $this->esther->update(['photo_path' => MemberPhoto::store($image->getRealPath(), $this->esther)]);
        $membership = $this->type('membership');
        $this->assertTrue($membership->show_photo);
        $this->assertFalse($this->type('letter')->show_photo);

        // L'aperçu montre la photo de la fiche.
        LivewireTest::test(Livewire\Documents\Issue::class)->call('chooseType', $membership->id)->call('chooseMember', $this->esther->id)
            ->assertSee(route('members.photo', $this->esther), false)->assertDontSee('sa fiche n’en a pas');

        $document = app(Documents::class)->issue($this->paroisse, $membership, ['member_id' => $this->esther->id, 'signatory' => 'Pasteur Daniel Paluku']);
        $this->assertNotNull($document->photo_path);
        $this->assertNotSame($this->esther->photo_path, $document->photo_path);

        // Une nouvelle photo dans la fiche ne change pas le document déjà délivré.
        Storage::disk('local')->delete($this->esther->photo_path);
        $this->get(route('documents.print', $document))->assertOk()->assertSee('data:image/jpeg;base64,', false);

        // La page de vérification montre la photo, pour la comparer avec le papier.
        auth()->logout();
        $this->get(route('documents.verify', $document->token))->assertOk()->assertSee(route('documents.verify.photo', $document->token));
        $this->get(route('documents.verify.photo', $document->token))->assertOk();

        // Un document annulé ne montre plus de photo.
        $this->actingAs($this->admin);
        app(Documents::class)->cancel($document, 'Erreur de prénom');
        $this->get(route('documents.verify.photo', $document->token))->assertNotFound();

        // Sans photo dans la fiche, le secrétariat est prévenu, et le document part sans photo.
        $josue = Member::create(['last_name' => 'Kakule', 'first_name' => 'Josué', 'gender' => 'M']);
        LivewireTest::test(Livewire\Documents\Issue::class)->call('chooseType', $membership->id)->call('chooseMember', $josue->id)->assertSee('sa fiche n’en a pas');
        $this->assertNull(app(Documents::class)->issue($this->paroisse, $membership, ['member_id' => $josue->id])->photo_path);

        // Le modèle se règle : la case se décoche.
        LivewireTest::test(Livewire\Documents\TemplateEditor::class, ['type' => app(DocumentTypes::class)->adapt($membership, $this->paroisse)])
            ->assertSee('Mettre la photo du membre')->set('form.show_photo', false)->call('save')->assertHasNoErrors();
        $this->assertFalse($this->type('membership')->show_photo);
    }
}
