<?php

declare(strict_types=1);

namespace ByteMinus\AiBlog\Tests\Feature;

use ByteMinus\AiBlog\Models\Post;
use ByteMinus\AiBlog\Seo\StructuredData;
use ByteMinus\AiBlog\Tests\TestCase;

class SeoOutputTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('ai-blog.routes.enabled', true);
        $app['config']->set('ai-blog.seo.site_name', 'Acme Blog');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['view']->addNamespace('seo-test', __DIR__.'/../Fixtures/views');
        config()->set('ai-blog.routes.layout', 'seo-test::layout');
    }

    private function published(array $attributes = []): Post
    {
        return Post::create($attributes + [
            'title' => 'Queues explained',
            'slug' => 'queues-explained',
            'body' => 'Queues move slow work out of the request.',
            'seo_description' => 'How Laravel queues work and when to use them.',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    public function test_a_post_page_names_its_own_address_as_canonical_by_default(): void
    {
        $this->published();

        $this->get('/blog/queues-explained')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/blog/queues-explained').'">', false)
            ->assertSee('<meta property="og:url" content="'.url('/blog/queues-explained').'">', false)
            ->assertSee('<meta property="og:site_name" content="Acme Blog">', false)
            ->assertSee('<meta name="description" content="How Laravel queues work and when to use them.">', false)
            ->assertSee('max-image-preview:large', false)
            ->assertSee('<meta property="article:modified_time"', false)
            ->assertSee('<link rel="alternate" type="application/rss+xml" title="Acme Blog" href="'.route('ai-blog.feed').'">', false);
    }

    public function test_an_explicit_canonical_url_wins(): void
    {
        $this->published(['canonical_url' => 'https://example.com/original']);

        $this->get('/blog/queues-explained')
            ->assertSee('<link rel="canonical" href="https://example.com/original">', false)
            ->assertSee('<meta property="og:url" content="https://example.com/original">', false);
    }

    public function test_a_relative_featured_image_becomes_an_absolute_url_everywhere(): void
    {
        $this->published(['featured_image' => '/storage/queues.png', 'featured_image_alt' => 'A queue']);

        $absolute = url('/storage/queues.png');

        $this->get('/blog/queues-explained')
            ->assertSee('<meta property="og:image" content="'.$absolute.'">', false)
            ->assertSee('<meta name="twitter:image" content="'.$absolute.'">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('<meta property="og:image:alt" content="A queue">', false);
    }

    public function test_it_omits_empty_description_tags_and_escapes_attribute_values(): void
    {
        $this->published(['title' => 'Tom & "Jerry"', 'slug' => 'tom', 'seo_description' => null]);

        $this->get('/blog/tom')
            ->assertDontSee('name="description"', false)
            ->assertDontSee('og:description', false)
            ->assertSee('content="Tom &amp; &quot;Jerry&quot;"', false);
    }

    public function test_the_json_ld_names_the_page_and_the_publisher(): void
    {
        $post = $this->published(['featured_image' => '/storage/queues.png']);

        $data = StructuredData::blogPosting($post);

        $this->assertSame(url('/blog/queues-explained'), $data['url']);
        $this->assertSame(['@type' => 'WebPage', '@id' => url('/blog/queues-explained')], $data['mainEntityOfPage']);
        $this->assertSame(['@type' => 'Organization', 'name' => 'Acme Blog'], $data['publisher']);
        $this->assertSame(url('/storage/queues.png'), $data['image']);
        $this->assertSame($data, $post->structuredData());
    }

    public function test_the_index_describes_itself_and_points_at_the_feed(): void
    {
        $this->published();

        $this->get('/blog')
            ->assertOk()
            ->assertSee('<title>Blog — Acme Blog</title>', false)
            ->assertSee('<meta name="description" content="Latest posts from Acme Blog">', false)
            ->assertSee('<link rel="canonical" href="'.url('/blog').'">', false)
            ->assertSee('<link rel="alternate" type="application/rss+xml" title="Acme Blog" href="'.route('ai-blog.feed').'">', false)
            ->assertSee('<meta property="og:type" content="website">', false);
    }

    public function test_a_configured_site_description_is_used(): void
    {
        config()->set('ai-blog.seo.description', 'Notes on shipping Laravel apps.');
        $this->published();

        $this->get('/blog')->assertSee('<meta name="description" content="Notes on shipping Laravel apps.">', false);
        $this->get('/blog/feed')->assertSee('<description>Notes on shipping Laravel apps.</description>', false);
    }

    public function test_later_index_pages_get_their_own_title_canonical_and_prev_next_links(): void
    {
        foreach (range(1, 13) as $n) {
            $this->published(['title' => "Post {$n}", 'slug' => "post-{$n}", 'published_at' => now()->subMinutes($n)]);
        }

        $this->get('/blog?page=2')
            ->assertOk()
            ->assertSee('<title>Blog — Page 2 — Acme Blog</title>', false)
            ->assertSee('<link rel="canonical" href="'.url('/blog').'?page=2">', false)
            ->assertSee('<link rel="prev" href="'.url('/blog').'?page=1">', false)
            ->assertDontSee('rel="next"', false);

        $this->get('/blog')
            ->assertSee('<link rel="canonical" href="'.url('/blog').'">', false)
            ->assertSee('<link rel="next" href="'.url('/blog').'?page=2">', false)
            ->assertDontSee('rel="prev"', false);
    }

    public function test_the_feed_declares_its_language_and_last_build_date(): void
    {
        $post = $this->published();

        $this->get('/blog/feed')
            ->assertOk()
            ->assertSee('<language>en</language>', false)
            ->assertSee('<lastBuildDate>'.$post->published_at->toRssString().'</lastBuildDate>', false)
            ->assertSee('<guid isPermaLink="true">'.url('/blog/queues-explained').'</guid>', false);
    }
}
