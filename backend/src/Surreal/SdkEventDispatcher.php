<?php

declare(strict_types=1);

namespace FjordPulse\Surreal;

use Psr\EventDispatcher\EventDispatcherInterface;

final class SdkEventDispatcher implements EventDispatcherInterface
{
    /** @var array<class-string, array<int, callable(object): void>> */
    private array $listeners = [];
    private int $nextId = 0;

    public function dispatch(object $event): object
    {
        foreach ($this->listeners as $class => $listeners) {
            if (!$event instanceof $class) {
                continue;
            }

            foreach ($listeners as $listener) {
                $listener($event);
            }
        }

        return $event;
    }

    /**
     * @param class-string $eventClass
     * @return \Closure(): void
     */
    public function subscribe(string $eventClass, callable $listener): \Closure
    {
        $id = $this->nextId++;
        $this->listeners[$eventClass][$id] = $listener;

        return function () use ($eventClass, $id): void {
            unset($this->listeners[$eventClass][$id]);
            if (($this->listeners[$eventClass] ?? []) === []) {
                unset($this->listeners[$eventClass]);
            }
        };
    }
}
