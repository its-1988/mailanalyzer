<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI — Smart classifier service.
GPLv2+
--------------------------------------------------------------------------
 */

/**
 * Detects ITIL ticket type (Incident vs Service Request) and urgency
 * from the email subject/content using configurable keyword dictionaries.
 *
 * Output is applied to Ticket::$input *before* the ticket is actually
 * created, so the values flow into GLPI's rule engine and business rules.
 */
class PluginMailanalyzerClassifier
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? Config::getConfigurationValues('plugin:mailanalyzer');
    }

    public function isEnabled(): bool
    {
        return (int) ($this->config['enable_smart_classification'] ?? 0) === 1;
    }

    /**
     * Decide ticket type and (optionally) urgency from a haystack of text
     * (typically subject + content).
     *
     * @return array{type:?int, urgency:?int, matches:array<string,string>}
     *   type:   Ticket::INCIDENT_TYPE | Ticket::DEMAND_TYPE | null (no decision)
     *   urgency: 1..5 | null
     *   matches: human-readable explanation of what was matched
     */
    public function decide(string $subject, string $body = ''): array
    {
        $matches = [];
        $type    = null;
        $urgency = null;

        if (!$this->isEnabled()) {
            return ['type' => null, 'urgency' => null, 'matches' => []];
        }

        $haystack = mb_strtolower($subject . "\n" . $body, 'UTF-8');

        $incidentKw = self::parseKeywords((string) ($this->config['incident_keywords'] ?? ''));
        $requestKw  = self::parseKeywords((string) ($this->config['request_keywords']  ?? ''));
        $urgencyKw  = self::parseKeywords((string) ($this->config['auto_priority_keywords'] ?? ''));

        if ($hit = $this->firstMatch($haystack, $incidentKw)) {
            $type = Ticket::INCIDENT_TYPE;
            $matches['type'] = "incident: $hit";
        } elseif ($hit = $this->firstMatch($haystack, $requestKw)) {
            $type = Ticket::DEMAND_TYPE;
            $matches['type'] = "request: $hit";
        } else {
            $default = (int) ($this->config['default_request_type'] ?? 0);
            if (in_array($default, [Ticket::INCIDENT_TYPE, Ticket::DEMAND_TYPE], true)) {
                $type = $default;
                $matches['type'] = 'default';
            }
        }

        if ($hit = $this->firstMatch($haystack, $urgencyKw)) {
            $urgency = 5; // Very High
            $matches['urgency'] = "high-urgency keyword: $hit";
        }

        return ['type' => $type, 'urgency' => $urgency, 'matches' => $matches];
    }

    /**
     * @return array<int, string>
     */
    private static function parseKeywords(string $raw): array
    {
        return array_values(array_filter(array_map(
            static fn(string $s): string => trim(mb_strtolower($s, 'UTF-8')),
            preg_split('/[\r\n,;]+/u', $raw) ?: []
        )));
    }

    /**
     * @param array<int, string> $keywords
     */
    private function firstMatch(string $haystack, array $keywords): ?string
    {
        foreach ($keywords as $kw) {
            if ($kw !== '' && mb_strpos($haystack, $kw, 0, 'UTF-8') !== false) {
                return $kw;
            }
        }
        return null;
    }
}
