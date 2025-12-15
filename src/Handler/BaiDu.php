<?php

namespace Fize\Provider\Upload\Handler;

use BaiduBce\Auth\SignOptions;
use BaiduBce\Services\Bos\BosClient;
use BaiduBce\Services\Bos\BosOptions;
use DateTime;
use Fize\Exception\FileException;
use Fize\Http\UploadedFile;
use Fize\IO\File;
use Fize\IO\MIME;
use Fize\Provider\Upload\UploadAbstract;
use Fize\Provider\Upload\UploadHandler;
use RuntimeException;

/**
 * 百度智能云BOS
 */
class BaiDu extends UploadAbstract implements UploadHandler
{

    /**
     * @var BosClient BOS对象
     */
    protected $bosClient;

    /**
     * 初始化
     * @param array       $cfg         配置
     * @param array       $providerCfg provider设置
     * @param string|null $tempDir     临时文件存放文件夹目录
     */
    public function __construct(array $cfg = [], array $providerCfg = [], string $tempDir = null)
    {
        self::assertHasKey($cfg, 'config');
        self::assertHasKey($cfg, 'bucket');
        $bosCFG = $cfg['config'];
        self::assertHasKey($bosCFG, 'credentials');
        $credentials = $bosCFG['credentials'];
        self::assertHasKey($credentials, 'accessKeyId');
        self::assertHasKey($credentials, 'secretAccessKey');
        self::assertHasKey($bosCFG, 'endpoint');
        if (!isset($cfg['domain'])) {
            $cfg['domain'] = $bosCFG['endpoint'];
        }
        $this->cfg = $cfg;
        $this->initProviderCfg($providerCfg);

        $this->bosClient = new BosClient($bosCFG);
    }

    /**
     * 单文件上传
     * @param string      $name 文件域表单名
     * @param string|null $key  文件路径标识
     * @return array 返回保存文件的相关信息
     */
    public function upload(string $name, ?string $key = null): array
    {
        $uploadFile = $this->getUploadedFile($name);
        return $this->handleUpload($uploadFile, $key);
    }

    /**
     * 多文件上传
     * @param string     $name 文件域表单名
     * @param array|null $keys 文件路径标识
     * @return array 返回每个保存文件的相关信息组成的数组
     */
    public function uploads(string $name, ?array $keys = null): array
    {
        $uploadFiles = $this->getUploadedFiles($name);
        $infos = [];
        foreach ($uploadFiles as $index => $file) {
            $key = $keys[$index] ?? null;
            $infos[] = $this->handleUpload($file, $key);
        }
        return $infos;
    }

    /**
     * 上传本地文件
     * @param string      $filePath 服务器端文件路径
     * @param string|null $key      文件路径标识
     * @return array 返回保存文件的相关信息
     */
    public function uploadFile(string $filePath, ?string $key = null): array
    {
        $origName = basename($filePath);
        $origPath = realpath($filePath);
        $size = filesize($filePath);
        $orig_file = new File($filePath);
        $extension = strtolower($orig_file->getExtension());
        $mime = $orig_file->getMime();
        unset($orig_file);
        $this->checkExtension($extension);

        if (is_null($key)) {
            if ($extension) {
                $name = uniqid() . '.' . $extension;
            } else {
                $name = uniqid();
            }
            $sdir = $this->getSaveDir();
            $key = $sdir . '/' . $name;
        }
        $path = $key;
        $domain = $this->cfg['domain'];
        $url = $domain . '/' . $key;
        $sha1 = hash_file('sha1', $filePath);
        $name = basename($key);
        $dir = dirname($key);

        $this->bosClient->putObjectFromFile($this->cfg['bucket'], $key, $filePath);

        $data = [
            'key'       => $key,
            'name'      => $name,
            'path'      => $path,
            'url'       => $url,
            'size'      => $size,
            'mime'      => $mime,
            'extension' => $extension,
            'sha1'      => $sha1,

            'dir'       => $dir,
            'orig_name' => $origName,
            'orig_path' => $origPath,  // 原文件路径
        ];
        return $data;
    }

    /**
     * 上传base64串生成文件并保存
     * @param string      $base64Centent base64串
     * @param string|null $key           文件路径标识
     * @return array 返回保存文件的相关信息
     */
    public function uploadBase64(string $base64Centent, ?string $key = null): array
    {
        if (!preg_match('/^(data:\s*(\w+\/\w+);base64,)/', $base64Centent, $matches)) {
            throw new FileException('没有找到要上传的文件');
        }

        $mime = strtolower($matches[2]);
        $extension = MIME::getExtensionByMime($mime);
        if (empty($extension)) {
            throw new FileException('无法识别上传的文件后缀名');
        }

        $name = uniqid() . '.' . $extension;
        $save_file = $this->cfg['tempDir'] . DIRECTORY_SEPARATOR . $name;  // 因为会上传到BOS，故放在临时文件夹中待后面上传后删除
        $fso = new File($save_file, 'w');
        $result = $fso->fwrite(base64_decode(str_replace($matches[1], '', $base64Centent)));
        $fso->clearstatcache();
        $size = $fso->getSize();

        if ($result === false) {
            throw new FileException('上传失败');
        }

        if (is_null($key)) {
            $sdir = $this->getSaveDir();
            $key = $sdir . '/' . $name;
        }
        $path = $key;
        $domain = $this->cfg['domain'];
        $url = $domain . '/' . $key;

        $this->bosClient->putObjectFromFile($this->cfg['bucket'], $key, $save_file);
        $sha1 = hash_file('sha1', $save_file);
        $data = [
            'key'       => $key,
            'name'      => $name,
            'path'      => $path,
            'url'       => $url,
            'size'      => $size,
            'mime'      => $mime,
            'extension' => $extension,
            'sha1'      => $sha1,
        ];
        unlink($save_file);
        return $data;
    }

    /**
     * 上传远程文件
     * @param string      $url URL
     * @param string|null $key 文件路径标识
     * @return array 返回保存文件的相关信息
     */
    public function uploadRemote(string $url, ?string $key = null): array
    {
        $origUrl = $url;
        $extension = pathinfo($origUrl, PATHINFO_EXTENSION);
        if ($extension) {
            $save_name = uniqid() . '.' . $extension;
        } else {
            $save_name = uniqid();
        }
        $save_file = $this->cfg['tempDir'] . '/' . $save_name;  // 因为会上传到BOS，故放在临时文件夹中待后面上传后删除

        $content = file_get_contents($url);
        if ($content === false) {
            throw new FileException('获取远程文件时发生错误：');
        }

        $fso = new File($save_file, 'w');
        $result = $fso->fwrite($content);
        if ($result === false) {
            throw new FileException('上传失败');
        }
        $data = $this->uploadFile($save_file, $key);
        unlink($save_file);  // 已上传到BOS，删除本地文件
        unset($data['orig_name']);
        $data['orig_url'] = $origUrl;
        return $data;
    }

    /**
     * 分块上传：初始化
     * @param int|null    $blobCount 分片总数量，建议指定该参数。
     * @param string|null $uuid      唯一识别码，不指定则自动生成。
     * @return string 唯一识别码，用于后续的分块上传。
     */
    public function uploadLargeInit(?int $blobCount = null, ?string $uuid = null): string
    {
        if (is_null($file_key)) {
            $save_name = uniqid();
            $sdir = $this->getSaveDir($type);
            $file_key = $sdir . '/' . $save_name;
        }
        if (is_null($uuid)) {
            $uuid = uniqid();
        }
        $info = $this->getPartUploadInfo($uuid);
        if ($info) {  // 存在旧的分片信息则删除重新初始化。
            $this->bosClient->abortMultipartUpload($this->cfg['bucket'], $uuid, $info['uploadId']);
            $this->deletPartUploadInfo($uuid);
        }
        $options = [];
        $extension = pathinfo($file_key, PATHINFO_EXTENSION);
        if ($extension) {
            $file_mime = MIME::getMimeByExtension($extension);
            if ($file_mime) {
                $options[BosOptions::CONTENT_TYPE] = $file_mime;
            }
        }
        $uploadId = $this->bosClient->initiateMultipartUpload($this->cfg['bucket'], $uuid, $options);
        $info = [
            'uuid'      => $uuid,
            'blobCount' => $blobCount,
            'uploadId'  => $uploadId,
        ];
        $this->savePartUploadInfo($uuid, $info);
        return $uuid;
    }

    /**
     * 分块上传：上传块
     * @param string   $uuid      唯一识别码
     * @param string   $content   块内容
     * @param int|null $blobIndex 当前分片下标，建议指定该参数。
     * @return array 返回保存文件的相关信息
     */
    public function uploadLargePart(string $uuid, string $content, ?int $blobIndex = null): array
    {
        $info = $this->getPartUploadInfo($uuid);
        $this->assertHasKey($info, 'uploadId');
        if (isset($info['ETags'])) {
            $partNumber = (end($info['ETags']))['PartNumber'] + 1;
        } else {
            $partNumber = 1;
        }

        $tempFile = $this->cfg['tempDir'] . '/' . $info['tempName'];
        if ($partNumber == 1) {
            $org_size = 0;
        } else {
            $org_size = filesize($tempFile);
        }
        file_put_contents($tempFile, $content, FILE_APPEND);
        clearstatcache(true, $tempFile);
        $new_size = filesize($tempFile);

        $response = $this->bosClient->uploadPartFromFile($this->cfg['bucket'], $uuid, $info['uploadId'], $partNumber, $tempFile, $org_size, $new_size - $org_size);
        $eTag = $response->metadata['etag'];
        $blockStatus = ['eTag' => $eTag, 'partNumber' => $partNumber];
        $etags = $info['eTags'] ?? [];
        $etags[] = $blockStatus;
        $this->savePartUploadInfo($uuid, ['eTags' => $etags]);
        $info = $this->getPartUploadInfo($uuid);
        return $info;
    }

    /**
     * 分块上传：完成上传
     * @param string      $uuid      唯一识别码
     * @param string|null $extension 后缀名，不指定则根据MIME进行猜测。
     * @return array 返回保存文件的相关信息
     */
    public function uploadLargeComplete(string $uuid, ?string $extension = null): array
    {
        $info = $this->getPartUploadInfo($uuid);
        $this->assertHasKey($info, 'uploadId');
        $this->assertHasKey($info, 'ETags');

        $this->bosClient->completeMultipartUpload($this->cfg['bucket'], $uuid, $info['uploadId'], $info['ETags']);

        $temp_file = $this->cfg['tempDir'] . '/' . $info['tempName'];
        $key = $info['key'];
        $name = basename($temp_file);
        $tempFile = new File($temp_file);
        $extension = pathinfo($key, PATHINFO_EXTENSION);
        if (empty($extension)) {
            $extension = $tempFile->getExtension();
        }

        $path = $key;
        $domain = $this->cfg['domain'];
        $url = $domain . '/' . $key;

        $size = $tempFile->getSize();
        $mime = $tempFile->getMime();
        $sha1 = hash_file('sha1', $temp_file);

        unlink($temp_file);
        $this->deletPartUploadInfo($uuid);

        return [
            'key'       => $key,                            // 文件路径标识
            'name'      => $name,                           // 保存文件名
            'path'      => $path,                           // WEB路径
            'url'       => $url,                            // 完整URL
            'size'      => $size,                           // 文件大小
            'mime'      => $mime,                           // MIME类型
            'extension' => $extension,                      // 后缀名
            'sha1'      => $sha1,                           // 文件SHA1
            'uuid'      => $uuid,
        ];
    }

    /**
     * 分块上传：终止上传
     * @param string $uuid 唯一识别码
     */
    public function uploadLargeAbort(string $uuid)
    {
        $info = $this->getPartUploadInfo($uuid);
        $this->assertHasKey($info, 'uploadId');
        $this->bosClient->abortMultipartUpload($this->cfg['bucket'], $info['key'], $info['uploadId']);
        $temp_file = $this->cfg['tempDir'] . '/' . $info['tempName'];
        unlink($temp_file);
        $this->deletPartUploadInfo($uuid);
    }

    /**
     * 大文件分片上传
     * @param string      $name      文件域表单名
     * @param int         $blobIndex 当前分片下标。-1表示初始化，-2表示完成上传。
     * @param int|null    $blobCount 分片总数量，建议指定该参数。
     * @param string|null $extension 后缀名，不指定则根据MIME进行猜测。
     * @param string|null $uuid      唯一识别码，不指定则自动生成。
     * @return array
     */
    public function uploadLarge(string $name, int $blobIndex, ?int $blobCount = null, ?string $extension = null, ?string $uuid = null): array
    {
        // 特殊值-1：初始化
        if ($blobIndex == -1) {
            $uuid = $this->uploadLargeInit($blobCount, $uuid);
            $info = $this->getPartUploadInfo($uuid);
            return $info;
        }

        // 特殊值-2：完成上传
        if ($blobIndex == -2) {
            if (!$uuid) {
                throw new RuntimeException('请指定参数uuid。');
            }
            $info = $this->uploadLargeComplete($uuid, $extension);
            return $info;
        }

        // 初始化
        if (is_null($uuid)) {
            $uuid = session_id() . "_upload_" . $name;  // 会话端唯一！
        }
        $info = $this->getPartUploadInfo($uuid);
        if (!$info) {
            $this->uploadLargeInit($blobCount, $uuid);
        }

        // 上传块
        $uploadFile = $this->getUploadedFile($name);
        $content = $uploadFile->getStream()->getContents();
        $info = $this->uploadLargePart($uuid, $content, $blobIndex);
        if (is_null($info['blobCount']) || $info['blobCount'] > count($info['parts'])) {
            return $info;
        }

        // 完成上传
        $info = $this->uploadLargeComplete($uuid, $extension);
        return $info;
    }

    /**
     * 上传多个分块并合并成文件
     * @param array       $parts     分块数组
     * @param string|null $extension 后缀名，不指定则根据MIME进行猜测。
     * @param string|null $uuid      唯一识别码，不指定则自动生成。
     * @return array
     */
    public function uploadParts(array $parts, ?string $extension = null, ?string $uuid = null): array
    {
        $blobCount = count($parts);
        $uuid = $this->uploadLargeInit($blobCount, $uuid);
        foreach ($parts as $blobIndex => $content) {
            $this->uploadLargePart($uuid, $content, $blobIndex);
        }
        $info = $this->uploadLargeComplete($uuid, $extension);
        return $info;
    }

    /**
     * 返回已授权的URL
     * @param string $url     原URL
     * @param int    $expires 有效期(秒)，为0表示永久有效
     * @return string
     */
    public function getAuthorizedUrl(string $url, int $expires = 0): string
    {
        if (isset($this->cfg['private']) && $this->cfg['private']) {
            $key = parse_url($url, PHP_URL_PATH);
            $key = substr($key, 1);  // 删除第一个【/】
            $options = [
                BosOptions::SIGN_OPTIONS => [
                    SignOptions::TIMESTAMP             => new DateTime(),
                    SignOptions::EXPIRATION_IN_SECONDS => $expires,
                ]
            ];
            $url = $this->bosClient->generatePreSignedUrl($this->cfg['bucket'], $key, $options);
        }
        return $url;
    }

    /**
     * 处理上传文件
     * @param UploadedFile $uploadFile 已上传的文件
     * @param string|null  $key        文件路径标识
     * @return array 返回保存文件的相关信息
     */
    protected function handleUpload(UploadedFile $uploadFile, string $key = null): array
    {
        $origName = $uploadFile->getClientFilename();
        if (is_null($origName)) {
            throw new FileException('', '文件错误！');
        }
        $mime = $uploadFile->getClientMediaType();
        if (is_null($mime)) {
            throw new FileException($origName, '无法识别文件！');
        }
        $extension = MIME::getExtensionByMime($mime);
        if (empty($extension)) {
            throw new FileException($origName, '禁止上传无后缀名的文件');
        }
        $this->checkExtension($extension);

        $size = $uploadFile->getSize();
        $tmpName = $uploadFile->getTmpName();
        $this->bosClient->putObjectFromFile($this->cfg['bucket'], $key, $tmpName);
        $sha1 = hash_file('sha1', $tmpName);
        $name = basename($key);
        $dir = dirname($key);
        unlink($tmpName);
        $path = str_replace(realpath($this->cfg['rootPath']), '', $key);
        $url = $this->cfg['domain'] . $path;
        $data = [
            'key'       => $key,                            // 文件路径标识
            'name'      => $name,                           // 保存文件名
            'path'      => $path,                           // WEB路径
            'url'       => $url,                            // 完整URL
            'size'      => $size,                           // 文件大小
            'mime'      => $mime,                           // MIME类型
            'extension' => $extension,                      // 后缀名
            'sha1'      => $sha1,                           // 文件SHA1

            'dir'       => $dir,         // 生成路径
            'orig_name' => $origName,    // 原文件名
            'tmp_name'  => $tmpName,     // 上传临时文件路径
        ];
        return $data;
    }
}