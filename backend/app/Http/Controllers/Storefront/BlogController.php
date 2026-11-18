<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\I18n\Localization;
use App\Models\BlogPost;
use App\Tenancy\CurrentStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BlogController extends Controller
{
    public function index(Request $request, CurrentStore $current): View
    {
        $tag = mb_substr(trim((string) $request->query('tag')), 0, 40);
        $posts = BlogPost::live()
            ->when($tag !== '', fn ($q) => $q->whereJsonContains('tags', $tag))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(12)->withQueryString();
        abort_if($posts->isEmpty() && $posts->currentPage() > 1, 404);

        $tags = BlogPost::live()->whereNotNull('tags')->pluck('tags')->flatten()->countBy()->sortDesc()->keys()->take(20)->values();

        return view($current->themeView('blog'), compact('posts', 'tag', 'tags'));
    }

    public function show(CurrentStore $current, string $handle): View
    {
        $post = BlogPost::live()->where('handle', $handle)->firstOrFail();
        $related = BlogPost::live()->whereKeyNot($post->id)->orderByDesc('published_at')->limit(3)->get();

        return view($current->themeView('article'), compact('post', 'related'));
    }

    /** Atom feed of the latest posts (for feed readers and newsletter tools). */
    public function feed(CurrentStore $current, Localization $l10n): Response
    {
        $store = $current->getOrFail();
        $base = $store->storefrontUrl().$l10n->prefix();
        $posts = BlogPost::live()->orderByDesc('published_at')->limit(20)->get();
        $x = fn ($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $updated = ($posts->first()?->updated_at ?? $store->updated_at ?? now())->toAtomString();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<feed xmlns="http://www.w3.org/2005/Atom">'
            .'<title>'.$x($store->name.' — '.__('Blog')).'</title>'
            .'<link href="'.$x($base.'/blog').'"/>'
            .'<link rel="self" href="'.$x($base.'/blog/feed.xml').'"/>'
            .'<id>'.$x($base.'/blog').'</id><updated>'.$updated.'</updated>'."\n";
        foreach ($posts as $p) {
            $url = $base.'/blog/'.rawurlencode($p->handle);
            $xml .= '<entry><title>'.$x($p->title).'</title><link href="'.$x($url).'"/><id>'.$x($url).'</id>'
                .'<published>'.$p->published_at?->toAtomString().'</published><updated>'.$p->updated_at->toAtomString().'</updated>'
                .($p->author ? '<author><name>'.$x($p->author).'</name></author>' : '<author><name>'.$x($store->name).'</name></author>')
                .'<summary>'.$x($p->summary(300)).'</summary>'
                .'<content type="html">'.$x($p->html()).'</content>'
                ."</entry>\n";
        }
        $xml .= "</feed>\n";

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=UTF-8']);
    }
}
