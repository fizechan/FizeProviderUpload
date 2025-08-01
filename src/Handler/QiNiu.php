<?php


namespace Fize\Provider\Upload\Handler;

use Exception;
use Fize\Exception\FileException;
use Fize\IO\File;
use Fize\Provider\Upload\UploadAbstract;
use Fize\Provider\Upload\UploadHandler;
use fuli\commons\third\qiNiu\ResumeUploaderV1;
use fuli\commons\third\qiNiu\ResumeUploaderV2;
use Qiniu\Auth;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\UploadManager;


/**
 * 七牛方式上传文件
 */
class QiNiu extends UploadAbstract implements UploadHandler
{

    /**
     * 初始化
     * @param array       $cfg         配置
     * @param array       $providerCfg provider设置
     * @param string|null $tempDir     临时文件存放文件夹目录
     */
    public function __construct(array $cfg = [], array $providerCfg = [], string $tempDir = null)
    {
        $this->cfg = array_merge(Config::get('third.QiNiu.upload'), $cfg);
        $this->providerCfg = array_merge(Config::get('provider.upload'), $providerCfg);

        if (is_null($tempDir)) {
            $tempDir = Config::get('filesystem.disks.temp.root') . '/uploads';
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
        $file = new Fso($file_path);
        $extension = $file->getExtensionPossible();
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

        $token = $this->getUploadToken();
        $uploadMgr = new UploadManager();
        [$ret, $err] = $uploadMgr->putFile($token, $file_key, $file_path, null, 'application/octet-stream', false, null, $this->cfg['version']);
        if ($err !== null) {
            throw new FileException($err);
        }

        $data = [
            'original_name' => basename($file_path),
            'url'           => $url,
            'path'          => $path,
            'extension'     => $extension,
            'image_width'   => $imagewidth,
            'image_height'  => $imageheight,
            'file_size'     => $file->getInfo('size'),
            'mime_type'     => $file->getMime(),
            'sha1'          => hash_file('sha1', $file_path),
            'extend'        => $ret  // 额外信息
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
        $extension = Fso::getExtensionFromMime($mime);
        if (empty($extension)) {
            throw new FileException('无法识别上传的文件后缀名');
        }

        $save_name = uniqid() . '.' . $extension;
        $save_file = $this->tempDir . '/' . $save_name;  // 因为会上传到OBS，故放在临时文件夹中待后面上传后删除
        $fso = new Fso($save_file, true, true, 'w');
        $result = $fso->write(base64_decode(str_replace($matches[1], '', $base64_centent)));
        $size = $fso->getInfo('size');
        $fso->close();

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

        $token = $this->getUploadToken();
        $uploadMgr = new UploadManager();
        [$ret, $err] = $uploadMgr->putFile($token, $file_key, $save_file, null, 'application/octet-stream', false, null, $this->cfg['version']);
        if ($err !== null) {
            throw new FileException($err);
        }
        $data = [
            'url'          => $url,
            'path'         => $path,
            'extension'    => $extension,
            'image_width'  => $imagewidth,
            'image_height' => $imageheight,
            'file_size'    => $size,
            'mime_type'    => $mime,
            'sha1'         => hash_file('sha1', $save_file),
            'extend'       => $ret  // 额外信息
        ];
        unlink($save_file);  // 已上传到OBS，删除本地文件
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
        if (is_null($extension)) {
            $extension = pathinfo($original_url, PATHINFO_EXTENSION);
        }
        if ($extension) {
            $save_name = uniqid() . '.' . $extension;
        } else {
            $save_name = uniqid();
        }
        $save_file = $this->tempDir . '/' . $save_name;  // 因为会上传到OBS，故放在临时文件夹中待后面上传后删除

        $http = new Http();
        $content = $http->get($url);
        if ($content === false) {
            throw new FileException('获取远程文件时发生错误：' . $http->lastErrMsg());
        }

        $fso = new Fso($save_file, true, true, 'w');
        $result = $fso->write($content);
        if ($result === false) {
            throw new FileException('上传失败');
        }
        $fso->close();
        $data = $this->uploadFile($save_file, $type, $file_key);
        unlink($save_file);  // 已上传到OBS，删除本地文件
        unset($data['original_name']);
        $data['original_url'] = $original_url;
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
        $upToken = $this->getUploadToken();
        if ($this->cfg['version'] == 'v2') {
            $up2 = new ResumeUploaderV2($upToken, $file_key, $this->tempDir);
            $up2->initiateMultipartUpload();
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
        $upToken = $this->getUploadToken();
        if ($this->cfg['version'] == 'v1') {
            $up1 = new ResumeUploaderV1($upToken, $file_key, $this->tempDir);
            $up1->mkblk($content);
        } else {
            $up2 = new ResumeUploaderV2($upToken, $file_key, $this->tempDir);
            $up2->uploadPart($content);
        }
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
        $upToken = $this->getUploadToken();
        if ($this->cfg['version'] == 'v1') {
            $up1 = new ResumeUploaderV1($upToken, $file_key, $this->tempDir);
            $result = $up1->mkfile($fname, $mimeType);
        } else {
            $up2 = new ResumeUploaderV2($upToken, $file_key, $this->tempDir);
            $result = $up2->completeMultipartUpload($fname, $mimeType);
        }
        return [
            'file_key' => $file_key,
            'fname'    => $fname,
            'mimeType' => $mimeType,
            'extend'   => $result
        ];
    }

    /**
     * 终止上传
     * @param string $file_key 文件路径标识
     */
    public function uploadLargeAbort(string $file_key)
    {
        if ($this->cfg['version'] == 'v1') {
            throw new Exception("七牛云分片上传v1版不支持终止上传！");
        } else {
            $upToken = $this->getUploadToken();
            $up2 = new ResumeUploaderV2($upToken, $file_key, $this->tempDir);
            $up2->abortMultipartUpload();
        }
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

        $auth = new Auth($this->cfg['accessKey'], $this->cfg['secretKey']);
        $bm = new BucketManager($auth);
        [$stat, $err] = $bm->stat($this->cfg['bucket'], $file_key);
        if ($err !== null) {
            throw new FileException($err);
        }
        // 没指定后缀名的情况下进行后缀名猜测并重命名该文件
        if (empty($extension)) {
            $extension = Fso::getExtensionFromMime($stat['mimeType']);
            if ($extension) {
                $old_file_key = $file_key;
                $file_key = $file_key . '.' . $extension;
                $bm->rename($this->cfg['bucket'], $old_file_key, $file_key);
            }
        }

        $domain = $this->cfg['domain'];
        $url = $domain . '/' . $file_key;
        $path = $file_key;

        $data = [
            'url'       => $url,
            'path'      => $path,
            'extension' => $extension,
            'file_size' => $stat['fsize'],
            'mime_type' => $stat['mimeType'],
            'sha1'      => $stat['hash'],
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
    public function uploadParts(array $parts, string $extension = null, string $type = null, string $file_key = null): array
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

        $this->uploadLargeInit($file_key, $type);
        foreach ($parts as $part) {
            $this->uploadLargePart($file_key, $part);
        }
        $this->uploadLargeComplete($file_key);

        $auth = new Auth($this->cfg['accessKey'], $this->cfg['secretKey']);
        $bm = new BucketManager($auth);
        [$stat, $err] = $bm->stat($this->cfg['bucket'], $file_key);
        if ($err !== null) {
            throw new FileException($err);
        }
        // 没指定后缀名的情况下进行后缀名猜测并重命名该文件
        if (is_null($extension)) {
            $extension = Fso::getExtensionFromMime($stat['mimeType']);
            if ($extension) {
                $old_file_key = $file_key;
                $file_key = $file_key . '.' . $extension;
                $bm->rename($this->cfg['bucket'], $old_file_key, $file_key);
            }
        }

        $domain = $this->cfg['domain'];
        $url = $domain . '/' . $file_key;
        $path = $file_key;

        $data = [
            'url'       => $url,
            'path'      => $path,
            'extension' => $extension,
            'file_size' => $stat['fsize'],
            'mime_type' => $stat['mimeType'],
            'sha1'      => $stat['hash'],
            'extend'    => $stat
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
        if ($expires == 0) {
            $expires = 100 * 365 * 24 * 3600;
        }
        $config = $this->cfg;
        $auth = new Auth($config['accessKey'], $config['secretKey']);
        if ($config['private']) {
            $url = $auth->privateDownloadUrl($url, $expires);
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
        $save_file = $this->tempDir . '/' . $save_name;  // 因为会上传到OBS，故放在临时文件夹中待后面上传后删除
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

        $token = $this->getUploadToken();
        $uploadMgr = new UploadManager();
        [$ret, $err] = $uploadMgr->putFile($token, $file_key, $save_file, null, 'application/octet-stream', false, null, $this->cfg['version']);
        if ($err !== null) {
            throw new FileException($err);
        }
        $data = [
            'file_key'      => $file_key,
            'original_name' => $originalName,
            'url'           => $url,
            'path'          => $path,
            'extension'     => $extension,
            'image_width'   => $imagewidth,
            'image_height'  => $imageheight,
            'file_size'     => $saveFile->getSize(),
            'mime_type'     => $saveFile->getMime(),
            'sha1'          => hash_file('sha1', $save_file),
            'extend'        => $ret  // 额外信息
        ];
        unlink($save_file);  // 已上传到OBS，删除本地文件
        return $data;
    }

    /**
     * 获取上传TOKEN
     * @return string
     */
    protected function getUploadToken(): string
    {
        $key = $this->cfg['uploadToken']['cacheKey'];
        if (!Cache::has($key)) {
            $auth = new Auth($this->cfg['accessKey'], $this->cfg['secretKey']);
            $token = $auth->uploadToken($this->cfg['bucket']);
            Cache::set($key, $token, $this->cfg['uploadToken']['expires'] - 600);  // 提前10分钟失效，提高稳定性
        }
        return Cache::get($key);
    }
}
