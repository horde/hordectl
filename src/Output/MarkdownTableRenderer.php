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
 * Markdown pipe-table renderer.
 *
 * Emits standard markdown that renders cleanly in GitHub Actions,
 * GitLab CI job summaries and any other markdown-aware log viewer.
 * Alignment hints are encoded via the header-separator row's colons:
 * ':---' (left, also default), '---:' (right), ':---:' (center).
 *
 * Markdown pipe tables have no built-in row-wrapping. Cell contents
 * with embedded newlines are collapsed to `<br>` HTML entities so
 * rendering surfaces still handle multi-line cells cleanly.
 */
final class MarkdownTableRenderer implements TableRenderer
{
    use TableRendererHelperTrait;

    public function __construct(private readonly HordeCli $cli) {}

    public function render(Table $table): void
    {
        if ($table->headers === [] && $table->rows === []) {
            return;
        }

        if ($table->caption !== null && $table->caption !== '') {
            $this->cli->writeln($table->caption);
            $this->cli->writeln('');
        }

        $alignments = $this->resolveAlignments($table);

        $this->cli->writeln($this->row($table->headers));
        $this->cli->writeln($this->separatorRow($alignments));

        foreach ($table->rows as $row) {
            $this->cli->writeln($this->row($row));
        }
        if ($table->footers !== []) {
            $this->cli->writeln($this->row($table->footers));
        }
    }

    /**
     * @param list<int|string> $cells
     */
    private function row(array $cells): string
    {
        $escaped = [];
        foreach ($cells as $c) {
            $escaped[] = $this->escapeCell((string) $c);
        }
        return '| ' . implode(' | ', $escaped) . ' |';
    }

    /**
     * @param list<string> $alignments
     */
    private function separatorRow(array $alignments): string
    {
        $parts = [];
        foreach ($alignments as $align) {
            $parts[] = match ($align) {
                'right' => '---:',
                'center' => ':---:',
                default => ':---',
            };
        }
        return '| ' . implode(' | ', $parts) . ' |';
    }

    /**
     * Escapes pipe characters and collapses newlines to <br> so the
     * markdown table row stays on a single line.
     */
    private function escapeCell(string $s): string
    {
        $s = str_replace('|', '\\|', $s);
        return preg_replace("/\r?\n/", '<br>', $s) ?? $s;
    }
}
