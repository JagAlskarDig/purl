<?php
/**
 * This file is part of the Purl package.
 * Copyright (C) 2016 pengzhile <pengzhile@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace Purl\Common;

class Event
{
    const TIMEOUT = 1;
    const READ = 2;
    const WRITE = 4;

    protected static $instance;

    /**
     * @var bool
     */
    protected $running = false;

    /**
     * @var int
     */
    protected $timer = 0;

    /**
     * @var array
     */
    protected $timeouts = array();

    /**
     * @var array
     */
    protected $events = array();

    /**
     * @var array
     */
    protected $resources = array();

    /**
     * @return static
     */
    public static function instance()
    {
        if (null === static::$instance) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * @param resource $resource
     * @param callable $callback
     * @param mixed $data
     * @param int|null $timeout
     * @return int
     */
    public function onRead($resource, $callback, $data = null, $timeout = null)
    {
        return $this->addEvent(self::READ, $resource, $callback, $data, $timeout);
    }

    /**
     * @param resource $resource
     * @param callable $callback
     * @param mixed $data
     * @param int|null $timeout
     * @return bool
     */
    public function onWrite($resource, $callback, $data = null, $timeout = null)
    {
        return $this->addEvent(self::WRITE, $resource, $callback, $data, $timeout);
    }

    /**
     * @param int $eventId
     */
    public function remove($eventId)
    {
        $event = $this->events[$eventId];
        unset($this->events[$eventId], $this->resources[$event['resourceId']][$event['type']]);
    }

    /**
     * @return bool
     */
    public function loopOnce()
    {
        $e = null;
        $r = $w = array();
        foreach ($this->events as $event) {
            if (self::READ === $event['type']) {
                $r[] = $event['resource'];
            } elseif (self::WRITE === $event['type']) {
                $w[] = $event['resource'];
            }
        }

        if (!$r && !$w) {
            return false;
        }

        $timer = $this->findTimer();
        $delta = microtime(true);
        $num = stream_select($r, $w, $e, null === $timer ? null : 0, $timer);
        if (false === $num) {
            return false;
        }

        $delta = microtime(true) - $delta;
        $this->expireTimers($delta, $r, $w);

        return true;
    }

    public function loop()
    {
        $this->running = true;

        while ($this->running && $this->loopOnce()) {
            ;
        }
    }

    public function stop()
    {
        $this->running = false;
    }

    /**
     * @param int $type
     * @param resource $resource
     * @param callable $callback
     * @param mixed $data
     * @param null|int $timeout
     * @return int
     */
    protected function addEvent($type, $resource, $callback, $data = null, $timeout = null)
    {
        static $id = 0;

        $resourceId = (int)$resource;
        $event = array(
            'id' => ++$id,
            'resource' => $resource,
            'resourceId' => $resourceId,
            'type' => $type,
            'callback' => $callback,
            'data' => array($id, $data),
            'timeout' => $timeout
        );

        $this->events[$id] = $event;
        $this->resources[$resourceId][$type] = $event;
        $this->addTimeout($event);

        return $id;
    }

    protected function findTimer()
    {
        if (!$this->timeouts) {
            $this->timer = 0;

            return null;
        }

        $timer = key($this->timeouts) - $this->timer;

        return $timer < 0 ? 0 : $timer;
    }

    protected function expireTimers($delta, array $reads, array $writes)
    {
        $this->timer += (int)($delta * 1000000);

        $timeouts = array();
        do {
            if (null === $key = key($this->timeouts)) {
                break;
            }

            if ($key > $this->timer) {
                break;
            }

            $timeouts += array_shift($this->timeouts);
        } while (true);

        $events = $this->getEvents($reads, self::READ) + $this->getEvents($writes, self::WRITE);
        foreach ($events as $event) {
            call_user_func($event['callback'], $event['resource'], $event['type'], $event['data']);

            if (isset($timeouts[$event['id']])) {
                unset($timeouts[$event['id']]);
                $this->addTimeout($event);
            }
        }

        foreach ($timeouts as $event) {
            if (isset($this->events[$event['id']])) {
                $this->remove($event['id']);
                call_user_func($event['callback'], $event['resource'], self::TIMEOUT, $event['data']);
            }
        }
    }

    protected function addTimeout(array $event)
    {
        if (null === $event['timeout']) {
            return false;
        }

        $this->timeouts[$this->timer + $event['timeout']][$event['id']] = $event;
        ksort($this->timeouts, SORT_NUMERIC);

        return true;
    }

    /**
     * @param array $resources
     * @param int $type
     * @return array
     */
    protected function getEvents(array $resources, $type)
    {
        $events = array();

        foreach ($resources as $resource) {
            $event = $this->resources[(int)$resource][$type];
            $events[$event['id']] = $event;
        }

        return $events;
    }
}
