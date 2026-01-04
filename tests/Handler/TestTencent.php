<?php

namespace Tests\Handler;

use Fize\Http\ServerRequest;
use Fize\Http\ServerRequestFactory;
use Fize\Http\UploadedFile;
use Fize\Provider\Upload\Handler\Tencent;
use PHPUnit\Framework\TestCase;

class TestTencent extends TestCase
{

    public function testUpload()
    {
        $request = new ServerRequest('POST', '//www.baidu.com/upload');
        $upfile1 = new UploadedFile(__FILE__, filesize(__FILE__), UPLOAD_ERR_OK);
        $upfile1->forTest();
        $request = $request->withUploadedFiles(['file1' => $upfile1]);
        ServerRequestFactory::setGlobals($request);
        $cfg = [
            'bucket' => 'fize-1253911393'
        ];
        $providerCfg = [
            'region' => 'ap-shanghai',
            'scheme' => 'https',
            'credentials'=> [
                'secretId'  => 'AKIDRp5PvPNWZYOGzeBkaLnDbIUDd7TsDWjn',
                'secretKey' => '31uhLsNitunaEj4HLG4lLl3A9yd7SgVK'
            ]
        ];
        $uploader = new Tencent($cfg, $providerCfg);
        $result = $uploader->upload('file1');
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploads()
    {
        $request = new ServerRequest('POST', '//www.baidu.com/upload');
        $upfile1 = new UploadedFile(__FILE__, filesize(__FILE__), UPLOAD_ERR_OK);
        $upfile1->forTest();
        $upfile2 = new UploadedFile(__FILE__, filesize(__FILE__), UPLOAD_ERR_OK);
        $upfile2->forTest();
        $request = $request->withUploadedFiles(['files1' => [$upfile1, $upfile2]]);
        ServerRequestFactory::setGlobals($request);
        $cfg = [
            'bucket' => 'fize-1253911393'
        ];
        $providerCfg = [
            'region' => 'ap-shanghai',
            'scheme' => 'https',
            'credentials'=> [
                'secretId'  => 'AKIDRp5PvPNWZYOGzeBkaLnDbIUDd7TsDWjn',
                'secretKey' => '31uhLsNitunaEj4HLG4lLl3A9yd7SgVK'
            ]
        ];
        $uploader = new Tencent($cfg, $providerCfg);
        $result = $uploader->uploads('files1');
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadFile()
    {
        $cfg = [
            'bucket' => 'fize-1253911393'
        ];
        $providerCfg = [
            'region' => 'ap-shanghai',
            'scheme' => 'https',
            'credentials'=> [
                'secretId'  => 'AKIDRp5PvPNWZYOGzeBkaLnDbIUDd7TsDWjn',
                'secretKey' => '31uhLsNitunaEj4HLG4lLl3A9yd7SgVK'
            ]
        ];
        $uploader = new Tencent($cfg, $providerCfg);
        $uploader->setReplace();
        $result = $uploader->uploadFile(__FILE__, basename(__FILE__));
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadBase64()
    {
        $cfg = [
            'bucket' => 'fize-1253911393'
        ];
        $providerCfg = [
            'region' => 'ap-shanghai',
            'scheme' => 'https',
            'credentials'=> [
                'secretId'  => 'AKIDRp5PvPNWZYOGzeBkaLnDbIUDd7TsDWjn',
                'secretKey' => '31uhLsNitunaEj4HLG4lLl3A9yd7SgVK'
            ]
        ];
        $uploader = new Tencent($cfg, $providerCfg);
        $base64 = file_get_contents(dirname(__FILE__, 3) . '/temp/base64_jpg.txt');
        $result = $uploader->uploadBase64($base64);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadRemote()
    {
        $cfg = [
            'bucket' => 'fize-1253911393'
        ];
        $providerCfg = [
            'region' => 'ap-shanghai',
            'scheme' => 'https',
            'credentials'=> [
                'secretId'  => 'AKIDRp5PvPNWZYOGzeBkaLnDbIUDd7TsDWjn',
                'secretKey' => '31uhLsNitunaEj4HLG4lLl3A9yd7SgVK'
            ]
        ];
        $uploader = new Tencent($cfg, $providerCfg);
        $url = 'https://doc.thinkphp.cn/lfs/55efb6ec3a68586bf4d3894849be6eeb456d80d29c1458984e636bb1d2e346dc';
        $result = $uploader->uploadRemote($url);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadLargeInit()
    {
        $cfg = [
            'domain'   => 'https://www.baidu.com',
            'rootPath' => dirname(__FILE__, 3) . '/temp',
            'saveDir'  => '/uploads/large',
            'tempDir'  => '/uploads/temp',
        ];
        $uploader = new Local($cfg);
        $uuid = $uploader->uploadLargeInit(3);
        var_dump($uuid);
        self::assertIsString($uuid);
    }

    public function testUploadLargePart()
    {
        $cfg = [
            'domain'   => 'https://www.baidu.com',
            'rootPath' => dirname(__FILE__, 3) . '/temp',
            'saveDir'  => '/uploads/large',
            'tempDir'  => '/uploads/temp',
        ];
        $uploader = new Local($cfg);
        $uuid = '6948e1e7023ce';
        $info = $uploader->uploadLargePart($uuid, file_get_contents('/Users/sjsj/Downloads/360zip_v1.0.4_split_files/chunk_2.part'), 1);
        var_dump($info);
        self::assertIsArray($info);
    }

    public function testUploadLargeComplete()
    {
        $cfg = [
            'domain'   => 'https://www.baidu.com',
            'rootPath' => dirname(__FILE__, 3) . '/temp',
            'saveDir'  => '/uploads/large',
            'tempDir'  => '/uploads/temp',
        ];
        $uploader = new Local($cfg);
        $uuid = '6948e1e7023ce';
        $info = $uploader->uploadLargeComplete($uuid, null, 'dmg');
        var_dump($info);
        self::assertIsArray($info);
    }

    public function testUploadLargeAbort()
    {
        $cfg = [
            'domain'   => 'https://www.baidu.com',
            'rootPath' => dirname(__FILE__, 3) . '/temp',
            'saveDir'  => '/uploads/large',
            'tempDir'  => '/uploads/temp',
        ];
        $uploader = new Local($cfg);
        $uuid = '68b809679c79b';
        $uploader->uploadLargeAbort($uuid);
        self::assertTrue(true);
    }

    public function testUploadLarge()
    {
        $file = '/Users/sjsj/Downloads/360zip_v1.0.4_split_files/chunk_3.part';
        $request = new ServerRequest('POST', '//www.baidu.com/upload');
        $upfile1 = new UploadedFile($file, filesize($file), UPLOAD_ERR_OK);
        $upfile1->forTest();
        $request = $request->withUploadedFiles(['file1' => $upfile1]);
        ServerRequestFactory::setGlobals($request);

        $cfg = [
            'domain'   => 'https://www.baidu.com',
            'rootPath' => dirname(__FILE__, 3) . '/temp',
            'saveDir'  => '/uploads/large',
            'tempDir'  => '/uploads/temp',
        ];
        $uploader = new Local($cfg);
        $info = $uploader->uploadLarge('file1', 2, 3, 'dmg');
        var_dump($info);
        self::assertIsArray($info);
    }

    public function testUploadParts()
    {
        $parts = [];
        $parts[] = file_get_contents('E:\work\fuli\commons\code\commons-third\temp\PMBOK.pdf.1.part');
        $parts[] = file_get_contents('E:\work\fuli\commons\code\commons-third\temp\PMBOK.pdf.2.part');
        $parts[] = file_get_contents('E:\work\fuli\commons\code\commons-third\temp\PMBOK.pdf.3.part');
        $parts[] = file_get_contents('E:\work\fuli\commons\code\commons-third\temp\PMBOK.pdf.4.part');
        $uploader = new Local();
        $result = $uploader->uploadParts($parts);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testGetAuthorizedUrl()
    {
        $cfg = [
            'bucket' => 'fize-1253911393'
        ];
        $providerCfg = [
            'region' => 'ap-shanghai',
            'scheme' => 'https',
            'credentials'=> [
                'secretId'  => 'AKIDRp5PvPNWZYOGzeBkaLnDbIUDd7TsDWjn',
                'secretKey' => '31uhLsNitunaEj4HLG4lLl3A9yd7SgVK'
            ]
        ];
        $uploader = new Tencent($cfg, $providerCfg);
        $key = "202601/04/695a17dbc6134.php";
        $url = $uploader->getAuthorizedUrl($key);
        var_dump($url);
        self::assertIsString($url);
    }
}
