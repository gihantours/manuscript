<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithManuscripts;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use InteractsWithManuscripts, RefreshDatabase;

    public function test_author_cannot_read_another_authors_manuscript(): void
    {
        Storage::fake('local');
        $owner = $this->makeUser('author', 'owner@example.com');
        $other = $this->makeUser('author', 'other@example.com');
        $manuscript = $this->makeManuscript($owner);

        Sanctum::actingAs($other);
        $this->getJson('/api/manuscripts/'.$manuscript->public_id)->assertStatus(403);

        Sanctum::actingAs($owner);
        $this->getJson('/api/manuscripts/'.$manuscript->public_id)
            ->assertOk()
            ->assertJsonPath('data.id', $manuscript->public_id);
    }

    public function test_editor_can_read_any_manuscript(): void
    {
        Storage::fake('local');
        $owner = $this->makeUser('author', 'owner@example.com');
        $editor = $this->makeUser('editor', 'editor@example.com');
        $manuscript = $this->makeManuscript($owner);

        Sanctum::actingAs($editor);
        $this->getJson('/api/manuscripts/'.$manuscript->public_id)->assertOk();
    }

    public function test_only_editors_can_change_status(): void
    {
        Storage::fake('local');
        $author = $this->makeUser('author', 'author@example.com');
        $editor = $this->makeUser('editor', 'editor@example.com');
        $manuscript = $this->makeManuscript($author);

        Sanctum::actingAs($author);
        $this->patchJson('/api/editor/manuscripts/'.$manuscript->public_id.'/status', ['status' => 'accepted'])
            ->assertStatus(403);

        Sanctum::actingAs($editor);
        $this->patchJson('/api/editor/manuscripts/'.$manuscript->public_id.'/status', ['status' => 'accepted'])
            ->assertOk()
            ->assertJson(['status' => 'accepted', 'status_version' => 1]);

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'editor.status_changed']);
        $this->assertDatabaseHas('manuscripts', ['id' => $manuscript->id, 'status' => 'accepted', 'status_version' => 1]);
    }

    public function test_author_cannot_access_editor_list(): void
    {
        Storage::fake('local');
        Sanctum::actingAs($this->makeUser('author', 'author@example.com'));

        $this->getJson('/api/editor/manuscripts')->assertStatus(403);
    }
}
