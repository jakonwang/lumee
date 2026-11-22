<?php

namespace App\Markets;

/**
 * Starting points for a market by region: price endings shoppers there are used to, an
 * announcement bar and purchase perks in the local language, how import duties are usually
 * handled, and which local payment methods to turn on. The merchant edits everything after.
 * Duty rates are left at 0 on purpose: they depend on the product and change often.
 */
final class Presets
{
    public const ALL = [
        'us' => [
            'name' => '美国 / 加拿大', 'rounding' => '0.99', 'duty_mode' => 'included',
            'payments' => ['card', 'afterpay_clearpay', 'klarna', 'paypal'],
            'text' => [
                'en' => ['Free shipping on orders over $49 · Easy 30-day returns', "Free shipping over \$49\nEasy 30-day returns\nSecure checkout"],
                'fr' => ['Livraison gratuite dès 49 $ · Retours faciles sous 30 jours', "Livraison gratuite dès 49 \$\nRetours faciles sous 30 jours\nPaiement sécurisé"],
                'es' => ['Envío gratis en pedidos de más de $49 · Devoluciones fáciles en 30 días', "Envío gratis desde \$49\nDevoluciones fáciles en 30 días\nPago seguro"],
            ],
            'tips' => ['美国 2025 年起取消了小额包裹免税，中国发货的商品需要缴纳关税。建议把关税算进售价（价格调整），结账时显示“关税已包含”。', '买家习惯先买后付（Afterpay、Klarna）和 PayPal。'],
        ],
        'eu' => [
            'name' => '欧洲', 'rounding' => '0.95', 'duty_mode' => 'included',
            'payments' => ['card', 'klarna', 'ideal', 'bancontact', 'eps', 'p24', 'paypal'],
            'text' => [
                'en' => ['Prices include VAT · Free returns within 30 days', "Prices include VAT and duties\nFree returns within 30 days\nSecure checkout"],
                'de' => ['Preise inkl. MwSt. · 30 Tage kostenlose Rücksendung', "Preise inkl. MwSt. und Zoll\n30 Tage kostenlose Rücksendung\nSichere Bezahlung"],
                'fr' => ['Prix TTC · Retours gratuits sous 30 jours', "Prix TTC, droits de douane inclus\nRetours gratuits sous 30 jours\nPaiement sécurisé"],
                'es' => ['Precios con IVA incluido · Devoluciones gratis en 30 días', "IVA y aranceles incluidos\nDevoluciones gratis en 30 días\nPago seguro"],
                'it' => ['Prezzi IVA inclusa · Resi gratuiti entro 30 giorni', "IVA e dazi inclusi\nResi gratuiti entro 30 giorni\nPagamento sicuro"],
            ],
            'tips' => ['欧盟按 IOSS 申报增值税，价格要含税展示；欧盟也在取消 150 欧元以下包裹的关税豁免，建议关税算进售价。', '荷兰常用 iDEAL，比利时 Bancontact，奥地利 EPS，波兰 Przelewy24；德国、北欧常用 Klarna。', '欧盟 GPSR 要求商品页写明制造商和欧盟境内的责任人。'],
        ],
        'latam' => [
            'name' => '拉美（墨西哥、巴西等）', 'rounding' => '0.90', 'duty_mode' => 'on_delivery',
            'payments' => ['card', 'oxxo', 'pix', 'boleto', 'paypal'],
            'text' => [
                'es' => ['Envío a todo el país · Paga con tarjeta u OXXO', "Envío a todo el país\nPaga con tarjeta u OXXO\nCambios fáciles"],
                'pt' => ['Frete para todo o Brasil · Pague com Pix ou boleto', "Frete para todo o Brasil\nPague com Pix ou boleto\nTrocas fáceis"],
                'en' => ['Nationwide shipping · Pay with card or local methods', "Nationwide shipping\nLocal payment methods\nEasy exchanges"],
            ],
            'tips' => ['巴西走 Remessa Conforme：进口税加州税（ICMS）可以在结账时收取，结账需要填写 CPF。墨西哥常用 OXXO 便利店付款和分期（MSI）。', '拉美买家对“到手价”很敏感：要么把税费算进价格，要么在结账时清楚列出。'],
        ],
        'sea' => [
            'name' => '东南亚', 'rounding' => '1', 'duty_mode' => 'included',
            'payments' => ['card', 'grabpay', 'paynow', 'promptpay', 'paypal'],
            'text' => [
                'en' => ['Fast shipping · Easy returns · Pay your way', "Fast shipping across the region\nEasy returns\nCard, e-wallet and local bank payments"],
            ],
            'tips' => ['东南亚移动端占绝大多数，9.9、10.10、11.11、12.12 是全年最重要的大促。', '新加坡常用 PayNow，泰国 PromptPay，马来西亚和新加坡 GrabPay；印尼、菲律宾大量使用电子钱包和货到付款。', '印尼盾价格建议取整到千（价格尾数选「取整到 1,000」）。'],
        ],
    ];

    /** The preset's market settings (duties). */
    public static function settingsFor(string $preset, array $current): array
    {
        return [
            ...$current,
            'preset' => $preset,
            'duties' => [...(array) ($current['duties'] ?? []), 'mode' => self::ALL[$preset]['duty_mode']],
        ];
    }

    /**
     * The preset's store texts in the market's language (when we have it), as theme settings for the
     * market's design: announcement bar and purchase perks.
     *
     * @return array<string, string> "group.field" => text
     */
    public static function themeTexts(string $preset, string $locale): array
    {
        $p = self::ALL[$preset];
        [$announcement, $perks] = $p['text'][$locale] ?? $p['text']['en'] ?? reset($p['text']);

        return ['header.announcement_text' => $announcement, 'header.announcement_enabled' => true, 'product.perks' => $perks];
    }

    public static function forList(): array
    {
        $names = ['card' => 'Card', 'paypal' => 'PayPal'] + array_map(fn ($m) => $m[0], \App\Payments\PaymentSettings::STRIPE_METHODS);

        return collect(self::ALL)->map(fn ($p, $k) => ['key' => $k, 'name' => $p['name'], 'rounding' => $p['rounding'],
            'duty_mode' => $p['duty_mode'], 'payments' => array_map(fn ($m) => $names[$m] ?? $m, $p['payments']), 'tips' => $p['tips']])->values()->all();
    }
}
