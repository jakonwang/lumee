<?php

namespace App\Themes;

/**
 * Shopper-facing texts the automatic designer writes into a market's store, in the market's language
 * (written for each language, not machine-translated). Numbers are never part of the copy: amounts,
 * days and months are filled in from the store's settings (shipping rates, returns, installments),
 * so a text can't promise "free shipping over $99" when the threshold is $49.
 */
final class DesignCopy
{
    public const LANGS = ['en', 'es', 'pt', 'fr', 'de', 'it', 'ja', 'ar'];

    private const TEXT = [
        'en' => [
            'hero_eyebrow' => 'New season', 'hero_heading' => 'New arrivals are here', 'hero_text' => 'Fresh picks, chosen for you.', 'shop_now' => 'Shop now',
            'slide2_heading' => 'Our best sellers', 'slide2_text' => 'The pieces everyone is buying right now.', 'see_all' => 'See all',
            'new_in' => 'New in', 'bestsellers' => 'Best sellers', 'on_sale' => 'On sale', 'trending' => 'Trending now', 'flash_sale' => 'Flash sale',
            'from_customers' => 'From our customers', 'shop_by' => 'Shop by category', 'newsletter' => 'Get 10% off your first order', 'newsletter_text' => 'Join our list for new arrivals and members-only offers.', 'subscribe' => 'Subscribe',
            'free_shipping_over' => 'Free shipping over :amount', 'returns' => ':days-day returns', 'installments' => 'Up to :count interest-free installments', 'cod' => 'Cash on delivery available', 'duties' => 'Duties and taxes included', 'secure' => 'Secure checkout', 'local_pay' => 'Pay with :methods',
        ],
        'es' => [
            'hero_eyebrow' => 'Nueva temporada', 'hero_heading' => 'Llegaron las novedades', 'hero_text' => 'Lo más nuevo, elegido para ti.', 'shop_now' => 'Comprar ahora',
            'slide2_heading' => 'Lo más vendido', 'slide2_text' => 'Lo que todos están comprando ahora.', 'see_all' => 'Ver todo',
            'new_in' => 'Novedades', 'bestsellers' => 'Más vendidos', 'on_sale' => 'Ofertas', 'trending' => 'Tendencia', 'flash_sale' => 'Ofertas relámpago',
            'from_customers' => 'Así lo usan nuestros clientes', 'shop_by' => 'Compra por categoría', 'newsletter' => '10% de descuento en tu primera compra', 'newsletter_text' => 'Suscríbete y recibe novedades y ofertas exclusivas.', 'subscribe' => 'Suscribirme',
            'free_shipping_over' => 'Envío gratis desde :amount', 'returns' => 'Devoluciones en :days días', 'installments' => 'Hasta :count meses sin intereses', 'cod' => 'Paga al recibir', 'duties' => 'Impuestos y aranceles incluidos', 'secure' => 'Pago seguro', 'local_pay' => 'Paga con :methods',
        ],
        'pt' => [
            'hero_eyebrow' => 'Nova coleção', 'hero_heading' => 'Chegaram as novidades', 'hero_text' => 'O que há de mais novo, escolhido para você.', 'shop_now' => 'Comprar agora',
            'slide2_heading' => 'Os mais vendidos', 'slide2_text' => 'O que todo mundo está comprando agora.', 'see_all' => 'Ver tudo',
            'new_in' => 'Novidades', 'bestsellers' => 'Mais vendidos', 'on_sale' => 'Promoções', 'trending' => 'Em alta', 'flash_sale' => 'Ofertas relâmpago',
            'from_customers' => 'Fotos de clientes', 'shop_by' => 'Compre por categoria', 'newsletter' => '10% off na primeira compra', 'newsletter_text' => 'Cadastre-se e receba novidades e ofertas exclusivas.', 'subscribe' => 'Cadastrar',
            'free_shipping_over' => 'Frete grátis acima de :amount', 'returns' => 'Trocas em até :days dias', 'installments' => 'Em até :countx sem juros', 'cod' => 'Pague na entrega', 'duties' => 'Impostos inclusos', 'secure' => 'Compra segura', 'local_pay' => 'Pague com :methods',
        ],
        'fr' => [
            'hero_eyebrow' => 'Nouvelle saison', 'hero_heading' => 'Les nouveautés sont arrivées', 'hero_text' => 'Une sélection fraîche, rien que pour vous.', 'shop_now' => 'Découvrir',
            'slide2_heading' => 'Nos meilleures ventes', 'slide2_text' => 'Les pièces que tout le monde s’arrache.', 'see_all' => 'Tout voir',
            'new_in' => 'Nouveautés', 'bestsellers' => 'Meilleures ventes', 'on_sale' => 'Promotions', 'trending' => 'Tendance', 'flash_sale' => 'Ventes flash',
            'from_customers' => 'Vos photos', 'shop_by' => 'Acheter par catégorie', 'newsletter' => '-10 % sur votre première commande', 'newsletter_text' => 'Inscrivez-vous pour recevoir nos nouveautés et offres réservées.', 'subscribe' => 'S’inscrire',
            'free_shipping_over' => 'Livraison offerte dès :amount', 'returns' => 'Retours sous :days jours', 'installments' => 'Paiement en :count fois sans frais', 'cod' => 'Paiement à la livraison', 'duties' => 'Prix TTC, droits inclus', 'secure' => 'Paiement sécurisé', 'local_pay' => 'Payez avec :methods',
        ],
        'de' => [
            'hero_eyebrow' => 'Neue Saison', 'hero_heading' => 'Die Neuheiten sind da', 'hero_text' => 'Frische Highlights, für dich ausgewählt.', 'shop_now' => 'Jetzt shoppen',
            'slide2_heading' => 'Unsere Bestseller', 'slide2_text' => 'Was gerade alle kaufen.', 'see_all' => 'Alle ansehen',
            'new_in' => 'Neu', 'bestsellers' => 'Bestseller', 'on_sale' => 'Sale', 'trending' => 'Im Trend', 'flash_sale' => 'Blitzangebote',
            'from_customers' => 'Von unseren Kund:innen', 'shop_by' => 'Nach Kategorie shoppen', 'newsletter' => '10 % Rabatt auf deine erste Bestellung', 'newsletter_text' => 'Melde dich an für Neuheiten und exklusive Angebote.', 'subscribe' => 'Anmelden',
            'free_shipping_over' => 'Kostenloser Versand ab :amount', 'returns' => ':days Tage Rückgabe', 'installments' => 'Bis zu :count zinsfreie Raten', 'cod' => 'Zahlung bei Lieferung', 'duties' => 'Inkl. MwSt. und Zoll', 'secure' => 'Sichere Bezahlung', 'local_pay' => 'Bezahle mit :methods',
        ],
        'it' => [
            'hero_eyebrow' => 'Nuova stagione', 'hero_heading' => 'Sono arrivate le novità', 'hero_text' => 'Nuove proposte, scelte per te.', 'shop_now' => 'Acquista ora',
            'slide2_heading' => 'I più venduti', 'slide2_text' => 'I pezzi che tutti stanno comprando.', 'see_all' => 'Vedi tutto',
            'new_in' => 'Novità', 'bestsellers' => 'Più venduti', 'on_sale' => 'Saldi', 'trending' => 'Di tendenza', 'flash_sale' => 'Offerte lampo',
            'from_customers' => 'Le foto dei clienti', 'shop_by' => 'Acquista per categoria', 'newsletter' => '10% di sconto sul primo ordine', 'newsletter_text' => 'Iscriviti per novità e offerte riservate.', 'subscribe' => 'Iscriviti',
            'free_shipping_over' => 'Spedizione gratuita da :amount', 'returns' => 'Resi entro :days giorni', 'installments' => 'Fino a :count rate senza interessi', 'cod' => 'Pagamento alla consegna', 'duties' => 'IVA e dazi inclusi', 'secure' => 'Pagamento sicuro', 'local_pay' => 'Paga con :methods',
        ],
        'ja' => [
            'hero_eyebrow' => '新作入荷', 'hero_heading' => '新作が入荷しました', 'hero_text' => 'あなたのために選んだ最新アイテム。', 'shop_now' => '今すぐ見る',
            'slide2_heading' => '人気アイテム', 'slide2_text' => '今いちばん売れているアイテム。', 'see_all' => 'すべて見る',
            'new_in' => '新着', 'bestsellers' => '人気', 'on_sale' => 'セール', 'trending' => '話題のアイテム', 'flash_sale' => 'タイムセール',
            'from_customers' => 'お客様の写真', 'shop_by' => 'カテゴリーから探す', 'newsletter' => '初回注文10%オフ', 'newsletter_text' => '新作や会員限定のお得な情報をお届けします。', 'subscribe' => '登録する',
            'free_shipping_over' => ':amount以上で送料無料', 'returns' => ':days日間返品可能', 'installments' => '最大:count回の分割払い（手数料無料）', 'cod' => '代金引換OK', 'duties' => '関税・税込み', 'secure' => '安全なお支払い', 'local_pay' => ':methods でお支払い可能',
        ],
        'ar' => [
            'hero_eyebrow' => 'موسم جديد', 'hero_heading' => 'وصلت التشكيلة الجديدة', 'hero_text' => 'أحدث القطع، اخترناها لك.', 'shop_now' => 'تسوّق الآن',
            'slide2_heading' => 'الأكثر مبيعًا', 'slide2_text' => 'القطع التي يشتريها الجميع الآن.', 'see_all' => 'عرض الكل',
            'new_in' => 'وصل حديثًا', 'bestsellers' => 'الأكثر مبيعًا', 'on_sale' => 'تخفيضات', 'trending' => 'رائج الآن', 'flash_sale' => 'عروض سريعة',
            'from_customers' => 'صور عملائنا', 'shop_by' => 'تسوّق حسب الفئة', 'newsletter' => 'خصم 10% على طلبك الأول', 'newsletter_text' => 'اشترك لتصلك أحدث المنتجات والعروض الحصرية.', 'subscribe' => 'اشتراك',
            'free_shipping_over' => 'شحن مجاني للطلبات فوق :amount', 'returns' => 'إرجاع خلال :days يومًا', 'installments' => 'قسّط حتى :count دفعات بدون فوائد', 'cod' => 'الدفع عند الاستلام متاح', 'duties' => 'الضرائب والرسوم مشمولة', 'secure' => 'دفع آمن', 'local_pay' => 'ادفع بواسطة :methods',
        ],
    ];

    /** Campaign banners for the shopping calendar's events: [heading, text] per language (English when missing). */
    private const EVENTS = [
        'valentines' => ['en' => ["Valentine's Day gifts", 'Little things that say a lot.'], 'es' => ['Regalos de San Valentín', 'Detalles que lo dicen todo.'], 'fr' => ['Cadeaux de Saint-Valentin', 'Des attentions qui en disent long.'], 'de' => ['Geschenke zum Valentinstag', 'Kleine Dinge, die viel sagen.'], 'it' => ['Regali di San Valentino', 'Piccoli pensieri che dicono tanto.']],
        'lunar_new_year' => ['en' => ['Lunar New Year', 'Gifts in red and gold for a lucky year.']],
        'ramadan' => ['en' => ['Ramadan collection', 'Thoughtful pieces for the holy month.'], 'ar' => ['تشكيلة رمضان', 'قطع مختارة للشهر الفضيل.']],
        'eid' => ['en' => ['Eid gifts', 'Celebrate with something special.'], 'ar' => ['هدايا العيد', 'احتفل بهدية مميزة.']],
        'mothers_mx' => ['es' => ['Día de las Madres', 'Regalos para la mujer más importante.'], 'en' => ["Mother's Day gifts", 'For the most important woman in your life.']],
        'mothers_day' => ['en' => ["Mother's Day gifts", 'Show her how much she means.'], 'es' => ['Regalos para el Día de la Madre', 'Demuéstrale cuánto la quieres.'], 'pt' => ['Presentes de Dia das Mães', 'Mostre o quanto ela é especial.'], 'de' => ['Geschenke zum Muttertag', 'Zeig ihr, wie viel sie dir bedeutet.'], 'it' => ['Regali per la Festa della Mamma', 'Dille quanto è speciale.']],
        'hot_sale' => ['es' => ['Hot Sale', 'Los mejores descuentos del año, por tiempo limitado.'], 'en' => ['Hot Sale', 'Our biggest deals, for a limited time.']],
        'namorados' => ['pt' => ['Dia dos Namorados', 'Presentes para quem você ama.'], 'en' => ["Valentine's Day gifts", 'For the one you love.']],
        'fathers_day' => ['en' => ["Father's Day gifts", 'Something he will actually use.'], 'es' => ['Regalos para el Día del Padre', 'Algo que de verdad va a usar.']],
        'back_to_school' => ['en' => ['Back to school', 'Everything for a great start.']],
        'pais_br' => ['pt' => ['Dia dos Pais', 'Presentes que ele vai usar de verdade.'], 'en' => ["Father's Day gifts", 'Something he will actually use.']],
        '9_9' => ['en' => ['9.9 Mega Sale', 'Big deals, one day only.']],
        '10_10' => ['en' => ['10.10 Sale', 'Early deals before 11.11.']],
        'criancas_br' => ['pt' => ['Dia das Crianças', 'Presentes que fazem a festa.'], 'en' => ["Children's Day", 'Gifts they will love.']],
        'halloween' => ['en' => ['Halloween', 'Spooky picks for the season.'], 'es' => ['Halloween', 'Lo más terrorífico de la temporada.']],
        'singles_day' => ['en' => ['11.11 Biggest Sale', 'The biggest deals of the year.']],
        'buen_fin' => ['es' => ['El Buen Fin', 'Los descuentos más grandes del año.'], 'en' => ['El Buen Fin', 'The biggest deals of the year.']],
        'black_friday' => ['en' => ['Black Friday', 'Our biggest sale of the year.'], 'es' => ['Black Friday', 'Los mayores descuentos del año.'], 'pt' => ['Black Friday', 'Os maiores descontos do ano.'], 'fr' => ['Black Friday', 'Nos plus grosses remises de l’année.'], 'de' => ['Black Friday', 'Unsere größten Rabatte des Jahres.'], 'it' => ['Black Friday', 'I nostri sconti più grandi dell’anno.']],
        'cyber_monday' => ['en' => ['Cyber Monday', 'Online-only deals, today only.'], 'es' => ['Cyber Monday', 'Ofertas solo en línea, solo hoy.'], 'fr' => ['Cyber Monday', 'Offres en ligne, aujourd’hui seulement.'], 'de' => ['Cyber Monday', 'Online-Angebote, nur heute.'], 'it' => ['Cyber Monday', 'Offerte online, solo oggi.']],
        '12_12' => ['en' => ['12.12 Year-end Sale', 'Last big deals of the year.']],
        'christmas' => ['en' => ['Christmas gifts', 'Find something for everyone.'], 'es' => ['Regalos de Navidad', 'Encuentra algo para cada persona.'], 'pt' => ['Presentes de Natal', 'Encontre algo para cada pessoa.'], 'fr' => ['Cadeaux de Noël', 'Un cadeau pour chacun.'], 'de' => ['Weihnachtsgeschenke', 'Für jeden das Richtige.'], 'it' => ['Regali di Natale', 'Qualcosa per tutti.']],
    ];

    public static function lang(?string $locale): string
    {
        $l = strtolower(substr((string) $locale, 0, 2));

        return in_array($l, self::LANGS, true) ? $l : 'en';
    }

    /** A text in the language, with :placeholders filled in. */
    public static function get(string $lang, string $key, array $replace = []): string
    {
        $t = self::TEXT[self::lang($lang)][$key] ?? self::TEXT['en'][$key] ?? $key;
        foreach ($replace as $k => $v) {
            $t = str_replace(':'.$k, (string) $v, $t);
        }

        return $t;
    }

    /** [heading, text] for a calendar event ("black_friday-2026" or "black_friday"), or null if unknown. */
    public static function event(string $lang, string $eventKey): ?array
    {
        $base = preg_replace('/-\d{4}$/', '', $eventKey);
        $e = self::EVENTS[$base] ?? null;

        return $e ? ($e[self::lang($lang)] ?? $e['en'] ?? reset($e)) : null;
    }

    /** All texts the designer can write in a language (the design check uses them to spot leftovers in other languages). @return string[] */
    public static function all(string $lang): array
    {
        return array_values(self::TEXT[self::lang($lang)] ?? []);
    }
}
