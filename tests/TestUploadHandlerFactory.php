<?php

namespace Tests;

use Fize\Http\ServerRequest;
use Fize\Http\ServerRequestFactory;
use Fize\Provider\Upload\UploadHandler;
use Fize\Provider\Upload\UploadHandlerFactory;
use PHPUnit\Framework\TestCase;

class TestUploadHandlerFactory extends TestCase
{

    public function testSetInstance()
    {
        $request = new ServerRequest('POST', '//www.baidu.com/upload');
        ServerRequestFactory::setGlobals($request);
        UploadHandlerFactory::setInstance('Local', ['rootPath' => dirname(__FILE__, 2) . '/tmp']);
        self::assertInstanceOf(UploadHandler::class, UploadHandlerFactory::getInstance('Local'));
    }

    public function testGetInstance()
    {
        $request = new ServerRequest('POST', '//www.baidu.com/upload');
        ServerRequestFactory::setGlobals($request);
        UploadHandlerFactory::setInstance('Local', ['rootPath' => dirname(__FILE__, 2) . '/tmp']);
        self::assertInstanceOf(UploadHandler::class, UploadHandlerFactory::getInstance('Local'));
    }

    public function testCreate()
    {
        $request = new ServerRequest('POST', '//www.baidu.com/upload');
        ServerRequestFactory::setGlobals($request);
        $uploader = UploadHandlerFactory::create('Local', ['rootPath' => dirname(__FILE__, 2) . '/tmp']);
        self::assertInstanceOf(UploadHandler::class, $uploader);
    }
}
