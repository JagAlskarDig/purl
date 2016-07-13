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

namespace Purl;

class AsyncClient implements IClient
{
    /**
     * @var bool
     */
    protected $verifyCert;
    protected $streamFlag;
    protected $connTimeout;
    protected $readTimeout;

    protected $requests = array();
    protected $callbacks = array();

    /**
     * Client constructor.
     * @param bool $verifyCert
     * @param int $connTimeout per request connection timeout. milliseconds
     * @param int $readTimeout per request read data timeout. milliseconds
     */
    public function __construct($verifyCert = false, $connTimeout = 30000, $readTimeout = 30000)
    {
        $this->verifyCert = $verifyCert;
        $this->connTimeout = $connTimeout * 1000;
        $this->readTimeout = $readTimeout * 1000;
        $this->streamFlag = STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT;
    }

    /**
     * @param string $url
     * @param callable $callback
     * @param array|null $headers
     * @return int
     */
    public function addGet($url, callable $callback, array $headers = array())
    {
        return $this->add(0, Request::METHOD_GET, $url, $callback, $headers);
    }

    /**
     * @param string $url
     * @param callable $callback
     * @param array|null $data
     * @param array|null $headers
     * @return int
     */
    public function addPost($url, callable $callback, array $data = array(), array $headers = array())
    {
        return $this->add(0, Request::METHOD_POST, $url, $callback, $headers, $data);
    }

    /**
     * @param callable|null $sentCallback
     */
    public function request(callable $sentCallback = null)
    {
        if (!$this->requests) {
            return;
        }

        $this->send($sentCallback);

        do {
            $r = array();
            foreach ($this->requests as $k => $v) {
                $r[] = $v->getStream();
            }

            if (!$r = $this->queryTimeout($r, $this->connTimeout)) {
                break;
            }

            foreach ($r as $fp) {
                $fd = (int)$fp;
                $request = $this->requests[$fd];
                $result = $request->read();

                if (null === $result) {
                    continue;
                }

                if ($result instanceof Result) {
                    call_user_func($this->callbacks[$request->getStreamId()], $request->getId(), $result);
                }
                $this->close($request);
            }
        } while (true);
    }

    /**
     * @return int
     */
    public function getConnTimeout()
    {
        return $this->connTimeout;
    }

    /**
     * @return boolean
     */
    public function isVerifyCert()
    {
        return $this->verifyCert;
    }

    /**
     * @return int
     */
    public function getStreamFlag()
    {
        return $this->streamFlag;
    }

    protected function add($id, $method, $url, callable $callback, array $headers, array $data = array())
    {
        $request = new Request($this, $id, $method, $url, $headers, $data);
        $this->requests[$request->getStreamId()] = $request;
        $this->callbacks[$request->getStreamId()] = $callback;

        return $request->getId();
    }

    protected function close(Request $request, $success = true)
    {
        $ret = $request->close();
        $streamId = $request->getStreamId();
        if (!$success) {
            call_user_func($this->callbacks[$streamId], $request->getId(), null);
        }

        unset($this->requests[$streamId], $this->callbacks[$streamId]);

        return $ret;
    }

    protected function queryTimeout(array $streams, $timeout, $isRead = true)
    {
        if (!$streams) {
            return false;
        }

        $h = $e = null;
        $originStreams = $streams;
        $timeStart = microtime(true);
        if ($isRead) {
            stream_select($streams, $h, $e, 0, $timeout);
        } else {
            stream_select($h, $streams, $e, 0, $timeout);
        }
        $timeSpent = microtime(true) - $timeStart;
        $timeSpent = ceil($timeSpent * 1000000);

        foreach ($originStreams as $fp) {
            $fd = (int)$fp;
            $request = $this->requests[$fd];
            if ($request->addTimer($timeSpent, $isRead) >= $timeout) {
                $this->close($request, false);
            }
        }

        return $streams;
    }

    protected function send(callable $sentCallback = null)
    {
        $sentStreams = array();

        do {
            $w = array();
            foreach ($this->requests as $request) {
                if ($request->needSend()) {
                    $w[] = $request->getStream();
                }
            }

            if (!$w = $this->queryTimeout($w, $this->connTimeout, false)) {
                break;
            }

            foreach ($w as $fp) {
                $request = $this->requests[(int)$fp];
                if (true === $request->send()) {
                    $sentStreams[] = $request->getId();
                }
            }
        } while (true);

        if ($sentCallback) {
            $sentCallback($sentStreams);
        }
    }
}
