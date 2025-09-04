<?php

namespace Tests\Handler;

use Fize\Http\ServerRequest;
use Fize\Http\ServerRequestFactory;
use Fize\Http\UploadedFile;
use Fize\Provider\Upload\Handler\Local;
use PHPUnit\Framework\TestCase;

class TestLocal extends TestCase
{

    public function testUpload()
    {
        $request = new ServerRequest('POST', '//www.baidu.com/upload');
        $upfile1 = new UploadedFile(__FILE__, filesize(__FILE__), UPLOAD_ERR_OK);
        $upfile1->forTest();
        $request = $request->withUploadedFiles(['file1' => $upfile1]);
        ServerRequestFactory::setGlobals($request);
        $cfg = [
            'rootPath' => dirname(__FILE__, 3) . '/temp',
        ];
        $uploader = new Local($cfg);
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
            'rootPath' => dirname(__FILE__, 3) . '/temp',
            'saveDir'  => '/uploads/file'
        ];
        $uploader = new Local($cfg);
        $result = $uploader->uploads('files1');
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadFile()
    {
        $cfg = [
            'rootPath' => dirname(__FILE__, 3) . '/temp',
            'domain'   => 'https://www.baidu.com',
        ];
        $uploader = new Local($cfg);
        $uploader->setReplace();
        $result = $uploader->uploadFile(__FILE__, basename(__FILE__));
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadBase64()
    {
        $cfg = [
            'domain'   => 'https://www.baidu.com',
            'rootPath' => dirname(__FILE__, 3) . '/temp',
            'saveDir'  => '/uploads/image'
        ];
        $uploader = new Local($cfg);
        $base64 = file_get_contents(dirname(__FILE__, 3) . '/temp/base64_jpg.txt');
        $result = $uploader->uploadBase64($base64);
        var_dump($result);
        self::assertIsArray($result);
    }

    public function testUploadRemote()
    {
        $cfg = [
            'domain'   => 'https://www.baidu.com',
            'rootPath' => dirname(__FILE__, 3) . '/temp',
        ];
        $uploader = new Local($cfg);
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
        $uuid = '68b8f41d6bf72';
        $info = $uploader->uploadLargePart($uuid, file_get_contents('/Users/sjsj/Downloads/360zip_v1.0.4_split_files/chunk_3.part'));
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
        $uuid = '68b8f41d6bf72';
        $info = $uploader->uploadLargeComplete($uuid, 'dmg');
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
        $request = new ServerRequest('POST', '//www.baidu.com/upload');
        ServerRequestFactory::setGlobals($request);
        $url = 'http://www.baidu.com';
        $uploader = new Local();
        $url = $uploader->getAuthorizedUrl($url);
        var_dump($url);
        self::assertIsString($url);
    }
}
