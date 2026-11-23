<?php

namespace App\Testing;

use App\Analytics\Visits;
use App\Content\ContentVersions;
use App\I18n\Localization;
use App\Models\ContentExperiment;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\DB;

/**
 * A/B tests of a product's main image or title (request-scoped). Each visitor always sees the same
 * version (arm = hash of the visitor cookie and the test); views and add-to-carts are counted per
 * arm. Bots and untracked requests always see A and aren't counted. Title tests only run in the
 * store's default language (other languages show their translation).
 */
class Experiments
{
    public const MIN_VIEWS = 100;   // per arm, before a winner is called

    /** @var array<int, ?ContentExperiment> */
    private array $running = [];

    public function running(int $productId): ?ContentExperiment
    {
        if (! array_key_exists($productId, $this->running)) {
            $this->running[$productId] = ContentExperiment::where('product_id', $productId)->where('status', 'running')->latest('id')->first();
        }

        return $this->running[$productId];
    }

    public function arm(ContentExperiment $e): ?string
    {
        $visitor = app(Visits::class)->visitorId();

        return $visitor ? (crc32($visitor.'|'.$e->id) % 2 === 0 ? 'a' : 'b') : null;
    }

    /** Product page: count the view and show the visitor's version. */
    public function present(Product $product): void
    {
        $e = $this->running($product->id);
        if (! $e || ! app(Visits::class)->trackable(request())) {
            return;
        }
        if ($e->kind === 'title' && app(Localization::class)->translating()) {
            return;   // tested in the default language only
        }
        $arm = $this->arm($e);
        if (! $arm) {
            return;
        }
        DB::table('content_experiments')->where('id', $e->id)->increment($arm.'_views');
        if ($arm === 'a') {
            return;
        }
        if ($e->kind === 'title') {
            $product->setAttribute('title', $e->b_value);   // display only: the storefront never saves products
        } else {
            $b = $product->images->firstWhere('id', (int) $e->b_value);
            if ($b) {
                $product->setRelation('images', $product->images->reject(fn ($i) => $i->id === $b->id)->prepend($b)->values());
            }
        }
    }

    /** Add to cart: credited to the arm this visitor saw. */
    public function cart(?int $productId): void
    {
        if (! $productId || ! ($e = $this->running($productId)) || ! app(Visits::class)->trackable(request())) {
            return;
        }
        if ($arm = $this->arm($e)) {
            DB::table('content_experiments')->where('id', $e->id)->increment($arm.'_carts');
        }
    }

    /**
     * Add-to-cart rate per arm, uplift of B and a two-proportion z-test.
     *
     * @return array{a_rate: float, b_rate: float, uplift: ?float, p_value: ?float, ready: bool, leader: ?string}
     */
    public static function stats(ContentExperiment $e): array
    {
        $ra = $e->a_views ? $e->a_carts / $e->a_views : 0.0;
        $rb = $e->b_views ? $e->b_carts / $e->b_views : 0.0;
        $p = null;
        if ($e->a_views && $e->b_views) {
            $pool = ($e->a_carts + $e->b_carts) / ($e->a_views + $e->b_views);
            $se = sqrt($pool * (1 - $pool) * (1 / $e->a_views + 1 / $e->b_views));
            $p = $se > 0 ? 2 * (1 - self::normalCdf(abs($rb - $ra) / $se)) : 1.0;
        }
        $ready = $e->a_views >= self::MIN_VIEWS && $e->b_views >= self::MIN_VIEWS && $p !== null && $p < 0.05;

        return [
            'a_rate' => round($ra * 100, 2), 'b_rate' => round($rb * 100, 2),
            'uplift' => $ra > 0 ? round(($rb - $ra) / $ra * 100, 1) : null,
            'p_value' => $p === null ? null : round($p, 4),
            'ready' => $ready,
            'leader' => $ra === $rb ? null : ($rb > $ra ? 'b' : 'a'),
        ];
    }

    /** End a test; when B wins it becomes the product's real title / main image. */
    public function finish(ContentExperiment $e, ?string $winner): void
    {
        DB::transaction(function () use ($e, $winner) {
            if ($winner === 'b') {
                $product = Product::findOrFail($e->product_id);
                if ($e->kind === 'title') {
                    app(ContentVersions::class)->apply($product, null, ['title' => $e->b_value], 'before_test');
                } elseif ($b = ProductImage::where('product_id', $product->id)->find((int) $e->b_value)) {
                    $others = ProductImage::where('product_id', $product->id)->whereKeyNot($b->id)->orderBy('position')->orderBy('id')->pluck('id');
                    $b->update(['position' => 0]);
                    foreach ($others as $i => $id) {
                        ProductImage::whereKey($id)->update(['position' => $i + 1]);
                    }
                }
            }
            $e->forceFill(['status' => $winner ? 'finished' : 'stopped', 'winner' => $winner, 'ended_at' => now()])->save();
        });
    }

    /** Two-proportion z-test: p-value for "the rates x/n differ" (null without data). Also used by design A/B tests. */
    public static function zTest(int $na, int $xa, int $nb, int $xb): ?float
    {
        if (! $na || ! $nb) {
            return null;
        }
        $pool = ($xa + $xb) / ($na + $nb);
        $se = sqrt($pool * (1 - $pool) * (1 / $na + 1 / $nb));

        return $se > 0 ? 2 * (1 - self::normalCdf(abs($xb / $nb - $xa / $na) / $se)) : 1.0;
    }

    /** Standard normal CDF (Abramowitz–Stegun approximation, error < 7.5e-8). */
    /**
     * Two-sided p-value for a difference in means (Welch, normal approximation — fine for hundreds of visits),
     * from each arm's count, sum and sum of squares.
     */
    public static function meanTest(int $na, float $sa, float $qa, int $nb, float $sb, float $qb): ?float
    {
        if ($na < 2 || $nb < 2) {
            return null;
        }
        $va = max(0, ($qa - $sa * $sa / $na) / ($na - 1));
        $vb = max(0, ($qb - $sb * $sb / $nb) / ($nb - 1));
        $se = sqrt($va / $na + $vb / $nb);

        return $se > 0 ? 2 * (1 - self::normalCdf(abs($sb / $nb - $sa / $na) / $se)) : 1.0;
    }

    /** Visits per arm needed to detect the difference between two rates (80% power, 5% significance). */
    public static function sampleSize(float $pa, float $pb): ?int
    {
        $d = abs($pb - $pa);
        if ($d <= 0 || $pa <= 0) {
            return null;
        }
        $p = ($pa + $pb) / 2;

        return (int) ceil(15.7 * $p * (1 - $p) / ($d * $d));   // (1.96 + 0.84)² × 2 ≈ 15.7
    }

    private static function normalCdf(float $z): float
    {
        $t = 1 / (1 + 0.2316419 * abs($z));
        $d = 0.3989423 * exp(-$z * $z / 2);
        $p = $d * $t * (0.3193815 + $t * (-0.3565638 + $t * (1.781478 + $t * (-1.821256 + $t * 1.330274))));

        return $z > 0 ? 1 - $p : $p;
    }
}
