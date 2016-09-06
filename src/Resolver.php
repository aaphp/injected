<?php
/**
 * Injected - Simple but fast PHP parameter resolver, callable invoker, Interop container
 *
 * @link      https://github.com/aaphp/injected
 * @copyright Copyright (c) 2016 Kosit Supanyo
 * @license   https://github.com/aaphp/injected/blob/v1.x/LICENSE.md (MIT License)
 */
namespace aaphp\Injected;

use aaphp\Utilized\VarUtil;
use Interop\Container\ContainerInterface;
use InvalidArgumentException;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;

class Resolver
{
    const USE_CONTAINER_BY_TYPE = 0x1;
    const USE_CONTAINER_BY_NAME = 0x2;
    const USE_ARGS_BY_POSITION  = 0x4;
    const USE_ARGS_BY_NAME      = 0x8;

    /**
     * Verify that the contents of a variable can be called as a function
     * and get its callable name.
     *
     * @param mixed $callable A variable to be tested.
     *
     * @return string|false Function/method Name if $callable is callable,
     *     false otherwise. 
     */
    public static function getCallableName($callable)
    {
        // Don't use parameter 3 of is_callable() to get callable name
        // when the value to be tested is not yet confirmed to be callable.
        // Because if it was an object and it was not callable and did not
        // have __toString(), is_callable() will raise fatal error.
        if (is_callable($callable, true)) {
            is_callable($callable, true, $callableName);
            return $callableName;
        }
        return false;
    }

    /**
     * Get ReflectionFunctionAbstract of a variable if it was callable.
     *
     * @param mixed $callable A variable to be tested.
     *
     * @return \ReflectionFunction|\ReflectionMethod|false
     *     Reflection of a function if $callable is callable, false otherwise. 
     */
    public static function getReflectionCallable($callable)
    {
        if (is_callable($callable, true)) {
            try {
                if (is_object($callable)) {
                    return new ReflectionFunction($callable);
                }
                if (is_array($callable)) {
                    return new ReflectionMethod($callable[0], $callable[1]);
                }
                if (strpos($callable, '::') === false) {
                    return new ReflectionFunction($callable);
                }
                return new ReflectionMethod($callable);
            } catch (ReflectionException $exception) {
            }
        }
        return false;
    }

    /**
     * Get callable name from reflection.
     *
     * @param \ReflectionFunctionAbstract $reflector Reflection of a function.
     *
     * @return string Callable name of the reflection.
     */
    public static function getReflectionCallableName(
        ReflectionFunctionAbstract $reflector
    ) {
        $class = $reflector instanceof ReflectionFunction
            ? $reflector->getClosureScopeClass()
            : $reflector->getDeclaringClass();
        if (is_null($class)) {
            return $reflector->getName();
        }
        return $class->getName() . '::' . $reflector->getName();
    }

    private $container;
    private $flags;

    public function __construct(
        ContainerInterface $container = null,
        $flags = null
    ) {
        if (isset($container)) {
            $this->setContainer($container);
        }
        $this->setFlags(
            isset($flags)
                ? $flags
                : self::USE_CONTAINER_BY_TYPE | self::USE_ARGS_BY_POSITION | self::USE_ARGS_BY_NAME
        );
    }

    public function getContainer()
    {
        return $this->container;
    }

    public function setContainer(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function getFlags()
    {
        return $this->flags;
    }

    public function setFlags($value)
    {
        if (!is_int($value)) {
            throw new InvalidArgumentException(
                sprintf(
                    '%s() expects parameter 1 to be int, %s given',
                    __METHOD__,
                    VarUtil::getType($value)
                )
            );
        }
        $this->flags = $value;
    }

    /**
     * Resolve arguments for use with call_user_func_array().
     *
     * @param \ReflectionFunctionAbstract $reflector Reflection of function
     *     to be resolved.
     * @param array[] $map Array of arrays of values to be resolved by parameter
     *     type. Keys are whatever allowed in type hinting except for 'self'.
     * @param array $args Array of values to be resolved. Values with int keys
     *     will be used to resolved by parameter positions. Values with string
     *     keys will be used to resolved by parameter names.
     * @return array Indexed array of resolved arguments.
     * @throws \InvalidArgumentException when
     *     - $flags is not int and is not null.
     *     - Non-array value in $map is found.
     *     - A resolved value does not match parameter type.
     * @throws \RuntimeException when a parameter cannot be resolved.
     */
    public function resolve(
        ReflectionFunctionAbstract $reflector,
        array $map = [],
        array $args = [],
        $flags = null
    ) {
        // Static variable to hold references of values resolved to parameters
        // requiring references.
        static $refs;
        $refs = null;
        $container = $this->container;
        if ($flags === null) {
            $flags = $this->flags;
        } elseif (!is_int($flags)) {
            throw new InvalidArgumentException(
                sprintf(
                    '%s() expects parameter 4 to be int or null, %s given',
                    __METHOD__,
                    VarUtil::getType($flags)
                )
            );
        }
        // Turn flags into variables for fast access.
        $useContainerByType = ($flags & self::USE_CONTAINER_BY_TYPE) !== 0;
        $useContainerByName = ($flags & self::USE_CONTAINER_BY_NAME) !== 0;
        $useArgsByPosition = ($flags & self::USE_ARGS_BY_POSITION) !== 0;
        $useArgsByName = ($flags & self::USE_ARGS_BY_NAME) !== 0;
        $php5 = PHP_MAJOR_VERSION < 7;
        $resolved = [];
        foreach ($reflector->getParameters() as $position => $param) {
            $name = $param->getName();
            if ($php5) {
                // ReflectionParameter::getClass() will throw
                // ReflectionException if class/interface does not exist.
                // So we have to get type information from
                // ReflectionParameter::toString() to prevent exception.
                preg_match(
                    '/Parameter #\d+ \[ \<[^>]+\> ([^\s&$]+)/A',
                    (string)$param,
                    $matches
                );
                $type = isset($matches[1])
                    ? $matches[1]
                    : null;
            }
            // Use ReflectionParameter::getType() when running under PHP 7.
            elseif (($type = $param->getType()) !== null) {
                // $type is now ReflectionType object, convert it to string.
                $type = (string)$type;
            }
            // So at this point $type will be string or null.
            // Check if it is not null.
            if (isset($type)) {
                if (isset($map[$type])) {
                    $values = $map[$type];
                    if (($valueIndex = &$valueIndices[$type]) === null) {
                        if (!is_array($values)) {
                            throw new InvalidArgumentException(
                                sprintf(
                                    '%s() expects parameter 2 to be '
                                    . "array of arrays, %s given at key '%s'",
                                    __METHOD__,
                                    VarUtil::getType($values),
                                    $type
                                )
                            );
                        }
                        $valueIndex = 0;
                    }
                    if (isset($values[$valueIndex])) {
                        $value = $values[$valueIndex++];
                        goto RESOLVED;
                    }
                }
                if (isset($container)) {
                    if ($useContainerByType && $container->has($type)) {
                        $value = $container->get($type);
                        if (isset($value)) {
                            goto VALIDATE;
                        }
                    }
                    if ($useContainerByName && $container->has($name)) {
                        $value = $container->get($name);
                        if (isset($value)) {
                            goto VALIDATE;
                        }
                    }
                }
            }
            if ($useArgsByPosition && isset($args[$position])) {
                $value = $args[$position];
                goto VALIDATE;
            }
            if ($useArgsByName && isset($args[$name])) {
                $value = $args[$name];
                goto VALIDATE;
            }
            if ($param->isDefaultValueAvailable()) {
                $value = $param->getDefaultValue();
                goto RESOLVED;
            }
            if ($param->allowsNull()) {
                $value = null;
                goto RESOLVED;
            }
            if ($param->isOptional()) {
                break;
            }
            throw new RuntimeException(
                isset($type)
                    ? sprintf(
                        '%s() expects parameter %s to be %s '
                        . 'but there is none to provide',
                        self::getReflectionCallableName($reflector),
                        $position + 1,
                        $type
                    )
                    // This is unlikely to happen.
                    : sprintf(
                        'Could not resolve parameter %s of %s()',
                        $position + 1,
                        self::getReflectionCallableName($reflector)
                    )
            );

            VALIDATE:
            if (isset($type)) {
                if (is_null($tmp = VarUtil::setType($value, $type))) {
                    throw new InvalidArgumentException(
                        sprintf(
                            '%s() expects parameter %s to be %s, '
                            . '%s was resolved to be given',
                            self::getReflectionCallableName($reflector),
                            $position + 1,
                            $type,
                            VarUtil::getType($value)
                        )
                    );
                }
                $value = $tmp;
            }

            RESOLVED:
            if ($param->isPassedByReference()) {
                $refs[$position] = $value;
                $resolved[] = &$refs[$position];
            } else {
                $resolved[] = $value;
            }
        }
        return $resolved;
    }

    public function invoke(
        // Don't use callable type hint to avoid fatal error in PHP 5.
        $callable,
        array $map = [],
        array $args = [],
        $flags = null
    ) {
        // But validate and throw exception here.
        if (!is_callable($callable)) {
            $callableName = self::getCallableName($callable);
            throw new InvalidArgumentException(
                $callableName === false
                    ? sprintf(
                        '%s() expects parameter 1 to be callable, %s given',
                        __METHOD__,
                        self::getType($callable)
                    )
                    : sprintf(
                        "Call to protected/private method %s from context '%s'",
                        $callableName,
                        get_class($this)
                    )
            );
        }
        // Get reflection using the same logic in getReflectionCallable().
        if (is_object($callable)) {
            $reflector = new ReflectionFunction($callable);
        } elseif (is_array($callable)) {
            $reflector = new ReflectionMethod($callable[0], $callable[1]);
        } else {
            $reflector = strpos($callable, '::') === false
                ? new ReflectionFunction($callable)
                : new ReflectionMethod($callable);
        }
        // Call and return immediately if the function has no parameters.
        if ($reflector->getNumberOfParameters() === 0) {
            return $callable();
        }
        return call_user_func_array(
            $callable,
            $this->resolve($reflector, $map, $args, $flags)
        );
    }
}
