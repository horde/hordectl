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
 * Outcome of an AppMapBuilder call.
 *
 * Success carries the resolved app list plus the default host / TLS
 * hint that emitters use when no per-app host is available.
 * Failure carries a single fatal error message. Warnings are
 * non-fatal notes the caller can render alongside a success.
 */
final class MapBuildResult
{
    /**
     * @param list<AppEntry> $apps
     * @param list<string> $warnings
     */
    private function __construct(
        public readonly bool $ok,
        public readonly array $apps,
        public readonly string $defaultHost,
        public readonly bool $defaultTls,
        public readonly string $error,
        public readonly array $warnings,
    ) {
    }

    /**
     * @param list<AppEntry> $apps
     * @param list<string> $warnings
     */
    public static function success(array $apps, string $defaultHost, bool $defaultTls, array $warnings = []): self
    {
        return new self(true, $apps, $defaultHost, $defaultTls, '', $warnings);
    }

    public static function failure(string $error): self
    {
        return new self(false, [], '', false, $error, []);
    }
}
