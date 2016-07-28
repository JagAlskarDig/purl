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

namespace Purl\Http;

use Exception;
use Purl\Common\Event;
use Purl\Common\Helper;
use Purl\Common\UrlInfo;
use Purl\Stream\Base as Stream;
use Purl\Stream\SSL;
use Purl\Stream\TCP;

class AsyncClient
{
    /**
     * @var Event
     */
    protected $event;

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
    protected $sentStreams = array();

    /**
     * Client constructor.
     * @param bool $verifyCert
     * @param int $connTimeout per request connection timeout. milliseconds
     * @param int $readTimeout per request read data timeout. milliseconds
     */
    public function __construct($verifyCert = false, $connTimeout = 30000, $readTimeout = 30000)
    {
        $this->event = new Event();
        $this->verifyCert = $verifyCert;
        $this->connTimeout = $connTimeout * 1000;
        $this->readTimeout = $readTimeout * 1000;
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
        $this->event->loop();
        if ($sentCallback) {
            call_user_func($sentCallback, $this->sentStreams);
        }
        $this->event->loop();
    }

    public function sendCallback($resource, $event, array $data)
    {
        /**
         * @var Stream $stream
         */
        list($eventId, $stream) = $data;
        if (Event::TIMEOUT === $event) {
            $stream->closeWithFail();

            return;
        }

        if (null === $ret = $stream->send()) {
            return;
        }

        if (true === $ret) {
            $this->sentStreams[] = $stream->getId();
            $this->event->onRead($resource, array($this, 'receiveCallback'), $stream, $this->readTimeout);
        }

        $this->event->remove($eventId);
    }

    public function receiveCallback($resource, $event, array $data)
    {
        /**
         * @var Stream $stream
         */
        list($eventId, $stream) = $data;
        if (Event::TIMEOUT === $event) {
            $stream->closeWithFail();

            return;
        }

        if (null === $ret = $stream->read()) {
            return;
        }

        $this->event->remove($eventId);
    }

    protected function add($method, $url, $callback, array $headers = null, array $data = null)
    {
        static $id = 0;

        ++$id;
        $info = new UrlInfo($url);
        $ip = Helper::host2ip($info->getHost());
        $parser = new Parser();

        if ('http' === $info->getScheme()) {
            $stream = new TCP($id, $ip, $info->getPort(), $parser);
        } elseif ('https' === $info->getScheme()) {
            $stream = new SSL($id, $info->getHost(), $ip, $info->getPort(), $parser, $this->connTimeout, $this->verifyCert);
        } else {
            throw new Exception('Unsupported url');
        }

        $stream->addRequest(new Request($method, $info, $headers, $data), $callback);
        $this->event->onWrite($stream->getResource(), array($this, 'sendCallback'), $stream, $this->connTimeout);

        return $id;
    }
}
