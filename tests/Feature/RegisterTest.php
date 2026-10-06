<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\IssuedDocument;
use App\Models\LifeEvent;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Register;
use App\Models\RegisterEntry;
use App\Models\User;
use App\Services\DocumentTypes;
use App\Services\Registers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private Register $baptisms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $admin = User::factory()->create();
        $this->eglise = $this->createCommunity('Église', $admin);
        $this->actingAs($admin);
        $this->inOrganization($this->eglise);
        $this->baptisms = app(Registers::class)->create($this->eglise, ['kind' => 'baptism', 'name' => 'Registre des baptêmes n° 1', 'from_year' => 1985, 'to_year' => 2002]);
    }

    public function test_quick_entry_keeps_the_common_details_and_numbers_follow(): void
    {
        $page = LivewireTest::test(Livewire\Registers\Show::class, ['register' => $this->baptisms])
            ->assertSet('entry.entry_number', '1')
            ->set('entry.entry_number', '125')->set('entry.page', '42')->set('entry.event_date', '1998-08-16')
            ->set('entry.place', 'Lac Kivu')->set('entry.officiant', 'Pasteur Daniel Mumbere')
            ->set('entry.last_name', 'Muhindo')->set('entry.first_name', 'Josias')->set('entry.gender', 'M')
            ->set('entry.father', 'Kasereka Muhindo')->set('entry.mother', 'Kavira Furaha')
            ->call('save')->assertHasNoErrors()
            ->assertSet('entry.entry_number', '126')->assertSet('entry.event_date', '1998-08-16')->assertSet('entry.place', 'Lac Kivu')
            ->assertSet('entry.last_name', '')->assertSee('MUHINDO Josias');

        $entry = RegisterEntry::sole();
        $this->assertSame('MUHINDO', $entry->last_name);
        $this->assertSame('Registre des baptêmes n° 1, acte 125, page 42', $entry->reference());

        // Le même numéro deux fois : refusé, sauf « bis ».
        $page->set('entry.entry_number', '125')->set('entry.last_name', 'Sikuli')->call('save')->assertHasErrors('entry.entry_number');
        $page->set('entry.entry_number', '125 bis')->call('save')->assertHasNoErrors();
        $this->assertSame('126', app(Registers::class)->nextNumber($this->baptisms));

        $this->get(route('registers.index', ['q' => 'josias']))->assertOk()->assertSee('MUHINDO Josias')->assertSee('Registre des baptêmes n° 1');
    }

    public function test_linking_an_act_completes_the_members_life_event(): void
    {
        $registers = app(Registers::class);
        $esther = Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Esther', 'gender' => 'F']);
        $josue = Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Josué', 'gender' => 'M']);
        LifeEvent::create(['member_id' => $josue->id, 'type' => 'baptism', 'occurred_on' => '2024-08-29']);

        $first = $registers->save($this->baptisms, ['entry_number' => '7', 'event_date' => '1998-08-16', 'place' => 'Lac Kivu', 'officiant' => 'Pasteur Mumbere', 'last_name' => 'Kahindo', 'first_name' => 'Esther']);
        $registers->link($first, $esther->id);
        $event = LifeEvent::where('member_id', $esther->id)->sole();
        $this->assertSame(['1998-08-16', 'Lac Kivu', 'Registre des baptêmes n° 1, acte 7'], [$event->occurred_on->toDateString(), $event->place, $event->register_number]);

        // La fiche garde ce qu'elle sait ; l'acte complète ce qui manque.
        $second = $registers->save($this->baptisms, ['entry_number' => '8', 'event_date' => '2024-09-01', 'place' => 'Temple', 'last_name' => 'Kahindo', 'first_name' => 'Josué']);
        $registers->link($second, $josue->id);
        $event = LifeEvent::where('member_id', $josue->id)->sole();
        $this->assertSame(['2024-08-29', 'Temple'], [$event->occurred_on->toDateString(), $event->place]);
    }

    public function test_reissuing_a_1998_baptism_with_a_qr_code(): void
    {
        $entry = app(Registers::class)->save($this->baptisms, ['entry_number' => '322', 'page' => '22', 'event_date' => '1998-08-16', 'place' => 'Lac Kivu, plage de Himbi',
            'officiant' => 'Pasteur Daniel Mumbere', 'last_name' => 'Muhindo', 'middle_name' => 'Kasereka', 'first_name' => 'Josias', 'gender' => 'M',
            'birth_date' => '1982-11-03', 'birth_place' => 'Goma', 'father' => 'Kasereka Muhindo', 'mother' => 'Kavira Furaha']);
        $type = app(DocumentTypes::class)->available($this->eglise)->firstWhere('key', 'baptism');

        LivewireTest::test(Livewire\Registers\Show::class, ['register' => $this->baptisms])->assertSee('Rééditer');
        LivewireTest::withQueryParams(['modele' => $type->id, 'acte' => $entry->id])->test(Livewire\Documents\Issue::class)
            ->assertSee('MUHINDO Kasereka Josias')->assertSee('acte 322')
            ->set('signatory', 'Daniel Paluku')->call('issue')->assertHasNoErrors();

        $document = IssuedDocument::sole();
        $this->assertSame($entry->id, $document->register_entry_id);
        $this->assertNull($document->member_id);
        $this->assertSame('MUHINDO Kasereka Josias', $document->beneficiary);
        $this->assertStringContainsString('<strong>Monsieur MUHINDO Kasereka Josias</strong>, né le 3 novembre 1982 à Goma', $document->body);
        $this->assertStringContainsString('<strong>16 août 1998</strong> à Lac Kivu, plage de Himbi, des mains de Pasteur Daniel Mumbere', $document->body);
        $this->assertStringContainsString('Registre des baptêmes n° 1, acte 322, page 22', $document->body);
        $this->get(route('documents.verify', $document->token))->assertOk()->assertSee('Document authentique');
    }

    public function test_registers_are_kept_by_those_allowed(): void
    {
        $tresorier = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($tresorier, $this->role($this->eglise, 'tresorier'), $this->eglise);
        $this->actingAs($tresorier)->get(route('registers.index'))->assertForbidden();

        $pasteur = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($pasteur, $this->role($this->eglise, 'pasteur'), $this->eglise);
        $this->actingAs($pasteur);
        // Le pasteur consulte et réédite, mais ne recopie pas les actes.
        LivewireTest::test(Livewire\Registers\Show::class, ['register' => $this->baptisms])->assertDontSee('Saisie rapide')->call('edit', 1)->assertForbidden();
    }
}
