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

class AsyncClient
{
    /**
     * @var bool
     */
    protected $verifyCert;

    /**
     * @var int
     */
    protected $streamFlag;

    /**
     * @var int
     */
    protected $connTimeout;

    /**
     * @var int
     */
    protected $readTimeout;

    /**
     * @var array
     */
    protected $streams = array();

    /**
     * @var array
     */
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
    public function addGet($url, $callback, array $headers = array())
    {
        return $this->add(Request::METHOD_GET, $url, $callback, $headers);
    }

    /**
     * @param string $url
     * @param callable $callback
     * @param array|null $data
     * @param array|null $headers
     * @return int
     */
    public function addPost($url, $callback, array $data = array(), array $headers = array())
    {
        return $this->add(Request::METHOD_POST, $url, $callback, $headers, $data);
    }

    /**
     * @param callable|null $sentCallback
     */
    public function request($sentCallback = null)
    {
        if (!$this->streams) {
            return;
        }

        $this->send($sentCallback);

        do {
            $r = array();
            /**
             * @var Stream $stream
             */
            foreach ($this->streams as $stream) {
                if (!$stream->isClosed()) {
                    $r[] = $stream->getResource();
                }
            }

            if (!$r = $this->queryTimeout($r, $this->connTimeout)) {
                break;
            }

            foreach ($r as $fp) {
                $stream = $this->streams[(int)$fp];
                $result = $stream->read();

                if ($stream->isClosed()) {
                    $this->close($stream, false);
                    continue;
                }

                if (false === $result) {
                    continue;
                }

                if ($result instanceof Result) {
                    call_user_func($this->callbacks[$stream->getResourceId()], $stream->getId(), $result);
                }
                $this->close($stream);
            }
        } while (true);
    }

    protected function add($method, $url, $callback, array $headers, array $data = null)
    {
        static $id = 0;

        $urlInfo = new UrlInfo($url);
        $https = 'https' === $urlInfo->getScheme();
        $stream = new Stream(++$id, $urlInfo->getHost(), $urlInfo->getPort(), $this->streamFlag, $this->connTimeout,
            $this->readTimeout, $https, $this->verifyCert);
        $stream->addRequest(new Request($method, $urlInfo, $headers, $data));

        $this->streams[$stream->getResourceId()] = $stream;
        $this->callbacks[$stream->getResourceId()] = $callback;

        return $id;
    }

    protected function close(Stream $stream, $success = true)
    {
        $stream->close();
        $streamId = $stream->getResourceId();
        if (!$success) {
            call_user_func($this->callbacks[$streamId], $stream->getId(), null);
        }

        unset($this->streams[$streamId], $this->callbacks[$streamId]);

        return true;
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
            /**
             * @var Stream $stream
             */
            $stream = $this->streams[(int)$fp];
            if ($stream->addTimer($timeSpent, $isRead) >= $timeout) {
                $this->close($stream, false);
            }
        }

        return $streams;
    }

    /**
     * @param callable $sentCallback
     */
    protected function send($sentCallback = null)
    {
        $sentStreams = array();

        do {
            $w = array();
            /**
             * @var Stream $stream
             */
            foreach ($this->streams as $stream) {
                if ($stream->needSend()) {
                    $w[] = $stream->getResource();
                }
            }

            if (!$w = $this->queryTimeout($w, $this->connTimeout, false)) {
                break;
            }

            foreach ($w as $fp) {
                $stream = $this->streams[(int)$fp];
                $ret = $stream->send();

                if ($stream->isClosed()) {
                    $this->close($stream, false);
                    continue;
                }

                if (true === $ret) {
                    $sentStreams[] = $stream->getId();
                }
            }
        } while (true);

        if ($sentCallback) {
            call_user_func($sentCallback, $sentStreams);
        }
    }
}
