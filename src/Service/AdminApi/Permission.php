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
 * Permission DTO from Admin API
 *
 * Represents a single permission with its assignments.
 * Permissions can be:
 * - matrix: Bitmask (SHOW=2, READ=4, EDIT=8, DELETE=16)
 * - boolean: Simple on/off
 * - int: Numeric quotas/limits
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Permission
{
    /**
     * Constructor
     *
     * @param string $name Permission name (hierarchical with colons)
     * @param string $type Permission type (matrix, boolean, int)
     * @param array $users User permissions
     * @param array $groups Group permissions
     * @param mixed $default Default permission
     * @param mixed $guest Guest permission
     * @param mixed $creator Creator permission
     * @param array $parents Parent permission names
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $users,
        public readonly array $groups,
        public readonly mixed $default,
        public readonly mixed $guest,
        public readonly mixed $creator,
        public readonly array $parents
    ) {}

    /**
     * Create from API response data
     *
     * @param array $apiData API response data
     * @return self
     */
    public static function fromApiResponse(array $apiData): self
    {
        return new self(
            name: $apiData['name'] ?? '',
            type: $apiData['type'] ?? 'matrix',
            users: $apiData['users'] ?? [],
            groups: $apiData['groups'] ?? [],
            default: $apiData['default'] ?? null,
            guest: $apiData['guest'] ?? null,
            creator: $apiData['creator'] ?? null,
            parents: $apiData['parents'] ?? []
        );
    }

    /**
     * Convert to array for YAML export
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'data' => [
                'users' => $this->users,
                'groups' => $this->groups,
                'default' => $this->default,
                'guest' => $this->guest,
                'creator' => $this->creator,
                'parents' => $this->parents,
            ],
        ];
    }
}
