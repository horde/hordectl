<?php
namespace Horde\Hordectl;
/**
 * Horde Installation finder
 *
 * Abstract access to an installation's registry and backends
 * Don't pollute global namespace
 */
class HordeInstallationFinder
{
    public function __construct(private ?Environment $env)
    {
        $this->env = $env;
    }

    /**
     * Find a horde installation
     *
     * Tries multiple strategies in order:
     * 1. Environment/Config HORDE_BASE (direct path to base app)
     * 2. Heuristic paths relative to cwd
     * 3. Environment/Config HORDE_GIT_DIR (construct path to base)
     * 4. Default locations (/srv/www/horde-dev, ~/www/horde-dev)
     *
     * @return string Path to installation's HORDE_BASE dir
     * @throws HordeNotFoundException if no valid installation found
     */
    public function find()
    {
        // Strategy 1: HORDE_BASE (direct path) from env/config
        if (!empty($this->env) && !empty($this->env['HORDE_BASE'])) {
            $candidate = $this->env['HORDE_BASE'];
            if ($this->isValidHordeBase($candidate)) {
                return $candidate;
            }
        }

        // Strategy 2: Heuristic paths relative to current working directory
        $usualSuspects = [
            getcwd() . '/vendor/horde/horde',       // Modern bundle install
            getcwd() . '/web/horde',                // Older installation
            dirname(__DIR__, 4) . '/web/horde',     // From vendor/horde/hordectl/src
        ];
        foreach ($usualSuspects as $candidate) {
            if ($this->isValidHordeBase($candidate)) {
                return $candidate;
            }
        }

        // Strategy 3: HORDE_GIT_DIR (construct path) from env/config
        if (!empty($this->env) && !empty($this->env['HORDE_GIT_DIR'])) {
            $candidate = $this->env['HORDE_GIT_DIR'] . '/base';
            if ($this->isValidHordeBase($candidate)) {
                return $candidate;
            }
        }

        // Strategy 4: Try common default installation locations
        $defaults = [
            '/srv/www/horde-dev/vendor/horde/horde',                  // Privileged bundle
            $_SERVER['HOME'] . '/www/horde-dev/vendor/horde/horde',  // User bundle
        ];
        foreach ($defaults as $candidate) {
            if ($this->isValidHordeBase($candidate)) {
                return $candidate;
            }
        }

        // No installation found
        throw new HordeNotFoundException('Horde installation not found');
    }

    /**
     * Check if a path is a valid Horde base directory
     *
     * @param string $path Path to check
     * @return bool True if valid Horde base directory
     */
    public function isValidHordeBase(string $path): bool
    {
        return file_exists($path . '/lib/Application.php')
            || file_exists($path . '/src/Application.php');
    }
}