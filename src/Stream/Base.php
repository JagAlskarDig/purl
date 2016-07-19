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

namespace Purl\Stream;

use Purl\Http\Parser;
use Purl\Interfaces\IParser;
use Purl\Interfaces\IRequest;
use Purl\Interfaces\IResponse;

abstract class Base
{
    const READ_BUFSIZ = 65536;

    const STATUS_OPENING = 1;
    const STATUS_WAIT_SEND = 2;
    const STATUS_WAIT_RECEIVE = 3;
    const STATUS_CLOSED = 4;

    /**
     * @var int
     */
    protected $id;

    /**
     * @var callable
     */
    protected $callback;

    /**
     * @var int
     */
    protected $connTimeout;

    /**
     * @var int
     */
    protected $readTimeout;

    /**
     * @var int
     */
    protected $connTimer = 0;

    /**
     * @var int
     */
    protected $readTimer = 0;

    /**
     * @var string
     */
    protected $sendBuffer = '';

    /**
     * @var IParser
     */
    protected $parser;

    /**
     * @var resource
     */
    protected $resource;

    /**
     * @var int
     */
    protected $resourceId;

    /**
     * @var int
     */
    protected $status;

    /**
     * @var string
     */
    protected $ip;

    /**
     * @var int
     */
    protected $port;

    /**
     * @var array Request
     */
    protected $requests = array();

    /**
     * Stream constructor.
     * @param int $id
     * @param string $ip
     * @param int $port
     * @param array $timeouts connection timeout and read timeout
     */
    public function __construct($id, $ip, $port, array $timeouts)
    {
        $this->id = $id;
        list($this->connTimeout, $this->readTimeout) = $timeouts;

        $this->ip = $ip;
        $this->port = $port;

        $fp = $this->newClient();
        stream_set_blocking($fp, 0);

        $this->resource = $fp;
        $this->resourceId = (int)$fp;
        $this->status = self::STATUS_WAIT_SEND;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * return true indicate success
     * return null indicate continue
     * return false indicate an error occurred
     * @return bool|null
     */
    public function send()
    {
        $buffer = $this->sendBuffer;
        $len = fwrite($this->resource, $buffer);

        if (strlen($buffer) === $len) {
            $this->waitReceive();

            return true;
        }

        if (false === $len) {
            $this->close(true);

            return false;
        }

        $this->sendBuffer = substr($buffer, 0, $len);

        return null;
    }

    /**
     * return true indicate success
     * return null indicate continue
     * return false indicate stream closed
     * @return bool|null
     */
    public function read()
    {
        $first = true;
        $buffer = '';
        do {
            $ret = fread($this->resource, self::READ_BUFSIZ);
            if ('' === $ret || false === $ret) {
                $first && $this->close();
                break;
            }

            $first = false;
            $buffer .= $ret;
        } while (true);

        $ret = $this->parser->tryParse($buffer, $this->isClosed());

        if ($ret instanceof IResponse) {
            $this->status = self::STATUS_WAIT_SEND;
            call_user_func($this->callback, $this->id, $ret);

            return true;
        }

        if (false === $ret) {
            $this->close(true);
        }

        return $ret;
    }

    /**
     * @param bool $failed
     * @return bool
     */
    public function close($failed = false)
    {
        $ret = true;
        if (!$this->isClosed()) {
            $ret = fclose($this->resource);
            $this->resource = null;
            $this->sendBuffer = null;
            $this->requests = null;
            $this->parser = null;
            $this->status = self::STATUS_CLOSED;
        }

        if ($failed) {
            call_user_func($this->callback, $this->id, null);
        }

        return $ret;
    }

    /**
     * @param int $time
     * @param bool $isRead
     * @return int
     */
    public function addTimer($time, $isRead = true)
    {
        if ($isRead) {
            $this->readTimer += $time;

            return $this->readTimer;
        }

        $this->connTimer += $time;

        return $this->connTimer;
    }

    /**
     * @param IRequest $request
     * @param IParser $parser
     * @param callable $callback
     * @return bool
     */
    public function addRequest(IRequest $request, IParser $parser, $callback)
    {
        if ($this->isClosed()) {
            return false;
        }

        $this->requests[] = array($request, $parser, $callback);

        return true;
    }

    /**
     * @return bool
     */
    public function needSend()
    {
        if (self::STATUS_WAIT_SEND !== $this->status) {
            return false;
        }

        if ($this->sendBuffer) {
            return true;
        }

        if (null === $arr = array_shift($this->requests)) {
            return false;
        }

        /**
         * @var IRequest $request
         */
        list($request, $this->parser, $this->callback) = $arr;
        $this->sendBuffer = $request->getContent();

        return true;
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return callable
     */
    public function getCallback()
    {
        return $this->callback;
    }

    /**
     * @return resource
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * @return int
     */
    public function getResourceId()
    {
        return $this->resourceId;
    }

    /**
     * @return string
     */
    public function getIp()
    {
        return $this->ip;
    }

    /**
     * @return int
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * @return bool
     */
    public function isClosed()
    {
        return self::STATUS_CLOSED === $this->status;
    }

    /**
     * @return int
     */
    public function getStatus()
    {
        return $this->status;
    }

    protected function waitReceive()
    {
        $this->sendBuffer = '';
        $this->status = self::STATUS_WAIT_RECEIVE;
    }

    /**
     * @return resource
     */
    abstract protected function newClient();
}
