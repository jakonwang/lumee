<?php

namespace App\LeadFinder\Sources;

use App\LeadFinder\LeadProfiles;
use App\LeadFinder\SafeFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenStreetMap (free, no key): finds shops/businesses of the profile's categories inside each city.
 * Data © OpenStreetMap contributors (ODbL). Coverage of phones/websites varies by country.
 */
class OsmSource
{
    private const NOMINATIM = 'https://nominatim.openstreetmap.org/search';

    private const OVERPASS = ['https://overpass-api.de/api/interpreter', 'https://overpass.kumi.systems/api/interpreter'];

    /** @return array<int, array> normalized places */
    public function search(string $profile, array $keywords, string $country, array $cities, int $limit, ?callable $progress = null): array
    {
        $p = LeadProfiles::get($profile);
        $cities = $cities ?: array_slice(LeadProfiles::countries()[$country]['cities'] ?? [], 0, 2);
        if (! $cities) {
            throw new RuntimeException('请至少填写一个城市');
        }
        // Keywords become a name filter: "screen protector wholesale" → "screen|protector"
        $words = collect($keywords)->flatMap(fn ($k) => preg_split('/\s+/u', mb_strtolower($k)))
            ->filter(fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, ['wholesale', 'store', 'shop', 'para', 'with', 'distributor', 'mayoreo', 'atacado'], true))
            ->map(fn ($w) => preg_quote($w, '/'))->unique()->take(12)->implode('|');
        $nameRe = trim($p['osm_name'].($words ? '|'.$words : ''), '|');

        $out = [];
        foreach ($cities as $i => $city) {
            if (count($out) >= $limit) {
                break;
            }
            $bbox = $this->geocode($city, $country);
            if ($i > 0) {
                usleep(1_100_000);   // Nominatim policy: ≤ 1 request / second
            }
            if (! $bbox) {
                continue;
            }
            try {
                $elements = $this->overpass($this->query($p, $nameRe, $bbox));
            } catch (RuntimeException $e) {
                $elements = $this->nominatimPlaces($p, $city, $country);   // Overpass unreachable from this server: smaller, slower fallback
                if (! $elements && ! $out) {
                    throw $e;
                }
            }
            foreach ($elements as $el) {
                $place = $this->normalize($el, $country, $city);
                if ($place) {
                    $out[$place['source_id']] = $place;
                }
            }
            $progress && $progress(count($out));
        }

        return array_slice(array_values($out), 0, $limit);
    }

    /** @return ?array{0: float, 1: float, 2: float, 3: float} south, west, north, east */
    private function geocode(string $city, string $country): ?array
    {
        $r = Http::timeout(15)->withHeaders(['User-Agent' => SafeFetcher::USER_AGENT])
            ->get(self::NOMINATIM, ['q' => $city, 'countrycodes' => strtolower($country), 'format' => 'json', 'limit' => 1]);
        $hit = $r->successful() ? ($r->json()[0] ?? null) : null;
        if (! $hit || empty($hit['boundingbox'])) {
            return null;
        }
        [$s, $n, $w, $e] = array_map('floatval', $hit['boundingbox']);
        // Very large areas (a whole state) make Overpass slow: clamp around the centre
        $lat = (float) $hit['lat'];
        $lng = (float) $hit['lon'];
        $s = max($s, $lat - 0.35);
        $n = min($n, $lat + 0.35);
        $w = max($w, $lng - 0.45);
        $e = min($e, $lng + 0.45);

        return [$s, $w, $n, $e];
    }

    private function query(array $p, string $nameRe, array $bbox): string
    {
        $b = implode(',', array_map(fn ($v) => round($v, 5), $bbox));
        $parts = [];
        foreach ($p['osm'] as $key => $values) {
            $parts[] = sprintf('nwr["%s"~"^(%s)$"](%s);', $key, implode('|', $values), $b);
        }
        if ($nameRe !== '') {
            $re = str_replace('"', '', $nameRe);
            $parts[] = sprintf('nwr["shop"]["name"~"%s",i](%s);', $re, $b);
            $parts[] = sprintf('nwr["office"]["name"~"%s",i](%s);', $re, $b);
        }

        return '[out:json][timeout:60];('.implode('', $parts).');out center tags 400;';
    }

    private function overpass(string $q): array
    {
        if (Cache::get('lead_finder.overpass_down')) {
            throw new RuntimeException('OpenStreetMap 暂时无法访问');
        }
        $last = null;
        foreach (self::OVERPASS as $url) {
            try {
                $r = Http::timeout(70)->connectTimeout(8)->asForm()->withHeaders(['User-Agent' => SafeFetcher::USER_AGENT])->post($url, ['data' => $q]);
                if ($r->successful()) {
                    return $r->json('elements') ?? [];
                }
                $last = 'HTTP '.$r->status();
            } catch (\Throwable $e) {
                $last = $e->getMessage();
            }
        }
        Cache::put('lead_finder.overpass_down', true, 600);   // don't make every city wait for the same timeout

        throw new RuntimeException('OpenStreetMap 暂时无法访问（'.$last.'），请稍后再试');
    }

    /** Nominatim category search ("mobile phone shop in Miami"), returned in Overpass element shape. */
    private function nominatimPlaces(array $p, string $city, string $country): array
    {
        $phrases = ['shop' => ['mobile_phone' => 'mobile phone shop', 'electronics' => 'electronics shop', 'computer' => 'computer shop', 'wholesale' => 'wholesale',
            'gift' => 'gift shop', 'telecommunication' => 'telecommunication shop'], 'craft' => ['electronics_repair' => 'electronics repair']];
        $out = [];
        foreach ($p['osm'] as $key => $values) {
            foreach ($values as $v) {
                if (! isset($phrases[$key][$v])) {
                    continue;
                }
                usleep(1_100_000);
                $r = Http::timeout(20)->withHeaders(['User-Agent' => SafeFetcher::USER_AGENT])->get(self::NOMINATIM, [
                    'q' => $phrases[$key][$v].' in '.$city, 'countrycodes' => strtolower($country), 'format' => 'jsonv2', 'limit' => 50, 'extratags' => 1, 'addressdetails' => 1,
                ]);
                foreach ($r->successful() ? $r->json() : [] as $hit) {
                    $a = $hit['address'] ?? [];
                    $out[] = ['type' => $hit['osm_type'] ?? 'node', 'id' => $hit['osm_id'] ?? 0, 'lat' => (float) $hit['lat'], 'lon' => (float) $hit['lon'],
                        'tags' => array_filter([...($hit['extratags'] ?? []), 'name' => $hit['name'] ?? null, $hit['category'] ?? 'shop' => $hit['type'] ?? null,
                            'addr:housenumber' => $a['house_number'] ?? null, 'addr:street' => $a['road'] ?? null, 'addr:city' => $a['city'] ?? $a['town'] ?? null,
                            'addr:postcode' => $a['postcode'] ?? null, 'addr:country' => $a['country_code'] ?? null])];
                }
            }
        }

        return $out;
    }

    private function normalize(array $el, string $country, string $city): ?array
    {
        $t = $el['tags'] ?? [];
        $name = $t['name:en'] ?? $t['name'] ?? $t['brand'] ?? null;
        if (! $name) {
            return null;
        }
        $addr = trim(implode(' ', array_filter([$t['addr:housenumber'] ?? null, $t['addr:street'] ?? null])));
        $socials = array_filter([
            'facebook' => $t['contact:facebook'] ?? $t['facebook'] ?? null,
            'instagram' => $t['contact:instagram'] ?? $t['instagram'] ?? null,
            'whatsapp' => $t['contact:whatsapp'] ?? null,
        ]);

        return [
            'source' => 'osm',
            'source_id' => $el['type'].'/'.$el['id'],
            'name' => mb_substr($name, 0, 200),
            'category' => str_replace('_', ' ', $t['shop'] ?? $t['craft'] ?? $t['office'] ?? ''),
            'country' => strtoupper($t['addr:country'] ?? $country),
            'city' => $t['addr:city'] ?? $city,
            'address' => trim(implode(', ', array_filter([$addr, $t['addr:city'] ?? null, $t['addr:postcode'] ?? null]))) ?: null,
            'lat' => $el['lat'] ?? $el['center']['lat'] ?? null,
            'lng' => $el['lon'] ?? $el['center']['lon'] ?? null,
            'phone' => $t['phone'] ?? $t['contact:phone'] ?? $t['contact:mobile'] ?? null,
            'website' => $t['website'] ?? $t['contact:website'] ?? $t['url'] ?? null,
            'emails' => array_values(array_filter([$t['email'] ?? $t['contact:email'] ?? null])),
            'socials' => $socials,
            'rating' => null,
            'reviews_count' => null,
        ];
    }
}
