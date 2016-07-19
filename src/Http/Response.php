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

use Purl\Interfaces\IResponse;

class Response implements IResponse
{
    /**
     * @var string
     */
    protected $httpVersion;

    /**
     * @var int
     */
    protected $statusCode;

    /**
     * @var string
     */
    protected $statusMsg;

    /**
     * @var array
     */
    protected $headers = array();

    /**
     * @var string
     */
    protected $body;

    /**
     * Result constructor.
     * @param string $httpVersion
     * @param int $statusCode
     * @param string $statusMsg
     * @param array $headers
     * @param string $body
     */
    public function __construct($httpVersion, $statusCode, $statusMsg, array $headers, $body)
    {
        $this->httpVersion = $httpVersion;
        $this->statusCode = (int)$statusCode;
        $this->statusMsg = $statusMsg;
        $this->headers = $headers;
        $this->body = $body;
    }

    /**
     * @return string
     */
    public function getHttpVersion()
    {
        return $this->httpVersion;
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @return string
     */
    public function getStatusMsg()
    {
        return $this->statusMsg;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @param string $key
     * @param mixed $defaultValue
     * @return mixed
     */
    public function getHeader($key, $defaultValue = null)
    {
        return isset($this->headers[$key]) ? $this->headers[$key] : $defaultValue;
    }

    /**
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }
}
