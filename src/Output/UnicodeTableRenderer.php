<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Output;

use Horde\Cli\Cli as HordeCli;

/**
 * Unicode box-drawing table renderer.
 *
 * Uses the U+2500 block characters for borders. Modern terminals
 * render these correctly. Headers are bolded via HordeCli::bold.
 */
final class UnicodeTableRenderer implements TableRenderer
{
    use TableRendererHelperTrait;

    private const TL = '┌';
    private const TR = '┐';
    private const BL = '└';
    private const BR = '┘';
    private const H  = '─';
    private const V  = '│';
    private const CROSS = '┼';
    private const T_UP = '┴';
    private const T_DOWN = '┬';
    private const T_LEFT = '┤';
    private const T_RIGHT = '├';

    public function __construct(private readonly HordeCli $cli) {}

    public function render(Table $table): void
    {
        $headers = $table->headers;
        if ($headers === [] && $table->rows === []) {
            return;
        }

        $widths = $this->computeColumnWidths($table);
        // Border overhead: '│ ' + ' │ ... │ ' + ' │' -> per column: 3
        // chars for '│ c │' junctions. Total = 3 * n + 1.
        $borderOverhead = 3 * count($widths) + 1;
        $widths = $this->shrinkToFit($widths, $borderOverhead, $this->terminalWidth());
        $alignments = $this->resolveAlignments($table);

        if ($table->caption !== null && $table->caption !== '') {
            $this->cli->writeln($table->caption);
        }

        $this->cli->writeln($this->buildBorder($widths, self::TL, self::T_DOWN, self::TR));
        $this->writeRow($headers, $widths, $alignments, boldCells: true);
        $this->cli->writeln($this->buildBorder($widths, self::T_RIGHT, self::CROSS, self::T_LEFT));

        foreach ($table->rows as $row) {
            $visual = $this->wrapRow($row, $widths);
            foreach ($visual as $line) {
                $this->writeRow($line, $widths, $alignments);
            }
        }

        if ($table->footers !== []) {
            $this->cli->writeln($this->buildBorder($widths, self::T_RIGHT, self::CROSS, self::T_LEFT));
            $this->writeRow($table->footers, $widths, $alignments);
        }

        $this->cli->writeln($this->buildBorder($widths, self::BL, self::T_UP, self::BR));
    }

    /**
     * @param list<int> $widths
     */
    private function buildBorder(array $widths, string $left, string $middle, string $right): string
    {
        $parts = [];
        foreach ($widths as $w) {
            $parts[] = str_repeat(self::H, $w + 2);
        }
        return $left . implode($middle, $parts) . $right;
    }

    /**
     * @param list<int|string> $row
     * @param list<int>        $widths
     * @param list<string>     $alignments
     */
    private function writeRow(array $row, array $widths, array $alignments, bool $boldCells = false): void
    {
        $cells = [];
        foreach ($widths as $i => $w) {
            $cell = (string) ($row[$i] ?? '');
            $padded = ' ' . $this->align($cell, $w, $alignments[$i]) . ' ';
            $cells[] = $boldCells ? $this->cli->bold($padded) : $padded;
        }
        $this->cli->writeln(self::V . implode(self::V, $cells) . self::V);
    }
}
