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
 * The compiled registry returned by /api/v1/admin/registry.
 *
 * A two-level structure emitted by
 * Horde\Core\Config\RegistryConfigCompiler:
 *
 *   [
 *     'default'         => [ ...merged default registry, per-app array ],
 *     'foo.example.com' => [ ...delta relative to default              ],
 *     'bar.example.com' => [ ...delta relative to default              ],
 *   ]
 *
 * The wrapper stays minimal on purpose. Each slot's contents are
 * opaque registry data the compiler produced. hordectl's job is to
 * relay them to the admin, not to parse individual app entries.
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Registry
{
    /**
     * @param array<string, array<string, array<string, mixed>>> $slots
     *        Top-level keys are 'default' plus one entry per compiled
     *        vhost. Values are per-app registry arrays.
     */
    public function __construct(
        private readonly array $slots,
    ) {}

    public static function fromApiResponse(array $data): self
    {
        return new self($data);
    }

    /**
     * Return the full compiled structure.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function toArray(): array
    {
        return $this->slots;
    }

    /**
     * Return the slot names ('default' plus each compiled vhost).
     *
     * @return string[]
     */
    public function getSlotNames(): array
    {
        return array_keys($this->slots);
    }

    /**
     * Return the vhost slot names (everything except 'default').
     *
     * @return string[]
     */
    public function getVhosts(): array
    {
        return array_values(array_filter(
            array_keys($this->slots),
            static fn(string $slot): bool => $slot !== 'default',
        ));
    }
}
