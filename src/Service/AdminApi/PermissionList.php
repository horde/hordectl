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
 * Permission list DTO from Admin API
 *
 * Represents a list of permissions returned from the admin API.
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class PermissionList
{
    /**
     * Constructor
     *
     * @param array<Permission> $permissions List of permissions
     */
    public function __construct(
        private array $permissions
    ) {}

    /**
     * Create from API response data
     *
     * @param array $data API response data
     * @return self
     */
    public static function fromApiResponse(array $data): self
    {
        $permissions = [];
        foreach ($data as $permData) {
            $permissions[] = Permission::fromApiResponse($permData);
        }

        return new self($permissions);
    }

    /**
     * Get all permissions as array
     *
     * @return array<Permission>
     */
    public function toArray(): array
    {
        return $this->permissions;
    }

    /**
     * Get permissions count
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->permissions);
    }
}
