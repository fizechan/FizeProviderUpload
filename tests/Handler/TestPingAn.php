<?php

namespace Tests\Handler;

use Fize\Provider\Upload\Handler\PingAn;
use PHPUnit\Framework\TestCase;

class TestPingAn extends TestCase
{

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
        $cfg = [
            'key'      => 'MLINPHLB8WZGNWYGLLKV',
            'secret'   => 'xuBTOhuq842aRyhYqOEZiuVm3TC1ZiOARKFBckne',
            'endpoint' => 'obs.cn-east-3.myhuaweicloud.com',
            'bucket'   => 'rzzl-test-ns-obs',
            'domain'   => 'https://rzzl-test-ns-obs.obs.cn-east-3.myhuaweicloud.com',
        ];
        $uploader = new PingAn($cfg);
        $result = $uploader->uploadBase64($base64);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadFile()
    {
        $cfg = [
            'key'      => 'MLINPHLB8WZGNWYGLLKV',
            'secret'   => 'xuBTOhuq842aRyhYqOEZiuVm3TC1ZiOARKFBckne',
            'endpoint' => 'obs.cn-east-3.myhuaweicloud.com',
            'bucket'   => 'rzzl-test-ns-obs',
            'domain'   => 'https://rzzl-test-ns-obs.obs.cn-east-3.myhuaweicloud.com',
        ];
        $uploader = new PingAn($cfg);
        $result = $uploader->uploadFile(dirname(__FILE__, 4) . '/temp/test.jpg');
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadLargePart()
    {

    }

    public function testUploadLarge()
    {

    }

    public function testUploadLargeComplete()
    {

    }

    public function testGetPreviewUrl()
    {
        $url = 'https://rzzl-test-ns-obs.obs.cn-east-3.myhuaweicloud.com/202109/26/61504239b01b7.jpg';
        $cfg = [
            'key'      => 'MLINPHLB8WZGNWYGLLKV',
            'secret'   => 'xuBTOhuq842aRyhYqOEZiuVm3TC1ZiOARKFBckne',
            'endpoint' => 'obs.cn-east-3.myhuaweicloud.com',
            'bucket'   => 'rzzl-test-ns-obs',
            'domain'   => 'https://rzzl-test-ns-obs.obs.cn-east-3.myhuaweicloud.com',
        ];
        $uploader = new PingAn($cfg);
        $url = $uploader->getPreviewUrl($url);
        var_dump($url);
        self::assertIsString($url);
    }

    public function testUploadLargeParts()
    {

    }

    public function testUploadLargeInit()
    {

    }

    public function testUploadFromUrl()
    {

    }

    public function testUploadLargeAbort()
    {

    }
}
