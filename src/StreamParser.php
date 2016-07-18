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

class StreamParser
{
    /**
     * @var string
     */
    protected $buffer = '';

    /**
     * @var int
     */
    protected $packageLength = 0;

    /**
     * @var bool
     */
    protected $headerParsed = false;

    /**
     * @var string
     */
    protected $version;

    /**
     * @var int
     */
    protected $code;

    /**
     * @var string
     */
    protected $msg;

    /**
     * @var array
     */
    protected $headers;

    /**
     * @var string
     */
    protected $body = '';

    /**
     * @var bool
     */
    protected $chuncked;

    /**
     * @var bool
     */
    protected $keepAlive;

    /**
     * @var bool
     */
    protected $parseComplete;

    /**
     * return an instance of Result indicate success
     * return null indicate continue
     * return false indicate an error occurred
     * @param $data
     * @param bool $isClosed
     * @return bool|null|Result
     */
    public function tryParse($data, $isClosed = false)
    {
        if ($this->parseComplete) {
            return false;
        }

        $this->buffer .= $data;
        if (!$this->headerParsed) {
            if ($isClosed) {
                return false;
            }

            $pos = strpos($this->buffer, "\r\n\r\n");
            if (0 === $pos) {
                return false;
            }

            if (false === $pos) {
                return null;
            }

            $this->parseHeader($data);

            if (!$this->chuncked) {
                $len = $this->getHeader('Content-Length');

                if (null !== $len) {
                    $this->packageLength = (int)$len;
                } elseif ($this->keepAlive) {
                    return false;
                }
            }
        }

        if (($this->keepAlive || $this->chuncked) && $isClosed) {
            return false;
        }

        if (!$this->chuncked) {
            if ($this->keepAlive) {
                if ($this->packageLength === strlen($this->buffer)) {
                    $this->body = $this->buffer;
                    return $this->resultFactory();
                }
            } elseif ($isClosed) {
                if (null === $this->packageLength || strlen($this->buffer) === $this->packageLength) {
                    $this->body = $this->buffer;
                    return $this->resultFactory();
                }

                return false;
            }

            return null;
        }

        do {
            if (false === strpos($this->buffer, "\r\n")) {
                return null;
            }

            list($size, $content) = explode("\r\n", $this->buffer, 2);
            if (0 === $size = hexdec($size)) {
                return $this->resultFactory();
            }

            if (strlen($content) < $size - 2) {
                return null;
            }

            $this->body .= substr($content, 0, $size);
            $this->buffer = substr($content, $size + 2);
        } while (true);

        return false;
    }

    /**
     * @return boolean
     */
    public function isKeepAlive()
    {
        return $this->keepAlive;
    }

    /**
     * @return Result
     */
    protected function resultFactory()
    {
        $this->parseComplete = true;

        return new Result($this->version, $this->code, $this->msg, $this->headers, $this->body);
    }

    protected function getHeader($key, $default = null)
    {
        return isset($this->headers[$key]) ? $this->headers[$key] : $default;
    }

    protected function parseHeader($data)
    {
        list($headerStr, $body) = explode("\r\n\r\n", $data, 2);

        $headersRaw = explode("\r\n", $headerStr);
        $responseLine = explode(' ', array_shift($headersRaw), 3);
        list($this->version, $this->code, $this->msg) = $responseLine;

        $headers = array();
        foreach ($headersRaw as $headerRaw) {
            list($key, $value) = explode(': ', $headerRaw, 2);
            $headers[$key] = $value;
        }

        $this->headers = $headers;
        $this->buffer = $body;
        $this->chuncked = 'chunked' === strtolower($this->getHeader('Transfer-Encoding'));
        $this->keepAlive = 'keep-alive' === strtolower($this->getHeader('Connection'));
        $this->headerParsed = true;
    }
}
