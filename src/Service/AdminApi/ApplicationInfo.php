<?php

declare(strict_types=1);

/**
 * Copyright 2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\Hordectl\Service\AdminApi;

/**
 * Application metadata
 *
 * Immutable value object representing a Horde application's metadata.
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ApplicationInfo
{
    /**
     * Constructor
     *
     * @param string $name Application name (e.g., 'turba', 'imp')
     * @param string $version Application version
     * @param string $status Application status (e.g., 'active', 'inactive')
     * @param bool $active Whether application is active
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $status,
        public readonly bool $active
    ) {}

    /**
     * Create from API response
     *
     * Factory method that creates ApplicationInfo from decoded JSON.
     *
     * @param array $data Decoded JSON for single application
     * @return self
     */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            version: $data['version'] ?? 'unknown',
            status: $data['status'] ?? 'unknown',
            active: $data['active'] ?? false
        );
    }
}
