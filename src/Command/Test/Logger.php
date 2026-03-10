<?php

namespace Horde\Hordectl\Command\Test;

use Horde\Argv\Parser;
use Horde\Hordectl\HordectlModuleTrait as ModuleTrait;
use Horde\Injector\Injector;
use Horde_Cli_Modular_Module as Module;
use Horde_Cli_Modular_ModuleUsage as ModuleUsage;

/**
 * Test logging system
 */
class Logger implements Module, ModuleUsage
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
        if ($argv[0] != 'logger') {
            return false;
        }

        $this->cli->writeln();
        $this->cli->writeln('Logger Test');
        $this->cli->writeln('===========');
        $this->cli->writeln();

        try {
            $conf = $GLOBALS['conf'] ?? null;
            $loggerConfigured = false;
            $logType = 'not configured';
            $logEnabled = false;

            if (isset($conf['log'])) {
                $logEnabled = $conf['log']['enabled'] ?? false;
                $logType = $conf['log']['type'] ?? 'not configured';
                $loggerConfigured = true;
            }

            $this->cli->message('Logging enabled: ' . ($logEnabled ? 'yes' : 'no'), 'cli.message');
            $this->cli->message('Log type: ' . $logType, 'cli.message');

            if (!$logEnabled) {
                $this->cli->writeln();
                $this->cli->message('Logging is disabled', 'cli.warning');
                $this->cli->writeln();
                return true;
            }

            // Show additional details based on log type
            if ($logType === 'file') {
                $logFile = $conf['log']['name'] ?? 'not set';
                $this->cli->message('Log file: ' . $logFile, 'cli.message');
            } elseif ($logType === 'syslog') {
                $logIdent = $conf['log']['ident'] ?? 'horde';
                $this->cli->message('Syslog ident: ' . $logIdent, 'cli.message');
                $this->cli->message('Check: /var/log/syslog or /var/log/messages', 'cli.message');
            } elseif ($logType === 'stream') {
                $logFile = $conf['log']['name'] ?? 'not set';
                $this->cli->message('Stream: ' . $logFile, 'cli.message');
            }

            if (!isset($GLOBALS['injector'])) {
                $this->cli->message('Injector not available', 'cli.error');
                return true;
            }

            $logger = $GLOBALS['injector']->getInstance('Horde_Log_Logger');
            if ($logger) {
                $loggerClass = get_class($logger);
                $this->cli->message('Logger class: ' . $loggerClass, 'cli.message');

                // Test logging by writing a test message
                try {
                    $testMessage = 'hordectl test logger - ' . date('Y-m-d H:i:s');
                    $logger->info($testMessage);
                    $this->cli->message('Test message logged (via syslog): ' . $testMessage, 'cli.message');

                    // Show where to check for the log
                    if ($logType === 'syslog') {
                        $this->cli->message('Check syslog for the test message', 'cli.message');
                    } elseif ($logType === 'file') {
                        $logFile = $conf['log']['name'] ?? 'not set';
                        $this->cli->message('Check log file for the test message: ' . $logFile, 'cli.message');
                    } elseif ($logType === 'stream') {
                        $logFile = $conf['log']['name'] ?? 'not set';
                        $this->cli->message('Check stream for the test message: ' . $logFile, 'cli.message');
                    }
                } catch (\Exception $logException) {
                    $this->cli->message('Failed to write test log message: ' . $logException->getMessage(), 'cli.warning');
                }

                // Test native systemd journal logging if available
                $journalLogger = new \Horde\Log\Handler\SystemdJournalHandler(
                    new \Horde\Log\Handler\SystemdJournalOptions([
                        'ident' => 'hordectl-test',
                        'additionalFields' => [
                            'HORDE_COMPONENT' => 'logger_test',
                            'HORDE_VERSION' => '6.0',
                            'TEST_TYPE' => 'subsystem',
                        ]
                    ])
                );

                if ($journalLogger->isAvailable()) {
                    $this->cli->writeln();
                    $this->cli->message('Systemd journal socket available', 'cli.message');

                    $journalMessage = new \Horde\Log\LogMessage(
                        new \Horde\Log\LogLevel(\Horde_Log::INFO, 'Info'),
                        'hordectl native systemd journal test - ' . date('Y-m-d H:i:s'),
                        [
                            'file' => __FILE__,
                            'line' => __LINE__,
                        ]
                    );
                    $journalMessage->formatMessage([]);

                    if ($journalLogger->write($journalMessage)) {
                        $this->cli->message('Test message logged (native systemd journal with metadata)', 'cli.message');
                        $this->cli->message('View with: journalctl -n 1 SYSLOG_IDENTIFIER=hordectl-test --output=json-pretty', 'cli.message');
                        $this->cli->message('Custom fields: HORDE_COMPONENT, HORDE_VERSION, TEST_TYPE, CODE_FILE, CODE_LINE', 'cli.message');
                    } else {
                        $this->cli->message('Failed to write to systemd journal socket', 'cli.warning');
                    }
                } else {
                    $this->cli->writeln();
                    $this->cli->message('Systemd journal socket not available (not on systemd system)', 'cli.warning');
                }

                $this->cli->writeln();
                $this->cli->message('Logger: OK', 'cli.success');
            } else {
                $this->cli->message('Logger not initialized', 'cli.error');
            }
        } catch (\Exception $e) {
            $this->cli->message('Error: ' . $e->getMessage(), 'cli.error');
        }

        $this->cli->writeln();
        return true;
    }
}
