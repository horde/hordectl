<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl\Service;

use Horde\Hordectl\Exception\NoCurrentTargetException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use RuntimeException;

/**
 * Null Admin API Client
 *
 * Placeholder that satisfies DI container during module discovery.
 * Commands should create real AdminApiClient from target configuration.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class NullAdminApiClient extends AdminApiClient
{
    public function __construct()
    {
        // Empty constructor - no dependencies needed
        // This is just a placeholder for DI resolution
    }

    /**
     * All methods throw exception - this is a placeholder only
     */
    private function throwError(): never
    {
        throw new RuntimeException(
            "NullAdminApiClient cannot be used for API calls. "
            . "Commands must create AdminApiClient from target configuration."
        );
    }

    public function get(string $path, array $params = []): array
    {
        $this->throwError();
    }

    public function post(string $path, array $data = []): array
    {
        $this->throwError();
    }

    public function put(string $path, array $data = []): array
    {
        $this->throwError();
    }

    public function delete(string $path): array
    {
        $this->throwError();
    }
}
