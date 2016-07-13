<?php
/**
 * This file is part of the Purl package.
 *
 * (c) pengzhile <pengzhile@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Purl\Tests;

use PHPUnit_Framework_TestCase;
use Purl\AsyncClient;
use Purl\Result;

class RequestTest extends PHPUnit_Framework_TestCase
{
    protected $requestIds = array();
    protected $requestCalled = false;

    public function testNewClient()
    {
        $oldER = error_reporting(-1);
        
        $client = new AsyncClient();

        $this->requestIds[] = $client->addGet('http://blog.csdn.net/', array($this, 'requestCallback'),
            array('Accept' => 'text/html'));

        $client->request(array($this, 'sentCallback'));
        $this->assertTrue($this->requestCalled, 'request callback missed');
        
        error_reporting($oldER);
    }

    public function sentCallback(array $ids)
    {
        $this->assertCount(count($this->requestIds), $ids);
    }

    public function requestCallback($id, Result $result = null)
    {
        $this->assertTrue($id > 0);
        $this->assertNotNull($result, 'result is null');

        $this->requestCalled = true;
    }
}