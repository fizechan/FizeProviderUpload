# UploadHandler

命名空间：`Fize\Provider\Upload`

源码：`src/UploadHandler.php`

文件上传统一接口。所有处理器类均声明 `implements UploadHandler`。业务层应只依赖本接口，以便在本地与各云存储之间替换实现。

[← 手册首页](index.md) · [下一章：UploadHandlerAbstract →](UploadHandlerAbstract.md)

## 类声明

```php
interface UploadHandler
```

无常量、无属性。方法全部为实例方法。

## 构造

```php
public function __construct(array $cfg = [], array $providerCfg = [], string $tempDir = null);
```

| 参数 | 说明 |
|------|------|
| `$cfg` | 处理器自身配置（路径、Bucket、密钥等） |
| `$providerCfg` | 底层 SDK / Provider 配置，多数实现可忽略 |
| `$tempDir` | 分片与中转文件目录；为 `null` 时由各实现自行决定 |

接口注释标明 `@todo 待移除，每个处理器初始化方法不一定一样`。当前各实现仍保留该三参数形态，但默认值与必填项不同。工厂只传递 `$cfg`，见 [UploadHandlerFactory](UploadHandlerFactory.md)。

空类骨架 [AWS](Handler/AWS.md)、[Azure](Handler/Azure.md)、[Google](Handler/Google.md) 未声明构造函数，实例化后调用任一接口方法都会因方法不存在而致命错误。

## 运行时限制

### setAllowExtensions

```php
public function setAllowExtensions(string $extensions);
```

允许的后缀名，多个以英文逗号分隔，不含点号。`*` 表示不限制。比较时区分大小写，与 `in_array` 一致；多数实现会先把探测到的后缀转为小写，因此白名单建议写小写。

基类默认值为 `"*"`。

### setMaxSize

```php
public function setMaxSize(int $maxSize);
```

允许的文件大小。基类会写入 `$this->maxSize`，默认 `0`。**当前没有任何处理器在上传过程中读取该值。**

### setReplace

```php
public function setReplace(bool $replace = true);
```

是否覆盖已存在目标。仅 [Local](Handler/Local.md) 在写入前检查目标路径。云处理器通常由对象存储覆盖语义决定，不读该标志。

## 表单上传

### upload

```php
public function upload(string $name, ?string $key = null): array;
```

单文件上传。`$name` 为 `$_FILES` 中的表单字段名。`$key` 为保存路径标识，不传则由实现生成。

从全局请求取出 `UploadedFile`，再交给各实现的 `handleUpload()`（若有）。字段不存在时基类抛出 `Fize\Exception\FileException`。

### uploads

```php
public function uploads(string $name, ?array $keys = null): array;
```

同一字段名下的多文件上传。`$keys[$index]` 与文件下标对应，缺省则该文件自动生成 key。返回“每个文件信息数组”组成的列表。

## 非表单上传

### uploadFile

```php
public function uploadFile(string $filePath, ?string $key = null): array;
```

把服务器本地文件复制或上传到目标存储。`$filePath` 必须是当前进程可读的路径。

### uploadBase64

```php
public function uploadBase64(string $base64Centent, ?string $key = null): array;
```

参数名在源码中拼写为 `base64Centent`。内容须匹配：

```text
data:{mime};base64,{payload}
```

例如 `data:image/jpeg;base64,/9j/4AAQ...`。实现用正则取出 MIME，再经 `Fize\IO\MIME::getExtensionByMime()` 推断后缀。格式不符或无法识别后缀时抛 `FileException`。

### uploadRemote

```php
public function uploadRemote(string $url, ?string $key = null): array;
```

用 `file_get_contents($url)` 拉取远程文件后转存。无法下载时抛 `FileException`。云实现一般先写入临时文件再调用 `uploadFile()`，成功后删除临时文件，返回中以 `orig_url` 替代 `orig_name`。

## 分块上传

### uploadLargeInit

```php
public function uploadLargeInit(?int $blobCount = null, ?string $key = null, ?string $uuid = null): string;
```

初始化分块任务，返回后续步骤使用的 `$uuid`。

| 参数 | 说明 |
|------|------|
| `$blobCount` | 分片总数，建议传入，完成时可校验是否收齐 |
| `$key` | 最终对象路径；不传则完成阶段再定 |
| `$uuid` | 任务 ID；不传则 `uniqid()` |

若该 `$uuid` 已有临时信息，实现应清理旧分片后重新初始化。

**实现差异：** [Local](Handler/Local.md)、[Tencent](Handler/Tencent.md) 与接口三参数一致。ALiYun、BaiDu、HuaWei、PingAn、QiNiu 的实现为 `(?int $blobCount = null, ?string $uuid = null)`，没有 `$key`。

### uploadLargePart

```php
public function uploadLargePart(string $uuid, string $content, ?int $blobIndex = null): array;
```

上传一块二进制内容。`$blobIndex` 建议从 0 递增指定；不传时部分实现用已有分片数量作为下标。返回当前临时信息，不是最终文件信息。

### uploadLargeComplete

```php
public function uploadLargeComplete(string $uuid, ?string $key = null, ?string $extension = null): array;
```

合并分片并写入最终存储，删除临时文件与 `{uuid}.json`。若初始化时指定了 `$blobCount` 且已上传数量不符，本地类实现会抛 `RuntimeException('分片未全部上传！')`。

**实现差异：** Local、Tencent 保留 `$key`。其余已实现处理器为 `(string $uuid, ?string $extension = null)`。

### uploadLargeAbort

```php
public function uploadLargeAbort(string $uuid);
```

中止任务：删除本地分片和/或调用云厂商 `abortMultipartUpload`，并删除临时 JSON。无返回值。

### uploadLarge

```php
public function uploadLarge(string $name, int $blobIndex, ?int $blobCount = null, ?string $extension = null, ?string $uuid = null): array;
```

面向表单的分片入口，从字段 `$name` 读取当前块。

| `$blobIndex` | 行为 |
|--------------|------|
| `-1` | 调用 `uploadLargeInit`，返回临时信息 |
| `-2` | 调用 `uploadLargeComplete`；`$uuid` 为空则抛 `RuntimeException('请指定参数uuid。')` |
| 其他 | 若尚无任务则先 init，再 `uploadLargePart`；分片已齐则自动 complete |

`$uuid` 为空时使用 `session_id() . "_upload_" . $name`。

各云实现里这段编排代码高度相似，但完成条件常写成 `count($info['parts'])`，而 Multipart 类实现的临时信息里可能没有 `parts` 键，自动完成不可靠。宜显式使用 `-1` / 分片 / `-2` 三步。

### uploadParts

```php
public function uploadParts(array $parts, ?string $extension = null, ?string $uuid = null): array;
```

`$parts` 为分片内容数组（下标即 `blobIndex`）。内部执行一次完整的 init → 逐块 part → complete，适合服务端已经拿齐全部分片的场景。

## 访问 URL

### getAuthorizedUrl

```php
public function getAuthorizedUrl(string $key, int $expires = 0): string;
```

返回可访问地址。`$expires` 为有效期秒数，`0` 表示永久（部分云实现会改写成一个很大的秒数，因厂商不支持真正永久签名）。

接口参数名是 `$key`（路径标识）。[Local](Handler/Local.md) 实际拼接 `domain + $key`；[Tencent](Handler/Tencent.md) 按对象 key 签名。ALiYun、BaiDu、HuaWei、PingAn、QiNiu 把第一个参数当作**完整 URL**，再 `parse_url` 取出 path。混用时务必看对应章节。

## 返回值与异常

成功时的数组字段见 [手册首页 · 统一返回结构](index.md#统一返回结构)。

常见异常：

| 类型 | 典型原因 |
|------|----------|
| `Fize\Exception\FileException` | 找不到表单文件、后缀禁止、写盘失败、远程拉取失败、云 SDK 返回错误 |
| `RuntimeException` | 分片未齐、完成时缺少 uuid、部分处理器配置不完整 |
| `Exception` | 基类 `assertHasKey()`：分片 JSON 缺少必要字段 |

接口本身不声明 `@throws`，调用方应按实现捕获。

## 实现清单

| 类 | 是否完整实现本接口 |
|----|--------------------|
| `Handler\Local` | 是 |
| `Handler\ALiYun` | 方法齐全，签名与分块细节有偏差 |
| `Handler\BaiDu` | 同上，init 存在未定义变量 |
| `Handler\HuaWei` | 同上，Multipart 存在未定义变量 |
| `Handler\PingAn` | 方法齐全，但客户端未保存，实际上传不可用 |
| `Handler\QiNiu` | 方法齐全，分块为本地合并后再整体上传 |
| `Handler\Tencent` | 方法齐全，签名与接口 `$key` 一致 |
| `Handler\AWS` / `Azure` / `Google` | 否，空类 |

## 相关章节

- [UploadHandlerAbstract](UploadHandlerAbstract.md) — 默认实现与受保护工具方法
- [UploadHandlerFactory](UploadHandlerFactory.md) — 按短类名创建处理器

---

[← 手册首页](index.md) · [下一章：UploadHandlerAbstract →](UploadHandlerAbstract.md)
