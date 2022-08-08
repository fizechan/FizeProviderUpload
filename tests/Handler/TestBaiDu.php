<?php

namespace Tests\Handler;

use Fize\Provider\Upload\Handler\BaiDu;
use PHPUnit\Framework\TestCase;

class TestBaiDu extends TestCase
{

    protected $config;

    protected $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $config = [
            'config' => [
                'credentials' => [
                    'accessKeyId'     => '9dda0093f38843c0b63c93ba325cfe76',
                    'secretAccessKey' => 'fd30bae3905e493cbba732dbd1f4013c',
                ],
                'endpoint'    => 'http://bj.bcebos.com',
            ],
            'bucket' => 'grz-qcjr-dev',
            'domain' => 'https://grz-qcjr-dev.bj.bcebos.com',
        ];
        $this->config = $config;
        $this->tempDir = dirname(__FILE__, 3) . '/temp';
    }

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

    public function testUploadFile()
    {
        $uploader = new BaiDu($this->config, [], $this->tempDir);
        $file_path = 'H:\todo\组合查询2.png';
        $data = $uploader->uploadFile($file_path);
        var_dump($data);
        self::assertIsArray($data);
    }

    public function testUploadBase64()
    {
        $root = dirname(__FILE__, 3);
        $base64 = file_get_contents($root . '/temp/base64_jpg.txt');
        $uploader = new BaiDu($this->config, [], $this->tempDir);
        $result = $uploader->uploadBase64($base64);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadRemote()
    {
        $url = 'https://e.topthink.com/api/item/782/pic';
        $uploader = new BaiDu($this->config, [], $this->tempDir);
        $result = $uploader->uploadRemote($url);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadLargeInit()
    {
        $uploader = new BaiDu($this->config, [], $this->tempDir);
        $file_key = $uploader->uploadLargeInit('uploadLargePMP23.pdf');
        var_dump($file_key);
        self::assertIsString($file_key);
    }

    public function testUploadLargePart()
    {
        $file_key = 'uploadLargePMP21.pdf';
        $uploader = new BaiDu($this->config, [], $this->tempDir);
        $uploader->uploadLargePart($file_key, file_get_contents('H:\work\FuLi\commons\code\commons-third\temp\PMBOK2.pdf.1.part'));
        $uploader->uploadLargePart($file_key, file_get_contents('H:\work\FuLi\commons\code\commons-third\temp\PMBOK2.pdf.2.part'));
        self::assertTrue(true);
    }

    public function testUploadLargeComplete()
    {
        $file_key = 'uploadLargePMP21.pdf';
        $uploader = new BaiDu($this->config, [], $this->tempDir);
        $result = $uploader->uploadLargeComplete($file_key);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadLargeAbort()
    {
        $file_key = 'uploadLargePMP23.pdf';
        $uploader = new BaiDu($this->config, [], $this->tempDir);
        $uploader->uploadLargeAbort($file_key);
        self::assertTrue(true);
    }

    public function testUploadLarge()
    {
        // 需要在HTTP环境下测试
    }

    public function testUploadParts()
    {
        $parts = [];
        $parts[] = file_get_contents('H:\work\FuLi\commons\code\commons-third\temp\PMBOK.pdf.1.part');
        $parts[] = file_get_contents('H:\work\FuLi\commons\code\commons-third\temp\PMBOK.pdf.2.part');
        $parts[] = file_get_contents('H:\work\FuLi\commons\code\commons-third\temp\PMBOK.pdf.3.part');
        $parts[] = file_get_contents('H:\work\FuLi\commons\code\commons-third\temp\PMBOK.pdf.4.part');
        $uploader = new BaiDu($this->config, [], $this->tempDir);
        $result = $uploader->uploadParts($parts, 'pdf');
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testGetAuthorizedUrl()
    {
        $config = $this->config;
        $config['private'] = true;
        $uploader = new BaiDu($config, [], $this->tempDir);
        $url = 'https://grz-qcjr-dev.bj.bcebos.com/202208/08/62f079a621c2f.png';
        $url2 = $uploader->getAuthorizedUrl($url, 3600 * 24 * 365 * 100);
        var_dump($url2);
        self::assertNotEquals($url2, $url);
    }
}
