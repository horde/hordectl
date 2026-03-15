<?php

declare(strict_types=1);

namespace Horde\Hordectl;

/**
 * Target type enumeration
 *
 * Defines the two types of hordectl targets:
 * - Local: Has filesystem access to Horde installation
 * - Remote: Has only REST API access via HTTP(S)
 *
 * @package Hordectl
 * @license http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */
enum TargetType: string
{
    case Local = 'local';
    case Remote = 'remote';
}
