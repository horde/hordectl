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
 * Collection of applications
 *
 * Immutable collection of ApplicationInfo objects.
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class ApplicationList
{
    /** @var ApplicationInfo[] */
    private array $applications;

    /**
     * Constructor
     *
     * @param ApplicationInfo ...$applications Variable number of ApplicationInfo objects
     */
    public function __construct(ApplicationInfo ...$applications)
    {
        $this->applications = $applications;
    }

    /**
     * Create from API response
     *
     * Factory method that creates ApplicationList from decoded JSON array.
     *
     * @param array $data Decoded JSON array from API response['data']
     * @return self
     */
    public static function fromApiResponse(array $data): self
    {
        $apps = [];
        foreach ($data as $appData) {
            $apps[] = ApplicationInfo::fromApiResponse($appData);
        }
        return new self(...$apps);
    }

    /**
     * Get all applications
     *
     * @return ApplicationInfo[]
     */
    public function toArray(): array
    {
        return $this->applications;
    }

    /**
     * Count applications
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->applications);
    }

    /**
     * Check if empty
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->applications);
    }
}
