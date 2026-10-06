<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class OrganizationHierarchyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_community_gets_role_templates_and_an_administrator(): void
    {
        $community = $this->createCommunity();

        $this->assertSame($community->id.'/', $community->path);
        $this->assertSame(0, $community->depth);
        $this->assertSame('trial', $community->status);
        $this->assertCount(count(config('waumini.role_templates')), $community->roles);
        $this->assertTrue($community->roles()->where('key', 'administrateur')->first()->is_locked);
        $this->assertSame(1, $community->roleAssignments()->count());
    }

    public function test_children_get_a_materialized_path(): void
    {
        $siege = $this->createCommunity('CEP Siège');
        $region = $this->createChild($siege, 'Région Nord-Kivu', 'Région');
        $paroisse = $this->createChild($region, 'Paroisse Himbi');

        $this->assertSame("{$siege->id}/{$region->id}/{$paroisse->id}/", $paroisse->path);
        $this->assertSame(2, $paroisse->depth);
        $this->assertSame([$siege->id, $region->id], $paroisse->ancestorIds());
        $this->assertTrue($paroisse->isDescendantOf($siege));
        $this->assertFalse($siege->isDescendantOf($paroisse));
        $this->assertSame($siege->id, $paroisse->root()->id);
        $this->assertEqualsCanonicalizing([$region->id, $paroisse->id], $siege->descendants()->pluck('id')->all());
    }

    public function test_a_parish_registered_alone_can_join_its_headquarters(): void
    {
        $siege = $this->createCommunity('CEP Siège');
        $region = $this->createChild($siege, 'Région Sud', 'Région');
        $paroisse = $this->createCommunity('Paroisse indépendante');
        $annexe = $this->createChild($paroisse, 'Annexe Sake', 'Annexe');

        $paroisse->moveUnder($region);

        $this->assertSame("{$siege->id}/{$region->id}/{$paroisse->id}/", $paroisse->fresh()->path);
        $this->assertSame("{$siege->id}/{$region->id}/{$paroisse->id}/{$annexe->id}/", $annexe->fresh()->path);
        $this->assertSame(3, $annexe->fresh()->depth);
        $this->assertSame($siege->id, $annexe->fresh()->root()->id);
    }

    public function test_an_organization_cannot_be_moved_under_its_own_descendant(): void
    {
        $siege = $this->createCommunity();
        $region = $this->createChild($siege, 'Région', 'Région');

        $this->expectException(InvalidArgumentException::class);
        $siege->moveUnder($region);
    }

    public function test_terminology_is_inherited_from_ancestors(): void
    {
        $siege = $this->createCommunity();
        $siege->update(['terminology' => ['pasteur' => 'Imam']]);
        $paroisse = $this->createChild($siege, 'Mosquée du quartier');

        $this->assertSame('Imam', $paroisse->term('pasteur'));
        $this->assertSame('Pasteur', $this->createCommunity('Autre')->term('pasteur'));
    }

    public function test_slugs_are_unique(): void
    {
        $a = $this->createCommunity('Église Béthel');
        $b = $this->createCommunity('Église Béthel');

        $this->assertSame('eglise-bethel', $a->slug);
        $this->assertSame('eglise-bethel-2', $b->slug);
        $this->assertInstanceOf(Organization::class, $b);
    }
}
