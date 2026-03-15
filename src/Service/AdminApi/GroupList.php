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
 * GroupList DTO from Admin API
 *
 * Represents a paginated list of groups.
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class GroupList
{
    /**
     * Constructor
     *
     * @param array $groups Array of Group objects
     * @param int $total Total number of groups
     * @param int $page Current page number
     * @param int $perPage Groups per page
     * @param bool $hasNext Has next page
     * @param bool $hasPrev Has previous page
     */
    public function __construct(
        public readonly array $groups,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly bool $hasNext,
        public readonly bool $hasPrev
    ) {}

    /**
     * Create from API response data
     *
     * @param array $data API response data (array of groups)
     * @param array $pagination Pagination metadata
     * @return self
     */
    public static function fromApiResponse(array $data, array $pagination): self
    {
        $groups = array_map(
            fn($item) => Group::fromApiResponse($item),
            $data
        );

        return new self(
            groups: $groups,
            total: $pagination['total'] ?? 0,
            page: $pagination['page'] ?? 1,
            perPage: $pagination['per_page'] ?? 50,
            hasNext: $pagination['has_next'] ?? false,
            hasPrev: $pagination['has_prev'] ?? false
        );
    }

    /**
     * Convert to array for iteration
     *
     * @return array Array of Group objects
     */
    public function toArray(): array
    {
        return $this->groups;
    }
}
