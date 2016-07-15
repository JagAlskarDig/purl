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

class Stream
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
     * @var StreamParser
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
    protected $host;

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
     * @param UrlInfo $urlInfo
     * @param callable $callback
     * @param array $timeouts connection timeout and read timeout
     * @param bool $ssl
     * @param bool $verifyCert
     */
    public function __construct($id, UrlInfo $urlInfo, $callback, array $timeouts, $ssl = false, $verifyCert = false)
    {
        $this->id = $id;
        $this->callback = $callback;
        list($this->connTimeout, $this->readTimeout) = $timeouts;

        $this->host = $urlInfo->getHost();
        $this->port = $urlInfo->getPort();
        $this->ip = Helper::host2ip($this->host);
        Helper::assert($this->ip, 'Unable to init stream: Invalid host');

        $flag = STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT;
        if ($ssl) {
            $remote = 'tls://' . $this->ip . ':' . $this->port;

            $options = array(
                'ssl' => array(
                    'peer_name' => $this->host,
                    'disable_compression' => true,
                    'cafile' => __DIR__ . '/cacert.pem',
                    'verify_peer' => $verifyCert,
                    'verify_peer_name' => $verifyCert,
                    'allow_self_signed' => !$verifyCert,
                )
            );
            if (PHP_VERSION_ID < 50600) {
                $options['ssl']['CN_match'] = $this->host;
            }

            $context = stream_context_create($options);
        } else {
            $remote = 'tcp://' . $this->ip . ':' . $this->port;
            $context = stream_context_create();
        }

        $fp = stream_socket_client($remote, $errNo, $errStr, $this->connTimeout / 1000000, $flag, $context);
        Helper::assert(false !== $fp, 'Unable to init stream, code: ' . $errNo);

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

        if ($ret instanceof Result) {
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
     * @param Request $request
     * @return bool
     */
    public function addRequest(Request $request)
    {
        if ($this->isClosed()) {
            return false;
        }

        $this->requests[] = $request;

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

        /**
         * @var Request $request
         */
        if (null === $request = array_shift($this->requests)) {
            return false;
        }

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
    public function getHost()
    {
        return $this->host;
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
        $this->parser = new StreamParser();
    }
}
