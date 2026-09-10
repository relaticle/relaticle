<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

/**
 * Resolves the registrable (organisational) label of a host using the Mozilla
 * Public Suffix List, the same data CRMs use to turn an email domain into a
 * company identity. Implements the ICANN-section matching algorithm
 * (https://publicsuffix.org/list/): exception rules, wildcards, and the implicit
 * "*" default, so every TLD shape resolves correctly:
 *
 *   email.anthropic.com -> anthropic
 *   mail.acme.co.uk     -> acme
 *   acme.co.us          -> acme
 *
 * Bind as a singleton: the list is parsed once per process from the bundled
 * snapshot in resources/public_suffix_list.dat.
 *
 * ponytail: ASCII/punycode hosts only. IDN U-label rules in the list aren't
 * normalised to match A-label email domains. B2B senders are ASCII; add an IDN
 * (idn_to_ascii) pass if internationalised domains ever appear.
 */
final class PublicSuffixList
{
    /** @var array<string, true> Exact suffix rules, e.g. "co.uk". */
    private array $rules = [];

    /** @var array<string, true> Fixed part of wildcard rules, e.g. "ck" for "*.ck". */
    private array $wildcards = [];

    /** @var array<string, true> Exception rules without the leading "!", e.g. "www.ck". */
    private array $exceptions = [];

    public function __construct(?string $path = null)
    {
        $this->load($path ?? __DIR__.'/../../resources/public_suffix_list.dat');
    }

    /**
     * The organisational domain (eTLD+1). Used only to pick a default company
     * name: email.anthropic.com → Anthropic, mail.acme.co.uk → Acme. Company
     * identity is the full host, not this value.
     */
    public function registrableDomain(string $host): ?string
    {
        $labels = $this->labels($host);

        if ($labels === []) {
            return null;
        }

        $suffixStart = $this->publicSuffixStart($labels);

        if ($suffixStart < 1) {
            return null;
        }

        return implode('.', array_slice($labels, $suffixStart - 1));
    }

    /**
     * The label immediately to the left of the public suffix, the part a company
     * registered. Null when the host is itself a public suffix or has no
     * registrable label.
     */
    public function registrableLabel(string $host): ?string
    {
        $domain = $this->registrableDomain($host);

        if ($domain === null) {
            return null;
        }

        return explode('.', $domain)[0];
    }

    /**
     * @return list<string>
     */
    private function labels(string $host): array
    {
        $host = trim(mb_strtolower($host), '.');

        if ($host === '') {
            return [];
        }

        return explode('.', $host);
    }

    /**
     * Index of the first label of the public suffix. Exception rules win: the
     * suffix is the rule minus its leftmost label, so that leftmost label is
     * registrable.
     *
     * @param  list<string>  $labels
     */
    private function publicSuffixStart(array $labels): int
    {
        $count = count($labels);

        for ($i = 0; $i < $count; $i++) {
            if (isset($this->exceptions[implode('.', array_slice($labels, $i))])) {
                return $i + 1;
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $rest = implode('.', array_slice($labels, $i + 1));

            if (isset($this->rules[implode('.', array_slice($labels, $i))])
                || ($rest !== '' && isset($this->wildcards[$rest]))
            ) {
                return $i;
            }
        }

        return $count - 1;
    }

    private function load(string $path): void
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return;
        }

        // Only the ICANN section: private suffixes (github.io, blogspot.com) would
        // wrongly split a real company domain into a "public suffix".
        $icann = strstr($contents, '// ===END ICANN DOMAINS===', true) ?: $contents;

        foreach (explode("\n", $icann) as $line) {
            $rule = trim($line);

            if ($rule === '') {
                continue;
            }

            if (str_starts_with($rule, '//')) {
                continue;
            }

            if (str_starts_with($rule, '!')) {
                $this->exceptions[substr($rule, 1)] = true;
            } elseif (str_starts_with($rule, '*.')) {
                $this->wildcards[substr($rule, 2)] = true;
            } else {
                $this->rules[$rule] = true;
            }
        }
    }
}
