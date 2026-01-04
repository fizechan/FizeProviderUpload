<?php

namespace Fize\Provider\Upload\Handler;

use Fize\Exception\FileException;
use Fize\Http\UploadedFile;
use Fize\IO\File;
use Fize\IO\MIME;
use Fize\Provider\Upload\UploadHandler;
use Fize\Provider\Upload\UploadHandlerAbstract;
use Qcloud\Cos\Client;
use RuntimeException;

/**
 * 腾讯云COS
 */
class Tencent extends UploadHandlerAbstract implements UploadHandler
{

    /**
     * @var Client COS对象
     */
    protected $cosClient;

    /**
     * 初始化
     * @param array       $cfg         配置
     * @param array       $providerCfg provider设置
     * @param string|null $tempDir     临时文件存放文件夹目录
     */
    public function __construct(array $cfg = [], array $providerCfg = [], string $tempDir = null)
    {
        if (empty($providerCfg['region'])) {
            throw new RuntimeException('providerCfg参数不能为空');
        }
        $defaultCfg = [
        ];
        $this->cfg = array_merge($defaultCfg, $cfg);
        $this->cosClient = new Client($providerCfg);

        if (is_null($tempDir)) {
            $tempDir = $this->cfg['tempDir'] ?? './temp';
        }
        $this->tempDirPath = $tempDir;
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

        $key = $key ?: $this->generateKey(null, $extension);
        $sha1 = hash_file('sha1', $filePath);
        $name = basename($key);
        $dir = dirname($key);

        $result = $this->cosClient->putObject([
            'Bucket' => $this->cfg['bucket'],
            'Key'    => $key,
            'Body'   => fopen($filePath, 'rb')
        ]);

        $data = [
            'key'       => $key,
            'name'      => $name,
            'size'      => $size,
            'mime'      => $mime,
            'extension' => $extension,
            'sha1'      => $sha1,

            'dir'       => $dir,
            'orig_name' => $origName,
            'orig_path' => $origPath,  // 原文件路径
            'extend'    => $result
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
        $save_file = $this->tempDirPath . DIRECTORY_SEPARATOR . $name;  // 因为会上传到COS，故放在临时文件夹中待后面上传后删除
        $fso = new File($save_file, 'w');
        $result = $fso->fwrite(base64_decode(str_replace($matches[1], '', $base64Centent)));
        $fso->clearstatcache();
        $size = $fso->getSize();
        $sha1 = hash_file('sha1', $save_file);

        if ($result === false) {
            throw new FileException('上传失败');
        }

        $key = $key ?: $this->generateKey($name, $extension);

        $result = $this->cosClient->putObject([
            'Bucket' => $this->cfg['bucket'],
            'Key'    => $key,
            'Body'   => fopen($save_file, 'rb')
        ]);

        $data = [
            'key'       => $key,
            'name'      => $name,
            'size'      => $size,
            'mime'      => $mime,
            'extension' => $extension,
            'sha1'      => $sha1,

            'extend'    => $result
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
        $save_file = $this->tempDirPath . DIRECTORY_SEPARATOR . $save_name;  // 因为会上传到COS，故放在临时文件夹中待后面上传后删除

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
        unlink($save_file);  // 已上传到COS，删除本地文件
        unset($data['orig_name']);
        $data['orig_url'] = $origUrl;
        return $data;
    }

    /**
     * 分块上传：初始化
     * @param int|null    $blobCount 分片总数量，建议指定该参数。
     * @param string|null $key       文件路径标识
     * @param string|null $uuid      唯一识别码，不指定则自动生成。
     * @return string 唯一识别码，用于后续的分块上传。
     */
    public function uploadLargeInit(?int $blobCount = null, ?string $key = null, ?string $uuid = null): string
    {
        if (is_null($uuid)) {
            $uuid = uniqid();
        }
        $info = $this->getPartUploadInfo($uuid);
        if ($info) {  // 存在旧的分片信息则删除重新初始化。
            if (isset($info['parts'])) {
                foreach ($info['parts'] as $part) {
                    if (is_file($part)) {
                        unlink($part);
                    }
                }
            }
            $this->deletPartUploadInfo($uuid);
        }
        $info = [
            'uuid'      => $uuid,
            'blobCount' => $blobCount,
            'parts'     => (object)[],
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
        self::assertHasKey($info, 'parts');
        if (is_null($blobIndex)) {
            $blobIndex = count($info['parts']);
        }
        $tempfileName = $uuid;
        if ($info['blobCount']) {
            $tempfileName .= '-' . $info['blobCount'];
        }
        $tempfileName .= '-' . $blobIndex;
        $tempfileName .= '.tmp';
        $tempFile = $this->tempDirPath . '/' . $tempfileName;
        $fso = new File($tempFile, 'wb');
        $result = $fso->fwrite($content);
        if (!$result) {
            throw new FileException($tempFile, '上传失败');
        }
        unset($fso);
        $info['parts']["BLOB-{$blobIndex}"] = $tempfileName;
        $this->savePartUploadInfo($uuid, $info);
        return $info;
    }

    /**
     * 分块上传：完成上传
     * @param string      $uuid      唯一识别码
     * @param string|null $key       文件路径标识
     * @param string|null $extension 后缀名，不指定则根据MIME进行猜测。
     * @return array 返回保存文件的相关信息
     */
    public function uploadLargeComplete(string $uuid, ?string $key = null, ?string $extension = null): array
    {
        $info = $this->getPartUploadInfo($uuid);
        self::assertHasKey($info, 'parts');
        if ($info['blobCount']) {
            if ($info['blobCount'] != count($info['parts'])) {
                throw new RuntimeException('分片未全部上传！');
            }
        }

        [$key, $name, $targetPath] = $this->getPathInfo(null, $extension);

        // 按序合并
        $fso = new File($targetPath, 'a+b');
        for ($i = 0; $i < count($info['parts']); $i++) {
            $tempFile = $this->tempDirPath . '/' . $info['parts']["BLOB-{$i}"];
            if (!is_file($tempFile)) {
                throw new RuntimeException('分片文件不存在！');
            }
            $fso->fwrite(file_get_contents($tempFile));
        }
        if (!$extension) {
            $extension = $fso->getExtension();
            if ($extension) {
                $key .= "." . $extension;
                $name .= "." . $extension;
                $targetPath .= "." . $extension;
                $fso->rename($name);
            }
        }
        $mime = $fso->getMime();
        unset($fso);

        $size = filesize($targetPath);
        $sha1 = hash_file('sha1', $targetPath);
        $path = str_replace(realpath($this->cfg['rootPath']), '', $targetPath);
        $url = $this->cfg['domain'] . $path;

        // 上传到腾讯云
        $result = $this->cosClient->Upload($this->cfg['bucket'], $key, fopen($targetPath, 'rb'));

        // 删除相关临时文件
        foreach ($info['parts'] as $tempfileName) {
            $tempFile = $this->tempDirPath . '/' . $tempfileName;
            unlink($tempFile);
        }
        $this->deletPartUploadInfo($uuid);
        unlink($targetPath);

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

            'full_path' => $targetPath,  // 本机完整路径
            'extend' => $result
        ];
    }

    /**
     * 分块上传：终止上传
     * @param string $uuid 唯一识别码
     */
    public function uploadLargeAbort(string $uuid)
    {
        $info = $this->getPartUploadInfo($uuid);
        self::assertHasKey($info, 'parts');
        // 删除相关临时文件
        foreach ($info['parts'] as $tempfileName) {
            $tempFile = $this->tempDirPath . '/' . $tempfileName;
            unlink($tempFile);
        }
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
     * @param string $key     文件路径标识
     * @param int    $expires 有效期(秒)，为0表示永久有效
     * @return string
     */
    public function getAuthorizedUrl(string $key, int $expires = 0): string
    {
        if ($expires == 0) {
            $expires = 100 * 365 * 24 * 3600;
        }
        $config = $this->cfg;
        $url = $this->cosClient->getObjectUrl($config['bucket'], $key, "+{$expires} seconds");
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
        $sha1 = hash_file('sha1', $tmpName);

        if (is_null($key)) {
            $key = $this->generateKey(null, $extension);
        }

        $result = $this->cosClient->putObject([
            'Bucket' => $this->cfg['bucket'],
            'Key'    => $key,
            'Body'   => fopen($tmpName, 'rb')
        ]);
        $name = basename($key);
        $dir = dirname($key);
        if (!$uploadFile->isForTest()) {
            unlink($tmpName);
        }
        $data = [
            'key'       => $key,                            // 文件路径标识
            'name'      => $name,                           // 保存文件名
            'size'      => $size,                           // 文件大小
            'mime'      => $mime,                           // MIME类型
            'extension' => $extension,                      // 后缀名
            'sha1'      => $sha1,                           // 文件SHA1

            'dir'       => $dir,         // 生成路径
            'orig_name' => $origName,    // 原文件名
            'tmp_name'  => $tmpName,     // 上传临时文件路径

            'extend'    => $result
        ];
        return $data;
    }

    /**
     * 获取路径相关信息
     * @param string|null $key       文件唯一标识
     * @param string|null $extension 后缀名
     * @return array [路径标识, 保存文件名, 保存路径]
     */
    protected function getPathInfo(string $key = null, string $extension = null): array
    {
        if (is_null($key)) {
            $sdir = $this->tempDirPath;
            if (empty($extension)) {
                $name = uniqid();
            } else {
                $name = uniqid() . '.' . $extension;
            }
            $key = $sdir . '/' . $name;
        } else {
            $dir = dirname($key);
            $name = basename($key);
        }
        $targetPath = $this->tempDirPath . '/' . $name;
        $targetPath = File::realpath($targetPath, false);
        return [$key, $name, $targetPath];
    }

}