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
 * Collection of users with pagination metadata
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class UserList
{
    /** @var User[] */
    private array $users;

    /**
     * Constructor
     *
     * @param User[] $users Array of User objects
     * @param int|null $total Total users across all pages (null if unpaginated)
     * @param int|null $page Current page number (1-indexed)
     * @param int|null $perPage Users per page
     * @param bool $hasNext Whether there are more pages
     * @param bool $hasPrev Whether there are previous pages
     */
    public function __construct(
        array $users,
        public readonly ?int $total = null,
        public readonly ?int $page = null,
        public readonly ?int $perPage = null,
        public readonly bool $hasNext = false,
        public readonly bool $hasPrev = false
    ) {
        $this->users = $users;
    }

    /**
     * Create from API response
     *
     * Factory method that creates UserList from decoded JSON response.
     *
     * @param array $data Array of user data from API response['data']
     * @param array $pagination Optional pagination metadata from API response['pagination']
     * @return self
     */
    public static function fromApiResponse(array $data, array $pagination = []): self
    {
        $users = [];
        foreach ($data as $userData) {
            $users[] = User::fromApiResponse($userData);
        }

        return new self(
            users: $users,
            total: $pagination['total'] ?? null,
            page: $pagination['page'] ?? null,
            perPage: $pagination['per_page'] ?? null,
            hasNext: $pagination['has_next'] ?? false,
            hasPrev: $pagination['has_prev'] ?? false
        );
    }

    /**
     * Get all users
     *
     * @return User[]
     */
    public function toArray(): array
    {
        return $this->users;
    }

    /**
     * Count users in this page/list
     *
     * @return int Number of users in current result set
     */
    public function count(): int
    {
        return count($this->users);
    }

    /**
     * Check if empty
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->users);
    }

    /**
     * Find user by username
     *
     * Searches within current page/list only.
     *
     * @param string $username
     * @return User|null
     */
    public function find(string $username): ?User
    {
        foreach ($this->users as $user) {
            if ($user->username === $username) {
                return $user;
            }
        }
        return null;
    }

    /**
     * Check if pagination is available
     *
     * @return bool True if response included pagination metadata
     */
    public function isPaginated(): bool
    {
        return $this->total !== null;
    }

    /**
     * Get total pages
     *
     * @return int|null Total pages or null if not paginated
     */
    public function getTotalPages(): ?int
    {
        if ($this->total === null || $this->perPage === null || $this->perPage === 0) {
            return null;
        }
        return (int) ceil($this->total / $this->perPage);
    }
}
