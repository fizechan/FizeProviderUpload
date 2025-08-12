<?php

namespace Tests\Handler;

use Fize\Http\ServerRequest;
use Fize\Http\ServerRequestFactory;
use Fize\Http\UploadedFile;
use Fize\Provider\Upload\Handler\Local;
use PHPUnit\Framework\TestCase;

class TestLocal extends TestCase
{

    public function test__construct()
    {

    }

    public function testUpload()
    {
        $srf = new ServerRequestFactory();
        $request = new ServerRequest('POST', '//upload');
        $upfile1 = new UploadedFile(__FILE__, filesize(__FILE__), UPLOAD_ERR_OK);
        $upfile1->forTest();
        $request = $request->withUploadedFiles(['file1' => $upfile1]);
        $srf->setGlobals($request);
        $uploader = new Local();
        $result = $uploader->upload('file1');
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploads()
    {
        // 需要在HTTP环境下测试
    }

    public function testUploadBase64()
    {
        $base64 = file_get_contents(dirname(__FILE__, 4) . '/temp/base64_jpg.txt');
        $uploader = new Local();
        $result = $uploader->uploadBase64($base64, 'image');
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadFile()
    {
        $uploader = new Local();
        $result = $uploader->uploadFile(__FILE__);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadRemote()
    {
        $url = 'https://e.topthink.com/api/item/782/pic';
        $uploader = new Local();
        $result = $uploader->uploadRemote($url);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadLarge()
    {
        // 需要在HTTP环境下测试
    }

    public function testUploadLargeParts()
    {
        $parts = [];
        $parts[] = file_get_contents('E:\work\fuli\commons\code\commons-third\temp\PMBOK.pdf.1.part');
        $parts[] = file_get_contents('E:\work\fuli\commons\code\commons-third\temp\PMBOK.pdf.2.part');
        $parts[] = file_get_contents('E:\work\fuli\commons\code\commons-third\temp\PMBOK.pdf.3.part');
        $parts[] = file_get_contents('E:\work\fuli\commons\code\commons-third\temp\PMBOK.pdf.4.part');
        $uploader = new Local();
        $result = $uploader->uploadLargeParts($parts);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadLargeInit()
    {
        $uploader = new Local();
        $file_key = $uploader->uploadLargeInit('uploadLargePMP2.pdf');
        var_dump($file_key);
        self::assertIsString($file_key);
    }

    public function testUploadLargePart()
    {
        $file_key = 'uploadLargePMP2.pdf';

        $uploader = new Local();
        $uploader->uploadLargePart($file_key, file_get_contents('E:\work\fuli\commons\code\commons-third\temp\PMBOK2.pdf.1.part'));
        $uploader->uploadLargePart($file_key, file_get_contents('E:\work\fuli\commons\code\commons-third\temp\PMBOK2.pdf.2.part'));

        self::assertTrue(true);
    }

    public function testUploadLargeComplete()
    {
        $file_key = 'uploadLargePMP2.pdf';
        $uploader = new Local();
        $result = $uploader->uploadLargeComplete($file_key);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadLargeAbort()
    {
        $file_key = 'uploadLargePMP2.pdf';
        $uploader = new Local();
        $uploader->uploadLargeAbort($file_key);

        self::assertTrue(true);
    }

    public function testGetPreviewUrl()
    {
        $url = 'http://www.baidu.com';
        $uploader = new Local();
        $url = $uploader->getPreviewUrl($url);
        var_dump($url);
        self::assertIsString($url);
    }
}
