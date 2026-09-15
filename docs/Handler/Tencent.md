# Tencent

命名空间：`Fize\Provider\Upload\Handler`

源码：`src/Handler/Tencent.php`

腾讯云 COS 处理器。内部持有 `Qcloud\Cos\Client`。普通上传 `putObject`；分块先在本地合并，再调用 SDK 的 `Upload()`（内部可走分片）。`getAuthorizedUrl` 的第一个参数按对象 **key** 处理，与接口一致。

[← QiNiu](QiNiu.md) · [手册首页](../index.md)

## 类声明

```php
class Tencent extends UploadHandlerAbstract implements UploadHandler
```

| 属性 | 类型 | 说明 |
|------|------|------|
| `$cosClient` | `Qcloud\Cos\Client` | 用 `$providerCfg` 构造 |

## 依赖

```bash
composer require qcloud/cos-sdk-v5
```

开发约束示例：`qcloud/cos-sdk-v5: ^2.6.16`。

## 构造与配置

```php
public function __construct(array $cfg = [], array $providerCfg = [], string $tempDir = null)
```

`$providerCfg['region']` 为空时立即抛出：

```text
RuntimeException: providerCfg参数不能为空
```

随后 `new Client($providerCfg)`。整份 `$providerCfg` 会交给 COS SDK，通常包括：

| `providerCfg` 键 | 说明 |
|------------------|------|
| `region` | 必填，如 `ap-shanghai` |
| `scheme` | 如 `https` |
| `credentials.secretId` | 密钥 |
| `credentials.secretKey` | 密钥 |

`$cfg` 默认空数组，使用：

| `cfg` 键 | 用途 |
|----------|------|
| `bucket` | 桶，格式多为 `{name}-{appid}` |
| `tempDir` | 第三参数为空时的临时目录，默认 `'./temp'` |
| `domain` | 仅分块 complete 拼 URL 时使用 |
| `rootPath` | 分块 complete 计算 `path` 时使用 |

`$tempDir` 参数优先，否则 `$cfg['tempDir'] ?? './temp'`，写入 `$this->tempDirPath`（**不再**拼 `rootPath`）。

```php
use Fize\Provider\Upload\Handler\Tencent;

$cfg = [
    'bucket'  => 'example-1250000000',
    'tempDir' => sys_get_temp_dir(),
];
$providerCfg = [
    'region'      => 'ap-shanghai',
    'scheme'      => 'https',
    'credentials' => [
        'secretId'  => 'your-secret-id',
        'secretKey' => 'your-secret-key',
    ],
];
$uploader = new Tencent($cfg, $providerCfg);
```

不要使用 `UploadHandlerFactory::create('Tencent', $cfg)`：工厂无法传入 `$providerCfg`，构造阶段就会失败。

## 普通上传

### uploadFile

`$key` 为空时 `$this->generateKey(null, $extension)`，即 `{Ym}/{d}/{uniqid}.{ext}`。

```php
$result = $this->cosClient->putObject([
    'Bucket' => $this->cfg['bucket'],
    'Key'    => $key,
    'Body'   => fopen($filePath, 'rb'),
]);
```

返回 `key`、`name`、`size`、`mime`、`extension`、`sha1`、`dir`、`orig_name`、`orig_path`、`extend`。**没有 `path`、`url`。**

### uploadBase64

解码到 `$tempDirPath/{uniqid}.{ext}`，`$key` 默认 `generateKey($name, $extension)`（注意：传入了已含后缀的 `$name`，`generateKey` 仍会把它当作完整文件名）。`putObject` 后删除临时文件。返回无 `path`/`url`，有 `extend`。

### uploadRemote

下载到 `$tempDirPath`，调用 `uploadFile`，删除临时文件，去掉 `orig_name`，加上 `orig_url`。

### upload / uploads → handleUpload

校验后若 `$key` 为空则 `generateKey(null, $extension)`，再 `putObject`。非测试上传会 `unlink($tmpName)`；`UploadedFile::isForTest()` 为真时保留文件（便于 PHPUnit）。返回无 `path`/`url`，有 `extend`。

这是各云处理器里表单上传对 `$key === null` 处理最完整的一个。

## 分块上传

本地拼片 + COS `Upload()`。签名与接口一致，含 `$key`：

```php
uploadLargeInit(?int $blobCount = null, ?string $key = null, ?string $uuid = null): string
uploadLargeComplete(string $uuid, ?string $key = null, ?string $extension = null): array
```

### uploadLargeInit

有旧任务则删除 `parts` 中路径并清 JSON。写入 `uuid`、`blobCount`、`parts`。**传入的 `$key` 没有写入临时信息**（与 Local 不同）。返回 `$uuid`。

### uploadLargePart

与 Local 相同：`{uuid}[-{blobCount}]-{blobIndex}.tmp` 写在 `$tempDirPath`。

### uploadLargeComplete

校验 `parts` 与可选 `blobCount`。然后：

```php
[$key, $name, $targetPath] = $this->getPathInfo(null, $extension);
```

**丢弃方法参数 `$key`**，总是在临时目录生成合并文件。合并后：

```php
$result = $this->cosClient->Upload($this->cfg['bucket'], $key, fopen($targetPath, 'rb'));
```

此处 `$key` 来自 `getPathInfo(null, …)`，通常是 `{tempDirPath}/{uniqid}[.{ext}]`，对象键会带上本地临时路径前缀。

返回含 `path`、`url`（`domain + path`）、`uuid`、`full_path`、`extend`。`path` 用 `rootPath` 从 `$targetPath` 裁剪，这两项若未配置则 URL 无意义。随后删除分片、JSON 和合并文件。

### uploadLargeAbort

只删本地分片与 JSON，不调用 COS Abort（因为尚未对 COS 建 Multipart）。

### uploadLarge

`$blobIndex == -2` 时执行 `uploadLargeComplete($uuid, $extension)`，后缀被传到 `$key` 参数，而 complete 内部又忽略 `$key`。与 Local 一样，`-2` 不适合用来传后缀。

自动完成条件使用 `parts`，与本实现一致。

### uploadParts

标准 init → part → complete。

### getPathInfo

与 QiNiu 类似：key 为空时把 `$tempDirPath` 编进 key；key 非空分支引用 `$dir`（仅 else 赋值）。complete 走 null key 分支。

## getAuthorizedUrl

```php
public function getAuthorizedUrl(string $key, int $expires = 0): string
```

`$expires === 0` 时改为约 100 年。始终：

```php
return $this->cosClient->getObjectUrl($cfg['bucket'], $key, "+{$expires} seconds");
```

与接口一致，传入对象 key，例如 `202609/15/68c8a1b2c3d4e.php`。不读取 `private` 开关。

## 与其他处理器的差异

| 点 | Tencent | 多数其他云实现 |
|----|---------|----------------|
| 空 `$key` 的表单上传 | `generateKey()` | 直接把 null 传给 SDK |
| 工厂创建 | 不可用（缺 `providerCfg`） | 一般可用 |
| 签名 URL 参数 | 对象 key | 完整 URL |
| 普通上传返回 `url` | 无 | 通常有 |
| 测试上传删临时文件 | `isForTest()` 时保留 | 直接 unlink |

## 相关章节

- [UploadHandlerFactory](../UploadHandlerFactory.md) — 为何不能只用工厂
- [Local](Local.md) — 分片文件与 `uploadLarge(-2)` 的参数错位
- [QiNiu](QiNiu.md) — 同样先合并再整体上传

---

[← QiNiu](QiNiu.md) · [手册首页](../index.md)
