<?php

namespace Fize\Provider\Upload\Handler;

use BaiduBce\Auth\SignOptions;
use BaiduBce\Services\Bos\BosClient;
use BaiduBce\Services\Bos\BosOptions;
use DateTime;
use Fize\Exception\FileException;
use Fize\IO\File;
use Fize\IO\Mime;
use Fize\Provider\Upload\UploadAbstract;
use Fize\Provider\Upload\UploadHandler;

/**
 * 百度智能云BOS
 */
class BaiDu extends UploadAbstract implements UploadHandler
{

    /**
     * @var string 记录上传临时信息所用的文件名前缀
     */
    protected $tempPre = 'baidu_';

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
        $boscfg = $cfg['config'];
        self::assertHasKey($boscfg, 'credentials');
        $credentials = $boscfg['credentials'];
        self::assertHasKey($credentials, 'accessKeyId');
        self::assertHasKey($credentials, 'secretAccessKey');
        self::assertHasKey($boscfg, 'endpoint');
        if (!isset($cfg['domain'])) {
            $cfg['domain'] = $boscfg['endpoint'];
        }
        $this->cfg = $cfg;
        $this->initProviderCfg($providerCfg);

        if (is_null($tempDir)) {
            $tempDir = '';
        }
        $this->tempDir = $tempDir;

        $this->bosClient = new BosClient($boscfg);
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
     * @todo 待修正
     */
    public function upload(string $name, string $type = null, string $file_key = null): array
    {
        $uploadFile = Request::file($name);
        return $this->handleUpload($uploadFile, $type, $file_key);
    }

    /**
     * 多文件上传
     *
     * 参数 `$type`：如[image,flash,audio,video,media,file]，指定该参数后保存路径以该参数开始。
     * 参数 `$file_key`：指定该参数后，参数 $type 无效
     * @param string      $name      文件域表单名
     * @param string|null $type      指定类型
     * @param array|null  $file_keys 文件路径标识
     * @return array 返回每个保存文件的相关信息组成的数组
     * @todo 待修正
     */
    public function uploads(string $name, string $type = null, array $file_keys = null): array
    {
        $files = Request::file($name);
        $infos = [];
        foreach ($files as $index => $file) {
            $file_key = $file_keys[$index] ?? null;
            $infos[] = $this->handleUpload($file, $type, $file_key);
        }
        return $infos;
    }

    /**
     * 上传base64串生成文件并保存
     *
     * 参数 `$type`：如[image,flash,audio,video,media,file]，指定该参数后保存路径以该参数开始。
     * 参数 `$file_key`：指定该参数后，参数 $type 无效
     * @param string      $base64_centent base64串
     * @param string|null $type           指定类型
     *
     * @param string|null $file_key       文件路径标识
     * @return array 返回保存文件的相关信息
     */
    public function uploadBase64(string $base64_centent, string $type = null, string $file_key = null): array
    {
        if (!preg_match('/^(data:\s*(\w+\/\w+);base64,)/', $base64_centent, $matches)) {
            throw new FileException('没有找到要上传的文件');
        }

        $mime = strtolower($matches[2]);
        $extension = (new Mime($mime))->getExtension();
        if (empty($extension)) {
            throw new FileException('无法识别上传的文件后缀名');
        }

        $save_name = uniqid() . '.' . $extension;
        $save_file = $this->tempDir . DIRECTORY_SEPARATOR . $save_name;
        $fso = new File($save_file, 'w');
        $result = $fso->fwrite(base64_decode(str_replace($matches[1], '', $base64_centent)));
        $fso->clearstatcache();
        $size = $fso->getSize();

        if ($result === false) {
            throw new FileException('上传失败');
        }

        [$imagewidth, $imageheight] = $this->imageResize($save_file, $extension);

        if (is_null($file_key)) {
            $sdir = $this->getSaveDir($type);
            $file_key = $sdir . '/' . $save_name;
        }
        $path = $file_key;
        $domain = $this->cfg['domain'];
        $url = $domain . '/' . $file_key;

        $this->bosClient->putObjectFromFile($this->cfg['bucket'], $file_key, $save_file);

        $data = [
            'url'          => $url,
            'path'         => $path,
            'extension'    => $extension,
            'image_width'  => $imagewidth,
            'image_height' => $imageheight,
            'file_size'    => $size,
            'mime_type'    => $mime,
            'sha1'         => hash_file('sha1', $save_file),
            'extend'       => []
        ];
        unlink($save_file);
        return $data;
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
        $file = new File($file_path);
        $extension = $file->getExtension();
        [$imagewidth, $imageheight] = $this->getImageSize($file_path, $extension);  // 文件直传故不进行图片压缩

        if (is_null($file_key)) {
            if ($extension) {
                $save_name = uniqid() . '.' . $extension;
            } else {
                $save_name = uniqid();
            }
            $sdir = $this->getSaveDir($type);
            $file_key = $sdir . '/' . $save_name;
        }
        $path = $file_key;
        $domain = $this->cfg['domain'];
        $url = $domain . '/' . $file_key;

        $this->bosClient->putObjectFromFile($this->cfg['bucket'], $file_key, $file_path);

        $data = [
            'url'          => $url,
            'path'         => $path,
            'extension'    => $extension,
            'image_width'  => $imagewidth,
            'image_height' => $imageheight,
            'file_size'    => $file->getSize(),
            'mime_type'    => $file->getMime(),
            'sha1'         => hash_file('sha1', $file_path),
            'extend'       => [
                'original_name' => basename($file_path)
            ]
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
    public function uploadFromUrl(string $url, string $extension = null, string $type = null, string $file_key = null): array
    {
        if (is_null($extension)) {
            $extension = pathinfo($url, PATHINFO_EXTENSION);
        }
        if ($extension) {
            $save_name = uniqid() . '.' . $extension;
        } else {
            $save_name = uniqid();
        }
        $save_file = $this->tempDir . '/' . $save_name;

        $content = file_get_contents($url);
        if ($content === false) {
            throw new FileException('获取远程文件时发生错误。');
        }

        $fso = new File($save_file, 'w');
        $result = $fso->fwrite($content);
        if ($result === 0) {
            throw new FileException('上传失败');
        }
        $data = $this->uploadFile($save_file, $type, $file_key);
        unlink($save_file);
        unset($data['extend']['original_name']);
        $data['extend']['original_url'] = $url;
        return $data;
    }

    /**
     * 大文件分片上传
     *
     * 参数 `$file_key`：当 $blob_index 为0时填 null 表示自动生成，不为 0 时必填。
     * 参数 `$extension`：不指定则根据MIME进行猜测
     * 参数 `$type`：如[image,flash,audio,video,media,file]，指定该参数后保存路径以该参数开始。
     * @param string      $name       文件域表单名
     * @param int         $blob_index 当前分片下标
     * @param int         $blob_count 分片总数量
     * @param string|null $file_key   文件路径标识
     * @param string|null $extension  后缀名
     * @param string|null $type       指定类型。当参数$file_key参数 $type 无效。
     * @return array
     * @todo 待修正
     */
    public function uploadLarge(string $name, int $blob_index, int $blob_count, string $file_key = null, string $extension = null, string $type = null): array
    {
        if (empty($name)) {
            throw new FileException('请指定要上传的文件。');
        }
        $uploadFile = Request::file($name);
        if (empty($uploadFile)) {
            throw new FileException('没有找到要上传的文件。');
        }

        if ($blob_index == 0 && is_null($file_key)) {
            if ($extension) {
                $save_name = uniqid() . '.' . $extension;
            } else {
                $save_name = uniqid();
            }
            $sdir = $this->getSaveDir($type);
            $file_key = $sdir . '/' . $save_name;
        }
        if (is_null($file_key)) {
            throw new FileException('请指定参数$file_key。');
        }

        // 开始
        if ($blob_index == 0) {
            $this->uploadLargeInit($file_key, $type);
        }

        // 中间
        // file_key参数在上传过程中必须保持一致
        $this->uploadLargePart($file_key, file_get_contents($uploadFile->getPathname()));
        if ($blob_index < $blob_count - 1) {
            return ['name' => $name, 'blob_index' => $blob_index, 'blob_count' => $blob_count, 'file_key' => $file_key, 'type' => $type];
        }

        // 结束
        $this->uploadLargeComplete($file_key);

        $stat = $this->ossClient->getObjectMeta($this->cfg['bucket'], $file_key);

        // 没指定后缀名的情况下进行后缀名猜测并重命名该文件
        if (empty($extension)) {
            $extension = Fso::getExtensionFromMime($stat['Content-Type']);
            if ($extension) {
                $old_file_key = $file_key;
                $file_key = $file_key . '.' . $extension;
                $this->ossClient->copyObject($this->cfg['bucket'], $old_file_key, $this->cfg['bucket'], $file_key);
                $this->ossClient->deleteObject($this->cfg['bucket'], $old_file_key);
            }
        }

        $domain = $this->cfg['domain'];
        $url = $domain . '/' . $file_key;
        $path = $file_key;

        $data = [
            'url'       => $url,
            'path'      => $path,
            'extension' => $extension,
            'file_size' => $stat['Content-Length'],
            'mime_type' => $stat['Content-Type'],
            'storage'   => 'AliYun',
            'sha1'      => $stat['ETag'],
            'extend'    => $stat
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
    public function uploadLargeParts(array $parts, string $extension = null, string $type = null, string $file_key = null): array
    {
        if (is_null($file_key)) {
            if ($extension) {
                $save_name = uniqid() . '.' . $extension;
            } else {
                $save_name = uniqid();
            }
            $sdir = $this->getSaveDir($type);
            $file_key = $sdir . '/' . $save_name;
        }
        $save_name = uniqid() . '.' . $extension;
        $temp_file = $this->tempDir . '/' . $save_name;
        foreach ($parts as $part) {
            file_put_contents($temp_file, $part, FILE_APPEND);
        }

        $domain = $this->cfg['domain'];
        $url = $domain . '/' . $file_key;
        $path = $file_key;
        [$imagewidth, $imageheight] = $this->imageResize($temp_file, $extension);

        $options = [
            BosOptions::PART_SIZE => 2 * 1024 * 1024
        ];
        $this->bosClient->putSuperObjectFromFile($this->cfg['bucket'], $file_key, $temp_file, $options);

        $tempFile = new File($temp_file);
        $tempFile->clearstatcache();
        $data = [
            'url'          => $url,
            'path'         => $path,
            'extension'    => $extension,
            'image_width'  => $imagewidth,
            'image_height' => $imageheight,
            'file_size'    => $tempFile->getSize(),
            'mime_type'    => $tempFile->getMime(),
            'sha1'         => hash_file('sha1', $temp_file),
            'extend'       => []
        ];
        unset($tempFile);
        unlink($temp_file);
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
            $save_name = uniqid();
            $sdir = $this->getSaveDir($type);
            $file_key = $sdir . '/' . $save_name;
        }

        $response = $this->bosClient->initiateMultipartUpload($this->cfg['bucket'], $file_key);
        $uploadId = $response->uploadId;
        $this->savePartUploadInfo($file_key, ['uploadId' => $uploadId, 'tempName' => uniqid()]);

        return $file_key;
    }

    /**
     * 分块上传：上传块
     * @param string $file_key 文件路径标识
     * @param string $content  块内容
     */
    public function uploadLargePart(string $file_key, string $content)
    {
        $info = $this->getPartUploadInfo($file_key);
        $this->assertHasKey($info, 'uploadId');
        if (isset($info['eTags'])) {
            $partNumber = (end($info['eTags']))['partNumber'] + 1;
        } else {
            $partNumber = 1;
        }

        $temp_file = $this->tempDir . '/' . $info['tempName'];
        if ($partNumber == 1) {
            $org_size = 0;
        } else {
            $org_size = filesize($temp_file);
        }
        file_put_contents($temp_file, $content, FILE_APPEND);
        clearstatcache(true, $temp_file);
        $new_size = filesize($temp_file);

        $response = $this->bosClient->uploadPartFromFile($this->cfg['bucket'], $file_key, $info['uploadId'], $partNumber, $temp_file, $org_size, $new_size - $org_size);
        $eTag = $response->metadata['etag'];
        $blockStatus = ['eTag' => $eTag, 'partNumber' => $partNumber];
        $etags = $info['eTags'] ?? [];
        $etags[] = $blockStatus;
        $this->savePartUploadInfo($file_key, ['eTags' => $etags]);
    }

    /**
     * 分块上传：结束并生成文件
     * @param string      $file_key 文件路径标识
     * @param string|null $fname    原文件名
     * @param string|null $mimeType 指定Mime
     * @return array 返回保存文件的相关信息
     */
    public function uploadLargeComplete(string $file_key, string $fname = null, string $mimeType = null): array
    {
        $info = $this->getPartUploadInfo($file_key);
        $this->assertHasKey($info, 'uploadId');
        $this->assertHasKey($info, 'eTags');

        $this->bosClient->completeMultipartUpload($this->cfg['bucket'], $file_key, $info['uploadId'], $info['eTags']);

        $temp_file = $this->tempDir . '/' . $info['tempName'];
        $tempFile = new File($temp_file);
        $extension = pathinfo($file_key, PATHINFO_EXTENSION);
        if (empty($extension) && $fname) {
            $extension = pathinfo($fname, PATHINFO_EXTENSION);
        }
        if (empty($extension)) {
            $extension = $tempFile->getExtension();
        }

        $path = $file_key;
        $domain = $this->cfg['domain'];
        $url = $domain . '/' . $file_key;
        [$imagewidth, $imageheight] = $this->imageResize($temp_file, $extension);

        $file_size = $tempFile->getSize();
        $file_mime = $tempFile->getMime();
        $sha1 = hash_file('sha1', $temp_file);

        unlink($temp_file);
        $this->deletPartUploadInfo($file_key);

        return [
            'url'          => $url,
            'path'         => $path,
            'extension'    => $extension,
            'image_width'  => $imagewidth,
            'image_height' => $imageheight,
            'file_size'    => $file_size,
            'mime_type'    => $file_mime,
            'sha1'         => $sha1,
            'extend'       => [
                'fname' => $fname
            ]
        ];
    }

    /**
     * 终止上传
     * @param string $file_key 文件路径标识
     */
    public function uploadLargeAbort(string $file_key)
    {
        $info = $this->getPartUploadInfo($file_key);
        $this->assertHasKey($info, 'uploadId');
        $this->bosClient->abortMultipartUpload($this->cfg['bucket'], $file_key, $info['uploadId']);
        $temp_file = $this->tempDir . '/' . $info['tempName'];
        unlink($temp_file);
        $this->deletPartUploadInfo($file_key);
    }

    /**
     * 返回已授权的URL
     * @param string $url     原URL
     * @param int    $expires 有效期(秒)，为0表示永久有效
     * @return string
     */
    public function getAuthorizedUrl(string $url, int $expires = 0): string
    {
        if ($this->cfg['private']) {
            $key = parse_url($url, PHP_URL_PATH);
            $key = substr($key, 1);  // 删除第一个【/】
            $options = [
                SignOptions::TIMESTAMP             => new DateTime(),
                SignOptions::EXPIRATION_IN_SECONDS => $expires,
            ];
            $url = $this->bosClient->generatePreSignedUrl($this->cfg['bucket'], $key, $options);
        }
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
     * @todo 待修正
     */
    protected function handleUpload(UploadedFile $uploadFile, string $type = null, string $file_key = null): array
    {
        if (empty($uploadFile)) {
            throw new FileException('没有找到要上传的文件');
        }

        $extension = $uploadFile->getOriginalExtension();
        if (empty($extension)) {
            $fso = new Fso($uploadFile->getRealPath());
            $mime = $fso->getMime();
            $extension = Fso::getExtensionFromMime($mime);
            if (empty($extension)) {
                throw new FileException('禁止上传无后缀名的文件');
            }
            $fso->close();
        }
        if (!in_array($extension, explode(',', $this->providerCfg['extensions']))) {
            throw new FileException("禁止上传后缀名为{$extension}的文件");
        }

        $originalName = $uploadFile->getOriginalName();

        $save_name = uniqid() . '.' . $extension;
        $save_file = $this->tempDir . '/' . $save_name;  // 因为会上传到OSS，故放在临时文件夹中待后面上传后删除
        $saveFile = new Fso($save_file, true);
        $saveFile->open('w');
        $saveFile->write(file_get_contents($uploadFile->getPathname()));
        $saveFile->close();
        [$imagewidth, $imageheight] = $this->imageResize($save_file, $extension);
        $saveFile = new File($save_file);

        if (is_null($file_key)) {
            $sdir = $this->getSaveDir($type);
            $file_key = $sdir . '/' . $save_name;
        }
        $path = $file_key;
        $domain = $this->cfg['domain'];
        $url = $domain . '/' . $file_key;

        $this->ossClient->uploadFile($this->cfg['bucket'], $file_key, $save_file);

        $data = [
            'original_name' => $originalName,
            'url'           => $url,
            'path'          => $path,
            'extension'     => $extension,
            'image_width'   => $imagewidth,
            'image_height'  => $imageheight,
            'file_size'     => $saveFile->getSize(),
            'mime_type'     => $saveFile->getMime(),
            'storage'       => 'ALiYun',
            'sha1'          => hash_file('sha1', $save_file),
            'extend'        => []
        ];
        unlink($save_file);
        return $data;
    }
}