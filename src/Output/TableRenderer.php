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
 * Renders a Table value to stdout via Horde\Cli\Cli::writeln.
 *
 * Phase 1 has three concrete implementations. Phase 2 dissolves them
 * into per-presenter table() methods on Horde\Cli\Output\Presenter.
 */
interface TableRenderer
{
    public function render(Table $table): void;
}
