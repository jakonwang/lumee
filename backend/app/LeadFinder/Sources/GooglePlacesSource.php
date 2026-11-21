<?php

namespace App\LeadFinder\Sources;

use App\LeadFinder\LeadProfiles;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Maps business listings through the official Places API (New) Text Search.
 * Best worldwide coverage of phone numbers, websites and ratings. Billed per request by Google.
 */
class GooglePlacesSource
{
    private const URL = 'https://places.googleapis.com/v1/places:searchText';

    private const FIELDS = 'places.id,places.displayName,places.formattedAddress,places.addressComponents,places.location,places.internationalPhoneNumber,'
        .'places.nationalPhoneNumber,places.websiteUri,places.rating,places.userRatingCount,places.primaryTypeDisplayName,places.types,places.businessStatus,nextPageToken';

    public function __construct(private string $key) {}

    public function search(string $profile, array $keywords, string $country, array $cities, int $limit, ?callable $progress = null): array
    {
        $countryName = LeadProfiles::countries()[$country]['en'] ?? $country;
        $areas = $cities ?: [null];
        $out = [];
        foreach ($areas as $city) {
            foreach ($keywords as $kw) {
                if (count($out) >= $limit) {
                    break 2;
                }
                $token = null;
                for ($page = 0; $page < 3 && count($out) < $limit; $page++) {
                    $body = ['textQuery' => $kw.' in '.($city ? "$city, " : '').$countryName, 'pageSize' => 20, 'regionCode' => $country];
                    if ($token) {
                        $body['pageToken'] = $token;
                    }
                    $r = Http::timeout(20)->withHeaders(['X-Goog-Api-Key' => $this->key, 'X-Goog-FieldMask' => self::FIELDS])->post(self::URL, $body);
                    if (! $r->successful()) {
                        $msg = $r->json('error.message') ?? ('HTTP '.$r->status());
                        throw new RuntimeException('Google 地图接口报错：'.$msg);
                    }
                    foreach ($r->json('places') ?? [] as $pl) {
                        if (($pl['businessStatus'] ?? 'OPERATIONAL') === 'CLOSED_PERMANENTLY') {
                            continue;
                        }
                        $n = $this->normalize($pl, $country, $city);
                        if ($n['country'] === $country) {
                            $out[$n['source_id']] = $n;
                        }
                    }
                    $progress && $progress(count($out));
                    $token = $r->json('nextPageToken');
                    if (! $token) {
                        break;
                    }
                }
            }
        }

        return array_slice(array_values($out), 0, $limit);
    }

    private function normalize(array $pl, string $country, ?string $city): array
    {
        $comp = fn (string $type) => collect($pl['addressComponents'] ?? [])->first(fn ($c) => in_array($type, $c['types'] ?? [], true));

        return [
            'source' => 'google',
            'source_id' => $pl['id'],
            'name' => mb_substr($pl['displayName']['text'] ?? '—', 0, 200),
            'category' => $pl['primaryTypeDisplayName']['text'] ?? str_replace('_', ' ', $pl['types'][0] ?? ''),
            'country' => strtoupper($comp('country')['shortText'] ?? $country),
            'city' => $comp('locality')['longText'] ?? $comp('administrative_area_level_2')['longText'] ?? $city,
            'address' => $pl['formattedAddress'] ?? null,
            'lat' => $pl['location']['latitude'] ?? null,
            'lng' => $pl['location']['longitude'] ?? null,
            'phone' => $pl['internationalPhoneNumber'] ?? $pl['nationalPhoneNumber'] ?? null,
            'website' => $pl['websiteUri'] ?? null,
            'emails' => [],
            'socials' => [],
            'rating' => $pl['rating'] ?? null,
            'reviews_count' => $pl['userRatingCount'] ?? null,
        ];
    }
}
