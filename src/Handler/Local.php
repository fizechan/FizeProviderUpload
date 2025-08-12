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
            'multiple'                            => false,                                 // 是否支持批量上传
            'domain'                              => null,                                  //上传时指定文件URL主机域名。为null表示直接获取当前domain
            'saveDir'                             => 'uploads',                             //上传路径
            'tempDir'                             => 'temp',                                // 临时文件路径
            "max_upload_amount_per_minute_logged" => 60,                                    // @todo 属于业务逻辑，考虑外移。
            "max_upload_amount_per_hour_unlogged" => 1800,                                  // @todo 属于业务逻辑，考虑外移。
        ];
        $this->cfg = array_merge($defaultCfg, $cfg);
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
     *
     * 参数 `$type`：如[image,flash,audio,video,media,file]，指定该参数后保存路径以该参数开始。
     * 参数 `$file_key`：指定该参数后，参数 $type 无效
     * @param string      $name     文件域表单名
     * @param string|null $type     指定类型
     * @param string|null $file_key 文件路径标识
     * @return array 返回保存文件的相关信息
     */
    public function upload(string $name, string $type = null, string $file_key = null): array
    {
        $uploadFile = $this->getUploadedFile($name);
        return $this->handleUpload($uploadFile, $type, $file_key);
    }

    /**
     * 多文件上传
     *
     * 参数 `$type`：如[image,flash,audio,video,media,file]，指定该参数后保存路径以该参数开始。
     * 参数 `$file_key`：指定该参数后，参数 $type 无效
     * @param string|null $name      文件域表单名
     * @param string|null $type      指定类型
     * @param array|null  $file_keys 文件路径标识
     * @return array 返回每个保存文件的相关信息组成的数组
     */
    public function uploads(string $name, ?string $type = null, ?array $file_keys = null): array
    {
        $uploadFiles = $this->getUploadedFiles($name);
        $infos = [];
        foreach ($uploadFiles as $index => $file) {
            $file_key = $file_keys[$index] ?? null;
            $infos[] = $this->handleUpload($file, $type, $file_key);
        }
        return $infos;
    }

    /**
     * 上传本地文件
     *
     * 参数 `$type`：如[image,flash,audio,video,media,file]，指定该参数后保存路径以该参数开始。
     * 参数 `$file_key`：指定该参数后，参数 $type 无效
     * @param string      $file_path 服务器端文件路径
     * @param string|null $type      指定类型
     * @param string|null $file_key  文件路径标识
     * @return array 返回保存文件的相关信息
     */
    public function uploadFile(string $file_path, string $type = null, string $file_key = null): array
    {
        $orig_file = new File($file_path);
        $extension = strtolower($orig_file->getExtension());
        unset($orig_file);

        [$file_key, $dir, $save_name, $save_file] = $this->getPathInfo($file_key, $type, $extension);
        $fso = new File($save_file, 'w');
        $result = $fso->fwrite(file_get_contents($file_path));
        if (in_array($result, [0, false])) {
            throw new FileException('上传失败');
        }
        $mime = $fso->getMime();
        unset($fso);

        if (empty($extension)) {
            [$save_file, $file_key] = $this->handleNoExtensionFile($dir, $save_name, $file_key);
        }

        $path = $file_key;
        $full_path = $this->cfg['dir'] . '/' . $path;

        $domain = $this->cfg['domain'];
        $domain = $domain ?: Request::domain();
        $url = $domain . '/' . $full_path;

        [$imagewidth, $imageheight] = $this->getImageSize($save_file, $extension);  // 文件直传故不进行图片压缩

        $data = [
            'key'           => $file_key,
            'path'          => $path,       // WEB路径
            'full_path'     => $full_path,  // 本机路径
            'url'           => $url,
            'size'          => filesize($save_file),
            'mime'          => $mime,
            'extension'     => $extension,
            'sha1'          => hash_file('sha1', $save_file),
            'original_name' => basename($file_path),

            'image_width'  => $imagewidth,
            'image_height' => $imageheight,


            'extend' => [
                'original_name' => basename($file_path),
                'original_path' => realpath($file_path),
                'full_path'     => $full_path,
            ]
        ];
        return $data;
    }

    /**
     * 上传base64串生成文件并保存
     *
     * 参数 `$type`：如[image,flash,audio,video,media,file]，指定该参数后保存路径以该参数开始。
     * 参数 `$file_key`：指定该参数后，参数 $type 无效
     * @param string      $base64_centent base64串
     * @param string|null $type           指定类型
     * @param string|null $file_key       文件路径标识
     * @return array 返回保存文件的相关信息
     */
    public function uploadBase64(string $base64_centent, string $type = null, string $file_key = null): array
    {
        if (!preg_match('/^(data:\s*(\w+\/\w+);base64,)/', $base64_centent, $matches)) {
            throw new FileException('没有找到要上传的文件');
        }

        $mime = strtolower($matches[2]);
        $extension = MIME::getExtensionByMime($mime);
        if (empty($extension)) {
            throw new FileException('无法识别上传的文件后缀名');
        }

        [$file_key, , , $save_file] = $this->getPathInfo($file_key, $type, $extension);
        $fso = new File($save_file, 'w');
        $result = $fso->fwrite(base64_decode(str_replace($matches[1], '', $base64_centent)));
        if (!$result) {
            throw new FileException('上传失败');
        }
        unset($fso);

        [$imagewidth, $imageheight] = $this->imageResize($save_file, $extension);
        $saveFile = new File($save_file);
        $size = $saveFile->getSize();

        $path = $file_key;
        $full_path = $this->cfg['dir'] . '/' . $path;

        $domain = $this->cfg['domain'];
        $domain = $domain ?: Request::domain();
        $url = $domain . '/' . $full_path;

        $data = [
            'url'          => $url,
            'path'         => $path,
            'extension'    => $extension,
            'image_width'  => $imagewidth,
            'image_height' => $imageheight,
            'file_size'    => $size,
            'mime_type'    => $mime,
            'sha1'         => hash_file('sha1', $save_file),
            'extend'       => [
                'full_path' => $full_path
            ]  // 额外信息
        ];
        return $data;
    }

    /**
     * 上传远程文件
     *
     * 参数 `$extension`：不指定则根据URL、MIME进行猜测
     * 参数 `$type`：如[image,flash,audio,video,media,file]，指定该参数后保存路径以该参数开始。
     * 参数 `$file_key`：指定该参数后，参数 $type 无效
     * @param string      $url       URL
     * @param string|null $extension 后缀名
     * @param string|null $type      指定类型
     * @param string|null $file_key  文件路径标识
     * @return array 返回保存文件的相关信息
     */
    public function uploadRemote(string $url, string $extension = null, string $type = null, string $file_key = null): array
    {
        $original_url = $url;
        $extension = pathinfo($original_url, PATHINFO_EXTENSION);
        [$file_key, $dir, $save_name, $save_file] = $this->getPathInfo($file_key, $type, $extension);

        $content = file_get_contents($url);
        if ($content === false) {
            throw new FileException('获取远程文件时发生错误');
        }

        $fso = new File($save_file, 'w');
        $result = $fso->fwrite($content);
        if (!$result) {
            throw new FileException('上传失败');
        }
        $mime = $fso->getMime();
        unset($fso);

        if (empty($extension)) {
            [$save_file, $file_key, $extension] = $this->handleNoExtensionFile($dir, $save_name, $file_key);
        }

        $path = $file_key;
        $full_path = $this->cfg['dir'] . '/' . $path;
        $domain = $this->cfg['domain'];
        $domain = $domain ?: Request::domain();
        $url = $domain . '/' . $full_path;
        [$imagewidth, $imageheight] = $this->getImageSize($save_file, $extension);  // 文件直传故不进行图片压缩

        $data = [
            'url'          => $url,
            'path'         => $path,
            'extension'    => $extension,
            'image_width'  => $imagewidth,
            'image_height' => $imageheight,
            'file_size'    => filesize($save_file),
            'mime_type'    => $mime,
            'sha1'         => hash_file('sha1', $save_file),
            'extend'       => [
                'original_url' => $original_url,
                'full_path'    => $full_path
            ]
        ];
        return $data;
    }

    /**
     * 分块上传：初始化
     * @param string|null $file_key 文件路径标识，不指定则自动生成
     * @param string|null $type     指定类型
     * @return string 返回文件路径标识
     */
    public function uploadLargeInit(string $file_key = null, string $type = null): string
    {
        if (is_null($file_key)) {
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
            throw new FileException('上传失败');
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
            throw new FileException('请指定要上传的文件。');
        }
        $uploadFile = $this->getUploadedFile($name);
        if (empty($uploadFile)) {
            throw new FileException('没有找到要上传的文件。');
        }

        if ($blob_index != 0 && is_null($file_key)) {
            throw new FileException('请指定参数$file_key。');
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
     *
     * 参数 `$type`：如[image,flash,audio,video,media,file]，指定该参数后保存路径以该参数开始。
     * 参数 `$file_key`：指定该参数后，参数 $type 无效
     * @param UploadedFile $uploadFile 已上传的文件
     * @param string|null  $type       指定类型
     * @param string|null  $file_key   文件路径标识
     * @return array
     */
    protected function handleUpload(UploadedFile $uploadFile, string $type = null, string $file_key = null): array
    {
        $originalName = $uploadFile->getClientFilename();
        if (is_null($originalName)) {
            throw new FileException('文件错误！');
        }
        $mime = $uploadFile->getClientMediaType();
        if (is_null($mime)) {
            throw new FileException('无法识别文件！');
        }
        $extension = MIME::getExtensionByMime($mime);
        if (empty($extension)) {
            throw new FileException('禁止上传无后缀名的文件');
        }
        $this->checkExtension($extension);

        $size = $uploadFile->getSize();


        if (is_null($file_key)) {
            $sdir = $this->getSaveDir($type);
            $dir = $this->cfg['dir'] . '/' . $sdir;
            $save_name = uniqid() . '.' . $extension;
            $file_key = $sdir . '/' . $save_name;
        } else {
            $dir = dirname($file_key);
            $save_name = basename($file_key);
        }
        $targetPath = $dir . '/' . $save_name;
        $uploadFile->moveTo($targetPath);

        [$imagewidth, $imageheight] = $this->imageResize($targetPath, $extension);

        $path = $file_key;
        $full_path = $this->cfg['dir'] . '/' . $path;
        $domain = $this->cfg['domain'] ?: Request::domain();
        $url = $domain . '/' . $full_path;
        $data = [
            'name'          => $save_name,
            'path'          => $path,       // WEB路径
            'url'           => $url,
            'size'          => $size,
            'mime'          => $mime,
            'extension'     => $extension,
            'sha1'          => hash_file('sha1', $targetPath),
            'original_name' => $originalName,
            'tmp_name'      => $uploadFile->getTmpName(),
            'full_path'     => $full_path,  // 本机路径

            'key'          => $file_key,
            'image_width'  => $imagewidth,
            'image_height' => $imageheight,
        ];
        return $data;
    }

    /**
     * 获取路径相关信息
     * @param string|null $file_key  文件路径标识
     * @param string|null $type      指定类型
     * @param string|null $extension 后缀名
     * @return array [路径标识, 保存目录, 保存文件名, 完整路径]
     */
    protected function getPathInfo(string $file_key = null, string $type = null, string $extension = null): array
    {
        if (is_null($file_key)) {
            $sdir = $this->getSaveDir($type);
            $dir = $this->cfg['dir'] . '/' . $sdir;
            if (empty($extension)) {
                $save_name = uniqid();
            } else {
                $save_name = uniqid() . '.' . $extension;
            }
            $file_key = $sdir . '/' . $save_name;
        } else {
            $sdir = dirname($file_key);
            $dir = $this->cfg['dir'] . '/' . $sdir;
            $save_name = basename($file_key);
        }
        $save_file = $dir . '/' . $save_name;
        return [$file_key, $dir, $save_name, $save_file];
    }

    /**
     * 处理上传文件没有后缀名的情况
     * @param string $dir       保存目录
     * @param string $save_name 保存文件名
     * @param string $file_key  文件路径标识
     * @return array [更新后的文件路径, 更新后的文件路径标识]
     */
    protected function handleNoExtensionFile(string $dir, string $save_name, string $file_key): array
    {
        $save_file = $dir . '/' . $save_name;
        $fso = new File($save_file);
        $extension = $fso->getExtension();
        if ($extension) {
            $save_name = $save_name . '.' . $extension;
            $file_key = $file_key . '.' . $extension;
            $save_file = $dir . '/' . $save_name;
            $result = $fso->reName($save_file);  // 重命名为含后缀名文件
            if (!$result) {
                throw new FileException('保存文件时发生错误！');
            }
        }
        unset($fso);
        return [$save_file, $file_key, $extension];
    }
}
