<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Support\PageTemplates;
use App\Support\Seo;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** Content pages: About, FAQ, shipping / refund / privacy policies… (Markdown body). */
class PageController extends Controller
{
    public function index(): JsonResponse
    {
        $existing = Page::pluck('handle')->all();

        return response()->json([
            'data' => Page::orderBy('title')->get()->map(fn (Page $p) => $this->summary($p)),
            // Ready-made pages the store doesn't have yet
            'templates' => collect(PageTemplates::all())
                ->reject(fn ($t, $handle) => in_array($handle, $existing, true))
                ->map(fn ($t, $handle) => ['handle' => $handle, 'title' => $t['title'], 'label' => $t['label']])
                ->values(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->detail(Page::findOrFail($id))]);
    }

    public function store(Request $request, CurrentStore $current): JsonResponse
    {
        // From a template: prefilled with the store's name, unpublished until the merchant reviews it
        if ($template = $request->input('template')) {
            $t = PageTemplates::all()[$template] ?? abort(422, __('模板不存在'));
            $page = Page::create([
                'title' => $t['title'],
                'handle' => $this->uniqueHandle($template),
                'body' => str_replace('{store}', $current->getOrFail()->name, $t['body']),
                'published' => false,
            ]);

            return response()->json(['data' => $this->detail($page)], 201);
        }

        $data = $this->validated($request, true);
        $page = Page::create([
            'title' => $data['title'],
            'handle' => $this->uniqueHandle($data['handle'] ?? $data['title']),
            'body' => $data['body'] ?? '',
            'layout' => $data['layout'] ?? 'text',
            'seo' => Seo::clean($data['seo'] ?? null),
            'published' => $data['published'] ?? true,
        ]);

        return response()->json(['data' => $this->detail($page)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $data = $this->validated($request, false);
        $page->fill(collect($data)->only('title', 'body', 'layout', 'published')->all());
        if (array_key_exists('handle', $data)) {
            $page->handle = $this->uniqueHandle($data['handle'] ?: $page->title, $page->id);
        }
        if (array_key_exists('seo', $data)) {
            $page->seo = Seo::clean($data['seo']);
        }
        $page->save();

        return response()->json(['data' => $this->detail($page)]);
    }

    public function destroy(int $id): Response
    {
        Page::findOrFail($id)->delete();   // menu links to it drop out of the storefront automatically

        return response()->noContent();
    }

    /** Live preview of the Markdown body (same renderer as the storefront). */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['body' => ['nullable', 'string', 'max:100000']]);

        return response()->json(['html' => (new Page(['body' => $data['body'] ?? '']))->html()]);
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'handle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'published' => ['sometimes', 'boolean'],
            'layout' => ['sometimes', \Illuminate\Validation\Rule::in(Page::LAYOUTS)],
            'seo' => ['sometimes', 'nullable', 'array'],
            'seo.title' => ['nullable', 'string', 'max:70'],
            'seo.description' => ['nullable', 'string', 'max:320'],
        ], ['title.required' => __('请填写页面标题')]);
    }

    private function summary(Page $p): array
    {
        return [
            'id' => $p->id,
            'title' => $p->title,
            'handle' => $p->handle,
            'published' => $p->published,
            'layout' => $p->layout ?? 'text',
            'updated_at' => $p->updated_at,
        ];
    }

    private function detail(Page $p): array
    {
        return [
            ...$this->summary($p),
            'body' => (string) $p->body,
            // Blocks pages are designed in the theme editor (live theme, this page's template)
            'theme_id' => $p->layout === 'blocks' ? app(\App\Themes\ThemeService::class)->ensureLive(app(\App\Tenancy\CurrentStore::class)->getOrFail())->id : null,
            'seo' => ['title' => $p->seo['title'] ?? null, 'description' => $p->seo['description'] ?? null],
        ];
    }

    private function uniqueHandle(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'page-'.substr(md5($source), 0, 6);
        $handle = $base;
        $i = 2;
        while (Page::where('handle', $handle)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $handle = $base.'-'.$i++;
        }

        return $handle;
    }
}
