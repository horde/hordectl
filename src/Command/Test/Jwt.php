<?php

namespace Horde\Hordectl\Command\Test;

use Horde\Argv\Parser;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;

/**
 * Test JWT authentication configuration
 */
class Jwt implements Module, ModuleUsage
{
    use ModuleTrait;

    protected \Horde_Cli $cli;

    public function __construct(Injector $dependencies)
    {
        $this->dependencies = $dependencies;
        $this->cli = $dependencies->getInstance('\Horde_Cli');
        $this->parser = $dependencies->getInstance(Parser::class);
        $this->parser->allowInterspersedArgs = false;
    }

    /**
     * Handle the test command
     *
     * @param array $argv
     * @return bool
     */
    public function handle(array $argv = [])
    {
        if (count($argv) < 1) {
            return false;
        }
        if ($argv[0] != 'jwt') {
            return false;
        }

        $this->cli->writeln();
        $this->cli->writeln('JWT Authentication Test');
        $this->cli->writeln('=======================');
        $this->cli->writeln();

        try {
            $conf = $GLOBALS['conf'] ?? null;
            if (!isset($conf['auth']['jwt'])) {
                $this->cli->message('JWT not configured in conf.php', 'cli.warning');
                $this->cli->writeln();
                return true;
            }

            $jwtEnabled = $conf['auth']['jwt']['enabled'] ?? false;
            $jwtSecretFile = $conf['auth']['jwt']['secret_file'] ?? '';
            $jwtIssuer = $conf['auth']['jwt']['issuer'] ?? 'not set';
            $jwtAccessTtl = $conf['auth']['jwt']['access_ttl'] ?? 'not set';

            $this->cli->message('JWT enabled: ' . ($jwtEnabled ? 'yes' : 'no'), 'cli.message');
            $this->cli->message('Issuer: ' . $jwtIssuer, 'cli.message');
            $this->cli->message('Access TTL: ' . $jwtAccessTtl . 's', 'cli.message');

            if (!$jwtEnabled) {
                $this->cli->writeln();
                $this->cli->message('JWT authentication is disabled', 'cli.warning');
                $this->cli->writeln();
                return true;
            }

            // Determine secret file path
            if (empty($jwtSecretFile)) {
                // Default location
                if (defined('HORDE_CONFIG_BASE')) {
                    $jwtSecretFile = HORDE_CONFIG_BASE . '/horde/jwt.secret';
                } elseif (defined('HORDE_BASE')) {
                    $jwtSecretFile = HORDE_BASE . '/../../../var/config/horde/jwt.secret';
                }
            } elseif (!str_starts_with($jwtSecretFile, '/')) {
                // Relative path
                if (defined('HORDE_CONFIG_BASE')) {
                    $jwtSecretFile = HORDE_CONFIG_BASE . '/' . $jwtSecretFile;
                } elseif (defined('HORDE_BASE')) {
                    $jwtSecretFile = HORDE_BASE . '/' . $jwtSecretFile;
                }
            }

            if (empty($jwtSecretFile)) {
                $this->cli->message('Secret file path could not be determined', 'cli.error');
                $this->cli->message('Set $conf[\'auth\'][\'jwt\'][\'secret_file\'] or define HORDE_CONFIG_BASE', 'cli.message');
                $this->cli->writeln();
                return true;
            }

            $this->cli->message('Secret file: ' . $jwtSecretFile, 'cli.message');

            // Check if secret file exists and is readable
            if (!file_exists($jwtSecretFile)) {
                $this->cli->message('Secret file not found', 'cli.error');
                $cmd = "openssl rand -base64 32 > " . escapeshellarg($jwtSecretFile) . " && chmod 600 " . escapeshellarg($jwtSecretFile);
                $this->cli->message('Generate with: ' . $cmd, 'cli.message');
                $this->cli->writeln();
                return true;
            }

            if (!is_readable($jwtSecretFile)) {
                $this->cli->message('Secret file not readable', 'cli.error');
                $cmd = "chmod 600 " . escapeshellarg($jwtSecretFile);
                $this->cli->message('Fix permissions: ' . $cmd, 'cli.message');
                $this->cli->writeln();
                return true;
            }

            $secret = trim(file_get_contents($jwtSecretFile));
            if (empty($secret) || strlen($secret) < 32) {
                $this->cli->message('Secret file is empty or too short (< 32 bytes)', 'cli.error');
                $cmd = "openssl rand -base64 32 > " . escapeshellarg($jwtSecretFile) . " && chmod 600 " . escapeshellarg($jwtSecretFile);
                $this->cli->message('Generate with: ' . $cmd, 'cli.message');
                $this->cli->writeln();
                return true;
            }

            $this->cli->message('Secret file size: ' . strlen($secret) . ' bytes', 'cli.message');
            $this->cli->writeln();
            $this->cli->message('JWT configuration: OK', 'cli.success');
        } catch (\Exception $e) {
            $this->cli->message('Error: ' . $e->getMessage(), 'cli.error');
        }

        $this->cli->writeln();
        return true;
    }
}
