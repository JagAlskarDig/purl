<?php
/**
 * This file is part of the Purl package.
 *
 * (c) pengzhile <pengzhile@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace purl;

class Result
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
        $this->statusCode = $statusCode;
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
