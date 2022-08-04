<?php


namespace Fize\Provider\Upload\Handler;

use Exception;
use Fize\IO\File;
use Fize\Third\PingAn\Api\OBS;
use Fize\Provider\Upload\UploadAbstract;
use Fize\Provider\Upload\UploadHandler;

/**
 * 平安OBS
 */
class PingAn extends UploadAbstract implements UploadHandler
{

    /**
     * @var File 上传的文件
     */
    protected static $uploadFile;

    /**
     * @var File 保存的文件
     */
    protected static $saveFile;

    /**
     * @var array 配置
     */
    protected $config;

    /**
     * 初始化
     * @param array       $cfg         配置
     * @param array       $providerCfg provider设置
     * @param string|null $tempDir     临时文件存放文件夹目录
     */
    public function __construct(array $cfg = [], array $providerCfg = [], string $tempDir = null)
    {
        $this->config = Config::get('third.PingAn.upload');
    }

    /**
     * 多媒体上传功能，正确返回保存文件的相关信息
     * @param string $name 文件域表单名
     * @param string $type 类型[image,flash,audio,video,media,file]
     * @return array [$errcode, $errmsg, $data]
     */
    public function upload($name, $type = null)
    {
        self::$uploadFile = Request::file($name);
        if (empty(self::$uploadFile)) {
            return [Upload::ERRCODE_UPLOADFILE_NOEXIST, '没有找到要上传的文件', null];
        }

        $setting = [
            'size' => Upload::getAcceptableSize($type),
            'ext' => Upload::getAcceptableExt($type),
        ];
        $config = $this->config;
        $ym = date('Ym');
        $dy = date('d');

        self::$saveFile = self::$uploadFile
            ->validate($setting)
            ->rule('uniqid')
            ->move(Env::get('runtime_path') . 'uploads');
        if (!self::$saveFile) {
            $err = self::$uploadFile->getError();
            $err_fext = [
                '非法图像文件！',
                '上传文件MIME类型不允许！',
                '上传文件后缀不允许'
            ];
            $err_size = [
                '没有上传的文件！',
                '上传文件大小不符！',
                '上传的文件超过了 php.ini 中 upload_max_filesize 选项限制的值！',
                '上传文件的大小超过了 HTML 表单中 MAX_FILE_SIZE 选项指定的值！'
            ];
            if (in_array($err, $err_fext)) {
                return [Upload::ERRCODE_FILEEXT_NOTACCEPT, $err, null];  // 文件格式有误
            }
            if (in_array($err, $err_size)) {
                return [Upload::ERRCODE_FIZESIZE_ERROR, $err, null]; // 文件大小不符合要求
            }
            // 其他情况返回errmsg
            return [Upload::ERRCODE_UPLOAD_FAILED, $err, null];  // 上传失败
        }

        $fileInfo = self::$uploadFile->getInfo();
        $suffix = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
        $suffix = $suffix ?: 'file';
        $imagewidth = 0;
        $imageheight = 0;
        if (in_array($suffix, Config::get('upload.ext.image'))) {
            self::imagerotateAuto(self::$saveFile, null, true);
            $imgInfo = getimagesize(self::$saveFile->getPathname());
            $imagewidth = $imgInfo[0] ?? 0;
            $imageheight = $imgInfo[1] ?? 0;
            if ($imagewidth > Upload::MAX_IMAGE_WIDTH) {
                $imageheight = round(Upload::MAX_IMAGE_WIDTH * $imageheight / $imagewidth);
                $imagewidth = Upload::MAX_IMAGE_WIDTH;
            }
            if ($fileInfo['size'] > 1048576) {
                $image = Image::open(self::$saveFile);
                $image->thumb($imagewidth, $imageheight)->save(self::$saveFile->getPathname());
            }
        }

        $file_key = $ym . '/' . $dy . '/' . self::$saveFile->getSaveName();
        $path = $file_key;
        $save_file = Env::get('runtime_path') . '/uploads/' . self::$saveFile->getSaveName();

        $obs = new Obs($config['accessKey'], $config['secretKey']);
        $obs->setInternalUpload($config['internalUpload']);
        $obs->setBucket($config['bucket']);
        $url = $obs->putObject($save_file, $file_key);
        if ($url === false) {
            return [Upload::ERRCODE_UPLOAD_FAILED, '上传文件时发生错误', null];
        }

        $data = [
            'file_key'      => $file_key,
            'url' => $url,
            'path' => $path,
            'extension' => self::$saveFile->getExtension(),
            'imagewidth' => $imagewidth,
            'imageheight' => $imageheight,
            'imagetype' => $suffix,
            'imageframes' => 0,
            'filesize' => self::$saveFile->getSize(),
            'mimetype' => Upload::getMimeType(self::$saveFile->getPathname()),
            'storage' => 'PingAn',
            'sha1' => hash_file('sha1', $save_file)
        ];
        unlink($save_file);
        return [0, '上传成功', $data];
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
        throw new \RuntimeException('暂未实现！');
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
        $result = null;
        if (!preg_match('/^(data:\s*image\/(\w+);base64,)/', $base64_image_content, $result)) {
            return [Upload::ERRCODE_UPLOADFILE_NOEXIST, '没有找到要上传的文件', null];
        }

        $suffix = strtolower($result[2]);
        $config = $this->config;
        $ym = date('Ym');
        $dy = date('d');

        $save_name = uniqid() . '.' . $suffix;
        $save_file = Env::get('runtime_path') . 'uploads/' . $save_name;
        $file = new Fso($save_file, true, true, 'w');
        $result = $file->write(base64_decode(str_replace($result[1], '', $base64_image_content)));
        $file->close();
        if ($result === false) {
            return [Upload::ERRCODE_UPLOAD_FAILED, '上传失败', null];
        }

        $imagewidth = 0;
        $imageheight = 0;
        if (in_array($suffix, Config::get('upload.ext.image'))) {
            self::imagerotateAuto($save_file, null, true);
            $imgInfo = getimagesize($save_file);
            $imagewidth = $imgInfo[0] ?? 0;
            $imageheight = $imgInfo[1] ?? 0;
            if ($imagewidth > Upload::MAX_IMAGE_WIDTH) {
                $imageheight = round(Upload::MAX_IMAGE_WIDTH * $imageheight / $imagewidth);
                $imagewidth = Upload::MAX_IMAGE_WIDTH;
            }
            if ($file->getInfo('size') > 1048576) {
                $image = Image::open($save_file);
                $image->thumb($imagewidth, $imageheight)->save($save_file);
                $file->clearStatCache();
            }
        }

        $file_key = $ym . '/' . $dy . '/' . $save_name;
        $path = $file_key;

        $obs = new Obs($config['accessKey'], $config['secretKey']);
        $obs->setInternalUpload($config['internalUpload']);
        $obs->setBucket($config['bucket']);
        $url = $obs->putObject($save_file, $file_key);
        if ($url === false) {
            return [Upload::ERRCODE_UPLOAD_FAILED, '上传文件时发生错误', null];
        }

        $data = [
            'url' => $url,
            'path' => $path,
            'extension' => $suffix,
            'imagewidth' => $imagewidth,
            'imageheight' => $imageheight,
            'imagetype' => $suffix,
            'imageframes' => 0,
            'filesize' => $file->getInfo('size'),
            'mimetype' => Upload::getMimeType($save_file),
            'storage' => 'PingAn',
            'sha1' => hash_file('sha1', $save_file)
        ];
        unlink($save_file);
        return [0, '上传成功', $data];
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
        $config = $this->config;
        $ym = date('Ym');
        $dy = date('d');

        $orig_file = new File($file_path);
        $suffix = strtolower($orig_file->getExtension());
        $save_name = uniqid() . '.' . $suffix;
        $save_file = Env::get('runtime_path') . 'uploads/' . $save_name;
        copy($file_path, $save_file);

        $imagewidth = 0;
        $imageheight = 0;
        if (in_array($suffix, Config::get('upload.ext.image'))) {
            self::imagerotateAuto($save_file, null, true);
            $imgInfo = getimagesize($save_file);
            $imagewidth = $imgInfo[0] ?? 0;
            $imageheight = $imgInfo[1] ?? 0;
        }

        $file_key = $ym . '/' . $dy . '/' . $save_name;
        $path = $file_key;

        $obs = new Obs($config['accessKey'], $config['secretKey']);
        $obs->setInternalUpload($config['internalUpload']);
        $obs->setBucket($config['bucket']);
        $url = $obs->putObject($save_file, $file_key);
        if ($url === false) {
            return [Upload::ERRCODE_UPLOAD_FAILED, '上传文件时发生错误', null];
        }

        $data = [
            'url' => $url,
            'path' => $path,
            'extension' => $suffix,
            'imagewidth' => $imagewidth,
            'imageheight' => $imageheight,
            'imagetype' => $suffix,
            'imageframes' => 0,
            'filesize' => filesize($save_file),
            'mimetype' => Upload::getMimeType($save_file),
            'storage' => 'PingAn',
            'sha1' => hash_file('sha1', $save_file)
        ];
        unlink($save_file);
        return [0, '上传成功', $data];
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
        throw new \RuntimeException('暂未实现！');
    }

    /**
     * 分块上传：初始化
     * @param string|null $file_key 文件路径标识，不指定则自动生成
     * @param string|null $type     指定类型，指定 $file_key 后无效
     * @return string 返回文件路径标识
     */
    public function uploadLargeInit(string $file_key = null, string $type = null): string
    {
        throw new \RuntimeException('暂未实现！');
    }

    /**
     * 分块上传：上传块
     * @param string $file_key 文件路径标识
     * @param string $content  块内容
     */
    public function uploadLargePart(string $file_key, string $content)
    {
        throw new \RuntimeException('暂未实现！');
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
        throw new \RuntimeException('暂未实现！');
    }

    /**
     * 终止上传
     * @param string $file_key 文件路径标识
     */
    public function uploadLargeAbort(string $file_key)
    {
        throw new \RuntimeException('暂未实现！');
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
        $config = $this->config;

        if (empty($name)) {
            return [Upload::ERRCODE_UPLOADFILE_NOEXIST, '没有找到要上传的文件', []];  // 上传失败
        }
        try {
            self::$uploadFile = Request::file($name);
        } catch (Exception $e) {
            $code = $e->getCode();
            $code = $code ?: -1;
            return [$code, $e->getMessage(), []];
        }
        if (empty(self::$uploadFile)) {
            return [Upload::ERRCODE_UPLOADFILE_NOEXIST, '没有找到要上传的文件', []];  // 上传失败
        }

        $setting = [
            'size' => Upload::getAcceptableSize($type),
        ];

        self::$saveFile = self::$uploadFile
            ->validate($setting)
            ->move(Env::get('runtime_path') . 'uploads/', "{$file_uid}_{$total_blob_num}_{$blob_num}", true, false);
        if (!self::$saveFile) {
            $err = self::$uploadFile->getError();
            $err_size = [
                '没有上传的文件！',
                '上传文件大小不符！',
                '上传的文件超过了 php.ini 中 upload_max_filesize 选项限制的值！',
                '上传文件的大小超过了 HTML 表单中 MAX_FILE_SIZE 选项指定的值！'
            ];
            if (in_array($err, $err_size)) {
                return [Upload::ERRCODE_FIZESIZE_ERROR, $err, []]; // 文件大小不符合要求
            }
            // 其他情况返回errmsg
            return [Upload::ERRCODE_UPLOAD_FAILED, $err, []];  // 上传失败
        }
        if ($blob_num < $total_blob_num - 1) {
            return [Upload::SLICE_UPLOAD, '上传成功', []];
        }
        $blob = '';
        for ($i = 0; $i < $total_blob_num; $i++) {
            $blob .= file_get_contents(Env::get('runtime_path') . "uploads/{$file_uid}_{$total_blob_num}_{$i}");
        }

        $ym = date('Ym');
        $dy = date('d');
        $save_name = uniqid() . '.' . $suffix;
        $save_file = Env::get('runtime_path') . 'uploads/' . $save_name;
        $file = new Fso($save_file, true, true, 'w');
        $result = $file->write($blob);
        if ($result === false) {
            return [Upload::ERRCODE_UPLOAD_FAILED, '上传失败', []];
        }
        $file->close();
        for ($i = 0; $i < $total_blob_num; $i++) {
            unlink(Env::get('runtime_path') . "uploads/{$file_uid}_{$total_blob_num}_{$i}");
        }

        $file_key = $ym . '/' . $dy . '/' . $save_name;
        $path = $file_key;

        $obs = new Obs($config['accessKey'], $config['secretKey']);
        $obs->setInternalUpload($config['internalUpload']);
        $obs->setBucket($config['bucket']);
        $url = $obs->putObject($save_file, $file_key);
        if ($url === false) {
            return [Upload::ERRCODE_UPLOAD_FAILED, '上传文件时发生错误', null];
        }

        $data = [
            'url' => $url,
            'path' => $path,
            'extension' => $suffix,
            'imagetype' => $suffix,
            'imageframes' => 0,
            'filesize' => filesize($save_file),
            'mimetype' => Upload::getMimeType($save_file),
            'storage' => 'PingAn',
            'sha1' => hash_file('sha1', $save_file)
        ];
        unlink($save_file);
        return [0, '上传成功', $data];
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
        throw new \RuntimeException('暂未实现！');
    }

    /**
     * 返回已授权的URL
     * @param string $url     原URL
     * @param int    $expires 有效期(秒)，为0表示永久有效
     * @return string
     */
    public function getAuthorizedUrl(string $url, int $expires = 0): string
    {
        $config = $this->config;
        if ($config['private']) {
            $obs = new Obs($config['accessKey'], $config['secretKey']);
            $obs->setBucket($config['bucket']);
            $path = parse_url($url, PHP_URL_PATH);
            $path = substr($path, 1);  // 剔除第一个字符“/”
            $path = urldecode($path);
            $url = $obs->getSignedUrl($path);
        }
        return $url;
    }
}
