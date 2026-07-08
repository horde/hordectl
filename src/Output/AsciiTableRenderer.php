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
 * ASCII table renderer.
 *
 * Emits the classic `+---+---+` / `| val | val |` grid the current
 * pear/console_table produces. Backwards-compatible look for anyone
 * used to horde-translation output.
 */
final class AsciiTableRenderer implements TableRenderer
{
    use TableRendererHelperTrait;

    public function __construct(private readonly HordeCli $cli) {}

    public function render(Table $table): void
    {
        $headers = $table->headers;
        if ($headers === [] && $table->rows === []) {
            return;
        }

        $widths = $this->computeColumnWidths($table);
        // Border overhead: '+ ' + ' | ... | ' + ' +' -> per column: 3 chars
        // for '| c |' junctions. Total = 3 * n + 1.
        $borderOverhead = 3 * count($widths) + 1;
        $widths = $this->shrinkToFit($widths, $borderOverhead, $this->terminalWidth());
        $alignments = $this->resolveAlignments($table);

        if ($table->caption !== null && $table->caption !== '') {
            $this->cli->writeln($table->caption);
        }

        $sep = $this->buildSeparator($widths);
        $this->cli->writeln($sep);
        $this->writeRow($headers, $widths, $alignments);
        $this->cli->writeln($sep);

        foreach ($table->rows as $row) {
            $visual = $this->wrapRow($row, $widths);
            foreach ($visual as $line) {
                $this->writeRow($line, $widths, $alignments);
            }
        }
        $this->cli->writeln($sep);

        if ($table->footers !== []) {
            $this->writeRow($table->footers, $widths, $alignments);
            $this->cli->writeln($sep);
        }
    }

    /**
     * @param list<int> $widths
     */
    private function buildSeparator(array $widths): string
    {
        $parts = [];
        foreach ($widths as $w) {
            $parts[] = str_repeat('-', $w + 2);
        }
        return '+' . implode('+', $parts) . '+';
    }

    /**
     * @param list<int|string> $row
     * @param list<int>        $widths
     * @param list<string>     $alignments
     */
    private function writeRow(array $row, array $widths, array $alignments): void
    {
        $cells = [];
        foreach ($widths as $i => $w) {
            $cell = (string) ($row[$i] ?? '');
            $cells[] = ' ' . $this->align($cell, $w, $alignments[$i]) . ' ';
        }
        $this->cli->writeln('|' . implode('|', $cells) . '|');
    }
}
