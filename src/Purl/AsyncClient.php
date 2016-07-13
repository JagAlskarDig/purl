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

class AsyncClient implements IClient
{
    /**
     * @var bool
     */
    protected $verifyCert;
    protected $streamFlag;
    protected $connTimeout;
    protected $readTimeout;

    protected $requests = array();
    protected $callbacks = array();

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
        $this->streamFlag = STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT;
    }

    /**
     * @param string $url
     * @param callable $callback
     * @param array|null $headers
     * @return int
     */
    public function addGet($url, callable $callback, array $headers = array())
    {
        return $this->add(0, Request::METHOD_GET, $url, $callback, $headers);
    }

    /**
     * @param string $url
     * @param callable $callback
     * @param array|null $data
     * @param array|null $headers
     * @return int
     */
    public function addPost($url, callable $callback, array $data = array(), array $headers = array())
    {
        return $this->add(0, Request::METHOD_POST, $url, $callback, $headers, $data);
    }


    /**
     * @param callable|null $sendComplete
     */
    public function request(callable $sendComplete = null)
    {
        $this->send($sendComplete);

        do {
            $r = array();
            foreach ($this->requests as $k => $v) {
                $r[] = $v->getStream();
            }

            if (!$r = $this->queryTimeout($r, $this->connTimeout)) {
                break;
            }

            foreach ($r as $fp) {
                $fd = (int)$fp;
                $request = $this->requests[$fd];
                $result = $request->read();

                if (null === $result) {
                    continue;
                }

                if ($result instanceof Result) {
                    call_user_func($this->callbacks[$request->getStreamId()], $request->getId(), $result);
                }
                $this->close($request);
            }
        } while (true);
    }

    /**
     * @return int
     */
    public function getConnTimeout()
    {
        return $this->connTimeout;
    }

    /**
     * @return boolean
     */
    public function isVerifyCert()
    {
        return $this->verifyCert;
    }

    /**
     * @return int
     */
    public function getStreamFlag()
    {
        return $this->streamFlag;
    }

    protected function add($id, $method, $url, callable $callback, array $headers, array $data = array())
    {
        $request = new Request($this, $id, $method, $url, $headers, $data);
        $this->requests[$request->getStreamId()] = $request;
        $this->callbacks[$request->getStreamId()] = $callback;

        return $request->getId();
    }

    protected function close(Request $request, $success = true)
    {
        $ret = $request->close();
        $streamId = $request->getStreamId();
        if (!$success) {
            call_user_func($this->callbacks[$streamId], $request->getId(), null);
        }

        unset($this->requests[$streamId], $this->callbacks[$streamId]);

        return $ret;
    }

    protected function queryTimeout(array $streams, $timeout, $isRead = true)
    {
        if (!$streams) {
            return false;
        }

        $originStreams = $streams;
        $timeStart = microtime(true);
        if ($isRead) {
            stream_select($streams, $w = null, $e = null, 0, $timeout);
        } else {
            stream_select($r = null, $streams, $e = null, 0, $timeout);
        }
        $timeSpent = microtime(true) - $timeStart;
        $timeSpent = ceil($timeSpent * 1000000);

        foreach ($originStreams as $fp) {
            $fd = (int)$fp;
            $request = $this->requests[$fd];
            if ($request->addTimer($timeSpent, $isRead) >= $timeout) {
                $this->close($request, false);
            }
        }

        return $streams;
    }

    protected function send(callable $sendComplete = null)
    {
        $sentStreams = array();

        do {
            $w = array();
            foreach ($this->requests as $request) {
                if ($request->needSend()) {
                    $w[] = $request->getStream();
                }
            }

            if (!$w = $this->queryTimeout($w, $this->connTimeout, false)) {
                break;
            }

            foreach ($w as $fp) {
                $request = $this->requests[(int)$fp];
                if (true === $request->send()) {
                    $sentStreams[] = $request->getId();
                }
            }
        } while (true);

        if ($sendComplete) {
            $sendComplete($sentStreams);
        }
    }
}
