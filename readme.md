# Purl 

A asynchronous http client without php-curl extension.

[![Build Status](https://travis-ci.org/pengzhile/purl.svg?branch=master)](https://travis-ci.org/pengzhile/purl)
[![Test Status](https://php-eye.com/badge/pengzhile/purl/tested.svg?branch=master)](https://travis-ci.org/pengzhile/purl)
[![Latest Stable Version](https://poser.pugx.org/pengzhile/purl/v/stable)](https://packagist.org/packages/pengzhile/purl)

## 特点
* 异步请求
* 支持批量请求
* 支持HTTP/HTTPS
* 无需PHP CURL扩展

```php
use purl\AsyncClient;
use purl\Result;

$requestIds = array();
$client = new AsyncClient();

// 添加一个请求,添加时并不会发出请求,需要调用 $client->request 
// 如果之后请求出错, result会为null
// 回调中会带之前返回的$requestId, 如果你共用回调,那么这个id可以让你知道是哪个请求返回了
$requestIds['163'] = $client->addGet('http://www.163.com/', function ($id, Result $result = null) {
    echo $id, ': ', substr($result->getBody(), 0, 50), PHP_EOL, PHP_EOL;
}, array('Accept' => 'text/html'));

$requestIds['sina'] = $client->addGet('http://www.sina.com.cn/', function ($id, Result $result = null) {
    echo $id, ': ', substr($result->getBody(), 0, 50), PHP_EOL, PHP_EOL;
}, array('Accept' => 'text/html'));

$requestIds['csdn'] = $client->addGet('http://blog.csdn.net/', function ($id, Result $result = null) {
    echo $id, ': ', substr($result->getBody(), 0, 50), PHP_EOL, PHP_EOL;
}, array('Accept' => 'text/html'));

$requestIds['sohu'] = $client->addGet('http://www.sohu.com/', function ($id, Result $result = null) {
    echo $id, ': ', substr($result->getBody(), 0, 50), PHP_EOL, PHP_EOL;
}, array('Accept' => 'text/html'));

$requestIds['qq'] = $client->addGet('http://www.qq.com/', function ($id, Result $result = null) {
    echo $id, ': ', substr($result->getBody(), 0, 50), PHP_EOL, PHP_EOL;
}, array('Accept' => 'text/html'));

echo 'request ids: ', print_r($requestIds, true), PHP_EOL;

$client->request(function (array $ids) {
    // 这个回调在所有请求已发出,但未返回时被调用,可以做点什么而不用等着返回。
    // 回调中参数为请求成功发送的requestId集合
    echo 'sent ids:', print_r($ids, true), PHP_EOL;
});

```
