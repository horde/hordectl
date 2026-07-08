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
 * Immutable description of a tabular data set to render.
 *
 * The value class stays presentation-agnostic. Which glyphs draw the
 * borders, whether numbers right-align, how wide the columns get, are
 * all renderer decisions. The Table only carries the data plus hints
 * (alignments, caption, footer) the renderer may honour.
 *
 * See ~/php/horde-development/tools/hordectl/table-presenter-sub-plan-2026-07-08.md
 * for the design rationale. Phase 2 moves this class into horde/Cli.
 */
final class Table
{
    /**
     * @param list<string>              $headers      Column headers, one per column.
     * @param list<list<int|string>>    $rows         Data rows. Each row's length must match $headers.
     * @param list<'left'|'right'|'center'> $alignments Per-column alignment hint. Missing entries default to 'left'.
     * @param string|null               $caption      Optional caption printed above the table.
     * @param list<int|string>          $footers      Optional single footer row (totals / summary). Same width as $headers.
     */
    public function __construct(
        public readonly array $headers,
        public readonly array $rows,
        public readonly array $alignments = [],
        public readonly ?string $caption = null,
        public readonly array $footers = [],
    ) {}

    /**
     * Convenience constructor accepting a single associative array with
     * top-level keys 'headers', 'rows', 'alignments', 'caption', 'footers'.
     *
     * Handy when composing tables from config or serialized data.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            headers: $data['headers'] ?? [],
            rows: $data['rows'] ?? [],
            alignments: $data['alignments'] ?? [],
            caption: $data['caption'] ?? null,
            footers: $data['footers'] ?? [],
        );
    }
}
