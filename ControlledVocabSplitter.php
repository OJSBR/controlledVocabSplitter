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
 *        the rules can be exercised on their own by the test suites.
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
     * Abbreviations whose period is part of the term, mostly from zoological and
     * botanical nomenclature ("nov. spec.", "sp. nov.", "comb. nov.", "et al.").
     * A period after one of them never ends the term, and the final period of a
     * term that ends in one is kept. Lower case; "al" counts only after "et".
     */
    private const ABBREVIATIONS = [
        'nov', 'sp', 'spp', 'spec', 'gen', 'comb', 'stat', 'nom', 'nud', 'indet',
        'inquir', 'emend', 'cf', 'aff', 'var', 'subsp', 'ssp', 'subvar', 'sect',
        'subg', 'subgen', 'al', 'etc',
    ];

    /**
     * The abbreviations above that are also ordinary words ("Honey comb."), so
     * on their own at the end of a term they do not keep the final period. They
     * still do inside a chain: "nov. spec.", "stat. nov.".
     */
    private const COMMON_WORDS = ['spec', 'gen', 'comb', 'stat', 'nom'];

    /**
     * Abbreviations that introduce a name: what follows them is an epithet or a
     * genus ("Helix cf. pomatia", "cf. Helix"), never a new term and never part
     * of the abbreviation.
     */
    private const NAME_PREFIXES = ['cf', 'aff', 'var', 'subsp', 'ssp', 'subvar', 'sect', 'subg', 'subgen'];

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

        $trimmed = trim((string) preg_replace(self::TRAILING_JUNK, '', $value));
        $junk = substr($value, strlen($trimmed));
        if (str_contains($junk, '.') && self::keepsFinalPeriod($trimmed)) {
            // "Hirtudiscus hirsutissimus nov. spec.": the period closes an abbreviation.
            $trimmed .= '.';
        }

        return $trimmed;
    }

    /**
     * Does the period at the end of this term (already stripped) belong to it?
     *
     * Yes when the last word is an abbreviation ("sp. nov.", "et al."), when it
     * continues a chain of abbreviations ("nov. spec.", "sp. inq."), or when it
     * is dotted itself ("s.str.", "U.S.A."). A plain word keeps the old rule and
     * loses the period: "Orthopedic appliance." becomes "Orthopedic appliance".
     */
    private static function keepsFinalPeriod(string $term): bool
    {
        $words = self::words($term);
        $count = count($words);
        if ($count === 0) {
            return false;
        }

        $last = $words[$count - 1];
        $previous = $count > 1 ? $words[$count - 2] : '';

        if (preg_match('/^(\p{L}{1,4}\.)+\p{L}{1,4}$/u', $last)) {
            return true;
        }

        if (self::isAbbreviation($last, $previous) && !in_array(mb_strtolower($last, 'UTF-8'), self::COMMON_WORDS, true)) {
            return true;
        }

        if (str_ends_with($previous, '.') && preg_match('/^\p{Ll}/u', $last)) {
            $beforePrevious = $count > 2 ? $words[$count - 3] : '';
            $previousWord = substr($previous, 0, -1);

            return self::isAbbreviation($previousWord, $beforePrevious)
                && !in_array(mb_strtolower($previousWord, 'UTF-8'), self::NAME_PREFIXES, true);
        }

        return false;
    }

    /**
     * Is this word, with the word before it, one of the known abbreviations?
     * Surrounding brackets are ignored: "(sp. nov.)".
     */
    private static function isAbbreviation(string $word, string $previous = ''): bool
    {
        $word = mb_strtolower(trim($word, '([{'), 'UTF-8');
        if ($word === 'al') {
            return mb_strtolower(trim($previous, '([{'), 'UTF-8') === 'et';
        }

        return in_array($word, self::ABBREVIATIONS, true);
    }

    /**
     * The words of a term, split on spaces.
     *
     * @return string[]
     */
    private static function words(string $text): array
    {
        return preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
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
     * 3. A period that closes a known abbreviation is part of the term:
     *    "Hirtudiscus hirsutissimus nov. spec.", "Helix cf. pomatia" and
     *    "Smith et al." stay whole. Only a capital letter right after an
     *    abbreviation that does not introduce a name starts a new term, as in
     *    "Helix sp. nov. Taxonomy".
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

            // Known abbreviation => part of the term, unless a new term clearly starts.
            if (self::closesAbbreviation($current, $chars, $i + 1)) {
                $current .= $char;
                continue;
            }

            // The period goes along: normalize() keeps it only where it closes
            // an abbreviation ("Helix sp. nov. Taxonomy").
            $parts[] = $current . $char;
            $current = '';
            $i++; // the space that follows belongs to the separator
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * Does the period after $before close an abbreviation that keeps the next
     * word in the same term? $chars from $next on is what follows the period.
     *
     * @param string[] $chars
     */
    private static function closesAbbreviation(string $before, array $chars, int $next): bool
    {
        $words = self::words($before);
        $count = count($words);
        if ($count === 0) {
            return false;
        }

        $word = $words[$count - 1];
        $previous = $count > 1 ? $words[$count - 2] : '';
        if (!self::isAbbreviation($word, $previous)) {
            return false;
        }
        if (in_array(mb_strtolower(trim($word, '([{'), 'UTF-8'), self::NAME_PREFIXES, true)) {
            return true;
        }

        // The first character of the next word: a capital starts a new term.
        $total = count($chars);
        while ($next < $total && self::isSpace($chars[$next])) {
            $next++;
        }

        return $next >= $total || !preg_match('/^\p{Lu}$/u', $chars[$next]);
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
