<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Service\WebserverConfig;

/**
 * One entry in an EmitPlan: a target path, its content, and whether
 * the file is operator-owned (sketch mode: write only if missing,
 * never overwrite).
 */
final class EmitEntry
{
    public function __construct(
        public readonly string $path,
        public readonly string $content,
        public readonly bool $sketch = false,
    ) {
    }
}
