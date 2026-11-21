<?php

namespace App\LeadFinder;

/**
 * Cheap deliverability signals for an email address, without sending anything:
 *  - does the domain accept mail (MX record, or an A record as the RFC fallback)?
 *  - what kind of inbox is it: company role inbox (sales@), a person (maria@), or a free webmail account?
 * Also proposes the usual sales inbox when a company publishes no address but its domain takes mail.
 */
class EmailChecker
{
    public const FREE = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'aol.com', 'icloud.com', 'msn.com', 'yahoo.com.mx', 'hotmail.es',
        'gmx.de', 'gmx.net', 'web.de', 't-online.de', 'orange.fr', 'free.fr', 'laposte.net', 'libero.it', 'virgilio.it', 'uol.com.br', 'bol.com.br', 'terra.com.br',
        'mail.ru', 'yandex.ru', 'yandex.com', 'qq.com', '163.com', '126.com', 'proton.me', 'protonmail.com', 'zoho.com', 'rediffmail.com'];

    private const ROLE = '/^(sales|wholesale|ventas|vendas|verkauf|vente|ventes|vendite|b2b|purchas|compras|buyer|order|orders|info|contact|contacto|contato|kontakt|hello|hola|office|admin|support|service|help|team|shop|store|tienda|loja|enquir|inquir|security|press|media|marketing|hr|jobs|careers|billing|accounts|finance|partners|import|export)/i';

    /** @var array<string, bool> */
    private array $mx = [];

    /** @var callable(string): bool */
    private $lookup;

    public function __construct(?callable $lookup = null)
    {
        $this->lookup = $lookup ?? function (string $domain): bool {
            if (! function_exists('checkdnsrr')) {
                return true;
            }

            return @checkdnsrr($domain.'.', 'MX') || @checkdnsrr($domain.'.', 'A');
        };
    }

    /** @return array{mx: bool, kind: string, guessed: bool} */
    public function check(string $email, bool $guessed = false): array
    {
        $domain = strtolower(substr(strrchr($email, '@'), 1));
        $local = strtolower(strstr($email, '@', true));

        return [
            'mx' => $this->acceptsMail($domain),
            'kind' => in_array($domain, self::FREE, true) ? 'free' : (preg_match(self::ROLE, $local) ? 'role' : 'personal'),
            'guessed' => $guessed,
        ];
    }

    public function acceptsMail(string $domain): bool
    {
        return $this->mx[$domain] ??= (bool) ($this->lookup)($domain);
    }

    /** Company domain takes mail but no address was published → the conventional inbox, clearly marked as a guess. */
    public function guess(?string $domain): ?string
    {
        if (! $domain || SiteEnricher::socialKind($domain) || ! $this->acceptsMail($domain)) {
            return null;
        }

        return 'info@'.$domain;
    }
}
