<?php

namespace Fize\Provider\Upload;

/**
 * 文件上传统一接口
 */
interface UploadHandler
{

    /**
     * 初始化
     * @param array       $cfg         配置
     * @param array       $providerCfg provider设置
     * @param string|null $tempDir     临时文件存放文件夹目录
     */
    public function __construct(array $cfg = [], array $providerCfg = [], string $tempDir = null);

    /**
     * 设置允许上传的文件后缀名
     * @param string $extensions 后缀名，多个以逗号隔开。
     */
    public function allowExtensions(string $extensions);

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
    public function upload(string $name, ?string $type = null, ?string $file_key = null): array;

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
    public function uploads(string $name, ?string $type = null, ?array $file_keys = null): array;

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
    public function uploadFile(string $file_path, ?string $type = null, ?string $file_key = null): array;

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
    public function uploadBase64(string $base64_centent, ?string $type = null, ?string $file_key = null): array;

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
    public function uploadRemote(string $url, ?string $extension = null, ?string $type = null, ?string $file_key = null): array;

    /**
     * 分块上传：初始化
     * @param string|null $file_key 文件路径标识，不指定则自动生成
     * @param string|null $type     指定类型，指定 $file_key 后无效
     * @return string 返回文件路径标识
     */
    public function uploadLargeInit(?string $file_key = null, ?string $type = null): string;

    /**
     * 分块上传：上传块
     * @param string $file_key 文件路径标识
     * @param string $content  块内容
     */
    public function uploadLargePart(string $file_key, string $content);

    /**
     * 分块上传：完成上传
     * @param string      $file_key 文件路径标识
     * @param string|null $fname    原文件名
     * @param string|null $mimeType 指定Mime
     * @return array 返回保存文件的相关信息
     */
    public function uploadLargeComplete(string $file_key, ?string $fname = null, ?string $mimeType = null): array;

    /**
     * 分块上传：终止上传
     * @param string $file_key 文件路径标识
     */
    public function uploadLargeAbort(string $file_key);

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
     *
     * @return array
     */
    public function uploadLarge(string $name, int $blob_index, int $blob_count, ?string $file_key = null, ?string $extension = null, ?string $type = null): array;

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
    public function uploadParts(array $parts, ?string $extension = null, ?string $type = null, ?string $file_key = null): array;

    /**
     * 返回已授权的URL
     * @param string $url     原URL
     * @param int    $expires 有效期(秒)，为0表示永久有效
     * @return string
     */
    public function getAuthorizedUrl(string $url, int $expires = 0): string;
}
