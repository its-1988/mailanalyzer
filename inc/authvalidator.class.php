<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — Email authentication validator (SPF / DKIM / DMARC).
GPLv2+
--------------------------------------------------------------------------
 */

/**
 * Reads the `Authentication-Results` header (RFC 8601) that upstream
 * mail servers attach during delivery and extracts the SPF/DKIM/DMARC
 * verdicts. We do NOT perform DNS lookups ourselves — that's the MTA's
 * job; the plugin only enforces policy on the recorded results.
 *
 * Returns a structured verdict; the orchestrator decides whether to
 * reject the email based on plugin config flags.
 */
class PluginMailanalyzerAuthValidator
{
    public const STATUS_PASS    = 'pass';
    public const STATUS_FAIL    = 'fail';
    public const STATUS_NEUTRAL = 'neutral';
    public const STATUS_NONE    = 'none';
    public const STATUS_UNKNOWN = 'unknown';

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? Config::getConfigurationValues('plugin:mailanalyzer');
    }

    public function isEnabled(): bool
    {
        return (int) ($this->config['enable_auth_validation'] ?? 0) === 1;
    }

    /**
     * Parse the verdict block from one or more Authentication-Results headers.
     *
     * @return array{spf:string, dkim:string, dmarc:string, raw:string}
     */
    public function parse(string $authResultsHeader): array
    {
        $hay = mb_strtolower($authResultsHeader, 'UTF-8');

        return [
            'spf'   => self::pickStatus($hay, 'spf'),
            'dkim'  => self::pickStatus($hay, 'dkim'),
            'dmarc' => self::pickStatus($hay, 'dmarc'),
            'raw'   => $authResultsHeader,
        ];
    }

    /**
     * Decide whether the email should be rejected according to plugin policy.
     *
     * @param array{spf:string, dkim:string, dmarc:string, raw:string} $verdict
     * @return string|null human-readable reason to reject, or null if accepted
     */
    public function shouldReject(array $verdict): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }
        $rejectOn = [
            'spf'   => 'reject_on_spf_fail',
            'dkim'  => 'reject_on_dkim_fail',
            'dmarc' => 'reject_on_dmarc_fail',
        ];
        foreach ($rejectOn as $check => $key) {
            if ((int) ($this->config[$key] ?? 0) === 1
                && $verdict[$check] === self::STATUS_FAIL) {
                return strtoupper($check) . ' failed';
            }
        }
        return null;
    }

    /**
     * Best-effort lookup of the Authentication-Results value from the raw
     * IMAP message via PluginMailanalyzerMailCollector.
     */
    public static function readAuthResults(
        PluginMailanalyzerMailCollector $mailgate,
        string $uid
    ): string {
        if ($uid === '') {
            return '';
        }
        try {
            $msg = $mailgate->getMessage($uid);
            if (!isset($msg->authenticationresults)) {
                return '';
            }
            $h = $msg->getHeader('authenticationresults');
            // Header can be multi-valued; collapse to one string
            if (is_array($h)) {
                $values = [];
                foreach ($h as $entry) {
                    $values[] = (string) $entry->getFieldValue();
                }
                return implode("\n", $values);
            }
            return (string) $h->getFieldValue();
        } catch (\Throwable $e) {
            Toolbox::logWarning("MailAnalyzer: Authentication-Results read failed UID=$uid - " . $e->getMessage());
            return '';
        }
    }

    private static function pickStatus(string $haystack, string $method): string
    {
        // Look for "<method>=<status>" with optional spaces, e.g. "spf=pass" / "dkim = fail"
        $pattern = '/\b' . preg_quote($method, '/') . '\s*=\s*(pass|fail|neutral|softfail|none|permerror|temperror|policy)\b/';
        if (!preg_match($pattern, $haystack, $m)) {
            return self::STATUS_UNKNOWN;
        }
        return match ($m[1]) {
            'pass'                 => self::STATUS_PASS,
            'fail', 'permerror'    => self::STATUS_FAIL,
            'softfail', 'temperror', 'policy', 'neutral' => self::STATUS_NEUTRAL,
            'none'                 => self::STATUS_NONE,
            default                => self::STATUS_UNKNOWN,
        };
    }
}
