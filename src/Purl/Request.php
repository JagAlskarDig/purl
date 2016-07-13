<?php
/**
 * This file is part of the Purl package.
 *
 * (c) pengzhile <pengzhile@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Purl;

use Exception;

class Request
{
    const USER_AGENT = 'Purl/1.0';
    const READ_BUFSIZ = 65536;
    const METHOD_POST = 'POST';
    const METHOD_GET = 'GET';

    protected static $dnsCache = array();
    protected static $lastId = 0;

    protected $client;
    protected $parser;
    protected $verifyCert;
    protected $streamFlag;
    protected $connTimeout;
    protected $sendBuffer = '';
    protected $connTimer = 0;
    protected $readTimer = 0;

    protected $id;
    protected $stream;
    protected $streamId;
    protected $isHttp;
    protected $isHttps;
    protected $scheme;
    protected $host;
    protected $ip;
    protected $port;
    protected $method;
    protected $uri;
    protected $url;
    protected $posts;
    protected $headers = array(
        'Cache-Control' => 'no-cache',
        'Pragma' => 'no-cache',
        'Accept' => '*/*',
        'User-Agent' => self::USER_AGENT,
    );

    /**
     * Request constructor.
     * @param IClient $client
     * @param int $id
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param array $posts
     */
    public function __construct(IClient $client, $id, $method, $url, array $headers, array $posts)
    {
        $this->client = $client;
        $this->parser = new StreamParser();
        $this->verifyCert = $client->isVerifyCert();
        $this->streamFlag = $client->getStreamFlag();
        $this->connTimeout = $client->getConnTimeout() / 1000000;

        if ($id = (int)$id) {
            $this->id = $id;
            if ($id > self::$lastId) {
                self::$lastId = $id;
            }
        } else {
            $this->id = ++self::$lastId;
        }

        $this->url = $url;
        $this->method = $method;
        $this->posts = $posts;

        if ($headers) {
            $this->headers = $headers + $this->headers;
        }

        $this->parseUrl();
        $this->initStream();
        $this->buildHttpRequest();
    }

    /**
     * @return bool
     */
    public function send()
    {
        $buffer = $this->sendBuffer;
        $len = fwrite($this->stream, $buffer);

        if (strlen($buffer) === $len) {
            $this->sendBuffer = '';

            return true;
        }

        if (false === $len) {
            $this->sendBuffer = '';

            return false;
        }

        $this->sendBuffer = substr($buffer, 0, $len);

        return false;
    }

    /**
     * @return bool
     */
    public function read()
    {
        $first = true;
        $buffer = '';
        do {
            $ret = fread($this->stream, self::READ_BUFSIZ);
            if ('' === $ret || false === $ret) {
                if ($first) {
                    return true;
                }
                break;
            }

            $buffer .= $ret;
            $first = false;
        } while (true);

        return $this->parser->tryParse($buffer);
    }

    /**
     * @return bool
     */
    public function close()
    {
        return fclose($this->stream);
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
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return resource
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * @return int
     */
    public function getStreamId()
    {
        return $this->streamId;
    }

    /**
     * @return bool
     */
    public function needSend()
    {
        return $this->sendBuffer ? true : false;
    }

    protected static function assert($cond, $message)
    {
        if ($cond) {
            return;
        }

        throw new Exception($message);
    }

    protected static function buildHeader(array $headers)
    {
        $header = '';
        $fixedHeaders = array('host', 'connection', 'content-length', 'content-type');
        foreach ($headers as $k => $v) {
            if (in_array(strtolower($k), $fixedHeaders)) {
                continue;
            }

            $header .= $k . ': ' . $v . "\r\n";
        }

        return $header;
    }

    protected function parseUrl()
    {
        $info = parse_url($this->url);
        self::assert(false !== $info, 'Invalid url');

        $scheme = strtolower($info['scheme']);
        $isHttp = 'http' === $scheme;
        $isHttps = 'https' === $scheme;
        self::assert($isHttp || $isHttps, 'Only support http/https');

        $host = strtolower($info['host']);
        if (isset(self::$dnsCache[$host])) {
            $ip = self::$dnsCache[$host];
        } else {
            $ip = gethostbyname($host);
            self::assert(false !== ip2long($ip), 'Invalid host');

            self::$dnsCache[$host] = $ip;
        }

        empty($info['port']) && $info['port'] = $isHttp ? 80 : 443;
        empty($info['path']) && $info['path'] = '/';
        empty($info['query']) && $info['query'] = '';

        $this->isHttp = $isHttp;
        $this->isHttps = $isHttps;
        $this->scheme = $scheme;
        $this->host = $host;
        $this->ip = $ip;
        $this->port = $info['port'];
        $this->uri = $info['path'] . $info['query'];
    }

    protected function initStream()
    {
        if ($this->isHttps) {
            $remote = 'tls://' . $this->ip . ':' . $this->port;
            $context = stream_context_create(array(
                'ssl' => array(
                    'peer_name' => $this->host,
                    'CN_match' => $this->host,
                    'disable_compression' => true,
                    'cafile' => __DIR__ . '/cacert.pem',
                    'verify_peer' => $this->verifyCert,
                    'verify_peer_name' => $this->verifyCert,
                    'allow_self_signed' => !$this->verifyCert,
                )
            ));
        } else {
            $remote = 'tcp://' . $this->ip . ':' . $this->port;
            $context = stream_context_create();
        }

        $fp = stream_socket_client($remote, $errNo, $errStr, $this->connTimeout, $this->streamFlag, $context);
        self::assert(false !== $fp, 'Unable to init stream, code: ' . $errNo);
        stream_set_blocking($fp, 0);

        $this->stream = $fp;
        $this->streamId = (int)$fp;
    }

    protected function buildHttpRequest()
    {
        $port = $this->isHttp && 80 === $this->port || 443 === $this->port ? '' : ':' . $this->port;
        $header = 'Host: ' . $this->host . $port . "\r\nConnection: keep-alive\r\n";

        if (self::METHOD_POST === $this->method) {
            $header .= 'Content-Type: application/x-www-form-urlencoded' . "\r\n";
        }

        if ($this->posts) {
            $data = http_build_query($this->posts);
            $header .= 'Content-Length: ' . strlen($data) . "\r\n";
        } else {
            $data = '';
        }

        $header .= self::buildHeader($this->headers);

        $this->sendBuffer = "{$this->method} {$this->uri} HTTP/1.1\r\n{$header}\r\n{$data}";
    }
}
