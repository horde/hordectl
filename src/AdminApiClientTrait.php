<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\Hordectl;

use Horde\Hordectl\Service\AdminApiClient;
use Horde\Hordectl\Service\AdminApiClientFactory;

/**
 * Trait for creating AdminApiClient from Target
 *
 * Provides helper method for commands that need to create API clients
 * from validated Target configuration.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
trait AdminApiClientTrait
{
    /**
     * Create AdminApiClient from Target configuration
     *
     * Use after calling requireApiCapability() to get validated target.
     *
     * Example:
     * <code>
     * $target = $this->requireApiCapability();
     * $this->apiClient = $this->createApiClientFromTarget($target);
     * $users = $this->apiClient->listUsers();
     * </code>
     *
     * @param Target $target Validated target with API capability
     * @return AdminApiClient Configured API client ready for use
     */
    protected function createApiClientFromTarget(Target $target): AdminApiClient
    {
        $factory = new AdminApiClientFactory();
        return $factory->createFromTarget($target);
    }
}
