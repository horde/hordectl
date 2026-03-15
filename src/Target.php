<?php

declare(strict_types=1);

namespace Horde\Hordectl;

/**
 * Target value object
 *
 * Represents a hordectl target (either local or remote Horde installation).
 * Immutable value object created from configuration data.
 *
 * Local targets have filesystem access and can:
 * - Reconfigure Horde (configure commands)
 * - Rotate admin secrets
 * - Activate/deactivate applications
 * - Self-heal from credential mismatches
 *
 * Remote targets have only REST API access and cannot:
 * - Reconfigure Horde
 * - Recover from credential rotation without external help
 *
 * Local targets may optionally have an API endpoint for REST commands.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class Target
{
    public function __construct(
        public readonly string $name,
        public readonly TargetType $type,
        public readonly ?string $hordeBase,
        public readonly ?string $hordeInstallDir,
        public readonly ?string $endpoint,
        public readonly ?string $adminSecret,
        public readonly bool $verifySsl = true,
        public readonly ?string $description = null,
        public readonly bool $autoDetected = false,
        public readonly bool $fromEnv = false,
    ) {}

    /**
     * Create Target from configuration array
     *
     * @param string $name Target name
     * @param array $config Configuration array
     * @return self
     */
    public static function fromArray(string $name, array $config): self
    {
        return new self(
            name: $name,
            type: TargetType::from($config['type']),
            hordeBase: $config['horde_base'] ?? null,
            hordeInstallDir: $config['horde_install_dir'] ?? null,
            endpoint: $config['endpoint'] ?? null,
            adminSecret: $config['admin_secret'] ?? null,
            verifySsl: $config['verify_ssl'] ?? true,
            description: $config['description'] ?? null,
            autoDetected: $config['auto_detected'] ?? false,
            fromEnv: $config['from_env'] ?? false,
        );
    }

    /**
     * Convert Target to configuration array
     *
     * @return array Configuration array
     */
    public function toArray(): array
    {
        $arr = ['type' => $this->type->value];

        if ($this->hordeBase !== null) {
            $arr['horde_base'] = $this->hordeBase;
        }
        if ($this->hordeInstallDir !== null) {
            $arr['horde_install_dir'] = $this->hordeInstallDir;
        }
        if ($this->endpoint !== null) {
            $arr['endpoint'] = $this->endpoint;
        }
        if ($this->adminSecret !== null) {
            $arr['admin_secret'] = $this->adminSecret;
        }
        if (!$this->verifySsl) {
            $arr['verify_ssl'] = false;
        }
        if ($this->description !== null) {
            $arr['description'] = $this->description;
        }
        if ($this->autoDetected) {
            $arr['auto_detected'] = true;
        }
        if ($this->fromEnv) {
            $arr['from_env'] = true;
        }

        return $arr;
    }

    /**
     * Check if this is a local target
     *
     * @return bool
     */
    public function isLocal(): bool
    {
        return $this->type === TargetType::Local;
    }

    /**
     * Check if this is a remote target
     *
     * @return bool
     */
    public function isRemote(): bool
    {
        return $this->type === TargetType::Remote;
    }

    /**
     * Check if target supports filesystem commands
     *
     * Filesystem commands include:
     * - configure database
     * - configure session-handler
     * - secret:generate
     * - activate
     *
     * @return bool
     */
    public function supportsFilesystemCommands(): bool
    {
        return $this->isLocal();
    }

    /**
     * Check if target supports REST API commands
     *
     * API commands include:
     * - query user/group/permission
     * - import user/group/permission
     * - patch user
     * - test all/auth/cache/db/session
     *
     * Remote targets always support API.
     * Local targets support API only if endpoint is configured.
     *
     * @return bool
     */
    public function supportsApiCommands(): bool
    {
        return $this->isRemote() || ($this->isLocal() && $this->endpoint !== null);
    }

    /**
     * Get human-readable location string
     *
     * For local targets: filesystem path (+ API if endpoint configured)
     * For remote targets: endpoint URL
     *
     * @return string
     */
    public function getLocationString(): string
    {
        if ($this->isLocal()) {
            $location = $this->hordeInstallDir ?? $this->hordeBase ?? '(unknown)';
            if ($this->endpoint !== null) {
                $location .= ' (+ API)';
            }
            return $location;
        }
        return $this->endpoint ?? '(unknown)';
    }

    /**
     * Get abbreviated location string for display in lists
     *
     * Truncates long paths/URLs to fit in terminal output.
     *
     * @param int $maxLength Maximum length before abbreviation
     * @return string
     */
    public function getAbbreviatedLocation(int $maxLength = 50): string
    {
        $location = $this->getLocationString();
        if (strlen($location) <= $maxLength) {
            return $location;
        }
        return substr($location, 0, $maxLength - 3) . '...';
    }
}
