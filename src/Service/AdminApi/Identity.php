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
 * Identity resource
 *
 * Represents a user identity (email address, name, signature, etc.)
 * from the Admin REST API.
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Identity
{
    /**
     * Constructor
     *
     * @param int $index Identity index (0-based position in user's identity list)
     * @param string $id Identity label/name (e.g., "Default", "Work")
     * @param string $fullname User's full name for this identity
     * @param string $fromAddr Email address (from_addr field)
     * @param string $location Optional location/signature
     * @param array $extra Additional fields from API response
     */
    public function __construct(
        public readonly int $index,
        public readonly string $id,
        public readonly string $fullname,
        public readonly string $fromAddr,
        public readonly string $location = '',
        public readonly array $extra = []
    ) {}

    /**
     * Create from API response
     *
     * Factory method that creates Identity from decoded JSON response
     * from /api/v1/admin/identities endpoints.
     *
     * @param array $data Decoded JSON from API response
     * @return self
     */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            index: $data['index'] ?? 0,
            id: $data['id'] ?? '',
            fullname: $data['fullname'] ?? '',
            fromAddr: $data['from_addr'] ?? '',
            location: $data['location'] ?? '',
            extra: array_diff_key($data, array_flip([
                'index', 'id', 'fullname', 'from_addr', 'location',
            ]))
        );
    }

    /**
     * Convert to array for API requests
     *
     * @return array
     */
    public function toArray(): array
    {
        $result = [
            'id' => $this->id,
            'fullname' => $this->fullname,
            'from_addr' => $this->fromAddr,
            'location' => $this->location,
        ];

        // Merge extra fields
        return array_merge($result, $this->extra);
    }
}
