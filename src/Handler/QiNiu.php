<?php

namespace Fize\Provider\Upload\Handler;

use Fize\Cache\CacheFactory;
use Fize\Cache\CacheInterface;
use Fize\Exception\FileException;
use Fize\Http\UploadedFile;
use Fize\IO\File;
use Fize\IO\MIME;
use Fize\Provider\Upload\UploadHandler;
use Fize\Provider\Upload\UploadHandlerAbstract;
use Qiniu\Auth;
use Qiniu\Storage\UploadManager;
use RuntimeException;


/**
 * 七牛云OBS
 */
class QiNiu extends UploadHandlerAbstract implements UploadHandler
{

    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * 初始化
     * @param array       $cfg         配置
     * @param array       $providerCfg provider设置
     * @param string|null $tempDir     临时文件存放文件夹目录
     */
    public function __construct(array $cfg = [], array $providerCfg = [], string $tempDir = null)
    {
        $defaultCfg = [
            'tempDir' => './temp',
            'private' => false,
            'version' => 'v1',
        ];
        $this->cfg = array_merge($defaultCfg, $cfg);
        $this->providerCfg = $providerCfg;
        $this->tempDirPath = $tempDir ?? $this->cfg['tempDir'];
        $this->cfg['tempDir'] = $this->tempDirPath;
        $this->cfg['uploadToken'] = array_merge([
            'cacheKey' => 'qiniu-upload-token-' . hash('sha256', ($cfg['accessKey'] ?? '') . ':' . ($cfg['bucket'] ?? '')),
            'expires'  => 3600,
        ], $cfg['uploadToken'] ?? []);
        $cacheHandler = $this->cfg['cache_handler'] ?? 'File';
        $cacheConfig = $this->cfg['cache_config'] ?? [];
        $this->cache = CacheFactory::create($cacheHandler, $cacheConfig);
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
        $ext = strtolower($orig_file->getExtension());
        $mime = $orig_file->getMime();
        unset($orig_file);
        $this->checkExt($ext);

        if (is_null($key)) {
            if ($ext) {
                $name = uniqid() . '.' . $ext;
            } else {
                $name = uniqid();
            }
            $sdir = $this->getSaveDir();
            $key = $sdir . '/' . $name;
        }
        $path = $key;
        $domain = $this->cfg['domain'];
        $url = rtrim($domain, '/') . '/' . ltrim($key, '/');
        $sha1 = hash_file('sha1', $filePath);
        $name = basename($key);
        $dir = dirname($key);

        $token = $this->getUploadToken();
        $uploadMgr = new UploadManager();
        [$ret, $err] = $uploadMgr->putFile($token, $key, $filePath, null, 'application/octet-stream', false, null, $this->cfg['version']);
        if ($err !== null) {
            throw new FileException($filePath, (string)$err);
        }

        $data = [
            'key'  => $key,
            'name' => $name,
            'path' => $path,
            'url'  => $url,
            'size' => $size,
            'mime' => $mime,
            'ext'  => $ext,
            'sha1' => $sha1,

            'dir'       => $dir,
            'orig_name' => $origName,
            'orig_path' => $origPath,  // 原文件路径

            'extend' => [
                'ret' => $ret,
            ]
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
        if (!preg_match('/^(data:\s*([a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+);base64,)/i', $base64Centent, $matches)) {
            throw new FileException('没有找到要上传的文件');
        }

        $mime = strtolower($matches[2]);
        $ext = MIME::getExtensionByMime($mime);
        if (empty($ext)) {
            throw new FileException('无法识别上传的文件后缀名');
        }

        $this->checkExt($ext);
        $content = base64_decode(substr($base64Centent, strlen($matches[1])), true);
        if ($content === false) {
            throw new FileException('', '无效的base64内容');
        }

        $name = uniqid() . '.' . $ext;
        $save_file = $this->cfg['tempDir'] . DIRECTORY_SEPARATOR . $name;  // 因为会上传到OSS，故放在临时文件夹中待后面上传后删除
        try {
            $fso = new File($save_file, 'w');
            $result = $fso->fwrite($content);
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
            $url = rtrim($domain, '/') . '/' . ltrim($key, '/');

            unset($fso);
            $name = basename($key);
            $token = $this->getUploadToken();
            $uploadMgr = new UploadManager();
            [$ret, $err] = $uploadMgr->putFile($token, $key, $save_file, null, 'application/octet-stream', false, null, $this->cfg['version']);
            if ($err !== null) {
                throw new FileException($save_file, (string)$err);
            }

            $sha1 = hash_file('sha1', $save_file);
            $data = [
                'key'    => $key,
                'name'   => $name,
                'path'   => $path,
                'url'    => $url,
                'size'   => $size,
                'mime'   => $mime,
                'ext'    => $ext,
                'sha1'   => $sha1,
                'extend' => [
                    'ret' => $ret,
                ]
            ];
            return $data;
        } finally {
            unset($fso);
            if (is_file($save_file)) {
                unlink($save_file);
            }
        }
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
        $ext = pathinfo(parse_url($origUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION);
        if ($ext) {
            $save_name = uniqid() . '.' . $ext;
        } else {
            $save_name = uniqid();
        }
        $save_file = $this->cfg['tempDir'] . '/' . $save_name;  // 因为会上传到OSS，故放在临时文件夹中待后面上传后删除

        $content = file_get_contents($url);
        if ($content === false) {
            throw new FileException('获取远程文件时发生错误：');
        }

        try {
            $fso = new File($save_file, 'w');
            $result = $fso->fwrite($content);
            if ($result === false) {
                throw new FileException('上传失败');
            }
            unset($fso);
            $data = $this->uploadFile($save_file, $key);
            unset($data['orig_name']);
            $data['orig_url'] = $origUrl;
            return $data;
        } finally {
            unset($fso);
            if (is_file($save_file)) {
                unlink($save_file);
            }
        }
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
                    $tempFile = $this->tempDirPath . '/' . $part;
                    if (is_file($tempFile)) {
                        unlink($tempFile);
                    }
                }
            }
            $this->deletPartUploadInfo($uuid);
        }
        $info = [
            'uuid'      => $uuid,
            'blobCount' => $blobCount,
            'key'       => $key,
            'parts'     => [],
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
        if ($result === false || $result !== strlen($content)) {
            throw new FileException($tempFile, '上传失败');
        }
        unset($fso);
        $info['parts']["BLOB-{$blobIndex}"] = $tempfileName;
        $this->savePartUploadInfo($uuid, $info);
        return $info;
    }

    /**
     * 分块上传：完成上传
     * @param string      $uuid 唯一识别码
     * @param string|null $key  文件路径标识
     * @param string|null $ext  后缀名，不指定则根据MIME进行猜测。
     * @return array 返回保存文件的相关信息
     */
    public function uploadLargeComplete(string $uuid, ?string $key = null, ?string $ext = null): array
    {
        $info = $this->getPartUploadInfo($uuid);
        self::assertHasKey($info, 'parts');
        if ($info['blobCount']) {
            if ($info['blobCount'] != count($info['parts'])) {
                throw new RuntimeException('分片未全部上传！');
            }
        }

        $extension = $ext;
        $parts = $info['parts'];
        if (!$parts) {
            throw new RuntimeException('没有可合并的分片！');
        }
        for ($i = 0; $i < count($parts); $i++) {
            if (!isset($parts["BLOB-{$i}"]) || !is_file($this->tempDirPath . '/' . $parts["BLOB-{$i}"])) {
                throw new RuntimeException('分片文件不存在！');
            }
        }

        $key = $key ?? ($info['key'] ?? null);
        $targetPath = tempnam($this->tempDirPath, 'qiniu_');
        if ($targetPath === false) {
            throw new RuntimeException('无法创建合并临时文件！');
        }
        try {
            // 每次重新合并，上传失败重试时不会重复追加分片。
            $fso = new File($targetPath, 'wb');
            for ($i = 0; $i < count($parts); $i++) {
                $tempFile = $this->tempDirPath . '/' . $parts["BLOB-{$i}"];
                $content = file_get_contents($tempFile);
                if ($content === false || $fso->fwrite($content) !== strlen($content)) {
                    throw new RuntimeException('合并分片失败！');
                }
            }
            unset($fso);
            $tempFile = new File($targetPath);
            $mime = $tempFile->getMime();
            $extension = $extension ?: pathinfo($key ?? '', PATHINFO_EXTENSION);
            if (!$extension) {
                $extension = MIME::getExtensionByMime($mime) ?: '';
            }
            $this->checkExt($extension);
            $key = $key ?? $this->generateKey(null, $extension);
            $name = basename($key);
            $size = $tempFile->getSize();
            $sha1 = hash_file('sha1', $targetPath);
            unset($tempFile);
            $path = $key;
            $url = rtrim($this->cfg['domain'], '/') . '/' . ltrim($key, '/');

            $token = $this->getUploadToken();
            $uploadMgr = new UploadManager();
            [$ret, $err] = $uploadMgr->putFile($token, $key, $targetPath, null, $mime, false, null, $this->cfg['version']);
            if ($err !== null) {
                throw new FileException($targetPath, (string)$err);
            }
        } finally {
            unset($fso, $tempFile);
            unlink($targetPath);
        }

        // 删除相关临时文件
        foreach ($info['parts'] as $tempfileName) {
            $tempFile = $this->tempDirPath . '/' . $tempfileName;
            unlink($tempFile);
        }
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

            'full_path' => $targetPath,  // 本机完整路径
            'extend'    => [
                'ret' => $ret,
            ]
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
     * @param string|null $ext       后缀名，不指定则根据MIME进行猜测。
     * @param string|null $uuid      唯一识别码，不指定则自动生成。
     * @return array
     */
    public function uploadLarge(string $name, int $blobIndex, ?int $blobCount = null, ?string $ext = null, ?string $uuid = null): array
    {
        // 特殊值-1：初始化
        if ($blobIndex == -1) {
            $uuid = $this->uploadLargeInit($blobCount, null, $uuid);
            $info = $this->getPartUploadInfo($uuid);
            return $info;
        }

        // 特殊值-2：完成上传
        if ($blobIndex == -2) {
            if (!$uuid) {
                throw new RuntimeException('请指定参数uuid。');
            }
            $info = $this->uploadLargeComplete($uuid, null, $ext);
            return $info;
        }

        // 初始化
        if (is_null($uuid)) {
            $uuid = session_id() . "_upload_" . $name;  // 会话端唯一！
        }
        $info = $this->getPartUploadInfo($uuid);
        if (!$info) {
            $this->uploadLargeInit($blobCount, null, $uuid);
        }

        // 上传块
        $uploadFile = $this->getUploadedFile($name);
        $content = $uploadFile->getStream()->getContents();
        $info = $this->uploadLargePart($uuid, $content, $blobIndex);
        if (is_null($info['blobCount']) || $info['blobCount'] > count($info['parts'])) {
            return $info;
        }

        // 完成上传
        $info = $this->uploadLargeComplete($uuid, null, $ext);
        return $info;
    }

    /**
     * 上传多个分块并合并成文件
     * @param array       $parts 分块数组
     * @param string|null $ext   后缀名，不指定则根据MIME进行猜测。
     * @param string|null $uuid  唯一识别码，不指定则自动生成。
     * @return array
     */
    public function uploadParts(array $parts, ?string $ext = null, ?string $uuid = null): array
    {
        $blobCount = count($parts);
        $uuid = $this->uploadLargeInit($blobCount, null, $uuid);
        foreach ($parts as $blobIndex => $content) {
            $this->uploadLargePart($uuid, $content, $blobIndex);
        }
        $info = $this->uploadLargeComplete($uuid, null, $ext);
        return $info;
    }

    /**
     * 返回已授权的预览URL
     * @param string $key     文件路径标识或完整URL
     * @param int    $expires 有效期(秒)，为0表示永久有效
     * @return string
     */
    public function getAuthorizedUrl(string $key, int $expires = 0): string
    {
        $url = preg_match('#^https?://#i', $key)
            ? $key
            : rtrim($this->cfg['domain'], '/') . '/' . ltrim($key, '/');
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
        $ext = MIME::getExtensionByMime($mime);
        if (empty($ext)) {
            throw new FileException($origName, '禁止上传无后缀名的文件');
        }
        $this->checkExt($ext);

        if (is_null($key)) {
            $key = $this->generateKey(null, $ext);
        }

        $size = $uploadFile->getSize();
        $tmpName = $uploadFile->getTmpName();
        $sha1 = hash_file('sha1', $tmpName);
        $name = basename($key);
        $dir = dirname($key);

        $token = $this->getUploadToken();
        $uploadMgr = new UploadManager();
        [$ret, $err] = $uploadMgr->putFile($token, $key, $tmpName, null, 'application/octet-stream', false, null, $this->cfg['version']);
        if ($err !== null) {
            throw new FileException($tmpName, (string)$err);
        }

        if (!$uploadFile->isForTest()) {
            unlink($tmpName);
        }
        $path = $key;
        $url = rtrim($this->cfg['domain'], '/') . '/' . ltrim($key, '/');
        $data = [
            'key'  => $key,                            // 文件路径标识
            'name' => $name,                           // 保存文件名
            'path' => $path,                           // WEB路径
            'url'  => $url,                            // 完整URL
            'size' => $size,                           // 文件大小
            'mime' => $mime,                           // MIME类型
            'ext'  => $ext,                            // 后缀名
            'sha1' => $sha1,                           // 文件SHA1

            'dir'       => $dir,         // 生成路径
            'orig_name' => $origName,    // 原文件名
            'tmp_name'  => $tmpName,     // 上传临时文件路径

            'extend' => [
                'ret' => $ret,
            ]
        ];
        return $data;
    }

    /**
     * 获取上传TOKEN
     * @return string
     */
    protected function getUploadToken(): string
    {
        $key = $this->cfg['uploadToken']['cacheKey'];
        if (!$this->cache->has($key)) {
            $auth = new Auth($this->cfg['accessKey'], $this->cfg['secretKey']);
            $expires = max(1, (int)$this->cfg['uploadToken']['expires']);
            $token = $auth->uploadToken($this->cfg['bucket'], null, $expires);
            $this->cache->set($key, $token, max(1, $expires - 600));  // 提前10分钟失效，提高稳定性
        }
        return $this->cache->get($key);
    }

    /**
     * 获取路径相关信息
     * @param string|null $key 文件唯一标识
     * @param string|null $ext 后缀名
     * @return array [路径标识, 保存文件名, 保存路径]
     */
    protected function getPathInfo(string $key = null, string $ext = null): array
    {
        if (is_null($key)) {
            $sdir = $this->tempDirPath;
            if (empty($ext)) {
                $name = uniqid();
            } else {
                $name = uniqid() . '.' . $ext;
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
