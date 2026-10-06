<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Member;
use App\Models\MemberField;
use App\Models\MemberFunction;
use App\Models\MemberStatus;
use App\Models\User;
use App\Services\MemberRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class MemberRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): MemberRegistry
    {
        return app(MemberRegistry::class);
    }

    public function test_a_new_community_receives_default_statuses_and_functions(): void
    {
        $eglise = $this->createCommunity();

        $statuses = $this->registry()->statuses($eglise);
        $this->assertSame(count(config('waumini.registry.statuses')), $statuses->count());
        $this->assertSame('Membre', $this->registry()->defaultStatus($eglise)->name);
        $this->assertTrue(MemberFunction::where('organization_id', $eglise->id)->where('name', 'Diacre')->exists());
    }

    public function test_parishes_follow_the_headquarters_statuses_and_numbering(): void
    {
        $siege = $this->createCommunity('CEP Siège');
        $paroisse = $this->createChild($siege, 'Paroisse Himbi');
        $siege->update(['settings' => ['members' => ['number_format' => '{SIEGE}/{SIGLE}/{NUMERO}', 'number_padding' => 3], 'members_code' => 'CEP']]);
        $paroisse->update(['settings' => ['members_code' => 'HIM']]);

        $this->assertSame(MemberStatus::where('organization_id', $siege->id)->count(), $this->registry()->statuses($paroisse)->count());
        $this->assertFalse(MemberStatus::where('organization_id', $paroisse->id)->exists());

        $member = Member::create(['organization_id' => $paroisse->id, 'last_name' => 'KAHINDO']);
        $this->registry()->assignNumber($member);
        $this->assertSame('CEP/HIM/001', $member->fresh()->number);
    }

    public function test_numbers_follow_each_other_and_can_restart_each_year(): void
    {
        $eglise = $this->createCommunity();
        $eglise->update(['settings' => ['members_code' => 'EP', 'members' => ['start_number' => 120]]]);

        $first = Member::create(['organization_id' => $eglise->id, 'last_name' => 'A']);
        $this->registry()->assignNumber($first, Carbon::create(2026, 3, 1));
        $second = Member::create(['organization_id' => $eglise->id, 'last_name' => 'B']);
        $this->registry()->assignNumber($second, Carbon::create(2026, 4, 1));

        $this->assertSame('EP-2026-0120', $first->fresh()->number);
        $this->assertSame('EP-2026-0121', $second->fresh()->number);

        $eglise->update(['settings' => ['members_code' => 'EP', 'members' => ['yearly_reset' => true]]]);
        $third = Member::create(['organization_id' => $eglise->id, 'last_name' => 'C']);
        $this->registry()->assignNumber($third, Carbon::create(2027, 1, 5));
        $this->assertSame('EP-2027-0001', $third->fresh()->number);
    }

    public function test_only_the_headquarters_changes_numbering_and_statuses(): void
    {
        $admin = User::factory()->create();
        $siege = $this->createCommunity('CEP Siège', $admin);
        $paroisse = $this->createChild($siege, 'Paroisse Himbi');
        $this->actingAs($admin);

        $this->inOrganization($paroisse);
        LivewireTest::test(Livewire\Members\Settings::class)
            ->set('numberFormat', '{NUMERO}')
            ->set('code', 'HIM')
            ->call('saveNumbering')
            ->assertHasNoErrors()
            ->call('editStatus')
            ->assertForbidden();

        $this->assertSame('HIM', $paroisse->fresh()->settings['members_code']);
        $this->assertArrayNotHasKey('members', $paroisse->fresh()->settings);
        $this->assertSame(config('waumini.registry.number_format'), $this->registry()->settings($paroisse)['number_format']);

        $this->inOrganization($siege);
        LivewireTest::test(Livewire\Members\Settings::class)
            ->set('numberFormat', '{SIGLE}{NUMERO}')
            ->call('saveNumbering')
            ->assertHasNoErrors()
            ->set('statusName', 'Ami de l’église')
            ->call('saveStatus')
            ->assertHasNoErrors();

        $this->assertSame('{SIGLE}{NUMERO}', $this->registry()->settings($paroisse)['number_format']);
        $this->assertTrue($this->registry()->statuses($paroisse)->contains('name', 'Ami de l’église'));
    }

    public function test_a_parish_adds_its_own_fields_and_functions(): void
    {
        $admin = User::factory()->create();
        $siege = $this->createCommunity('CEP Siège', $admin);
        $paroisse = $this->createChild($siege, 'Paroisse Himbi');
        $autre = $this->createChild($siege, 'Paroisse Katindo');
        MemberField::create(['organization_id' => $siege->id, 'key' => 'carte', 'label' => 'N° de carte d’électeur', 'type' => 'text']);

        $this->actingAs($admin);
        $this->inOrganization($paroisse);
        LivewireTest::test(Livewire\Members\Settings::class)
            ->set('functionName', 'Sentinelle')
            ->call('addFunction')
            ->assertHasNoErrors()
            ->set('functionName', 'Diacre')
            ->call('addFunction')
            ->assertHasErrors('functionName')
            ->set('fieldLabel', 'Groupe de prière')
            ->set('fieldType', 'select')
            ->set('fieldOptions', "Lundi\nMercredi")
            ->call('saveField')
            ->assertHasNoErrors();

        $this->assertSame(['N° de carte d’électeur', 'Groupe de prière'], $this->registry()->fields($paroisse)->pluck('label')->all());
        $this->assertTrue($this->registry()->functions($paroisse)->contains('name', 'Sentinelle'));
        $this->assertFalse($this->registry()->functions($autre)->contains('name', 'Sentinelle'));
        $this->assertSame(['N° de carte d’électeur'], $this->registry()->fields($autre)->pluck('label')->all());
    }

    public function test_a_parish_joining_a_headquarters_adopts_its_registry(): void
    {
        $siege = $this->createCommunity('CEP Siège');
        $paroisse = $this->createCommunity('Paroisse indépendante');
        $paroisse->update(['settings' => ['members' => ['number_format' => 'P{NUMERO}'], 'members_code' => 'PI']]);

        $membre = MemberStatus::where('organization_id', $paroisse->id)->where('name', 'Membre')->first();
        $propre = MemberStatus::create(['organization_id' => $paroisse->id, 'name' => 'Visiteur régulier']);
        $a = Member::create(['organization_id' => $paroisse->id, 'last_name' => 'A', 'status_id' => $membre->id]);
        $b = Member::create(['organization_id' => $paroisse->id, 'last_name' => 'B', 'status_id' => $propre->id]);

        $paroisse->moveUnder($siege);
        $this->registry()->harmonize($paroisse->fresh());

        $siegeMembre = MemberStatus::where('organization_id', $siege->id)->where('name', 'Membre')->first();
        $this->assertSame($siegeMembre->id, Member::withoutOrganizationScope()->find($a->id)->status_id);
        $this->assertSame($propre->id, Member::withoutOrganizationScope()->find($b->id)->status_id);
        $this->assertTrue($propre->fresh()->needs_harmonization);
        $this->assertNull($membre->fresh());
        $this->assertSame(config('waumini.registry.number_format'), $this->registry()->settings($paroisse->fresh())['number_format']);
        $this->assertSame('PI', $this->registry()->code($paroisse->fresh()));
        $this->assertTrue($this->registry()->statuses($paroisse->fresh())->contains('name', 'Visiteur régulier'));
    }
}
