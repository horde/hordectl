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
 * Data Transfer Object for health check results
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class HealthCheckResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly array $details = []
    ) {}

    /**
     * Create from API response data
     *
     * @param array $data Response data from API
     * @return self
     */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            status: $data['status'] ?? 'unknown',
            message: $data['message'] ?? '',
            details: $data['details'] ?? []
        );
    }

    /**
     * Check if health check passed
     *
     * @return bool
     */
    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    /**
     * Check if health check has warnings
     *
     * @return bool
     */
    public function isWarning(): bool
    {
        return $this->status === 'warning';
    }

    /**
     * Check if health check failed
     *
     * @return bool
     */
    public function isError(): bool
    {
        return $this->status === 'error';
    }
}
