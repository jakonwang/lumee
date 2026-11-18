<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Imports\ProductCsv;
use App\Imports\RunProductImport;
use App\Models\Product;
use App\Models\ProductImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk import/export. Import is two-step: upload → preview (parsed + validated, nothing saved
 * to the catalog) → start (queued job). Requires the "products" permission.
 */
class ProductImportController extends Controller
{
    public function template(): StreamedResponse
    {
        $csv = "\u{FEFF}".implode("\n", [
            'Handle,Title,Body (HTML),Vendor,Type,Tags,Status,Collection,Option1 Name,Option1 Value,Option2 Name,Option2 Value,Variant SKU,Variant Price,Variant Compare At Price,Variant Inventory Qty,Variant Grams,Image Src',
            'linen-shirt,Linen Shirt,"<p>Breathable 100% linen.</p>",Bloom,Shirts,"summer, linen",active,New Arrivals,Color,White,Size,M,LS-W-M,49.00,59.00,20,220,https://example.com/images/linen-white.jpg',
            'linen-shirt,,,,,,,,,White,,L,LS-W-L,49.00,59.00,15,240,',
            'linen-shirt,,,,,,,,,Blue,,M,LS-B-M,49.00,,8,220,https://example.com/images/linen-blue.jpg',
            'canvas-tote,Canvas Tote,Everyday tote bag.,Bloom,Bags,,active,,,,,,TOTE-1,25.00,,40,350,',
        ])."\n";

        return response()->streamDownload(fn () => print($csv), 'lumee-products-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function export(ProductCsv $csv): StreamedResponse
    {
        $products = Product::with('variants', 'options', 'images', 'collections')->orderBy('id')->get();

        return response()->streamDownload(fn () => print($csv->export($products)), 'products-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Upload a CSV and get a preview. Nothing is created yet. */
    public function store(Request $request, ProductCsv $csv): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/octet-stream'],
            'mode' => ['required', Rule::in(['create', 'overwrite'])],
        ], [
            'file.max' => __('文件不能超过 5MB'),
            'file.mimetypes' => __('请上传 CSV 文件（Excel 中选择「另存为 → CSV」）'),
        ]);
        if (strtolower($request->file('file')->getClientOriginalExtension()) !== 'csv') {
            return response()->json(['message' => __('请上传 .csv 文件（Excel 中选择「另存为 → CSV」）')], 422);
        }

        $parsed = $csv->parse((string) file_get_contents($request->file('file')->getRealPath()));
        if (! $parsed['products']) {
            return response()->json([
                'message' => __('没有可导入的商品'),
                'errors_list' => array_slice($parsed['errors'], 0, 100),
            ], 422);
        }

        $handles = array_column($parsed['products'], 'handle');
        $existing = Product::whereIn('handle', $handles)->count();   // store-scoped

        $import = ProductImport::create([
            'user_id' => $request->user()->id,
            'filename' => mb_substr($request->file('file')->getClientOriginalName(), 0, 200),
            'mode' => $request->input('mode'),
            'status' => 'preview',
            'payload' => json_encode($parsed['products']),
            'total' => count($parsed['products']),
            'summary' => [
                'rows' => $parsed['rows'],
                'products' => count($parsed['products']),
                'variants' => array_sum(array_map(fn ($p) => count($p['variants']), $parsed['products'])),
                'images' => array_sum(array_map(fn ($p) => count($p['images']), $parsed['products'])),
                'existing' => $existing,
                'new' => count($parsed['products']) - $existing,
                'errors' => array_slice($parsed['errors'], 0, 100),
                'error_count' => count($parsed['errors']),
                'sample' => array_map(fn ($p) => [
                    'title' => $p['title'],
                    'handle' => $p['handle'],
                    'variants' => count($p['variants']),
                    'price' => min(array_map(fn ($v) => (float) $v['price'], $p['variants'])),
                    'images' => count($p['images']),
                    'status' => $p['status'],
                ], array_slice($parsed['products'], 0, 8)),
            ],
        ]);

        return response()->json(['data' => $import->toApi()], 201);
    }

    public function start(int $id): JsonResponse
    {
        $import = ProductImport::where('status', 'preview')->findOrFail($id);   // store-scoped
        $import->update(['status' => 'queued']);
        RunProductImport::dispatch($import->id);

        return response()->json(['data' => $import->fresh()->toApi()]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => ProductImport::findOrFail($id)->toApi()]);
    }
}
