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

class Client
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
     * @var Response
     */
    protected $result;

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

    /**
     * @param string $url
     * @param callable $sentCallback
     * @param array|null $headers
     * @return Response|null
     */
    public function get($url, $sentCallback = null, array $headers = null)
    {
        $asyncClient = new AsyncClient($this->verifyCert, $this->connTimeout, $this->readTimeout);
        $asyncClient->addGet($url, array($this, 'sentCallback'), $headers);
        $asyncClient->request(function () use ($sentCallback) {
            $sentCallback && call_user_func($sentCallback);
        });

        return $this->result;
    }

    /**
     * @param string $url
     * @param callable $sentCallback
     * @param array|null $data
     * @param array|null $headers
     * @return Response|null
     */
    public function post($url, $sentCallback = null, array $data = null, array $headers = null)
    {
        $asyncClient = new AsyncClient($this->verifyCert, $this->connTimeout, $this->readTimeout);
        $asyncClient->addPost($url, array($this, 'sentCallback'), $headers, $data);
        $asyncClient->request(function () use ($sentCallback) {
            $sentCallback && call_user_func($sentCallback);
        });

        return $this->result;
    }

    public function sentCallback($id, Response $result = null)
    {
        $this->result = $result;
    }
}