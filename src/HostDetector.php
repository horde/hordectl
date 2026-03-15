<?php

declare(strict_types=1);

namespace Horde\Hordectl;

/**
 * Host target auto-detector
 *
 * Automatically detects and creates "host" target when hordectl
 * is installed as a dependency of a full Horde installation.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class HostDetector
{
    public function __construct(
        private HordeInstallationFinder $finder,
        private ConfigManager $config,
        private TargetResolver $resolver
    ) {}

    /**
     * Auto-detect and add host target if not already configured
     *
     * Attempts to find a local Horde installation and creates
     * a "host" target for it. Only runs if "host" doesn't exist.
     */
    public function autoDetectAndAddHost(): void
    {
        // Skip if host already configured
        if ($this->resolver->targetExists($this->config, 'host')) {
            return;
        }

        try {
            $hordePath = $this->finder->find();
            $installDir = dirname($hordePath, 3); // Remove /vendor/horde/horde

            // Create host target
            $hostTarget = new Target(
                name: 'host',
                type: TargetType::Local,
                hordeBase: $hordePath,
                hordeInstallDir: $installDir,
                endpoint: null,
                adminSecret: null,
                description: 'Host Horde installation (auto-detected)',
                autoDetected: true,
            );

            $this->resolver->saveTarget($this->config, $hostTarget);

            // Set as current if no other targets or no current target
            $targets = $this->resolver->getAllTargets($this->config);
            if (count($targets) === 1 || $this->config->get('current-target') === null) {
                $this->resolver->setCurrentTarget($this->config, 'host');
            }

        } catch (HordeNotFoundException $e) {
            // No Horde found, skip auto-detection
        }
    }
}
