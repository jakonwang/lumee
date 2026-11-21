<?php

namespace App\LeadFinder;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Reads a prospect's own website (home page + up to two contact/about pages) and pulls out
 * what they publish for business contact: emails, phone numbers, social / WhatsApp links,
 * a short description, the visible text (for scoring) and commerce signals.
 */
class SiteEnricher
{
    /** Hosts that are profiles on someone else's platform, not the company's own site. */
    public const SOCIAL_HOSTS = [
        'facebook.com' => 'facebook', 'fb.com' => 'facebook', 'instagram.com' => 'instagram', 'linkedin.com' => 'linkedin',
        'twitter.com' => 'twitter', 'x.com' => 'twitter', 'tiktok.com' => 'tiktok', 'youtube.com' => 'youtube',
        'wa.me' => 'whatsapp', 'whatsapp.com' => 'whatsapp', 'api.whatsapp.com' => 'whatsapp', 't.me' => 'telegram',
    ];

    private const JUNK_EMAIL = '/(example\.|domain\.com|yourdomain|yoursite|email\.com$|sentry|wixpress|@2x|\.(png|jpe?g|gif|webp|svg|css|js)$|godaddy|no-?reply|noreply|privacy@|abuse@|webmaster@|postmaster@|hostmaster@)/i';

    public function __construct(private SafeFetcher $fetcher) {}

    /**
     * @return array{website: ?string, emails: string[], phones: string[], socials: array<string,string>, description: ?string, title: ?string, site_name: ?string, text: string, signals: string[]}
     * @throws RuntimeException when the home page can't be read
     */
    public function enrich(string $website): array
    {
        $out = ['website' => null, 'emails' => [], 'phones' => [], 'socials' => [], 'description' => null, 'title' => null, 'site_name' => null, 'text' => '', 'signals' => []];
        if (! preg_match('#^https?://#i', $website)) {
            $website = 'https://'.$website;
        }
        $domain = SafeFetcher::domain($website);
        if (! $domain) {
            throw new RuntimeException('网址无效');
        }
        if ($kind = self::socialKind($domain)) {
            $out['socials'][$kind] = $website;
            throw new RuntimeException('官网是社交主页，已记录');
        }

        try {
            $home = $this->fetcher->get($website);
        } catch (RuntimeException $e) {
            if (str_starts_with($website, 'https://')) {   // some small-business sites still only answer on http
                $home = $this->fetcher->get('http://'.substr($website, 8));
            } else {
                throw $e;
            }
        }
        $out['website'] = $home['url'];
        $pages = [$home];
        $homeParsed = $this->parse($home['html'], $home['url']);

        // Contact / about pages on the same site
        $seen = [rtrim($home['url'], '/')];
        foreach ($homeParsed['contact_links'] as $link) {
            if (count($pages) >= 3) {
                break;
            }
            if (in_array(rtrim($link, '/'), $seen, true) || SafeFetcher::domain($link) !== SafeFetcher::domain($home['url'])) {
                continue;
            }
            $seen[] = rtrim($link, '/');
            try {
                $pages[] = $this->fetcher->get($link, 6);
            } catch (RuntimeException) {
                // a broken contact page doesn't spoil the rest
            }
        }

        $texts = [];
        foreach ($pages as $i => $page) {
            $p = $i === 0 ? $homeParsed : $this->parse($page['html'], $page['url']);
            $out['emails'] = [...$out['emails'], ...$p['emails']];
            $out['phones'] = [...$out['phones'], ...$p['phones']];
            $out['socials'] += $p['socials'];
            $out['signals'] = [...$out['signals'], ...$p['signals']];
            $out['description'] ??= $p['description'];
            $out['title'] ??= $p['title'];
            $out['site_name'] ??= $p['site_name'];
            $texts[] = $p['text'];
        }

        $out['emails'] = $this->rankEmails(array_values(array_unique(array_map('strtolower', $out['emails']))), SafeFetcher::domain($home['url']) ?? $domain);
        $out['phones'] = array_slice(array_values(array_unique($out['phones'])), 0, 5);
        $out['signals'] = array_values(array_unique($out['signals']));
        $out['text'] = mb_substr(trim(preg_replace('/\s+/u', ' ', implode(' · ', $texts))), 0, 4000);

        return $out;
    }

    public static function socialKind(string $host): ?string
    {
        $host = preg_replace('/^(www\.|m\.|web\.|business\.)/', '', strtolower($host));
        foreach (self::SOCIAL_HOSTS as $h => $kind) {
            if ($host === $h || str_ends_with($host, '.'.$h)) {
                return $kind;
            }
        }

        return null;
    }

    private function parse(string $html, string $url): array
    {
        $r = ['emails' => [], 'phones' => [], 'socials' => [], 'contact_links' => [], 'signals' => [], 'description' => null, 'title' => null, 'site_name' => null, 'text' => ''];

        // Cloudflare email protection: data-cfemail="hex"
        if (preg_match_all('/data-cfemail="([0-9a-f]+)"/i', $html, $m)) {
            foreach ($m[1] as $hex) {
                $r['emails'][] = self::cfDecode($hex);
            }
        }
        // Commerce platform fingerprints
        foreach (['cdn.shopify.com' => 'shopify', 'woocommerce' => 'woocommerce', 'magento' => 'magento', 'bigcommerce' => 'bigcommerce', 'wix.com' => 'wix'] as $needle => $sig) {
            if (stripos($html, $needle) !== false) {
                $r['signals'][] = $sig;
            }
        }

        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        $r['title'] = self::clean($xp->evaluate('string(//title)')) ?: null;
        $r['site_name'] = self::clean($xp->evaluate('string(//meta[@property="og:site_name"]/@content)')) ?: null;
        $r['description'] = self::clean($xp->evaluate('string(//meta[@name="description"]/@content)'))
            ?: self::clean($xp->evaluate('string(//meta[@property="og:description"]/@content)')) ?: null;

        foreach ($xp->query('//a[@href]') as $a) {
            $href = trim($a->getAttribute('href'));
            $label = mb_strtolower(self::clean($a->textContent));
            if (stripos($href, 'mailto:') === 0) {
                $r['emails'][] = urldecode(explode('?', substr($href, 7))[0]);
            } elseif (stripos($href, 'tel:') === 0) {
                $tel = preg_replace('/[^\d+]/', '', urldecode(substr($href, 4)));
                if (strlen($tel) >= 7) {
                    $r['phones'][] = $tel;
                }
            } elseif (preg_match('#^(https?:)?//#i', $href)) {
                $abs = SafeFetcher::absolute($href, $url);
                $host = (string) parse_url($abs, PHP_URL_HOST);
                if (($kind = self::socialKind($host)) && ! isset($r['socials'][$kind]) && ! preg_match('#/(sharer|share|intent|plugins|dialog)\b#i', $abs)) {
                    $r['socials'][$kind] = $abs;
                }
            }
            if (count($r['contact_links']) < 6 && ! str_starts_with($href, '#') && ! preg_match('/^(mailto|tel|javascript):/i', $href)
                && preg_match('/contact|kontakt|contacto|contato|contatti|contactez|impressum|about|nosotros|quienes|sobre|über|chi-siamo|wholesale|mayoreo|atacado|联系|关于/iu', $href.' '.$label)) {
                $r['contact_links'][] = SafeFetcher::absolute($href, $url);
            }
        }
        // Wholesale / contact pages first
        usort($r['contact_links'], fn ($a, $b) => (int) preg_match('/contact|kontakt|contacto|contato|impressum/i', $b) <=> (int) preg_match('/contact|kontakt|contacto|contato|impressum/i', $a));

        foreach ($xp->query('//script|//style|//noscript|//svg|//template') as $n) {
            $n->parentNode?->removeChild($n);
        }
        $body = $xp->query('//body')->item(0);
        $r['text'] = mb_substr(self::clean($body ? $body->textContent : ''), 0, 3000);

        if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,24}/i', $r['text'].' '.strip_tags(str_replace('<', ' <', $html)), $m)) {
            $r['emails'] = [...$r['emails'], ...$m[0]];
        }
        $r['emails'] = array_values(array_filter(array_map(fn ($e) => strtolower(trim($e, " .;,:<>\"'")), $r['emails']),
            fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) && ! preg_match(self::JUNK_EMAIL, $e) && strlen($e) <= 80));

        return $r;
    }

    /** Company-domain addresses first, then sales/info style inboxes, then the rest; at most 6. */
    private function rankEmails(array $emails, string $domain): array
    {
        $rank = function (string $e) use ($domain) {
            $host = substr(strrchr($e, '@'), 1);
            $s = 0;
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                $s += 10;
            }
            if (preg_match('/^(sales|wholesale|ventas|vendas|verkauf|vente|b2b|purchas|compras|buyer|order|info|contact|hello|office)/', $e)) {
                $s += 5;
            }

            return -$s;
        };
        usort($emails, fn ($a, $b) => $rank($a) <=> $rank($b));

        return array_slice($emails, 0, 6);
    }

    private static function cfDecode(string $hex): string
    {
        $key = hexdec(substr($hex, 0, 2));
        $out = '';
        for ($i = 2; $i < strlen($hex); $i += 2) {
            $out .= chr(hexdec(substr($hex, $i, 2)) ^ $key);
        }

        return $out;
    }

    private static function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
