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

class UrlInfo
{
    /**
     * @var array dumped from RFC1738
     */
    protected static $defaultPortMap = array(
        'ftp' => 21,
        'http' => 80,
        'https' => 443,
        'gopher' => 70,
        'nntp' => 119,
        'telnet' => 23,
        'wais' => 210,
        'prospero' => 1525,
    );

    /**
     * @var string
     */
    protected $url;

    /**
     * @var string
     */
    protected $scheme;

    /**
     * @var string
     */
    protected $host;

    /**
     * @var int
     */
    protected $port;

    /**
     * @var string
     */
    protected $path = '/';

    /**
     * @var string
     */
    protected $user = '';

    /**
     * @var string
     */
    protected $pass = '';

    /**
     * @var string
     */
    protected $query = '';

    /**
     * @var string
     */
    protected $fragment = '';

    /**
     * @param string $url
     * @return UrlInfo
     */
    public static function factory($url)
    {
        return new self($url);
    }

    /**
     * UrlInfo constructor.
     * @param string $url
     */
    public function __construct($url)
    {
        $this->setUrl($url);
    }

    /**
     * @param string $url
     */
    public function setUrl($url)
    {
        $info = parse_url($url);
        Helper::assert(false !== $info, 'Invalid url');

        $scheme = strtolower($info['scheme']);
        Helper::assert(isset(self::$defaultPortMap[$scheme]), 'Invalid scheme');

        $this->url = $url;
        $this->scheme = $scheme;
        $this->host = strtolower($info['host']);
        $this->port = isset($info['port']) ? (int)$info['port'] : self::$defaultPortMap[$scheme];

        isset($info['user']) && $this->user = $info['user'];
        isset($info['pass']) && $this->pass = $info['pass'];
        isset($info['path']) && $this->path = $info['path'];
        isset($info['query']) && $this->query = $info['query'];
        isset($info['fragment']) && $this->fragment = $info['fragment'];
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @return string
     */
    public function getScheme()
    {
        return $this->scheme;
    }

    /**
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * @return int
     */
    public function getPort()
    {
        return $this->port;
    }

    /**
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * @return string
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @return string
     */
    public function getPass()
    {
        return $this->pass;
    }

    /**
     * @return string
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * @return string
     */
    public function getFragment()
    {
        return $this->fragment;
    }
}
