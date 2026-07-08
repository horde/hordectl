<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Output;

/**
 * Shared column-width, wrap and alignment logic for table renderers.
 *
 * Every concrete renderer (Ascii, Unicode, Markdown) mixes in this
 * trait so width computation and text wrapping behave identically
 * regardless of glyphs.
 *
 * Wide-character aware: uses mb_strwidth so CJK and most emoji count
 * as 2 columns. Never truncates. Wraps at word boundaries and falls
 * back to hard-wrap when a "word" exceeds the column budget.
 */
trait TableRendererHelperTrait
{
    /**
     * Terminal width in columns. Falls back to 80 when it cannot be
     * detected. Consulted before wrapping decisions.
     */
    private function terminalWidth(): int
    {
        $env = getenv('COLUMNS');
        if ($env !== false && ctype_digit((string) $env) && (int) $env > 0) {
            return (int) $env;
        }
        // `stty size` returns "rows cols" (space-separated).
        $out = @shell_exec('stty size 2>/dev/null');
        if (is_string($out) && preg_match('/\d+\s+(\d+)/', $out, $m)) {
            return (int) $m[1];
        }
        return 80;
    }

    /**
     * Returns the display width of a string in terminal columns.
     *
     * Wraps mb_strwidth so tests can stub if needed.
     */
    private function width(string $s): int
    {
        return mb_strwidth($s, 'UTF-8');
    }

    /**
     * Pads $s on the right (left-align), left (right-align), or both
     * sides (center) to reach $columns display columns. Preserves
     * multi-byte characters.
     */
    private function align(string $s, int $columns, string $alignment): string
    {
        $current = $this->width($s);
        if ($current >= $columns) {
            return $s;
        }
        $pad = $columns - $current;
        return match ($alignment) {
            'right' => str_repeat(' ', $pad) . $s,
            'center' => str_repeat(' ', intdiv($pad, 2))
                . $s
                . str_repeat(' ', $pad - intdiv($pad, 2)),
            default => $s . str_repeat(' ', $pad),
        };
    }

    /**
     * Computes column widths based on headers, all rows and any
     * footer. Returned array parallels the column index.
     *
     * @param list<list<int|string>> $rows
     * @return list<int>
     */
    private function computeColumnWidths(Table $table): array
    {
        $widths = array_map(fn(string $h) => $this->width($h), $table->headers);
        foreach ($table->rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, $this->width((string) $cell));
            }
        }
        foreach ($table->footers as $i => $cell) {
            $widths[$i] = max($widths[$i] ?? 0, $this->width((string) $cell));
        }
        return $widths;
    }

    /**
     * Given a target total width and initial column widths, returns
     * shrunk widths such that (sum + border overhead) <= target.
     *
     * Shrinks the widest columns first, uniformly, until the budget
     * fits. Never returns a width below a floor of 4 columns per
     * cell (arbitrary but sane: enough for "…abc" or one wide char).
     *
     * @param list<int> $widths
     * @param int       $borderOverhead  columns consumed by borders and separators
     * @return list<int>
     */
    private function shrinkToFit(array $widths, int $borderOverhead, int $targetTotal): array
    {
        $floor = 4;
        $count = count($widths);
        if ($count === 0) {
            return $widths;
        }
        // Guard against pathological narrow terminals: if even the
        // floor cannot fit, keep floor and let horizontal overflow
        // happen. The renderer will still print. It just spills.
        $minRequired = $borderOverhead + ($floor * $count);
        if ($targetTotal < $minRequired) {
            return array_fill(0, $count, $floor);
        }

        while (array_sum($widths) + $borderOverhead > $targetTotal) {
            $maxIdx = 0;
            for ($i = 1; $i < $count; $i++) {
                if ($widths[$i] > $widths[$maxIdx]) {
                    $maxIdx = $i;
                }
            }
            if ($widths[$maxIdx] <= $floor) {
                break;
            }
            $widths[$maxIdx]--;
        }
        return $widths;
    }

    /**
     * Wraps a single cell into a list of lines, each at most $width
     * display columns wide.
     *
     * Word boundaries are preferred. When a single word exceeds
     * $width, it gets hard-wrapped inside a word.
     *
     * @return list<string>
     */
    private function wrapCell(string $text, int $width): array
    {
        if ($width <= 0 || $this->width($text) <= $width) {
            return [$text];
        }
        $lines = [];
        // Split on whitespace but keep multi-byte characters intact.
        $words = preg_split('/\s+/u', $text) ?: [];
        $current = '';
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($this->width($candidate) <= $width) {
                $current = $candidate;
                continue;
            }
            // Candidate would overflow. Flush current if any.
            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }
            // Word itself may exceed the budget. Hard-wrap it.
            while ($this->width($word) > $width) {
                $chunk = '';
                $chunkLen = 0;
                $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                foreach ($chars as $idx => $char) {
                    $w = $this->width($char);
                    if ($chunkLen + $w > $width) {
                        break;
                    }
                    $chunk .= $char;
                    $chunkLen += $w;
                }
                $lines[] = $chunk;
                $word = mb_substr($word, mb_strlen($chunk));
            }
            $current = $word;
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return $lines === [] ? [''] : $lines;
    }

    /**
     * Wraps every cell of a row to its column's width and returns a
     * list of "visual rows" (one per line after wrapping), padded so
     * cells that produced fewer lines align at the top.
     *
     * @param list<int|string> $row
     * @param list<int>        $widths
     * @return list<list<string>>  Each visual row is a list of cell strings, one per column.
     */
    private function wrapRow(array $row, array $widths): array
    {
        $wrapped = [];
        $maxLines = 1;
        foreach ($row as $i => $cell) {
            $wrapped[$i] = $this->wrapCell((string) $cell, $widths[$i] ?? 0);
            $maxLines = max($maxLines, count($wrapped[$i]));
        }
        $visual = [];
        for ($line = 0; $line < $maxLines; $line++) {
            $visualRow = [];
            foreach ($wrapped as $i => $lines) {
                $visualRow[$i] = $lines[$line] ?? '';
            }
            $visual[] = $visualRow;
        }
        return $visual;
    }

    /**
     * Resolves per-column alignment, filling in 'left' for missing
     * entries.
     *
     * @return list<'left'|'right'|'center'>
     */
    private function resolveAlignments(Table $table): array
    {
        $count = count($table->headers);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[$i] = $table->alignments[$i] ?? 'left';
        }
        return $out;
    }
}
