<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BlogTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://shop-a.lumee.localhost';

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $res = $this->postJson('/api/admin/auth/register', [
            'name' => 'M', 'email' => 'owner@shop-a.test', 'password' => 'secret-pass', 'store_name' => 'Shop A',
        ])->assertCreated();
        $this->a = ['token' => $res['token'], 'store_id' => $res['stores'][0]['id']];
    }

    private function as(array $a = null): static
    {
        $a ??= $this->a;
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$a['token'], 'X-Store-Id' => (string) $a['store_id'], 'Accept' => 'application/json']);
    }

    private function front(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function createPost(array $data): array
    {
        return $this->as()->postJson('/api/admin/blog-posts', $data)->assertCreated()->json('data');
    }

    public function test_create_publish_schedule_and_render(): void
    {
        $this->as()->postJson('/api/admin/blog-posts', ['title' => ''])->assertStatus(422);
        $this->as()->postJson('/api/admin/blog-posts', ['title' => 'X', 'cover_image' => 'javascript:alert(1)'])->assertStatus(422)->assertJsonValidationErrors('cover_image');

        $live = $this->createPost([
            'title' => 'How to Layer Necklaces', 'body' => "## Start short\n\nPick a **choker** first.<script>alert(1)</script>\n\n[bad](javascript:alert(1))",
            'tags' => ['Styling', 'styling', 'Guides'], 'author' => 'Mia', 'published' => true, 'cover_image' => 'https://cdn.example.com/c.jpg',
        ]);
        $this->assertSame('how-to-layer-necklaces', $live['handle']);
        $this->assertSame(['Styling', 'Guides'], $live['tags']);   // de-duplicated, case-insensitively
        $this->assertNotNull($live['published_at']);              // first publish stamps the date

        $draft = $this->createPost(['title' => 'Secret draft', 'body' => 'Not yet']);
        $future = $this->createPost(['title' => 'Coming soon', 'body' => 'Later', 'published' => true, 'published_at' => now()->addDay()->toIso8601String()]);
        $this->assertTrue($future['scheduled']);
        $this->assertSame('how-to-layer-necklaces-2', $this->createPost(['title' => 'How to layer necklaces'])['handle']);

        $index = $this->front()->get(self::HOST.'/blog')->assertOk()
            ->assertSee('How to Layer Necklaces')->assertDontSee('Secret draft')->assertDontSee('Coming soon')
            ->assertSee('Styling')->assertSee('application/atom+xml', false);
        $this->front()->get(self::HOST.'/blog?tag=Guides')->assertOk()->assertSee('How to Layer Necklaces');
        $this->front()->get(self::HOST.'/blog?tag=Nope')->assertOk()->assertSee('No posts yet');

        $article = $this->front()->get(self::HOST.'/blog/how-to-layer-necklaces')->assertOk()
            ->assertSee('<h2>Start short</h2>', false)->assertSee('<strong>choker</strong>', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('javascript:alert', false)
            ->assertSee('"@type":"BlogPosting"', false)->assertSee('By Mia');
        $this->front()->get(self::HOST.'/blog/secret-draft')->assertNotFound();
        $this->front()->get(self::HOST.'/blog/coming-soon')->assertNotFound();

        // Scheduled post appears once its time comes
        $this->travel(2)->days();
        $this->front()->get(self::HOST.'/blog/coming-soon')->assertOk();
        $this->travelBack();

        // Feed + sitemap
        $feed = simplexml_load_string($this->front()->get(self::HOST.'/blog/feed.xml')->assertOk()->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8')->getContent());
        $this->assertSame('How to Layer Necklaces', (string) $feed->entry[0]->title);
        $this->front()->get(self::HOST.'/sitemap.xml')->assertSee('/blog/how-to-layer-necklaces</loc>', false)->assertDontSee('secret-draft', false);

        // Update / unpublish / delete
        $this->as()->putJson("/api/admin/blog-posts/{$live['id']}", ['published' => false])->assertOk()->assertJsonPath('data.published', false);
        $this->front()->get(self::HOST.'/blog/how-to-layer-necklaces')->assertNotFound();
        $this->as()->deleteJson("/api/admin/blog-posts/{$draft['id']}")->assertNoContent();
        $this->as()->getJson('/api/admin/blog-posts')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_translation_menu_link_and_isolation(): void
    {
        $p = $this->createPost(['title' => 'Gift guide', 'excerpt' => 'Ideas for her', 'body' => 'Rings and more', 'published' => true]);
        $this->as()->putJson('/api/admin/settings/languages', ['default' => 'en', 'published' => ['fr']])->assertOk();
        $this->as()->getJson('/api/admin/translations?type=post&locale=fr')->assertOk()->assertJsonPath('data.0.label', 'Gift guide');
        $this->as()->putJson("/api/admin/translations/post/{$p['id']}", ['locale' => 'fr', 'fields' => ['title' => 'Guide des cadeaux', 'excerpt' => 'Des idées pour elle']])->assertOk();
        $this->front()->get(self::HOST.'/fr/blog')->assertOk()->assertSee('Guide des cadeaux')->assertSee('Des idées pour elle')->assertSee('href="/fr/blog/gift-guide"', false);
        $this->front()->get(self::HOST.'/blog')->assertSee('Gift guide');

        // Menu item of type "blog"
        $menus = $this->as()->getJson('/api/admin/menus')->assertOk();
        $this->assertContains('blog', array_keys($menus->json('types')));
        $items = [['id' => 'b1', 'title' => 'Journal', 'type' => 'blog', 'children' => []]];
        $this->as()->putJson('/api/admin/menus/main', ['items' => $items])->assertOk();
        $this->front()->get(self::HOST.'/')->assertSee('href="/blog"', false)->assertSee('Journal');

        // Another store can't see or edit it
        $b = $this->postJson('/api/admin/auth/register', ['name' => 'B', 'email' => 'b@shop-b.test', 'password' => 'secret-pass', 'store_name' => 'Shop B'])->json();
        $other = ['token' => $b['token'], 'store_id' => $b['stores'][0]['id']];
        $this->as($other)->getJson("/api/admin/blog-posts/{$p['id']}")->assertNotFound();
        $this->front()->get('http://shop-b.lumee.localhost/blog/gift-guide')->assertNotFound();
        $this->assertSame(1, BlogPost::withoutGlobalScope('store')->count());
    }
}
