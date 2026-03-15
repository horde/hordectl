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

namespace Horde\Hordectl\Service;

/**
 * Admin API client configuration
 *
 * Immutable value object holding endpoint URL and admin_secret for
 * authenticating with Horde's admin REST API.
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class AdminApiConfig
{
    /**
     * Constructor
     *
     * @param string $endpoint Base Horde URL (e.g., 'http://localhost/horde')
     * @param string $adminSecret Bearer token for authentication
     * @param string $apiVersion API version (default: 'v1')
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly string $adminSecret,
        public readonly string $apiVersion = 'v1'
    ) {}

    /**
     * Get full API base URL
     *
     * Combines endpoint with API version path.
     *
     * @return string Full API base URL (e.g., 'http://localhost/horde/api/v1')
     */
    public function getApiBaseUrl(): string
    {
        return rtrim($this->endpoint, '/') . '/api/' . $this->apiVersion;
    }
}
