<?php

/**
 * @file plugins/generic/controlledVocabSplitter/ControlledVocabSplitter.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ControlledVocabSplitter
 *
 * @brief The splitting rules, and nothing else. No database, no request, no
 *        plugin API: everything here is a pure function of its arguments, so
 *        the same rules can be exercised by the regression suite and mirrored
 *        byte for byte by js/controlledVocabSplitter.js.
 *
 * Authors routinely select the whole keyword line in their manuscript, copy it
 * and paste it into the keyword field, which stores one giant term instead of
 * several. This class turns that single string back into the list the author
 * meant, without destroying terms that legitimately contain punctuation.
 */

namespace APP\plugins\generic\controlledVocabSplitter;

class ControlledVocabSplitter
{
    /** Separator keys, in the order they take precedence. */
    public const SEPARATOR_SEMICOLON = 'semicolon';
    public const SEPARATOR_COMMA = 'comma';
    public const SEPARATOR_PERIOD = 'period';

    /**
     * Precedence matters: only ONE separator is used per string, the first of
     * these that occurs in it. A list written as "Hypertension, Pregnancy-Induced;
     * Diabetes" is therefore cut on the semicolon only, which keeps the inverted
     * MeSH descriptor in one piece.
     */
    public const SEPARATORS = [
        self::SEPARATOR_SEMICOLON,
        self::SEPARATOR_COMMA,
        self::SEPARATOR_PERIOD,
    ];

    /**
     * Spaces that are not the space bar: no-break space, en/em/thin spaces and
     * friends. Word and PDF are full of them and they make two identical-looking
     * terms compare as different.
     */
    private const EXOTIC_SPACES = '/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u';

    /** Zero-width characters: invisible, and they break every comparison. */
    private const ZERO_WIDTH = '/[\x{200B}\x{200C}\x{200D}\x{FEFF}]/u';

    /** Punctuation left dangling at the start of a term (": Microentrepreneur"). */
    private const LEADING_JUNK = '/^[\s:;,.\x{2013}\x{2014}\x{2012}\x{2015}\-]+/u';

    /** Punctuation left dangling at the end of a term ("Orthopedic appliance."). */
    private const TRAILING_JUNK = '/[\s;,.]+$/u';

    /**
     * Clean one term: normalise spacing and Unicode, then drop punctuation that
     * is left over from the separator itself. Never changes the words.
     */
    public static function normalize(string $value): string
    {
        $value = (string) preg_replace(self::EXOTIC_SPACES, ' ', $value);
        $value = (string) preg_replace(self::ZERO_WIDTH, '', $value);

        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }

        $value = (string) preg_replace('/\s+/u', ' ', $value);
        $value = trim($value);
        $value = (string) preg_replace(self::LEADING_JUNK, '', $value);
        $value = (string) preg_replace(self::TRAILING_JUNK, '', $value);

        return trim($value);
    }

    /**
     * Split one raw string into the terms it holds.
     *
     * @param string[] $separators Active separator keys (self::SEPARATORS)
     *
     * @return string[] Zero terms for an empty string, one term when nothing was
     *                  split, N terms otherwise. Duplicates are removed.
     */
    public static function split(string $raw, array $separators = self::SEPARATORS): array
    {
        $value = self::normalize($raw);
        if ($value === '') {
            return [];
        }

        $parts = null;

        if (in_array(self::SEPARATOR_SEMICOLON, $separators, true) && str_contains($value, ';')) {
            $parts = preg_split('/\s*;\s*/u', $value);
        } elseif (in_array(self::SEPARATOR_COMMA, $separators, true) && preg_match('/,\s/u', $value)) {
            // A comma only separates when a space follows it, so "1,5 mm" survives.
            $parts = preg_split('/\s*,\s+/u', $value);
        } elseif (in_array(self::SEPARATOR_PERIOD, $separators, true)) {
            $parts = self::splitOnPeriod($value);
        }

        if ($parts === null || count($parts) < 2) {
            return [$value];
        }

        $terms = [];
        $seen = [];
        foreach ($parts as $part) {
            $part = self::normalize((string) $part);
            if ($part === '') {
                continue;
            }
            $key = mb_strtolower($part, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $terms[] = $part;
        }

        return $terms ?: [$value];
    }

    /**
     * The period is the dangerous separator, so it is the only one cut by hand.
     *
     * Two rules keep real data alive:
     *
     * 1. A period only separates when a space follows it. That is what keeps
     *    "Lei 13.964/2019" and "Decreto 12.456/2025" in one piece — a legal
     *    reference is a single keyword and cutting it destroys the citation.
     * 2. A period that closes a single letter is an initial, not an end of term:
     *    "S. aureus", "E. coli" and "C. albicans" stay whole.
     *
     * @return string[]
     */
    private static function splitOnPeriod(string $value): array
    {
        $chars = mb_str_split($value, 1, 'UTF-8');
        $total = count($chars);

        $parts = [];
        $current = '';

        for ($i = 0; $i < $total; $i++) {
            $char = $chars[$i];

            if ($char !== '.' || $i + 1 >= $total || !self::isSpace($chars[$i + 1])) {
                $current .= $char;
                continue;
            }

            $previous = $i > 0 ? $chars[$i - 1] : '';
            $beforePrevious = $i > 1 ? $chars[$i - 2] : '';

            // Single letter before the period => initial, keep it glued.
            if (self::isLetter($previous) && ($i < 2 || !self::isLetterOrDigit($beforePrevious))) {
                $current .= $char;
                continue;
            }

            $parts[] = $current;
            $current = '';
            $i++; // the space that follows belongs to the separator
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * Split a whole list of values, as it arrives from a form, the REST API or
     * an XML import: a mix of plain strings and controlled-vocabulary entry data
     * (['name' => ..., 'identifier' => ..., 'source' => ...]).
     *
     * Entry data is preserved as it came whenever the value did not have to be
     * split; when it does split, the extra terms are emitted as plain strings,
     * because an identifier that was minted for the whole concatenated line does
     * not describe any of the individual terms.
     *
     * @param array<int, string|array> $values
     * @param string[] $separators
     *
     * @return array<int, string|array>
     */
    public static function splitList(array $values, array $separators = self::SEPARATORS): array
    {
        $result = [];
        $seen = [];

        foreach ($values as $value) {
            $isEntryData = is_array($value);
            $raw = $isEntryData ? (string) ($value['name'] ?? '') : (string) $value;

            $terms = self::split($raw, $separators);

            foreach ($terms as $index => $term) {
                $key = mb_strtolower($term, 'UTF-8');
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                if ($isEntryData && $index === 0 && count($terms) === 1) {
                    $entry = $value;
                    $entry['name'] = $term;
                    $result[] = $entry;
                    continue;
                }

                $result[] = $term;
            }
        }

        return $result;
    }

    /**
     * Would splitting change anything? Used to keep the write path untouched
     * when there is nothing to fix.
     *
     * @param array<int, string|array> $values
     * @param string[] $separators
     */
    public static function changes(array $values, array $separators = self::SEPARATORS): bool
    {
        return self::flatten(self::splitList($values, $separators)) !== self::flatten($values);
    }

    /**
     * @param array<int, string|array> $values
     *
     * @return string[]
     */
    private static function flatten(array $values): array
    {
        return array_map(
            fn ($value): string => is_array($value) ? (string) ($value['name'] ?? '') : (string) $value,
            array_values($values)
        );
    }

    private static function isSpace(string $char): bool
    {
        return $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r";
    }

    private static function isLetter(string $char): bool
    {
        return $char !== '' && (bool) preg_match('/^\p{L}$/u', $char);
    }

    private static function isLetterOrDigit(string $char): bool
    {
        return $char !== '' && (bool) preg_match('/^[\p{L}\p{N}]$/u', $char);
    }
}
