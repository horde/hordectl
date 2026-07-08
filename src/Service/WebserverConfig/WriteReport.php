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
 * Outcome of a ConfigWriter run. Carries per-category path lists so
 * the CLI glue can render a summary.
 */
final class WriteReport
{
    /**
     * @param list<string> $written
     * @param list<string> $skipped
     * @param list<string> $sketched Operator-owned sketch files that were preserved.
     * @param list<string> $failed
     */
    public function __construct(
        public readonly array $written,
        public readonly array $skipped,
        public readonly array $sketched,
        public readonly array $failed,
    ) {
    }

    public function ok(): bool
    {
        return $this->failed === [];
    }
}
