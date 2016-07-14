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
    }

    public function __destruct()
    {
        $this->streams = null;
    }

    /**
     * @param string $url
     * @param callable $callback
     * @param array|null $headers
     * @return int
     */
    public function addGet($url, $callback, array $headers = null)
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
    public function addPost($url, $callback, array $data = null, array $headers = null)
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

        /**
         * @var Stream $stream
         */
        $this->send($sentCallback);

        do {
            $r = array();
            foreach ($this->streams as $stream) {
                if ($stream->isClosed()) {
                    continue;
                }
                $r[] = $stream->getResource();
            }

            if (!$r = $this->queryTimeout($r, $this->connTimeout)) {
                break;
            }

            foreach ($r as $fp) {
                $stream = $this->streams[(int)$fp];
                $ret = $stream->read();

                if (null === $ret) {
                    continue;
                }

                unset($this->streams[$stream->getResourceId()]);

                if (false === $ret) {
                    continue;
                }

                $stream->close();
            }
        } while (true);
    }

    protected function add($method, $url, $callback, array $headers = null, array $data = null)
    {
        static $id = 0;

        $urlInfo = new UrlInfo($url);
        $https = 'https' === $urlInfo->getScheme();
        $timeouts = array($this->connTimeout, $this->readTimeout);
        $stream = new Stream(++$id, $urlInfo, $callback, $timeouts, $https, $this->verifyCert);
        $stream->addRequest(new Request($method, $urlInfo, $headers, $data));

        $this->streams[$stream->getResourceId()] = $stream;

        return $id;
    }

    protected function queryTimeout(array $streams, $timeout, $isRead = true)
    {
        if (!$streams) {
            return false;
        }

        /**
         * @var Stream $stream
         */
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
            $stream = $this->streams[(int)$fp];
            if ($stream->addTimer($timeSpent, $isRead) >= $timeout) {
                $stream->close(true);
                unset($this->streams[$stream->getResourceId()]);
            }
        }

        return $streams;
    }

    /**
     * @param callable $sentCallback
     */
    protected function send($sentCallback = null)
    {
        /**
         * @var Stream $stream
         */
        $sentStreams = array();

        do {
            $w = array();
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

                if (false === $ret) {
                    unset($this->streams[$stream->getResourceId()]);
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
