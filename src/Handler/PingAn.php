<?php


namespace provider\upload\handler;

use Exception;
use provider\upload\Upload;
use think\facade\Config;
use think\facade\Env;
use think\facade\Request;
use think\File;
use think\Image;
use fuli\commons\util\io\File as Fso;
use third\pingan\api\Obs;

/**
 * 平安OBS
 * @todo 待整理，存在显见BUG，请勿使用！
 */
class PingAn extends Common
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

    public function __construct()
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
     * base64生成图片并保存，正确返回保存文件的相关信息
     * @param string $base64_image_content 图片base64串
     * @return array [$errcode, $errmsg, $data]
     */
    public function uploadBase64($base64_image_content)
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
     * 文件上传功能，正确返回保存文件的相关信息
     * @param string $file_path 服务器端文件路径
     * @return array [$errcode, $errmsg, $data]
     */
    public function uploadFile($file_path)
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
     * 多媒体上传大文件功能，正确返回保存文件的相关信息
     * @param string $name 文件域表单名
     * @param string $type 类型[image,flash,audio,video,media,file]
     * @return array [$errcode, $errmsg, $data]
     */
    public function uploadLargeMedia($name, $file_uid, $suffix, $blob_num, $total_blob_num, $type = null)
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
     * 返回已授权的预览URL
     * @param string $url 原URL
     * @return string
     */
    public function getPreviewUrl($url)
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
