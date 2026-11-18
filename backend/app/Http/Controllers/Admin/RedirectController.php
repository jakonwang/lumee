<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UrlRedirect;
use App\Support\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * URL redirects (Online Store › Navigation): old path → new address, 301.
 * CSV import accepts our export and Shopify's ("Redirect from","Redirect to").
 */
class RedirectController extends Controller
{
    public const MAX = 20000;

    public function index(Request $request): JsonResponse
    {
        $page = UrlRedirect::query()
            ->when($request->query('q'), function ($q, $t) {
                $like = '%'.addcslashes($t, '%_\\').'%';
                $q->where(fn ($w) => $w->where('path', 'like', $like)->orWhere('target', 'like', $like));
            })
            ->latest('id')->paginate(min((int) $request->query('per_page', 20), 100));

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        abort_if(UrlRedirect::count() >= self::MAX, 422, __('最多可以设置 :p0 条重定向', ['p0' => self::MAX]));
        [$path, $target] = $this->validated($request);

        return response()->json(['data' => UrlRedirect::create(['path' => $path, 'target' => $target])], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $r = UrlRedirect::findOrFail($id);
        [$path, $target] = $this->validated($request, $r->id);
        $r->update(['path' => $path, 'target' => $target]);

        return response()->json(['data' => $r]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer']]);

        return response()->json(['deleted' => UrlRedirect::whereIn('id', $data['ids'])->delete()]);
    }

    public function export(): StreamedResponse
    {
        $rows = function () {
            foreach (UrlRedirect::query()->lazyById(1000) as $r) {
                yield [$r->path, $r->target];
            }
        };

        return Csv::download('redirects-'.now()->format('Ymd').'.csv', ['Redirect from', 'Redirect to'], $rows());
    }

    /** Import a CSV (from, to). Existing paths are updated; bad rows are reported, not fatal. */
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']], ['file.max' => __('文件不能超过 5MB')]);
        $text = (string) file_get_contents($request->file('file')->getRealPath());
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'GB18030');
        }
        $lines = array_map('str_getcsv', preg_split('/\r\n|\n|\r/', trim($text)));
        if ($lines && preg_match('/redirect|from|path|原/i', implode(',', $lines[0]))) {
            array_shift($lines);
        }

        $created = $updated = 0;
        $errors = [];
        $room = self::MAX - UrlRedirect::count();
        foreach (array_slice($lines, 0, 5000) as $i => $row) {
            if (count(array_filter($row, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            try {
                [$path, $target] = $this->clean((string) ($row[0] ?? ''), (string) ($row[1] ?? ''));
            } catch (ValidationException $e) {
                $errors[] = ['line' => $i + 2, 'message' => collect($e->errors())->flatten()->first()];

                continue;
            }
            $existing = UrlRedirect::where('path', $path)->first();
            if ($existing) {
                $existing->update(['target' => $target]);
                $updated++;
            } elseif ($room-- > 0) {
                UrlRedirect::create(['path' => $path, 'target' => $target]);
                $created++;
            }
        }

        return response()->json(['created' => $created, 'updated' => $updated, 'errors' => array_slice($errors, 0, 50)]);
    }

    private function validated(Request $request, ?int $ignore = null): array
    {
        $request->validate(['path' => ['required', 'string', 'max:500'], 'target' => ['required', 'string', 'max:500']]);
        [$path, $target] = $this->clean($request->input('path'), $request->input('target'));
        if (UrlRedirect::where('path', $path)->when($ignore, fn ($q) => $q->whereKeyNot($ignore))->exists()) {
            throw ValidationException::withMessages(['path' => __('这个路径已经有重定向了')]);
        }

        return [$path, $target];
    }

    /** @return array{string, string} */
    private function clean(string $path, string $target): array
    {
        $path = UrlRedirect::normalize($path);
        $target = trim($target);
        if ($path === '/' || $path === '') {
            throw ValidationException::withMessages(['path' => __('不能重定向首页')]);
        }
        $ok = preg_match('~^(/(?!/)|https?://)~i', $target) === 1 && ! preg_match('/[\s<>"\']/', $target);
        if (! $ok) {
            throw ValidationException::withMessages(['target' => __('目标必须是站内路径（以 / 开头）或 http(s):// 开头的网址')]);
        }
        if (str_starts_with($target, '/') && UrlRedirect::normalize($target) === $path) {
            throw ValidationException::withMessages(['target' => __('目标不能和原路径相同')]);
        }

        return [$path, mb_substr($target, 0, 500)];
    }
}
