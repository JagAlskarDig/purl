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

use Purl\Interfaces\IParser;
use Purl\Interfaces\IRequest;
use Purl\Interfaces\IResponse;

abstract class Base
{
    const READ_BUFSIZ = 65536;

    /**
     * @var int
     */
    protected $id;

    /**
     * @var callable
     */
    protected $callback;

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
     * @var bool
     */
    protected $closed;

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
     * @param IParser $parser
     */
    public function __construct($id, $ip, $port, IParser $parser)
    {
        $this->id = $id;
        $this->ip = $ip;
        $this->port = $port;
        $this->parser = $parser;

        $fp = $this->newClient();
        stream_set_blocking($fp, 0);

        $this->resource = $fp;
        $this->resourceId = (int)$fp;
        $this->closed = false;
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
        if (false === $buffer = $this->fetchSendBuffer()) {
            return false;
        }

        $len = fwrite($this->resource, $buffer);
        if (strlen($buffer) === $len) {
            $this->sendBuffer = '';

            return true;
        }

        if (false === $len) {
            $this->closeWithFail();

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
            call_user_func($this->callback, $this->id, $ret);

            return true;
        }

        if (false === $ret) {
            $this->closeWithFail();
        }

        return $ret;
    }

    /**
     * @return bool
     */
    public function close()
    {
        if ($this->isClosed()) {
            return false;
        }

        $ret = fclose($this->resource);
        $this->closed = true;
        $this->resource = null;
        $this->sendBuffer = null;
        $this->requests = null;
        $this->parser = null;

        return $ret;
    }

    /**
     * @return bool
     */
    public function closeWithFail()
    {
        $ret = $this->close();
        call_user_func($this->callback, $this->id, null);

        return $ret;
    }

    /**
     * @param IRequest $request
     * @param callable $callback
     * @return bool
     */
    public function addRequest(IRequest $request, $callback)
    {
        if ($this->isClosed()) {
            return false;
        }

        $this->requests[] = array($request, $callback);

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
        return $this->closed;
    }

    /**
     * @return mixed
     */
    protected function fetchSendBuffer()
    {
        do {
            if ($this->sendBuffer) {
                break;
            }

            if (null === $arr = array_shift($this->requests)) {
                return false;
            }

            /**
             * @var IRequest $request
             */
            list($request, $this->callback) = $arr;
            $this->sendBuffer = $request->getContent();
        } while (false);

        return $this->sendBuffer;
    }

    /**
     * @return resource
     */
    abstract protected function newClient();
}
