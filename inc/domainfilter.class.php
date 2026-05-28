<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — DomainFilter service.
GPLv2+
--------------------------------------------------------------------------
 */

/**
 * Decides whether an inbound email's sender should be blocked, allowed,
 * or treated as a VIP based on plugin configuration lists.
 *
 * Lists are stored as plain textareas in the GLPI plugin config:
 *   - whitelist_domains : one entry per line — full address or @domain
 *   - blacklist_domains : same format
 *   - vip_senders       : same format
 */
class PluginMailanalyzerDomainFilter
{
    public const RESULT_NORMAL    = 'normal';
    public const RESULT_BLACKLIST = 'blacklist';
    public const RESULT_WHITELIST = 'whitelist';
    public const RESULT_VIP       = 'vip';

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? Config::getConfigurationValues('plugin:mailanalyzer');
    }

    /**
     * Classify an inbound sender.
     *
     * @param string $from raw From header value
     * @return string one of self::RESULT_*
     */
    public function classify(string $from): string
    {
        $address = strtolower(self::extractAddress($from));
        if ($address === '') {
            return self::RESULT_NORMAL;
        }
        $domain = self::extractDomain($address);

        if ($this->matches($this->config['blacklist_domains'] ?? '', $address, $domain)) {
            return self::RESULT_BLACKLIST;
        }
        if ($this->matches($this->config['vip_senders'] ?? '', $address, $domain)) {
            return self::RESULT_VIP;
        }
        if ($this->matches($this->config['whitelist_domains'] ?? '', $address, $domain)) {
            return self::RESULT_WHITELIST;
        }
        return self::RESULT_NORMAL;
    }

    /**
     * Extract a bare email address from a From header that may be
     * "Display Name <user@example.com>" or just "user@example.com".
     */
    public static function extractAddress(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+/i', $from, $m)) {
            return trim($m[0]);
        }
        return '';
    }

    public static function extractDomain(string $address): string
    {
        $at = strrpos($address, '@');
        return $at === false ? '' : strtolower(substr($address, $at + 1));
    }

    /**
     * Match an address/domain against a newline-separated rule list.
     * A rule of the form "@domain" matches the domain; otherwise it's a literal address.
     */
    private function matches(string $rawList, string $address, string $domain): bool
    {
        $rules = array_filter(array_map('trim', preg_split('/[\r\n]+/', $rawList) ?: []));
        foreach ($rules as $rule) {
            $r = strtolower($rule);
            if ($r === '') {
                continue;
            }
            if ($r[0] === '@') {
                if (ltrim($r, '@') === $domain) {
                    return true;
                }
            } elseif ($r === $address) {
                return true;
            }
        }
        return false;
    }
}
