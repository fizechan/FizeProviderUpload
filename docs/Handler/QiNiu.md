# QiNiu

命名空间：`Fize\Provider\Upload\Handler`

源码：`src/Handler/QiNiu.php`

七牛云对象存储处理器。类注释写作“七牛云 OBS”，实际对接的是七牛 `Qiniu\Auth` + `Qiniu\Storage\UploadManager`（Kodo）。普通上传直接 `putFile`；分块先在本地合并，再整体上传。上传 Token 缓存在 `fize/cache` 中。

[← PingAn](PingAn.md) · [手册首页](../index.md) · [下一章：Tencent →](Tencent.md)

## 类声明

```php
class QiNiu extends UploadHandlerAbstract implements UploadHandler
```

| 属性 | 类型 | 说明 |
|------|------|------|
| `$cache` | `Fize\Cache\CacheInterface` | Token 缓存 |

## 依赖

```bash
composer require qiniu/php-sdk
```

另需运行时依赖 `fize/cache`（本包已 require）。开发约束示例：`qiniu/php-sdk: ^7.14.0`。

## 构造与配置

```php
public function __construct(array $cfg = [], array $providerCfg = [], string $tempDir = null)
```

`$providerCfg`、`$tempDir` 未使用。`$tempDirPath` 保持 `./temp`。缓存：

```php
$cacheHandler = $cfg['cache_handler'] ?? 'File';
$cacheConfig  = $cfg['cache_config'] ?? [];
$this->cache  = CacheFactory::create($cacheHandler, $cacheConfig);
```

上传使用的键：

| 键 | 用途 |
|----|------|
| `accessKey` / `secretKey` | 七牛密钥 |
| `bucket` | 空间名 |
| `domain` | 拼 URL |
| `version` | `UploadManager::putFile` 的版本参数 |
| `uploadToken.cacheKey` | Token 缓存键 |
| `uploadToken.expires` | Token 有效期（秒）；写入缓存时减 600 秒提前失效 |
| `cache_handler` / `cache_config` | `fize/cache` 驱动 |
| `private` | 私有下载 URL |
| `tempDir` / `rootPath` | 中转文件与 path |

```php
use Fize\Provider\Upload\Handler\QiNiu;

$uploader = new QiNiu([
    'accessKey'     => 'your-access-key',
    'secretKey'     => 'your-secret-key',
    'bucket'        => 'your-bucket',
    'domain'        => 'https://cdn.example.com',
    'version'       => 'v2',
    'private'       => false,
    'tempDir'       => sys_get_temp_dir(),
    'uploadToken'   => [
        'cacheKey' => 'qiniu-upload-token',
        'expires'  => 3600,
    ],
    'cache_handler' => 'File',
    'cache_config'  => ['path' => sys_get_temp_dir() . '/qiniu-cache'],
]);
```

## 上传 Token

`getUploadToken()`（protected）：

1. 若缓存无 `uploadToken.cacheKey`，则 `Auth::uploadToken($bucket)`。
2. TTL 为 `uploadToken.expires - 600`（提前 10 分钟过期）。
3. 返回缓存中的 Token。

`uploadToken` 结构缺失时会在读数组键时出错。多机部署需把 `cache_handler` 换成共享缓存，否则各机器各自签发。

## 普通上传

统一模式：

```php
$token = $this->getUploadToken();
$uploadMgr = new UploadManager();
[$ret, $err] = $uploadMgr->putFile(
    $token,
    $key,
    $filePath,
    null,
    'application/octet-stream',
    false,
    null,
    $this->cfg['version']
);
if ($err !== null) {
    throw new FileException($err);
}
```

`uploadFile` 在 `$key` 为空时生成日期路径。返回含 `extend.ret`（七牛响应）。

`uploadBase64` 先写 `tempDir` 再 `putFile`，然后删除临时文件。

`uploadRemote` 下载后走 `uploadFile`。

`handleUpload` 对表单临时文件 `putFile` 后 `unlink($tmpName)`。`$key` 为 null 时传给七牛的 key 为空，应显式指定。

## 分块上传

**不使用七牛分片 API。** 流程与 [Local](Local.md) 相同：分片写入 `$tempDirPath`，complete 时按序合并到 `getPathInfo()` 给出的临时文件，再 `putFile` 到七牛，最后删除分片、JSON 和合并文件。

### 签名差异

`uploadLargeInit($blobCount, $uuid)`、`uploadLargeComplete($uuid, $extension)`，无 `$key`。

init 写入 `uuid`、`blobCount`、`parts`。part 逻辑与 Local 一致。complete 使用：

```php
[$key, $name, $targetPath] = $this->getPathInfo(null, $extension);
```

即**总是**按 `null` key 在 `$tempDirPath` 下生成合并文件名，再把该路径既当本地文件又当七牛 key（`getPathInfo` 在 key 为空时把 `$sdir = $this->tempDirPath` 编进 key）。最终对象 key 可能含本地临时目录前缀，调用方难以预测。

`getPathInfo` 在 `$key` 非空的分支里使用未定义的 `$dir`（只在 else 里赋值），当前 complete 走的是 `$key === null` 分支，暂未触发。

`uploadLarge()` 的自动完成条件看 `parts`，与本实现一致，相对 ALiYun/BaiDu 更可用。但仍受 `$tempDirPath` 默认 `./temp`、以及 complete 忽略最终对象 key 的限制。

## getAuthorizedUrl

```php
public function getAuthorizedUrl(string $url, int $expires = 0): string
```

`$expires === 0` 时改为 `100 * 365 * 24 * 3600`（约 100 年）。`$cfg['private']` 为真时：

```php
$auth = new Auth($cfg['accessKey'], $cfg['secretKey']);
$url  = $auth->privateDownloadUrl($url, $expires);
```

第一个参数必须是**可下载的完整 URL**，不是对象 key。公开空间直接返回原 URL。

## 实现备注

- Token 缓存是本处理器独有设计，注意 `expires - 600` 不要小于等于 0。
- 大文件分块会在应用服务器上完整拼一份临时文件，磁盘与内存压力大于真正的云端 Multipart。
- 工厂 `create('QiNiu', $cfg)` 可用。
- 部分旧测试仍调用 `uploadFromUrl` / `uploadLargeParts` / `getPreviewUrl`，这些方法在当前类中已不存在。

## 相关章节

- [Local](Local.md) — 分片文件命名与合并顺序
- [Tencent](Tencent.md) — 同样先本地合并再上传云端

---

[← PingAn](PingAn.md) · [手册首页](../index.md) · [下一章：Tencent →](Tencent.md)
