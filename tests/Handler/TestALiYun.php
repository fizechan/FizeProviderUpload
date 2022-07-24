<?php

namespace Tests\Handler;

use Fize\Provider\Upload\Handler\ALiYun;
use PHPUnit\Framework\TestCase;
use think\facade\App;
use think\facade\Config;
use think\Request;

class TestALiYun extends TestCase
{

    public function testUpload()
    {
        $request = new Request();
        $request->setUrl($uri);
        $request->setMethod($method);
        $request->setPathinfo($this->getPathinfo($uri));
        if (is_array($parameters)) {
            switch ($method) {
                case "GET":
                    $request->withGet($parameters);
                    break;
                case "PUT":
                case "DELETE":
                case "PATCH":
                    $request->withInput($parameters);
                    break;
                case "POST":
                    $request->withPost($parameters);
                    break;
            }
        } elseif (is_string($parameters)) {
            $request->withInput($parameters);
        }
        $request->withCookie($cookies);
        $request->withFiles($files);
        $request->withServer(array_replace($this->serverVariables, $server));

        $http = App::bind('request', $request)->http;
        $response = $http->run($request);
        $http->end($response);
    }

    public function testUploads()
    {
        // 需要在HTTP环境下测试
    }

    public function testUploadBase64()
    {
        $base64 = file_get_contents(App::getRootPath() . '/temp/base64_jpg.txt');
        $uploader = new ALiYun();
        $result = $uploader->uploadBase64($base64);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadFile()
    {
        $uploader = new ALiYun();
        $file_path = 'H:\todo\组合查询2.png';
        $data = $uploader->uploadFile($file_path);
        var_dump($data);
        self::assertIsArray($data);
    }

    public function testUploadFromUrl()
    {
        $url = 'https://e.topthink.com/api/item/782/pic';
        $uploader = new ALiYun();
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
        $parts[] = file_get_contents('H:\work\FuLi\commons\code\commons-third\temp\PMBOK.pdf.1.part');
        $parts[] = file_get_contents('H:\work\FuLi\commons\code\commons-third\temp\PMBOK.pdf.2.part');
        $parts[] = file_get_contents('H:\work\FuLi\commons\code\commons-third\temp\PMBOK.pdf.3.part');
        $parts[] = file_get_contents('H:\work\FuLi\commons\code\commons-third\temp\PMBOK.pdf.4.part');
        $uploader = new ALiYun();
        $result = $uploader->uploadLargeParts($parts, 'pdf');
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadLargeInit()
    {
        $uploader = new ALiYun();
        $file_key = $uploader->uploadLargeInit('uploadLargePMP22.pdf');
        var_dump($file_key);
        self::assertIsString($file_key);
    }

    public function testUploadLargePart()
    {
        $file_key = 'uploadLargePMP22.pdf';
        $uploader = new ALiYun();
        $uploader->uploadLargePart($file_key, file_get_contents('H:\work\FuLi\commons\code\commons-third\temp\PMBOK2.pdf.1.part'));
        $uploader->uploadLargePart($file_key, file_get_contents('H:\work\FuLi\commons\code\commons-third\temp\PMBOK2.pdf.2.part'));
        self::assertTrue(true);
    }

    public function testUploadLargeComplete()
    {
        $file_key = 'uploadLargePMP2.pdf';
        $uploader = new ALiYun();
        $result = $uploader->uploadLargeComplete($file_key);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadLargeAbort()
    {
        $file_key = 'uploadLargePMP2.pdf';
        $uploader = new ALiYun();
        $uploader->uploadLargeAbort($file_key);
        self::assertTrue(true);
    }

    public function testGetAuthorizedUrl()
    {
        $uploader = new ALiYun();
        $url = "https://grz-qcjr-dev.oss-cn-hangzhou.aliyuncs.com/202207/19/62d6222265303.jpg";
        $url2 = $uploader->getAuthorizedUrl($url, 3600 * 24 * 365 * 100);
        var_dump($url2);
        self::assertEquals($url2, $url);
    }
}
