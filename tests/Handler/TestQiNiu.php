<?php

namespace Tests\Handler;

use Fize\Provider\Upload\Handler\QiNiu;
use PHPUnit\Framework\TestCase;

class TestQiNiu extends TestCase
{

    public function test__construct()
    {
        $file = new File(App::getRootPath() . '/think');
        $ext = $file->getExtension();
        var_dump($ext);
        self::assertEmpty($ext);
        $mime = $file->getMime();
        var_dump($mime);
        self::assertIsString($mime);
    }


    public function testUpload()
    {
        // 需要在HTTP环境下测试
    }

    public function testUploads()
    {
        // 需要在HTTP环境下测试
    }

    public function testUploadBase64()
    {
        $base64 = file_get_contents(dirname(__FILE__, 4) . '/temp/base64_jpg.txt');
        $uploader = new QiNiu();
        $result = $uploader->uploadBase64($base64);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadFile()
    {
        $uploader = new QiNiu();
        $result = $uploader->uploadFile(__FILE__);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadFromUrl()
    {
        $url = 'https://e.topthink.com/api/item/782/pic';
        $uploader = new QiNiu();
        $result = $uploader->uploadFromUrl($url);
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
        $uploader = new QiNiu();
        $result = $uploader->uploadLargeParts($parts);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadLargeInit()
    {
        $uploader = new QiNiu();
        $file_key = $uploader->uploadLargeInit('uploadLargePMP2.pdf');
        var_dump($file_key);
        self::assertIsString($file_key);
    }

    public function testUploadLargePart()
    {
        $file_key = 'uploadLargePMP2.pdf';

        $uploader = new QiNiu();
        $uploader->uploadLargePart($file_key, file_get_contents('E:\work\fuli\commons\code\commons-third\temp\PMBOK2.pdf.1.part'));
        $uploader->uploadLargePart($file_key, file_get_contents('E:\work\fuli\commons\code\commons-third\temp\PMBOK2.pdf.2.part'));

        self::assertTrue(true);
    }

    public function testUploadLargeComplete()
    {
        $file_key = 'uploadLargePMP2.pdf';
        $uploader = new QiNiu();
        $result = $uploader->uploadLargeComplete($file_key);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadLargeAbort()
    {
        $file_key = 'uploadLargePMP3.pdf';
        $uploader = new QiNiu();
        $uploader->uploadLargeAbort($file_key);

        self::assertTrue(true);
    }

    public function testGetPreviewUrl()
    {
        $url = 'https://fuli-dev-media.huaruiauto.cn/uploadLargePMP2.pdf';
        $uploader = new QiNiu();
        $url = $uploader->getPreviewUrl($url);
        var_dump($url);
        self::assertIsString($url);
    }
}
