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
        $uuid = '68b80ef1a4a1c';
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
        $uuid = '68b80ef1a4a1c';
        $info = $uploader->uploadLargeComplete($uuid);
        var_dump($info);
        self::assertIsArray($info);
    }

    public function testUploadLargeAbort()
    {
        $file_key = 'uploadLargePMP2.pdf';
        $uploader = new Local();
        $uploader->uploadLargeAbort($file_key);

        self::assertTrue(true);
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

    public function testGetPreviewUrl()
    {
        $url = 'http://www.baidu.com';
        $uploader = new Local();
        $url = $uploader->getPreviewUrl($url);
        var_dump($url);
        self::assertIsString($url);
    }

    public function testSplitFileBySize()
    {
        // 使用示例
        $sourceFile = '/Users/sjsj/Downloads/360zip_v1.0.4.dmg'; // 替换为你的大文件路径
        $targetDir = '/Users/sjsj/Downloads/360zip_v1.0.4_split_files';          // 替换为分割文件存放目录
        $partSize = 2;                               // 每个分割文件5MB
        $prefix = 'chunk_';                          // 分割文件前缀
        if ($this->splitFileBySize($sourceFile, $targetDir, $partSize, $prefix)) {
            echo "文件分割成功！";
        } else {
            echo "文件分割失败。";
        }
        self::assertTrue(true);
    }

    /**
     * 按文件大小分割文件
     * @param string $sourceFile 源文件路径
     * @param string $targetDir  目标文件夹路径
     * @param int    $partSize   每个分割文件的大小，单位MB
     * @param string $prefix     分割文件的前缀名
     * @return bool 成功返回true，失败返回false
     */
    protected function splitFileBySize($sourceFile, $targetDir, $partSize, $prefix = 'part_')
    {
        // 检查源文件是否存在
        if (!is_file($sourceFile)) {
            trigger_error("Source file not found: " . $sourceFile, E_USER_WARNING);
            return false;
        }

        // 检查目标目录是否存在，不存在则尝试创建
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0777, true)) {
                trigger_error("Failed to create target directory: " . $targetDir, E_USER_WARNING);
                return false;
            }
        }

        // 计算文件总大小和需要分割的数量
        $fileSize = filesize($sourceFile);
        $partSizeBytes = $partSize * 1024 * 1024; // 将MB转换为字节
        $partCount = ceil($fileSize / $partSizeBytes);

        // 以二进制只读模式打开源文件
        $sourceHandle = fopen($sourceFile, 'rb');
        if (!$sourceHandle) {
            trigger_error("Failed to open source file: " . $sourceFile, E_USER_WARNING);
            return false;
        }

        // 循环读取并创建分割文件
        for ($i = 0; $i < $partCount; $i++) {
            // 生成目标文件路径
            $targetFile = $targetDir . '/' . $prefix . ($i + 1) . '.part';

            // 以二进制写入模式打开目标文件
            $targetHandle = fopen($targetFile, 'wb');
            if (!$targetHandle) {
                fclose($sourceHandle);
                trigger_error("Failed to create part file: " . $targetFile, E_USER_WARNING);
                return false;
            }

            // 计算本次需要读取的字节数（防止超出文件末尾）
            $bytesToRead = min($partSizeBytes, $fileSize - ($i * $partSizeBytes));

            // 从源文件读取指定大小的数据
            $buffer = fread($sourceHandle, $bytesToRead);
            if ($buffer === false) {
                fclose($sourceHandle);
                fclose($targetHandle);
                trigger_error("Failed to read from source file", E_USER_WARNING);
                return false;
            }

            // 将数据写入分割文件
            if (fwrite($targetHandle, $buffer) === false) {
                fclose($sourceHandle);
                fclose($targetHandle);
                trigger_error("Failed to write to part file: " . $targetFile, E_USER_WARNING);
                return false;
            }

            // 关闭当前分割文件的句柄
            fclose($targetHandle);
        }

        // 关闭源文件句柄
        fclose($sourceHandle);

        return true;
    }
}
