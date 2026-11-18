<?php

namespace App\Imports;

use App\Models\Product;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Product CSV in Shopify's column format, so merchants can move a Shopify export over as-is.
 * Rows sharing a Handle form one product: the first row carries product fields, each row may add
 * a variant (Option values + Variant price…) and/or an image (Image Src).
 * Chinese column names are accepted as aliases; GBK/GB18030 files (Excel on Chinese Windows) are converted.
 */
class ProductCsv
{
    public const MAX_PRODUCTS = 1000;

    public const MAX_ROWS = 10000;

    /** Canonical column => accepted header spellings (compared lower-case, trimmed). */
    private const COLUMNS = [
        'handle' => ['handle', '网址', '商品网址'],
        'title' => ['title', '标题', '商品名称'],
        'body' => ['body (html)', 'body', 'description', '描述', '商品描述'],
        'vendor' => ['vendor', '品牌'],
        'type' => ['type', 'product type', '类型', '商品类型'],
        'tags' => ['tags', '标签'],
        'published' => ['published'],
        'status' => ['status', '状态'],
        'collection' => ['collection', 'collections', '分类'],
        'option1_name' => ['option1 name', '规格1名称'],
        'option1_value' => ['option1 value', '规格1值'],
        'option2_name' => ['option2 name', '规格2名称'],
        'option2_value' => ['option2 value', '规格2值'],
        'option3_name' => ['option3 name', '规格3名称'],
        'option3_value' => ['option3 value', '规格3值'],
        'sku' => ['variant sku', 'sku'],
        'price' => ['variant price', 'price', '价格'],
        'compare_at_price' => ['variant compare at price', 'compare at price', '原价'],
        'inventory' => ['variant inventory qty', 'inventory', '库存'],
        'weight' => ['variant grams', 'grams', 'weight (g)', '重量', '重量(克)', '重量（克）'],
        'image' => ['image src', 'image', '图片', '图片链接'],
    ];

    /**
     * @return array{products: array<int, array>, errors: array<int, array{row:int, message:string}>, rows:int}
     */
    public function parse(string $contents): array
    {
        $contents = $this->toUtf8($contents);
        $rows = $this->readRows($contents);
        if (! $rows) {
            return ['products' => [], 'errors' => [['row' => 1, 'message' => __('文件是空的')]], 'rows' => 0];
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($rows));
        $map = $this->mapHeader($header);
        if (! isset($map['title']) && ! isset($map['handle'])) {
            return ['products' => [], 'errors' => [['row' => 1, 'message' => __('找不到 Title（标题）列，请使用模板的表头')]], 'rows' => count($rows)];
        }
        if (count($rows) > self::MAX_ROWS) {
            return ['products' => [], 'errors' => [['row' => 1, 'message' => __('一次最多导入 ').self::MAX_ROWS.__(' 行，请拆分文件')]], 'rows' => count($rows)];
        }

        $errors = [];
        $groups = [];   // handle => ['row' => first row no, 'rows' => [[rowNo, assoc]...]]
        $lastHandle = null;
        foreach ($rows as $i => $raw) {
            $rowNo = $i + 2;   // 1-based, header is row 1
            if (! array_filter($raw, fn ($v) => trim((string) $v) !== '')) {
                continue;      // blank line
            }
            $r = [];
            foreach ($map as $key => $idx) {
                $r[$key] = trim((string) ($raw[$idx] ?? ''));
            }
            // A row without handle continues the previous product only if it has no title (Shopify style)
            $handle = $r['handle'] ?? '';
            if ($handle === '') {
                $handle = ($r['title'] ?? '') !== '' ? $r['title'] : $lastHandle;
            }
            // Non-Latin titles (e.g. 中文) slug to "" → stable generated handle from the text itself
            $handle = $handle === null || $handle === '' ? null
                : (Str::slug((string) $handle) ?: 'product-'.substr(md5((string) $handle), 0, 8));
            if (! $handle) {
                $errors[] = ['row' => $rowNo, 'message' => __('缺少 Handle 或 Title，无法识别是哪个商品')];
                continue;
            }
            $lastHandle = $handle;
            $groups[$handle] ??= ['row' => $rowNo, 'rows' => []];
            $groups[$handle]['rows'][] = [$rowNo, $r];
        }

        if (count($groups) > self::MAX_PRODUCTS) {
            return ['products' => [], 'errors' => [['row' => 1, 'message' => __('一次最多导入 ').self::MAX_PRODUCTS.__(' 个商品，请拆分文件')]], 'rows' => count($rows)];
        }

        $products = [];
        foreach ($groups as $handle => $group) {
            [$product, $productErrors] = $this->buildProduct($handle, $group['rows']);
            array_push($errors, ...$productErrors);
            if ($product) {
                $products[] = $product;
            }
        }

        usort($errors, fn ($a, $b) => $a['row'] <=> $b['row']);

        return ['products' => $products, 'errors' => $errors, 'rows' => count($rows)];
    }

    /** @return array{0: ?array, 1: array} */
    private function buildProduct(string $handle, array $rows): array
    {
        $errors = [];
        [$firstRowNo, $first] = $rows[0];
        $fail = function (int $row, string $msg) use (&$errors, $handle) {
            $errors[] = ['row' => $row, 'message' => __('「:p0」：:p1', ['p0' => $handle, 'p1' => $msg])];
        };

        $title = $first['title'] ?? '';
        if ($title === '') {
            $fail($firstRowNo, __('缺少商品标题'));

            return [null, $errors];
        }

        // Options: names come from the first row; "Title / Default Title" means no options (Shopify)
        $optionNames = [];
        foreach ([1, 2, 3] as $n) {
            $name = $first["option{$n}_name"] ?? '';
            if ($name !== '' && ! ($n === 1 && strcasecmp($name, 'Title') === 0 && strcasecmp($first['option1_value'] ?? '', 'Default Title') === 0)) {
                $optionNames[$n] = Str::limit($name, 64, '');
            }
        }

        $variants = [];
        $images = [];
        $seen = [];
        foreach ($rows as [$rowNo, $r]) {
            if (($r['image'] ?? '') !== '') {
                if (! preg_match('~^https?://~i', $r['image'])) {
                    $fail($rowNo, __('图片链接必须以 http:// 或 https:// 开头'));
                } elseif (count($images) < 20 && ! in_array($r['image'], $images, true)) {
                    $images[] = $r['image'];
                }
            }

            $hasVariant = ($r['price'] ?? '') !== '' || array_filter(array_map(fn ($n) => $r["option{$n}_value"] ?? '', array_keys($optionNames)));
            if (! $hasVariant) {
                continue;   // image-only row
            }

            $values = [];
            foreach ($optionNames as $n => $name) {
                $v = Str::limit($r["option{$n}_value"] ?? '', 64, '');
                if ($v === '') {
                    $fail($rowNo, __('缺少规格「:p0」的值', ['p0' => $name]));
                    continue 2;
                }
                $values[$name] = $v;
            }
            $key = Str::lower(implode("\u{1F}", $values));
            if (isset($seen[$key])) {
                $fail($rowNo, __('规格组合重复：').implode(' / ', $values));
                continue;
            }
            $seen[$key] = true;

            $price = $this->money($r['price'] ?? '');
            if ($price === null) {
                $fail($rowNo, __('价格「').($r['price'] ?? '').__('」不是有效数字'));
                continue;
            }
            $compare = ($r['compare_at_price'] ?? '') === '' ? null : $this->money($r['compare_at_price']);
            $inventory = ($r['inventory'] ?? '') === '' ? 0 : filter_var($r['inventory'], FILTER_VALIDATE_INT);
            if ($inventory === false) {
                $fail($rowNo, __('库存「').$r['inventory'].__('」必须是整数'));
                continue;
            }
            $variant = [
                'options' => $values,
                'price' => $price,
                'compare_at_price' => $compare,
                'sku' => ($r['sku'] ?? '') !== '' ? Str::limit($r['sku'], 100, '') : null,
                'inventory' => $inventory,
            ];
            // Only when the file has a weight column — otherwise an overwrite keeps the current weight
            if (($r['weight'] ?? '') !== '') {
                $grams = filter_var($r['weight'], FILTER_VALIDATE_FLOAT);
                if ($grams === false || $grams < 0) {
                    $fail($rowNo, __('重量「').$r['weight'].__('」必须是以克为单位的数字'));
                    continue;
                }
                $variant['weight'] = (int) round($grams);
            }
            $variants[] = $variant;
        }

        if (! $variants) {
            $fail($firstRowNo, __('没有可导入的价格行'));

            return [null, $errors];
        }
        if (count($variants) > 100) {
            $fail($firstRowNo, __('规格组合超过 100 个'));

            return [null, $errors];
        }

        $options = [];
        foreach ($optionNames as $name) {
            $options[] = ['name' => $name, 'values' => array_values(array_unique(array_map(fn ($v) => $v['options'][$name], $variants)))];
        }

        $status = strtolower($first['status'] ?? '');
        if (! in_array($status, ['active', 'draft', 'archived'], true)) {
            $published = strtolower($first['published'] ?? '');
            $status = in_array($published, ['false', '0', 'no'], true) ? 'draft' : 'active';
        }

        return [[
            'handle' => $handle,
            'title' => Str::limit($title, 255, ''),
            'description' => $this->htmlToText($first['body'] ?? ''),
            'vendor' => ($first['vendor'] ?? '') ?: null,
            'product_type' => ($first['type'] ?? '') ?: null,
            'tags' => array_values(array_filter(array_map('trim', explode(',', $first['tags'] ?? '')))),
            'status' => $status,
            'collections' => array_values(array_filter(array_map('trim', explode(',', $first['collection'] ?? '')))),
            'options' => $options,
            'variants' => $variants,
            'images' => $images,
            'row' => $firstRowNo,
        ], $errors];
    }

    /** Export products in the same format (round-trips through parse()). */
    public function export(Collection $products): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\u{FEFF}");   // BOM so Excel opens UTF-8 (Chinese) correctly
        fputcsv($out, ['Handle', 'Title', 'Body (HTML)', 'Vendor', 'Type', 'Tags', 'Status', 'Collection',
            'Option1 Name', 'Option1 Value', 'Option2 Name', 'Option2 Value', 'Option3 Name', 'Option3 Value',
            'Variant SKU', 'Variant Price', 'Variant Compare At Price', 'Variant Inventory Qty', 'Variant Grams', 'Image Src']);

        foreach ($products as $p) {
            /** @var Product $p */
            $names = $p->options->pluck('name')->values();
            $images = $p->images->map->url()->values();
            $rows = max($p->variants->count(), $images->count());
            for ($i = 0; $i < $rows; $i++) {
                $v = $p->variants[$i] ?? null;
                $first = $i === 0;
                $opt = fn ($n) => $v && isset($names[$n]) ? [$first ? $names[$n] : '', $v->options[$names[$n]] ?? ''] : ['', ''];
                fputcsv($out, [
                    $p->handle,
                    $first ? $p->title : '',
                    $first ? (string) $p->description : '',
                    $first ? (string) $p->vendor : '',
                    $first ? (string) $p->product_type : '',
                    $first ? implode(', ', $p->tags ?? []) : '',
                    $first ? $p->status : '',
                    $first ? $p->collections->pluck('title')->implode(', ') : '',
                    ...$opt(0), ...$opt(1), ...$opt(2),
                    $v?->sku ?? '',
                    $v ? Money::format($v->price) : '',
                    $v && $v->compare_at_price !== null ? Money::format($v->compare_at_price) : '',
                    $v ? $v->inventory_quantity : '',
                    $v ? $v->weight : '',
                    $images[$i] ?? '',
                ]);
            }
        }

        rewind($out);

        return stream_get_contents($out);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function toUtf8(string $s): string
    {
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
        if (! mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'GB18030');   // Excel on Chinese Windows saves GBK
        }

        return $s;
    }

    private function readRows(string $contents): array
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $contents);
        rewind($fh);
        $rows = [];
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($fh);

        return $rows;
    }

    private function mapHeader(array $header): array
    {
        $map = [];
        foreach (self::COLUMNS as $key => $aliases) {
            foreach ($header as $idx => $h) {
                if (in_array($h, $aliases, true)) {
                    $map[$key] = $idx;
                    break;
                }
            }
        }

        return $map;
    }

    /** "1,299.00", "$12.5", "12" → "1299.00" style decimal string; null if invalid. */
    private function money(string $v): ?string
    {
        $v = str_replace([',', ' ', '$', '€', '£', '¥'], '', $v);
        if ($v === '' || ! is_numeric($v) || (float) $v < 0 || (float) $v > 9999999) {
            return null;
        }

        return number_format((float) $v, 2, '.', '');
    }

    /** Shopify descriptions are HTML; we store plain text (rendered escaped with line breaks). */
    private function htmlToText(string $html): ?string
    {
        if ($html === '') {
            return null;
        }
        $text = preg_replace(['~<br\s*/?>~i', '~</(p|div|li|h[1-6])>~i'], "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text);

        return Str::limit(trim(preg_replace("/\n{3,}/", "\n\n", $text)), 20000, '');
    }
}
