<?php

namespace App\LeadFinder;

/**
 * Industry presets and target markets for the lead finder.
 * A profile says what to search for (per language), which listing categories matter,
 * and which words on a company's site mean "this is a buyer for us".
 */
final class LeadProfiles
{
    /** Words that tell us what kind of business it is — matched case-insensitively, any language. */
    public const SEGMENT_TERMS = [
        'wholesale' => ['wholesale', 'wholesaler', 'distributor', 'distribution', 'importer', 'import', 'supplier', 'b2b', 'bulk', 'trade only', 'trade price',
            'mayoreo', 'mayorista', 'distribuidor', 'importador', 'atacado', 'atacadista', 'distribuidora', 'großhandel', 'grosshandel', 'händler', 'grossiste',
            'distributeur', 'ingrosso', 'grossista', 'groothandel', 'hurtownia', 'toptan', 'оптом', 'опт', 'grosir', 'pemborong', 'nhà phân phối', 'bán sỉ', 'جملة', 'بالجملة'],
        'repair' => ['repair', 'repairs', 'fix', 'service center', 'reparación', 'reparacion', 'reparaciones', 'conserto', 'assistência técnica', 'assistencia tecnica',
            'reparatur', 'réparation', 'reparation', 'riparazione', 'reparatie', 'naprawa', 'tamir', 'ремонт', 'servis', 'sửa chữa', 'صيانة', 'تصليح'],
        'ecommerce' => ['online store', 'online shop', 'shop online', 'add to cart', 'add to basket', 'free shipping', 'checkout', 'amazon', 'ebay', 'shopify',
            'tienda en línea', 'tienda online', 'loja online', 'onlineshop', 'boutique en ligne', 'negozio online', 'webshop'],
        'retail' => ['store', 'shop', 'tienda', 'loja', 'laden', 'geschäft', 'boutique', 'magasin', 'negozio', 'winkel', 'sklep', 'mağaza', 'магазин', 'toko', 'cửa hàng', 'متجر', 'محل'],
    ];

    /** Carrier / big-box chain stores: they buy centrally, not from a factory email — left out of results. */
    public const CHAINS = '/^(t-?mobile|metro by t-?mobile|at&t|verizon|cricket( wireless)?|boost mobile|sprint|us cellular|best buy|apple( store)?|samsung( experience)?|walmart|target|'
        .'costco|staples|office ?depot|gamestop|radioshack|vodafone|o2|ee|three|3 store|carphone warehouse|currys|orange|sfr|bouygues|movistar|claro|telcel|at&t mexico|'
        .'tim|vivo|oi|personal|entel|bitel|digitel|mtn|airtel|safaricom|jio|reliance digital|croma|telstra|optus|rogers|telus|fido|koodo|virgin( mobile)?|'
        .'mediamarkt|media markt|saturn|fnac|darty|unieuro|euronics|el corte ingl[eé]s|xiaomi|huawei|oppo|vivo store|realme)\b/iu';

    public static function isChain(string $name): bool
    {
        return (bool) preg_match(self::CHAINS, trim($name))
            || (bool) preg_match('/\b(by|authorized (retailer|dealer)( of| for)?|premium retailer)\s+(verizon|t-?mobile|at&t|cricket|boost|metro)\b/iu', $name);
    }

    public static function all(): array
    {
        return [
            'phone_film' => [
                'label' => '手机膜 / 钢化膜',
                'pitch' => '钢化膜、水凝膜、防窥膜、镜头膜等手机屏幕保护产品',
                'buyers' => ['手机配件批发商 / 进口商', '手机维修店', '手机 / 数码零售店', '电商卖家（亚马逊、Shopify 等）'],
                'keywords' => [
                    'en' => ['screen protector wholesale', 'phone accessories wholesale', 'mobile accessories distributor', 'cell phone accessories store', 'cell phone repair shop'],
                    'es' => ['accesorios para celulares mayoreo', 'protector de pantalla mayorista', 'mica de cristal templado mayoreo', 'reparación de celulares'],
                    'pt' => ['acessórios para celular atacado', 'película de vidro atacado', 'assistência técnica celular'],
                    'de' => ['Handyzubehör Großhandel', 'Displayschutz Großhandel', 'Handy Reparatur'],
                    'fr' => ['accessoires téléphone grossiste', 'protection écran grossiste', 'réparation téléphone'],
                    'it' => ['accessori cellulari ingrosso', 'pellicola vetro temperato ingrosso', 'riparazione cellulari'],
                    'nl' => ['telefoonaccessoires groothandel', 'screenprotector groothandel', 'telefoon reparatie'],
                    'pl' => ['akcesoria GSM hurtownia', 'szkło hartowane hurt', 'serwis telefonów'],
                    'tr' => ['telefon aksesuar toptan', 'ekran koruyucu toptan', 'telefon tamir'],
                    'ru' => ['аксессуары для телефонов оптом', 'защитные стекла оптом', 'ремонт телефонов'],
                    'id' => ['grosir aksesoris hp', 'tempered glass grosir', 'servis hp'],
                    'vi' => ['phụ kiện điện thoại bán sỉ', 'kính cường lực bán sỉ', 'sửa chữa điện thoại'],
                    'ar' => ['اكسسوارات جوال جملة', 'شاشات حماية جملة', 'صيانة جوالات'],
                ],
                'relevance' => ['screen protector', 'wireless', 'cellular', 'accessories', 'mobile', 'phones', 'phone repair', 'electronics repair', 'cell phone repair', 'screen protection', 'tempered glass', 'glass protector', 'hydrogel', 'privacy screen', 'phone accessories', 'mobile accessories',
                    'cell phone', 'cellphone', 'mobile phone', 'smartphone', 'iphone', 'samsung', 'phone case', 'gsm', 'protector de pantalla', 'mica', 'vidrio templado', 'cristal templado',
                    'celulares', 'celular', 'película', 'pelicula', 'displayschutz', 'panzerglas', 'handy', 'protection écran', 'verre trempé', 'téléphone', 'vetro temperato',
                    'cellulari', 'screenprotector', 'telefoon', 'szkło hartowane', 'ekran koruyucu', 'telefon', 'защитное стекло', 'телефон', 'tempered glass', 'hp', 'điện thoại', 'جوال', 'موبايل'],
                'osm' => ['shop' => ['mobile_phone', 'mobile_phone_accessories', 'electronics', 'telecommunication', 'computer', 'wholesale'], 'craft' => ['electronics_repair']],
                'osm_name' => 'phone|mobile|cell|celular|movil|móvil|handy|gsm|smart|telefon|téléphone|cellulari|tech|accessor|repair|fix',
            ],
            'phone_case' => [
                'label' => '手机壳 / 手机配件',
                'pitch' => '手机壳、数据线、充电器、支架等手机配件',
                'buyers' => ['手机配件批发商 / 进口商', '手机 / 数码零售店', '礼品与潮品店', '电商卖家'],
                'keywords' => [
                    'en' => ['phone case wholesale', 'phone accessories wholesale', 'mobile accessories distributor', 'cell phone accessories store'],
                    'es' => ['fundas para celular mayoreo', 'accesorios para celulares mayoreo', 'tienda de accesorios para celulares'],
                    'pt' => ['capinhas atacado', 'acessórios para celular atacado'],
                    'de' => ['Handyhüllen Großhandel', 'Handyzubehör Großhandel'],
                    'fr' => ['coques téléphone grossiste', 'accessoires téléphone grossiste'],
                    'it' => ['cover cellulari ingrosso', 'accessori cellulari ingrosso'],
                ],
                'relevance' => ['phone case', 'phone cases', 'cover', 'charger', 'cable', 'power bank', 'phone accessories', 'mobile accessories', 'cell phone', 'smartphone', 'iphone',
                    'funda', 'fundas', 'capinha', 'capas', 'handyhülle', 'coque', 'celulares', 'celular', 'handy', 'cellulari', 'téléphone'],
                'osm' => ['shop' => ['mobile_phone', 'mobile_phone_accessories', 'electronics', 'gift', 'wholesale'], 'craft' => ['electronics_repair']],
                'osm_name' => 'phone|mobile|cell|celular|handy|gsm|case|funda|accessor|gadget',
            ],
            'custom' => [
                'label' => '其他行业（自定义关键词）',
                'pitch' => '',
                'buyers' => ['批发商 / 进口商', '零售店', '电商卖家'],
                'keywords' => ['en' => []],
                'relevance' => [],
                'osm' => ['shop' => ['wholesale']],
                'osm_name' => '',
            ],
        ];
    }

    public static function get(string $key): array
    {
        return self::all()[$key] ?? self::all()['custom'];
    }

    /** Suggested search phrases for a market: its own language first, English as backup. */
    public static function keywordsFor(string $profile, string $country): array
    {
        $p = self::get($profile);
        $lang = self::countries()[$country]['lang'] ?? 'en';
        $local = $p['keywords'][$lang] ?? [];
        $en = $p['keywords']['en'] ?? [];

        return array_values(array_unique($lang === 'en' ? $en : [...$local, ...array_slice($en, 0, 2)]));
    }

    /** Target markets: name (EN/中文), main language, and the biggest commercial cities. */
    public static function countries(): array
    {
        return [
            'US' => ['en' => 'United States', 'zh' => '美国', 'lang' => 'en', 'cities' => ['Los Angeles', 'New York', 'Miami', 'Houston', 'Chicago', 'Dallas', 'Atlanta']],
            'CA' => ['en' => 'Canada', 'zh' => '加拿大', 'lang' => 'en', 'cities' => ['Toronto', 'Vancouver', 'Montreal', 'Calgary']],
            'MX' => ['en' => 'Mexico', 'zh' => '墨西哥', 'lang' => 'es', 'cities' => ['Mexico City', 'Guadalajara', 'Monterrey', 'Puebla', 'Tijuana']],
            'BR' => ['en' => 'Brazil', 'zh' => '巴西', 'lang' => 'pt', 'cities' => ['São Paulo', 'Rio de Janeiro', 'Belo Horizonte', 'Curitiba']],
            'CO' => ['en' => 'Colombia', 'zh' => '哥伦比亚', 'lang' => 'es', 'cities' => ['Bogotá', 'Medellín', 'Cali', 'Barranquilla']],
            'CL' => ['en' => 'Chile', 'zh' => '智利', 'lang' => 'es', 'cities' => ['Santiago', 'Valparaíso', 'Concepción']],
            'PE' => ['en' => 'Peru', 'zh' => '秘鲁', 'lang' => 'es', 'cities' => ['Lima', 'Arequipa', 'Trujillo']],
            'AR' => ['en' => 'Argentina', 'zh' => '阿根廷', 'lang' => 'es', 'cities' => ['Buenos Aires', 'Córdoba', 'Rosario']],
            'EC' => ['en' => 'Ecuador', 'zh' => '厄瓜多尔', 'lang' => 'es', 'cities' => ['Guayaquil', 'Quito']],
            'GB' => ['en' => 'United Kingdom', 'zh' => '英国', 'lang' => 'en', 'cities' => ['London', 'Manchester', 'Birmingham', 'Glasgow']],
            'DE' => ['en' => 'Germany', 'zh' => '德国', 'lang' => 'de', 'cities' => ['Berlin', 'Hamburg', 'Munich', 'Frankfurt', 'Cologne']],
            'FR' => ['en' => 'France', 'zh' => '法国', 'lang' => 'fr', 'cities' => ['Paris', 'Marseille', 'Lyon', 'Lille']],
            'IT' => ['en' => 'Italy', 'zh' => '意大利', 'lang' => 'it', 'cities' => ['Milan', 'Rome', 'Naples', 'Turin']],
            'ES' => ['en' => 'Spain', 'zh' => '西班牙', 'lang' => 'es', 'cities' => ['Madrid', 'Barcelona', 'Valencia', 'Seville']],
            'NL' => ['en' => 'Netherlands', 'zh' => '荷兰', 'lang' => 'nl', 'cities' => ['Amsterdam', 'Rotterdam', 'The Hague']],
            'PL' => ['en' => 'Poland', 'zh' => '波兰', 'lang' => 'pl', 'cities' => ['Warsaw', 'Kraków', 'Wrocław', 'Poznań']],
            'TR' => ['en' => 'Turkey', 'zh' => '土耳其', 'lang' => 'tr', 'cities' => ['Istanbul', 'Ankara', 'Izmir']],
            'RU' => ['en' => 'Russia', 'zh' => '俄罗斯', 'lang' => 'ru', 'cities' => ['Moscow', 'Saint Petersburg', 'Novosibirsk']],
            'AE' => ['en' => 'United Arab Emirates', 'zh' => '阿联酋', 'lang' => 'en', 'cities' => ['Dubai', 'Abu Dhabi', 'Sharjah']],
            'SA' => ['en' => 'Saudi Arabia', 'zh' => '沙特阿拉伯', 'lang' => 'ar', 'cities' => ['Riyadh', 'Jeddah', 'Dammam']],
            'EG' => ['en' => 'Egypt', 'zh' => '埃及', 'lang' => 'ar', 'cities' => ['Cairo', 'Alexandria', 'Giza']],
            'NG' => ['en' => 'Nigeria', 'zh' => '尼日利亚', 'lang' => 'en', 'cities' => ['Lagos', 'Abuja', 'Kano']],
            'KE' => ['en' => 'Kenya', 'zh' => '肯尼亚', 'lang' => 'en', 'cities' => ['Nairobi', 'Mombasa']],
            'ZA' => ['en' => 'South Africa', 'zh' => '南非', 'lang' => 'en', 'cities' => ['Johannesburg', 'Cape Town', 'Durban']],
            'IN' => ['en' => 'India', 'zh' => '印度', 'lang' => 'en', 'cities' => ['Delhi', 'Mumbai', 'Bangalore', 'Chennai', 'Kolkata']],
            'PK' => ['en' => 'Pakistan', 'zh' => '巴基斯坦', 'lang' => 'en', 'cities' => ['Karachi', 'Lahore', 'Islamabad']],
            'BD' => ['en' => 'Bangladesh', 'zh' => '孟加拉国', 'lang' => 'en', 'cities' => ['Dhaka', 'Chittagong']],
            'ID' => ['en' => 'Indonesia', 'zh' => '印度尼西亚', 'lang' => 'id', 'cities' => ['Jakarta', 'Surabaya', 'Bandung', 'Medan']],
            'VN' => ['en' => 'Vietnam', 'zh' => '越南', 'lang' => 'vi', 'cities' => ['Ho Chi Minh City', 'Hanoi', 'Da Nang']],
            'TH' => ['en' => 'Thailand', 'zh' => '泰国', 'lang' => 'en', 'cities' => ['Bangkok', 'Chiang Mai', 'Pattaya']],
            'MY' => ['en' => 'Malaysia', 'zh' => '马来西亚', 'lang' => 'en', 'cities' => ['Kuala Lumpur', 'Penang', 'Johor Bahru']],
            'PH' => ['en' => 'Philippines', 'zh' => '菲律宾', 'lang' => 'en', 'cities' => ['Manila', 'Cebu', 'Davao']],
            'SG' => ['en' => 'Singapore', 'zh' => '新加坡', 'lang' => 'en', 'cities' => ['Singapore']],
            'JP' => ['en' => 'Japan', 'zh' => '日本', 'lang' => 'en', 'cities' => ['Tokyo', 'Osaka', 'Nagoya']],
            'KR' => ['en' => 'South Korea', 'zh' => '韩国', 'lang' => 'en', 'cities' => ['Seoul', 'Busan']],
            'AU' => ['en' => 'Australia', 'zh' => '澳大利亚', 'lang' => 'en', 'cities' => ['Sydney', 'Melbourne', 'Brisbane', 'Perth']],
            'NZ' => ['en' => 'New Zealand', 'zh' => '新西兰', 'lang' => 'en', 'cities' => ['Auckland', 'Wellington']],
        ];
    }
}
