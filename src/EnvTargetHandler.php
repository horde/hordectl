<?php

declare(strict_types=1);

namespace Horde\Hordectl;

/**
 * Environment variable target handler
 *
 * Creates "last_env" target from HORDE_BASE or HORDE_GIT_DIR
 * environment variables. Target is persisted to configuration.
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
class EnvTargetHandler
{
    public function __construct(
        private Environment $env,
        private ConfigManager $config,
        private TargetResolver $resolver
    ) {}

    /**
     * Create or update last_env target from environment variables
     *
     * Checks for HORDE_BASE or HORDE_GIT_DIR environment variables
     * and creates/updates the "last_env" target if found.
     */
    public function createOrUpdateFromEnv(): void
    {
        $hordeBase = $this->env->get('HORDE_BASE');
        $hordeGitDir = $this->env->get('HORDE_GIT_DIR');

        if ($hordeBase === null && $hordeGitDir === null) {
            return; // No env vars set
        }

        // Determine paths
        if ($hordeGitDir !== null) {
            $hordeBase = $hordeGitDir . '/base';
        }

        $installDir = dirname($hordeBase, 3); // Remove /vendor/horde/horde

        // Create or update last_env target
        $envTarget = new Target(
            name: 'last_env',
            type: TargetType::Local,
            hordeBase: $hordeBase,
            hordeInstallDir: $installDir,
            endpoint: null,
            adminSecret: null,
            description: 'Created from environment variables',
            fromEnv: true,
        );

        $this->resolver->saveTarget($this->config, $envTarget);

        // Set as current if no other targets exist
        $currentTarget = $this->config->get('current-target');
        if ($currentTarget === null) {
            $this->resolver->setCurrentTarget($this->config, 'last_env');
        }
    }
}
