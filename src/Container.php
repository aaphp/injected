<?php
/**
 * Injected - Simple but fast PHP parameter resolver, callable invoker, Interop container
 *
 * @link      https://github.com/aaphp/injected
 * @copyright Copyright (c) 2016 Kosit Supanyo
 * @license   https://github.com/aaphp/injected/blob/v1.x/LICENSE.md (MIT License)
 */
namespace aaphp\Injected;

use aaphp\Injected\Exception\ContainerException;
use aaphp\Injected\Exception\NotFoundException;
use aaphp\Injected\Exception\TypeMismatchException;
use aaphp\Utilized\VarUtil;
use ArrayAccess;
use ArrayIterator;
use Countable;
use Interop\Container\ContainerInterface;
use InvalidArgumentException;
use IteratorAggregate;
use ReflectionClass;
use ReflectionException;

class Container extends Resolver implements
    ArrayAccess,
    Countable,
    IteratorAggregate,
    ContainerInterface
{
    private $entries = [];
    private $aliases = [];
    private $args = [];
    private $settings;
    private $map;

    public function __construct(
        array $settings = [],
        ContainerInterface $delegate = null
    ) {
        parent::__construct($delegate ?: $this);
        $this->settings = $settings;
        $this->map = ['Interop\Container\ContainerInterface' => [$this]];
    }

    public function &offsetGet($offset)
    {
        return $this->settings[$offset];
    }

    public function offsetSet($offset, $value)
    {
        if ($offset === null) {
            $this->settings[] = $value;
        } else {
            $this->settings[$offset] = $value;
        }
    }

    public function offsetExists($offset)
    {
        return isset($this->settings[$offset]);
    }

    public function offsetUnset($offset)
    {
        unset($this->settings[$offset]);
    }

    public function count()
    {
        return count($this->settings);
    }

    public function getIterator()
    {
        return new ArrayIterator($this->settings);
    }

    public function get($id)
    {
        $realId = isset($this->aliases[$id])
            ? $this->aliases[$id]
            : $id;
        if (!isset($this->entries[$realId])) {
            throw new NotFoundException(
                sprintf("Entry '%s' not found", $id)
            );
        }
        $entry = &$this->entries[$realId];
        if (isset($entry['created'])) {
            return $entry['value'];
        }
        try {
            if (isset($entry['factory'])) {
                $value = $this->invoke(
                    $entry['factory'],
                    $this->map,
                    $this->args
                );
            } else {
                $reflector = new ReflectionClass($entry['class']);
                if (is_null($ctor = $reflector->getConstructor())) {
                    $value = $reflector->newInstance();
                } elseif ($ctor->getNumberOfParameters() === 0) {
                    $value = $reflector->newInstance();
                } else {
                    $value = $reflector->newInstanceWithoutConstructor();
                    $ctor->invokeArgs(
                        $value,
                        $this->resolve(
                            $ctor,
                            $this->map,
                            isset($entry['args'])
                                ? $entry['args'] + $this->args
                                : $this->args
                        )
                    );
                }
            }
        } catch (ReflectionException $exception) {
            throw new ContainerException(
                sprintf(
                    "Error while retrieving entry '%s': %s",
                    $id,
                    $exception->getMessage()
                ),
                $exception->getCode(),
                $exception
            );
        }
        if (   isset($entry['type'])
            && !VarUtil::matchType($value, $entry['type'])
        ) {
            throw new TypeMismatchException(
                sprintf(
                    "Value of entry '%s' is expected to be %s, %s got",
                    $id,
                    $entry['type'],
                    VarUtil::getType($value)
                )
            );
        }
        if (isset($entry['shared'])) {
            $entry['created'] = true;
            $entry['value'] = $value;
            if (isset($entry['type'])) {
                $this->map[$entry['type']][] = $value;
            }
            $this->args[$id] = $value;
            unset($entry['class'], $entry['args'], $entry['factory']);
        }
        return $value;
    }

    public function has($id)
    {
        if (isset($this->aliases[$id])) {
            $id = $this->aliases[$id];
        }
        return isset($this->entries[$id]);
    }

    public function __get($name)
    {
        return $this->get($name);
    }

    public function __set($name, $value)
    {
        $this->set($name, $value);
    }

    public function __isset($name)
    {
        return $this->has($name);
    }

    public function __unset($name)
    {
        $this->remove($name);
    }

    final public function set($id, $value, $type = null)
    {
        if (isset($this->entries[$id])) {
            $oldEntry = $this->entries[$id];
            $this->remove($id);
        }
        $entry = [
            'shared'  => true,
            'created' => true,
            'value'   => $value,
        ];
        if ($type === null && isset($oldEntry['type'])) {
            $type = $oldEntry['type'];
        }
        if (isset($type)) {
            if (!VarUtil::matchType($value, $type)) {
                throw new TypeMismatchException(
                    sprintf(
                        "Value of entry '%s' is expected to be %s, %s got",
                        $id,
                        $type,
                        VarUtil::getType($value)
                    )
                );
            }
            $this->aliases[$entry['type'] = $type] = $id;
            $this->map[$type][] = $value;
        }
        $this->args[$id] = $value;
        $this->entries[$id] = $entry;
    }

    final public function setArray(array $values)
    {
        foreach ($values as $id => $value) {
            $this->set($id, $value);
        }
    }

    final public function define($id, $def)
    {
        if (isset($this->entries[$id])) {
            $oldEntry = $this->entries[$id];
            $this->remove($id);
        }
        if (is_callable($def, true)) {
            $this->entries[$id] = [
                'shared'  => true,
                'locked'  => true,
                'factory' => $def,
            ];
            return;
        }
        if (!is_array($def)) {
            throw new InvalidArgumentException(
                sprintf(
                    '%s() expects parameter 2 to be array or callable, %s given',
                    __METHOD__,
                    VarUtil::getType($def)
                )
            );
        }
        if (isset($oldEntry)) {
            unset($oldEntry['created'], $oldEntry['value']);
            $def += $oldEntry;
        }
        if (isset($def['shared'])) {
            $entry['shared'] = true;
        }
        if (isset($def['locked'])) {
            $entry['locked'] = true;
        }
        if (isset($def['class'])) {
            $entry['class'] = $def['class'];
            if (isset($def['args'])) {
                $entry['args'] = $def['args'];
            }
        } elseif (isset($def['factory'])) {
            $entry['factory'] = $def['factory'];
        } else {
            throw new InvalidArgumentException(
                sprintf(
                    "Neither 'class' nor 'factory' is defined for entry '%s'",
                    $id
                )
            );
        }
        if (isset($def['type'])) {
            $this->aliases[$entry['type'] = $def['type']] = $id;
        }
        $this->entries[$id] = $entry;
    }

    final public function defineArray(array $defs)
    {
        foreach ($defs as $id => $def) {
            $this->define($id, $def);
        }
    }

    final public function remove($id)
    {
        $entry = &$this->entries[$id];
        if (isset($entry['locked'])) {
            throw new ContainerException(
                sprintf("Entry '%s' is locked and cannot be modified", $id)
            );
        }
        unset($this->entries[$id]);
        if (isset($entry['created'])) {
            if (isset($entry['type'])) {
                array_splice(
                    $this->map[$entry['type']],
                    array_search(
                        $entry['value'],
                        $this->map[$entry['type']],
                        true
                    ),
                    1
                );
            }
            unset($this->args[$id]);
        }
    }

    final public function removeArray(array $ids)
    {
        foreach ($ids as $id) {
            $this->remove($id);
        }
    }
}
