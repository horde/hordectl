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
 * Group DTO from Admin API
 *
 * Represents a single group with its members.
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Group
{
    /**
     * Constructor
     *
     * @param string $id Group ID
     * @param string $name Group name
     * @param array $members List of usernames
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $members
    ) {}

    /**
     * Create from API response data
     *
     * @param array $data API response data
     * @return self
     */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            name: $data['name'] ?? '',
            members: $data['members'] ?? []
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
            'groupName' => $this->name,
            'groupId' => $this->id,
            'members' => $this->members,
        ];
    }
}
