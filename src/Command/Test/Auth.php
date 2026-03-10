<?php

namespace Horde\Hordectl\Command\Test;

use Horde\Argv\Parser;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;

/**
 * Test authentication system
 */
class Auth implements Module, ModuleUsage
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
        if ($argv[0] != 'auth') {
            return false;
        }

        $this->cli->writeln();
        $this->cli->writeln('Authentication System Test');
        $this->cli->writeln('==========================');
        $this->cli->writeln();

        try {
            $conf = $GLOBALS['conf'] ?? null;
            if (!isset($conf['auth']['driver'])) {
                $this->cli->message('Authentication driver not configured in conf.php', 'cli.error');
                return true;
            }

            $authDriver = $conf['auth']['driver'];
            $authAdmins = $conf['auth']['admins'] ?? [];
            $adminList = is_array($authAdmins) ? implode(', ', $authAdmins) : $authAdmins;

            $this->cli->message('Configured driver: ' . $authDriver, 'cli.message');
            if (!empty($adminList)) {
                $this->cli->message('Administrators: ' . $adminList, 'cli.message');
            }

            if (isset($GLOBALS['injector'])) {
                $auth = $GLOBALS['injector']->getInstance('Horde_Core_Factory_Auth')->create();
                if ($auth) {
                    $authClass = get_class($auth);
                    $this->cli->message('Active driver class: ' . $authClass, 'cli.message');

                    // Check if this is the Application wrapper and introspect the wrapped driver
                    if ($authClass === 'Horde_Core_Auth_Application') {
                        try {
                            $reflection = new \ReflectionClass($auth);
                            if ($reflection->hasProperty('_base')) {
                                $baseProperty = $reflection->getProperty('_base');
                                $baseProperty->setAccessible(true);
                                $baseDriver = $baseProperty->getValue($auth);

                                if ($baseDriver !== null) {
                                    $baseClass = get_class($baseDriver);
                                    $this->cli->message(
                                        'Wrapped backend: ' . $baseClass,
                                        'cli.message'
                                    );
                                }
                            }
                        } catch (\ReflectionException $e) {
                            // Reflection failed, just show the wrapper class
                        }
                    }

                    $this->cli->writeln();
                    $this->cli->message('Authentication system: OK', 'cli.success');
                } else {
                    $this->cli->message('Auth factory returned null', 'cli.error');
                }
            } else {
                $this->cli->message('Injector not available', 'cli.error');
            }
        } catch (\Exception $e) {
            $this->cli->message('Error: ' . $e->getMessage(), 'cli.error');
        }

        $this->cli->writeln();
        return true;
    }
}
