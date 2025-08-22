<?php

namespace Fize\Provider\Upload\Handler;

use Fize\Exception\FileException;
use Fize\Http\UploadedFile;
use Fize\IO\File;
use Fize\IO\MIME;
use Fize\Web\Request;
use Fize\Provider\Upload\UploadAbstract;
use Fize\Provider\Upload\UploadHandler;


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
            'domain'   => null,                                   //上传时指定文件URL主机域名。为null表示直接获取当前域名
            'rootPath' => '.',                                    // 根目录
            'saveDir'  => '/uploads',                             //上传路径
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
        $this->tempDir = $tempDir;
    }

    /**
     * 单文件上传
     * @param string      $name    文件域表单名
     * @param string|null $key     文件路径标识
     * @return array 返回保存文件的相关信息
     */
    public function upload(string $name, ?string $key = null): array
    {
        $uploadFile = $this->getUploadedFile($name);
        return $this->handleUpload($uploadFile, $key);
    }

    /**
     * 多文件上传
     * @param string     $name    文件域表单名
     * @param array|null $keys    文件路径标识
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
     * @param string      $url     URL
     * @param string|null $key     文件路径标识
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
     * @param string|null $key       文件路径标识，不指定则自动生成。
     * @param int|null    $blobCount 分片总数量，建议指定该参数。
     * @return string 返回文件路径标识，该标识用于后续的分块上传。
     */
    public function uploadLargeInit(?string $key = null, ?int $blobCount = null): string
    {
        if (is_null($key)) {
            $sdir = $this->getSaveDir($type);
            $save_name = uniqid();
            $file_key = $sdir . '/' . $save_name;
        } else {  // 指定$file_key时如果有已存在的上传临时文件则删除作废以重新上传。
            $save_part_file = $this->cfg['dir'] . '/' . $file_key . '.tmp';
            if (is_file($save_part_file)) {
                unlink($save_part_file);
            }
        }
        return $file_key;
    }

    /**
     * 分块上传：上传块
     * @param string $file_key 文件路径标识
     * @param string $content  块内容
     */
    public function uploadLargePart(string $file_key, string $content)
    {
        [, $dir, $save_name] = $this->getPathInfo($file_key);
        $save_part_name = $save_name . '.tmp';
        $save_file = $this->cfg['dir'] . '/' . $dir . '/' . $save_part_name;
        $fso = new File($save_file, 'a');
        $result = $fso->fwrite($content);
        if (!$result) {
            throw new FileException($save_file, '上传失败');
        }
        unset($fso);
    }

    /**
     * 分块上传：完成上传
     * @param string      $file_key 文件路径标识
     * @param string|null $fname    原文件名
     * @param string|null $mimeType 指定Mime
     * @return array 返回保存文件的相关信息
     */
    public function uploadLargeComplete(string $file_key, string $fname = null, string $mimeType = null): array
    {
        [, $dir, $save_name, $save_file] = $this->getPathInfo($file_key);
        $save_part_name = $save_name . '.tmp';
        $fso = new File($this->cfg['dir'] . '/' . $dir . '/' . $save_part_name);
        $fso->rename($save_file);
        unset($fso);
        $extension = pathinfo($save_file, PATHINFO_EXTENSION);
        if (empty($extension)) {
            [$save_file, $file_key, $extension] = $this->handleNoExtensionFile($dir, $save_name, $file_key);
        }
        $save_file = realpath($save_file);
        return [
            'file_key' => $file_key,
            'fname'    => $fname,
            'mimeType' => $mimeType,
            'extend'   => [
                'save_file' => $save_file,
                'extension' => $extension
            ]
        ];
    }

    /**
     * 分块上传：终止上传
     * @param string $file_key 文件路径标识
     */
    public function uploadLargeAbort(string $file_key)
    {
        [, $dir, $save_name] = $this->getPathInfo($file_key);
        $save_part_name = $save_name . '.tmp';
        $save_part_file = $this->cfg['dir'] . '/' . $dir . '/' . $save_part_name;
        unlink($save_part_file);
    }

    /**
     * 大文件分片上传
     *
     * 参数 `$file_key`：当 $blob_index 为0时填 null 表示自动生成，不为 0 时必填。指定该参数后，参数 $type 无效。
     * 参数 `$extension`：不指定则根据MIME进行猜测
     * 参数 `$type`：如[image,flash,audio,video,media,file]，指定该参数后保存路径以该参数开始。
     * @param string      $name       文件域表单名
     * @param int         $blob_index 当前分片下标
     * @param int         $blob_count 分片总数量
     * @param string|null $file_key   文件路径标识
     * @param string|null $extension  后缀名
     * @param string|null $type       指定类型
     * @return array
     */
    public function uploadLarge(string $name, int $blob_index, int $blob_count, string $file_key = null, string $extension = null, string $type = null): array
    {
        if (empty($name)) {
            throw new FileException('', '请指定要上传的文件。');
        }
        $uploadFile = $this->getUploadedFile($name);
        if (empty($uploadFile)) {
            throw new FileException('', '没有找到要上传的文件。');
        }

        if ($blob_index != 0 && is_null($file_key)) {
            throw new FileException('', '请指定参数$file_key。');
        }

        [$file_key, $dir, $save_name, $save_file] = $this->getPathInfo($file_key, $type, $extension);

        if ($blob_index == 0) {  // @todo 由于上传无序不能用$blob_index来确定是否已初始化，应使用临时记录文件判断，如果记录文件不存在则表示未初始化。
            $this->uploadLargeInit($file_key, $type);
        }

        // 中间
        // file_key参数在上传过程中必须保持一致
        $this->uploadLargePart($file_key, $uploadFile->getStream()->getContents());
        if ($blob_index < $blob_count - 1) {
            return ['name' => $name, 'blob_index' => $blob_index, 'blob_count' => $blob_count, 'file_key' => $file_key, 'type' => $type];
        }

        // 结束
        $this->uploadLargeComplete($file_key);

        $fso = new File($save_file);
        $mime = $fso->getMime();
        unset($fso);

        // 处理没有后缀名的情况
        if (empty($extension)) {
            [$save_file, $file_key, $extension] = $this->handleNoExtensionFile($dir, $save_name, $file_key);
        }

        $path = $file_key;
        $full_path = $this->cfg['dir'] . '/' . $path;
        $domain = $this->cfg['domain'];
        $domain = $domain ?: Request::domain();
        $url = $domain . '/' . $full_path;

        [$imagewidth, $imageheight] = $this->imageResize($save_file, $extension);

        $data = [
            'url'       => $url,
            'path'      => $path,
            'extension' => $extension,
            'file_size' => filesize($save_file),
            'mime_type' => $mime,
            'sha1'      => hash_file('sha1', $save_file),
            'extend'    => [
                'image_width'  => $imagewidth,
                'image_height' => $imageheight,
                'full_path'    => $full_path
            ]  // 额外信息
        ];
        return $data;
    }

    /**
     * 上传多个分块并合并成文件
     *
     * 参数 `$extension`：不指定则根据URL、MIME进行猜测
     * 参数 `$type`：如[image,flash,audio,video,media,file]，指定该参数后保存路径以该参数开始。
     * 参数 `$file_key`：指定该参数后，参数 $type 无效
     * @param array       $parts     分块数组
     * @param string|null $extension 后缀名
     * @param string|null $type      指定类型
     * @param string|null $file_key  文件路径标识
     * @return array
     */
    public function uploadParts(array $parts, string $extension = null, string $type = null, string $file_key = null): array
    {
        [$file_key, $dir, $save_name, $save_file] = $this->getPathInfo($file_key, $type, $extension);

        $this->uploadLargeInit($file_key, $type);
        foreach ($parts as $part) {
            $this->uploadLargePart($file_key, $part);
        }
        $this->uploadLargeComplete($file_key);

        $fso = new File($save_file);
        $mime = $fso->getMime();
        unset($fso);

        // 处理没有后缀名的情况
        if (empty($extension)) {
            [$save_file, $file_key, $extension] = $this->handleNoExtensionFile($dir, $save_name, $file_key);
        }

        $path = $file_key;
        $full_path = $this->cfg['dir'] . '/' . $path;
        $domain = $this->cfg['domain'];
        $domain = $domain ?: Request::domain();
        $url = $domain . '/' . $full_path;

        [$imagewidth, $imageheight] = $this->imageResize($save_file, $extension);

        $data = [
            'url'       => $url,
            'path'      => $path,
            'extension' => $extension,
            'file_size' => filesize($save_file),
            'mime_type' => $mime,
            'sha1'      => hash_file('sha1', $save_file),
            'extend'    => [
                'image_width'  => $imagewidth,
                'image_height' => $imageheight,
                'full_path'    => $full_path
            ]  // 额外信息
        ];
        return $data;
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
        $tmpNmae = $uploadFile->getTmpName();

        [$key, $dir, $name, $targetPath] = $this->getPathInfo($key, $extension);
        if (is_file($targetPath)) {
            if ($this->replace) {
                unlink($targetPath);
            } else {
                throw new FileException($targetPath, '文件已存在！');
            }
        }
        $uploadFile->moveTo($targetPath);

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
            'sha1'      => hash_file('sha1', $targetPath),  // 文件SHA1

            'dir'       => $dir,         // 生成路径
            'full_path' => $targetPath,  // 本机完整路径
            'orig_name' => $origName,    // 原文件名
            'tmp_name'  => $tmpNmae,     // 上传临时文件路径

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
