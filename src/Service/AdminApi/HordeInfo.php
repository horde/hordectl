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
 * Horde installation info
 *
 * Immutable value object representing Horde version and paths.
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class HordeInfo
{
    /**
     * Constructor
     *
     * @param string $version Horde version (e.g., '6.0.0-beta4')
     * @param string $basePath Full path to Horde base directory
     * @param string $webroot Web-accessible path (e.g., '/horde')
     * @param array $applications List of application names
     */
    public function __construct(
        public readonly string $version,
        public readonly string $basePath,
        public readonly string $webroot,
        public readonly array $applications
    ) {}

    /**
     * Create from API response
     *
     * Factory method that creates HordeInfo from decoded JSON response
     * from /api/v1/admin/info endpoint.
     *
     * @param array $data Decoded JSON from API response['data']
     * @return self
     */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            version: $data['version'] ?? 'unknown',
            basePath: $data['base_path'] ?? '',
            webroot: $data['webroot'] ?? '',
            applications: $data['applications'] ?? []
        );
    }
}
