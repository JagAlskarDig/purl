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

namespace Purl\Tests;

use PHPUnit_Framework_TestCase;
use Purl\Http\AsyncClient;
use Purl\Http\Client;
use Purl\Http\Response;

class RequestTest extends PHPUnit_Framework_TestCase
{
    protected $requestIds = array();
    protected $requestCalled = 0;

    public function testBatchClient()
    {
        $oldER = error_reporting(-1);

        $client = new AsyncClient();

        $this->requestIds[] = $client->addGet('http://blog.csdn.net/', array($this, 'requestCallback'),
            array('Accept' => 'text/html'));

        $this->requestIds[] = $client->addGet('https://github.com/', array($this, 'requestCallback'),
            array('Accept' => 'text/html'));

        $this->requestIds[] = $client->addGet('http://www.163.com', array($this, 'requestCallback'),
            array('Accept' => 'text/html'));

        $client->request(array($this, 'sentCallbackBatch'));
        self::assertCount($this->requestCalled, $this->requestIds, 'request callback missed');

        error_reporting($oldER);
    }

    public function sentCallbackBatch(array $ids)
    {
        self::assertCount(count($this->requestIds), $ids);
    }

    public function requestCallback($id, Response $result = null)
    {
        self::assertTrue($id > 0);
        self::assertNotNull($result, 'result is null');
        self::assertEquals(200, $result->getStatusCode());
        self::assertEquals('OK', $result->getStatusMsg());
        self::assertEquals('HTTP/1.1', $result->getHttpVersion());
        self::assertNotEmpty($result->getBody());
        echo $id, ' returned.', PHP_EOL;

        $this->requestCalled++;
    }

    public function testClient()
    {
        $oldER = error_reporting(-1);

        $client = new Client();

        $result = $client->get('http://blog.csdn.net', array($this, 'sentCallback'), array('Accept' => 'text/html'));

        self::assertNotNull($result, 'result is null');
        self::assertEquals(200, $result->getStatusCode());
        self::assertEquals('OK', $result->getStatusMsg());
        self::assertEquals('HTTP/1.1', $result->getHttpVersion());
        self::assertNotEmpty($result->getBody());

        error_reporting($oldER);
    }

    public function sentCallback()
    {
        // request sent
    }
}
