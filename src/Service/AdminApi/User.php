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
 * User resource
 *
 * Represents a Horde user with username as the natural identifier.
 * Concrete implementation for user-specific operations.
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class User
{
    /**
     * Constructor
     *
     * @param string $username Unique username (natural identifier)
     * @param string $email User email address
     * @param bool $enabled Whether user is enabled
     * @param array $identities User identities (from_addr, fullname, etc.)
     * @param array $extra Additional backend-specific fields
     */
    public function __construct(
        public readonly string $username,
        public readonly string $email,
        public readonly bool $enabled,
        public readonly array $identities,
        public readonly array $extra = []
    ) {}

    /**
     * Create from API response
     *
     * Factory method that creates User from decoded JSON response
     * from /api/v1/admin/users endpoint.
     *
     * @param array $data Decoded JSON from API response
     * @return self
     */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            username: $data['username'] ?? '',
            email: $data['email'] ?? '',
            enabled: $data['enabled'] ?? true,
            identities: $data['identities'] ?? [],
            extra: $data['extra'] ?? []
        );
    }

    /**
     * Convert to array for API requests
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'username' => $this->username,
            'email' => $this->email,
            'enabled' => $this->enabled,
            'identities' => $this->identities,
            'extra' => $this->extra,
        ];
    }

    /**
     * Create minimal user for password changes
     *
     * Helper for creating User object when only username is known.
     *
     * @param string $username
     * @return self
     */
    public static function minimal(string $username): self
    {
        return new self(
            username: $username,
            email: '',
            enabled: true,
            identities: [],
            extra: []
        );
    }
}
