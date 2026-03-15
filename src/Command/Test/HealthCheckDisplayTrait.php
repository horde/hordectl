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

namespace Horde\Hordectl\Command\Test;

use Horde\Hordectl\Service\AdminApi\HealthCheckResult;
use Exception;

/**
 * Trait for displaying health check results
 *
 * @category Horde
 * @package  Hordectl
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 * @license  http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
trait HealthCheckDisplayTrait
{
    /**
     * Display health check result
     *
     * @param HealthCheckResult $result Health check result
     */
    protected function displayHealthCheck(HealthCheckResult $result): void
    {
        if ($result->isOk()) {
            $this->cli->message('Status: ' . $this->cli->green('OK'), 'cli.message');
            $this->cli->message('Message: ' . $result->message, 'cli.message');
        } elseif ($result->isWarning()) {
            $this->cli->message('Status: ' . $this->cli->yellow('WARNING'), 'cli.message');
            $this->cli->message('Message: ' . $result->message, 'cli.warning');
        } else {
            $this->cli->message('Status: ' . $this->cli->red('ERROR'), 'cli.message');
            $this->cli->message('Message: ' . $result->message, 'cli.error');
        }

        // Display details
        if (!empty($result->details)) {
            $this->cli->writeln();
            $this->cli->writeln('Details:');
            foreach ($result->details as $key => $value) {
                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                } elseif (is_array($value)) {
                    $value = json_encode($value);
                }
                $this->cli->writeln('  ' . $key . ': ' . $value);
            }
        }

        $this->cli->writeln();
    }

    /**
     * Display API error
     *
     * @param Exception $e Exception
     */
    protected function displayApiError(Exception $e): void
    {
        $this->cli->writeln();
        $this->cli->message('ERROR: ' . $e->getMessage(), 'cli.error');
        $this->cli->writeln();
        $this->cli->writeln('Unable to perform health check via REST API.');
        $this->cli->writeln('Please check:');
        $this->cli->writeln('  - Admin API is enabled in Horde conf.php');
        $this->cli->writeln('  - admin_secret is configured in hordectl.php or Horde conf.php');
        $this->cli->writeln('  - Horde endpoint is accessible');
        $this->cli->writeln();
    }
}
