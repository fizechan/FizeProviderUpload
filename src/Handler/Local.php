<?php

namespace Fize\Provider\Upload\Handler;

use Fize\Exception\FileException;
use Fize\Http\UploadedFile;
use Fize\IO\File;
use Fize\IO\MIME;
use Fize\Web\Request;
use Fize\Provider\Upload\UploadAbstract;
use Fize\Provider\Upload\UploadHandler;
use RuntimeException;


/**
 * 本地方式上传文件
 */
class Local extends UploadAbstract implements UploadHandler
{

    /**
     * 初始化
     * @param array       $cfg         配置
     * @param array       $providerCfg provider设置
     * @param string|null $tempDir     临时文件存放文件夹目录
     */
    public function __construct(array $cfg = [], array $providerCfg = [], string $tempDir = null)
    {
        $defaultCfg = [
            'domain'   => null,                                   // 上传时指定文件URL主机域名。为null表示直接获取当前域名
            'rootPath' => '.',                                    // 根目录
            'saveDir'  => '/uploads',                             // 上传路径
            'tempDir'  => '/temp',                                // 临时文件路径

            'multiple'                            => false,                                 // 是否支持批量上传
            "max_upload_amount_per_minute_logged" => 60,                                    // @todo 属于业务逻辑，考虑外移。
            "max_upload_amount_per_hour_unlogged" => 1800,                                  // @todo 属于业务逻辑，考虑外移。
        ];
        $this->cfg = array_merge($defaultCfg, $cfg);

        if (!$this->cfg['domain']) {
            $this->cfg['domain'] = Request::domain();
        }

        $defaultProviderCfg = [
            'image_max_size'  => 102400,                                // 最大图片文件大小，超过该大小才进行缩略
            'image_max_width' => 1000,                                  // 最大图片宽度，超过该宽度将进行缩放至宽度2048
        ];
        $this->providerCfg = array_merge($defaultProviderCfg, $providerCfg);

        if (is_null($tempDir)) {
            $tempDir = $this->cfg['tempDir'];
        }
        $this->tempDirPath = $this->cfg['rootPath'] . $tempDir;
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
        $orig_file = new File($filePath);
        $extension = strtolower($orig_file->getExtension());
        unset($orig_file);
        $this->checkExtension($extension);

        [$key, $dir, $name, $targetPath] = $this->getPathInfo($key, $extension);
        $size = filesize($filePath);

        if (is_file($targetPath)) {
            if ($this->replace) {
                unlink($targetPath);
            } else {
                throw new FileException($targetPath, '文件已存在！');
            }
        }
        $fso = new File($targetPath, 'w');
        $result = $fso->fwrite(file_get_contents($filePath));
        if (in_array($result, [0, false])) {
            throw new FileException($targetPath, '上传失败');
        }
        $mime = $fso->getMime();
        unset($fso);

        $sha1 = hash_file('sha1', $targetPath);
        $path = str_replace(realpath($this->cfg['rootPath']), '', $targetPath);
        $url = $this->cfg['domain'] . $path;

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
            'full_path' => $targetPath,
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
            throw new FileException('', '没有找到要上传的文件');
        }

        $mime = strtolower($matches[2]);
        $extension = MIME::getExtensionByMime($mime);
        if (empty($extension)) {
            throw new FileException('', '无法识别上传的文件后缀名');
        }
        $this->checkExtension($extension);

        [$key, $dir, $name, $targetPath] = $this->getPathInfo($key, $extension);

        if (is_file($targetPath)) {
            if ($this->replace) {
                unlink($targetPath);
            } else {
                throw new FileException($targetPath, '文件已存在！');
            }
        }
        $fso = new File($targetPath, 'w');
        $file_content = base64_decode(str_replace($matches[1], '', $base64Centent));
        $result = $fso->fwrite($file_content);
        if (in_array($result, [0, false])) {
            throw new FileException($targetPath, '上传失败');
        }
        $size = $fso->getSize();
        unset($fso);

        $sha1 = hash_file('sha1', $targetPath);
        $path = str_replace(realpath($this->cfg['rootPath']), '', $targetPath);
        $url = $this->cfg['domain'] . $path;

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
            'full_path' => $targetPath
        ];
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
        [$key, $dir, $name, $targetPath] = $this->getPathInfo($key, $extension);

        $content = file_get_contents($url);
        if ($content === false) {
            throw new FileException('', '获取远程文件时发生错误');
        }

        if (is_file($targetPath)) {
            if ($this->replace) {
                unlink($targetPath);
            } else {
                throw new FileException($targetPath, '文件已存在！');
            }
        }
        $fso = new File($targetPath, 'w');
        $result = $fso->fwrite($content);
        if (!$result) {
            throw new FileException($targetPath, '上传失败');
        }
        $mime = $fso->getMime();
        unset($fso);

        if (empty($extension)) {
            [$targetPath, $name, $extension, $key] = $this->handleNoExtensionFile($dir, $name, $key);
        }

        $path = str_replace(realpath($this->cfg['rootPath']), '', $targetPath);
        $url = $this->cfg['domain'] . $path;

        [$imagewidth, $imageheight] = $this->getImageSize($targetPath, $extension);  // 文件直传故不进行图片压缩

        $size = filesize($targetPath);
        $sha1 = hash_file('sha1', $targetPath);

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
            'full_path' => $targetPath,
            'orig_url'  => $origUrl,

            'extend' => [
                'image_width'  => $imagewidth,
                'image_height' => $imageheight,
            ]
        ];
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
     * @param string|null $extension 后缀名，不指定则根据MIME进行猜测。
     * @return array 返回保存文件的相关信息
     */
    public function uploadLargeComplete(string $uuid, ?string $extension = null): array
    {
        $info = $this->getPartUploadInfo($uuid);
        self::assertHasKey($info, 'parts');
        if ($info['blobCount']) {
            if ($info['blobCount'] != count($info['parts'])) {
                throw new RuntimeException('分片未全部上传！');
            }
        }

        [$key, $dir, $name, $targetPath] = $this->getPathInfo(null, $extension);

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

        // 删除相关临时文件
        foreach ($info['parts'] as $tempfileName) {
            $tempFile = $this->tempDirPath . '/' . $tempfileName;
            unlink($tempFile);
        }
        $this->deletPartUploadInfo($uuid);

        $size = filesize($targetPath);
        $sha1 = hash_file('sha1', $targetPath);
        $path = str_replace(realpath($this->cfg['rootPath']), '', $targetPath);
        $url = $this->cfg['domain'] . $path;
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

            'dir'       => $dir,         // 生成路径
            'full_path' => $targetPath,  // 本机完整路径
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
     * @todo 本机不需要这么麻烦，直接拼接。
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
     * 返回已授权的预览URL
     * @param string $url     原URL
     * @param int    $expires 有效期(秒)，为0表示永久有效
     * @return string
     */
    public function getAuthorizedUrl(string $url, int $expires = 0): string
    {
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

        [$key, $dir, $name, $targetPath] = $this->getPathInfo($key, $extension);
        if (is_file($targetPath)) {
            if ($this->replace) {
                unlink($targetPath);
            } else {
                throw new FileException($targetPath, '文件已存在！');
            }
        }
        $uploadFile->moveTo($targetPath);
        $sha1 = hash_file('sha1', $targetPath);

        [$imagewidth, $imageheight] = $this->imageResize($targetPath, $extension);

        $path = str_replace(realpath($this->cfg['rootPath']), '', $targetPath);
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
            'full_path' => $targetPath,  // 本机完整路径
            'orig_name' => $origName,    // 原文件名
            'tmp_name'  => $tmpName,     // 上传临时文件路径

            'extend' => [
                'image_width'  => $imagewidth,
                'image_height' => $imageheight,
            ]
        ];
        return $data;
    }

    /**
     * 获取路径相关信息
     * @param string|null $key       文件唯一标识
     * @param string|null $extension 后缀名
     * @return array [路径标识, 保存目录, 保存文件名, 保存路径]
     */
    protected function getPathInfo(string $key = null, string $extension = null): array
    {
        if (is_null($key)) {
            $sdir = $this->getSaveDir();
            $dir = $this->cfg['saveDir'] . '/' . $sdir;
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
        $targetPath = $this->cfg['rootPath'] . '/' . $dir . '/' . $name;
        $targetPath = File::realpath($targetPath, false);
        return [$key, $dir, $name, $targetPath];
    }

    /**
     * 处理上传文件没有后缀名的情况
     * @param string $dir  保存目录
     * @param string $name 保存文件名
     * @param string $key  文件路径标识
     * @return array [文件路径, 文件名, 后缀名]
     */
    protected function handleNoExtensionFile(string $dir, string $name, string $key): array
    {
        $targetPath = $this->cfg['rootPath'] . $dir . '/' . $name;
        $fso = new File($targetPath);
        $extension = $fso->getExtension();
        if ($extension) {
            $name = $name . '.' . $extension;
            $key = $key . '.' . $extension;
            $targetPath = $this->cfg['rootPath'] . $dir . '/' . $name;
            $result = $fso->rename($targetPath);  // 重命名为含后缀名文件
            if (!$result) {
                throw new FileException('保存文件时发生错误！');
            }
        }
        unset($fso);
        return [$targetPath, $name, $extension, $key];
    }
}
