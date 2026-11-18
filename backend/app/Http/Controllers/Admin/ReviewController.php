<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductReview;
use App\Reviews\Reviews;
use App\Tenancy\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Review moderation: list, publish / hide, reply, delete, bulk actions, CSV import and settings. */
class ReviewController extends Controller
{
    public function index(Request $request, CurrentStore $current): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(ProductReview::STATUSES)],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'product_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $query = ProductReview::with('product:id,title,handle')->latest();
        if ($s = $request->input('status')) {
            $query->where('status', $s);
        }
        if ($r = $request->input('rating')) {
            $query->where('rating', (int) $r);
        }
        if ($p = $request->input('product_id')) {
            $query->where('product_id', (int) $p);
        }
        if ($q = trim((string) $request->input('q'))) {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $query->where(fn ($w) => $w->where('author', 'like', $like)->orWhere('email', 'like', $like)->orWhere('body', 'like', $like)->orWhere('title', 'like', $like));
        }
        $page = $query->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (ProductReview $r) => $this->row($r)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'counts' => ProductReview::select('status', DB::raw('COUNT(*) as n'))->groupBy('status')->pluck('n', 'status'),
            'settings' => Reviews::settings($current->getOrFail()),
        ]);
    }

    public function update(Request $request, int $id, Reviews $reviews): JsonResponse
    {
        $review = ProductReview::findOrFail($id);
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(ProductReview::STATUSES)],
            'reply' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        if (array_key_exists('reply', $data)) {
            $reply = trim((string) $data['reply']);
            $review->reply = $reply === '' ? null : $reply;
            $review->replied_at = $reply === '' ? null : now();
        }
        if (isset($data['status'])) {
            $review->status = $data['status'];
        }
        $review->save();
        $reviews->recount($review->product_id);

        return response()->json(['data' => $this->row($review->load('product:id,title,handle'))]);
    }

    public function destroy(int $id, Reviews $reviews): JsonResponse
    {
        $review = ProductReview::findOrFail($id);
        $review->delete();
        $reviews->recount($review->product_id);

        return response()->json(null, 204);
    }

    public function bulk(Request $request, Reviews $reviews): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:200'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['publish', 'hide', 'delete'])],
        ]);
        $items = ProductReview::whereIn('id', $data['ids'])->get();   // store-scoped
        foreach ($items as $r) {
            $data['action'] === 'delete' ? $r->delete() : $r->update(['status' => $data['action'] === 'publish' ? 'published' : 'hidden']);
        }
        foreach ($items->pluck('product_id')->unique() as $pid) {
            $reviews->recount($pid);
        }

        return response()->json(['affected' => $items->count()]);
    }

    public function settings(Request $request, CurrentStore $current): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'auto_publish' => ['required', Rule::in(Reviews::AUTO_PUBLISH)],
            'request_delay_days' => ['nullable', 'integer', Rule::in(Reviews::REQUEST_DELAYS)],
        ]);
        Reviews::saveSettings($current->getOrFail(), $data);

        return response()->json(['settings' => Reviews::settings($current->getOrFail()->fresh())]);
    }

    /**
     * Import reviews from a CSV (e.g. exported from another platform). Columns, by header name:
     * product_handle, rating, author, body (required) · email, title, created_at, images (URLs), verified, reply.
     */
    public function import(Request $request, Reviews $reviews): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']], [
            'file.mimes' => __('请上传 CSV 文件'),
        ]);

        $fh = fopen($request->file('file')->getRealPath(), 'r');
        $first = fgets($fh);
        $first = preg_replace('/^\xEF\xBB\xBF/', '', (string) $first);   // Excel's UTF-8 BOM
        $header = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-'], '_', $h))), str_getcsv($first));
        foreach (['product_handle', 'rating', 'author', 'body'] as $required) {
            if (! in_array($required, $header, true)) {
                fclose($fh);
                throw ValidationException::withMessages(['file' => __('CSV 缺少必填列：:p0', ['p0' => $required])]);
            }
        }

        $products = Product::pluck('id', 'handle');
        $imported = 0;
        $errors = [];
        $line = 1;
        $touched = [];
        while (($cells = fgetcsv($fh)) !== false) {
            $line++;
            if ($cells === [null] || count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            if ($line > 5001) {
                $errors[] = ['line' => $line, 'message' => __('一次最多导入 5000 条，其余行已忽略')];
                break;
            }
            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            $pid = $products[trim($row['product_handle'])] ?? null;
            $rating = (int) $row['rating'];
            $author = trim($row['author']);
            $body = trim($row['body']);
            $problem = match (true) {
                ! $pid => __('找不到商品：:p0', ['p0' => mb_substr($row['product_handle'], 0, 60)]),
                $rating < 1 || $rating > 5 => __('评分必须是 1 到 5'),
                $author === '' => __('缺少评论人'),
                $body === '' => __('缺少评论内容'),
                default => null,
            };
            if ($problem) {
                $errors[] = ['line' => $line, 'message' => $problem];

                continue;
            }
            $images = collect(preg_split('/[\s,;|]+/', (string) ($row['images'] ?? '')))
                ->filter(fn ($u) => preg_match('#^https?://\S+$#i', $u))->take(5)->map(fn ($u) => ['url' => $u])->values()->all();
            try {
                $date = filled($row['created_at'] ?? null) ? Carbon::parse($row['created_at']) : now();
            } catch (\Throwable) {
                $date = now();
            }
            ProductReview::create([
                'product_id' => $pid,
                'author' => mb_substr($author, 0, 80),
                'email' => filter_var(trim((string) ($row['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: null,
                'rating' => $rating,
                'title' => filled($row['title'] ?? null) ? mb_substr(trim($row['title']), 0, 150) : null,
                'body' => mb_substr($body, 0, 5000),
                'images' => $images ?: null,
                'status' => 'published',
                'verified' => in_array(strtolower(trim((string) ($row['verified'] ?? ''))), ['1', 'true', 'yes', 'y'], true),
                'source' => 'import',
                'reply' => filled($row['reply'] ?? null) ? mb_substr(trim($row['reply']), 0, 2000) : null,
                'replied_at' => filled($row['reply'] ?? null) ? $date : null,
                'created_at' => $date->min(now()),
            ]);
            $touched[$pid] = true;
            $imported++;
        }
        fclose($fh);
        foreach (array_keys($touched) as $pid) {
            $reviews->recount($pid);
        }

        return response()->json(['imported' => $imported, 'errors' => array_slice($errors, 0, 100), 'error_count' => count($errors)]);
    }

    private function row(ProductReview $r): array
    {
        return [
            'id' => $r->id,
            'product' => $r->product ? ['id' => $r->product->id, 'title' => $r->product->title, 'handle' => $r->product->handle] : null,
            'author' => $r->author,
            'email' => $r->email,
            'rating' => $r->rating,
            'title' => $r->title,
            'body' => $r->body,
            'images' => $r->imageUrls(),
            'status' => $r->status,
            'verified' => $r->verified,
            'source' => $r->source,
            'locale' => $r->locale,
            'reply' => $r->reply,
            'replied_at' => $r->replied_at,
            'created_at' => $r->created_at,
        ];
    }
}
