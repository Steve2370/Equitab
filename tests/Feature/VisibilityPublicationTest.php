<?php

namespace Tests\Feature;

use App\Features\Group\Services\GroupDraftService;
use App\Models\Group;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GroupDraftTestCase;

class VisibilityPublicationTest extends GroupDraftTestCase
{
    public static function modesAndEndpoints(): array
    {
        $cases = [];
        foreach (['public', 'private', 'invite_only'] as $mode) {
            foreach (['/group-drafts', '/api/group-drafts'] as $base) {
                $cases[$mode.' '.$base] = [$mode, $base];
            }
        }

        return $cases;
    }

    #[DataProvider('modesAndEndpoints')]
    public function test_publication_normalizes_legacy_visibility_and_generates_only_private_links(string $mode, string $base): void
    {
        $owner = $this->readyOwner();
        $draft = $this->draftFor($owner, [...$this->validDraftData(), 'visibility' => $mode]);
        $this->authenticateDraftOwner($owner, $base);
        $this->productGateway->shouldReceive('ensureProduct')->once()->andReturn('prod_visibility_fixture');
        $this->postJson($base.'/'.$draft->id.'/publish', ['version' => 1, 'certify' => true])->assertOk();
        $group = Group::sole();
        $this->assertSame($mode === 'public' ? 'public' : 'private', $group->visibility);
        if ($mode === 'public') {
            $this->assertNull($group->invite_token);
        } else {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $group->invite_token);
            $this->get('/invite/'.$group->invite_token)->assertOk();
        }
        $token = $group->invite_token;
        $this->postJson($base.'/'.$draft->id.'/publish', ['version' => 1, 'certify' => true])->assertOk();
        $this->assertSame($token, $group->fresh()->invite_token);
        $this->assertDatabaseCount('groups', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_old_draft_is_presented_as_private_and_saves_without_losing_its_data_or_version_checks(): void
    {
        $owner = User::factory()->create();
        $data = [...$this->validDraftData(), 'visibility' => 'invite_only'];
        $draft = $this->draftFor($owner, $data);
        $this->actingAs($owner)->get('/dashboard/groups/drafts/'.$draft->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('draft.data.visibility', 'private')->where('draft.version', 1));
        $this->assertSame($data, $draft->fresh()->data, 'Reading a legacy draft must not rewrite it.');
        $same = app(GroupDraftService::class)->create($owner, $draft->id, [...$data, 'visibility' => 'private']);
        $this->assertSame($draft->id, $same->id);
        $this->putJson('/group-drafts/'.$draft->id, ['version' => 1, 'data' => $data])->assertOk()
            ->assertJsonPath('draft.data.visibility', 'private')->assertJsonPath('draft.version', 2);
        $this->assertSame([...$data, 'visibility' => 'private'], $draft->fresh()->data);
        $this->putJson('/group-drafts/'.$draft->id, ['version' => 1, 'data' => $data])->assertConflict();
        $this->assertNoDraftSideEffects();
    }
}
