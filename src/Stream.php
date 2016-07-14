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

    protected $id;

    protected $connTimeout;
    protected $readTimeout;
    protected $connTimer = 0;
    protected $readTimer = 0;
    protected $sendBuffer = '';

    /**
     * @var StreamParser
     */
    protected $parser;

    protected $resource;
    protected $resourceId;

    /**
     * @var int
     */
    protected $status;

    protected $host;
    protected $ip;
    protected $port;

    /**
     * @var array Request
     */
    protected $requests = array();

    /**
     * Stream constructor.
     * @param int $id
     * @param string $host
     * @param int $port
     * @param int $flag
     * @param int $connTimeout
     * @param int $readTimeout
     * @param bool $ssl
     * @param bool $verifyCert
     */
    public function __construct($id, $host, $port, $flag, $connTimeout, $readTimeout, $ssl = false, $verifyCert = false)
    {
        $this->id = $id;
        $this->connTimeout = $connTimeout;
        $this->readTimeout = $readTimeout;

        $this->host = $host;
        $this->port = $port;
        $this->ip = Helper::host2ip($host);
        Helper::assert($this->ip, 'Unable to init stream: Invalid host');

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

        $fp = stream_socket_client($remote, $errNo, $errStr, $connTimeout / 1000000, $flag, $context);
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
     * @return bool
     */
    public function send()
    {
        $buffer = $this->sendBuffer;
        $len = fwrite($this->resource, $buffer);

        if (strlen($buffer) === $len) {
            $this->waitReceive();

            return Helper::RET_SUCCESS;
        }

        if (false === $len) {
            $this->close();

            return Helper::RET_ERROR;
        }

        $this->sendBuffer = substr($buffer, 0, $len);

        return Helper::RET_CONTINUE;
    }

    /**
     * @return bool
     */
    public function read()
    {
        $buffer = fread($this->resource, self::READ_BUFSIZ);
        if ('' === $buffer || false === $buffer) {
            $this->close();

            return Helper::RET_SUCCESS;
        }

        do {
            $ret = fread($this->resource, self::READ_BUFSIZ);
            if ('' === $ret || false === $ret) {
                break;
            }

            $buffer .= $ret;
        } while (true);

        $ret = $this->parser->tryParse($buffer);

        if (Helper::RET_CONTINUE === $ret) {
        } elseif (Helper::RET_ERROR === $ret) {
            $this->close();
        } else {
            $this->status = self::STATUS_WAIT_SEND;
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
        $this->resource = null;
        $this->sendBuffer = null;
        $this->requests = null;
        $this->parser = null;
        $this->status = self::STATUS_CLOSED;

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
