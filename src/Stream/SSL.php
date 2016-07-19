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

use Purl\Common\Helper;

class SSL extends Base
{
    /**
     * @var string
     */
    protected $host;

    /**
     * @var bool
     */
    protected $verifyCert;

    /**
     * Stream constructor.
     * @param int $id
     * @param string $host
     * @param string $ip
     * @param int $port
     * @param array $timeouts connection timeout and read timeout
     * @param bool $verifyCert
     */
    public function __construct($id, $host, $ip, $port, array $timeouts, $verifyCert = false)
    {
        $this->host = $host;
        $this->verifyCert = $verifyCert;

        parent::__construct($id, $ip, $port, $timeouts);
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    protected function newClient()
    {
        $flag = STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT;
        $remote = 'tls://' . $this->ip . ':' . $this->port;

        $options = array(
            'ssl' => array(
                'peer_name' => $this->host,
                'disable_compression' => true,
                'cafile' => __DIR__ . '/cacert.pem',
                'verify_peer' => $this->verifyCert,
                'verify_peer_name' => $this->verifyCert,
                'allow_self_signed' => !$this->verifyCert,
            )
        );
        if (PHP_VERSION_ID < 50600) {
            $options['ssl']['CN_match'] = $this->host;
        }

        $context = stream_context_create($options);

        $fp = stream_socket_client($remote, $errNo, $errStr, $this->connTimeout / 1000000, $flag, $context);
        Helper::assert(false !== $fp, 'Unable to init stream, code: ' . $errNo);

        return $fp;
    }
}
