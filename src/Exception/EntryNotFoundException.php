<?php
/**
 * Injected - Simple but fast PHP parameter resolver, callable invoker, Interop container
 *
 * @link      https://github.com/aaphp/injected
 * @copyright Copyright (c) 2016 Kosit Supanyo
 * @license   https://github.com/aaphp/injected/blob/v1.x/LICENSE.md (MIT License)
 */
namespace aaphp\Injected\Exception;

use Interop\Container\Exception\NotFoundException as InteropException;

class NotFoundException extends ContainerException implements InteropException
{
}
