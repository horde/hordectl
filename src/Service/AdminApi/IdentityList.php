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
 * Identity collection
 *
 * Represents a list of identities for a user from the Admin REST API.
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class IdentityList
{
    /**
     * Constructor
     *
     * @param string $username Username these identities belong to
     * @param int $defaultIdentity Index of default identity (0-based)
     * @param array $identities Array of Identity objects
     */
    public function __construct(
        public readonly string $username,
        public readonly int $defaultIdentity,
        public readonly array $identities
    ) {}

    /**
     * Create from API response
     *
     * Factory method that creates IdentityList from decoded JSON response
     * from GET /api/v1/admin/identities/:username.
     *
     * @param array $data Decoded JSON from API response
     * @return self
     */
    public static function fromApiResponse(array $data): self
    {
        $identities = [];
        foreach ($data['identities'] ?? [] as $identityData) {
            $identities[] = Identity::fromApiResponse($identityData);
        }

        return new self(
            username: $data['username'] ?? '',
            defaultIdentity: $data['default_identity'] ?? 0,
            identities: $identities
        );
    }

    /**
     * Get the default identity
     *
     * @return Identity|null Default identity or null if none
     */
    public function getDefault(): ?Identity
    {
        return $this->identities[$this->defaultIdentity] ?? null;
    }

    /**
     * Count identities
     *
     * @return int Number of identities
     */
    public function count(): int
    {
        return count($this->identities);
    }

    /**
     * Check if list is empty
     *
     * @return bool True if no identities
     */
    public function isEmpty(): bool
    {
        return empty($this->identities);
    }

    /**
     * Get identity by index
     *
     * @param int $index Identity index
     * @return Identity|null Identity or null if not found
     */
    public function get(int $index): ?Identity
    {
        return $this->identities[$index] ?? null;
    }
}
