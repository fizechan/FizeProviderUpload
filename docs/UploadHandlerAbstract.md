# UploadHandlerAbstract

命名空间：`Fize\Provider\Upload`

源码：`src/UploadHandlerAbstract.php`

文件上传抽象基类。不实现 [`UploadHandler`](UploadHandler.md)（也未标 `abstract` 方法去对应接口），只提供各 Handler 共用的状态与工具方法。具体存储逻辑由子类完成。

[← UploadHandler](UploadHandler.md) · [手册首页](index.md) · [下一章：UploadHandlerFactory →](UploadHandlerFactory.md)

## 类声明

```php
abstract class UploadHandlerAbstract
```

当前所有处理器均 `extends UploadHandlerAbstract implements UploadHandler`。

依赖：`Fize\Codec\Json`、`Fize\Exception\FileException`、`Fize\Http\ServerRequestFactory`、`Fize\Http\UploadedFile`、`Fize\IO\File`。

## 属性

| 属性 | 可见性 | 默认值 | 说明 |
|------|--------|--------|------|
| `$providerCfg` | protected array | 未在基类初始化 | Provider / SDK 配置，仅少数子类写入 |
| `$cfg` | protected array | 未在基类初始化 | 处理器配置，由子类构造函数 `array_merge` 后赋值 |
| `$tempDirPath` | protected string | `'./temp'` | 分片 JSON 与临时块文件目录 |
| `$allowExtensions` | protected string | `'*'` | 后缀白名单 |
| `$maxSize` | protected int | `0` | 大小上限（未被上传逻辑使用） |
| `$replace` | protected bool | `false` | 是否覆盖已存在文件 |

基类**没有构造函数**。`$cfg`、`$tempDirPath` 必须由子类赋值，否则分片读写会落到默认 `./temp`。

## 公开方法

这三项同时满足接口，子类一般不必重写。

### setAllowExtensions

```php
public function setAllowExtensions(string $extensions)
```

直接赋值 `$this->allowExtensions`。不会做去空格或小写规范化。

### setMaxSize

```php
public function setMaxSize(int $maxSize)
```

赋值 `$this->maxSize`。单位由调用方约定（源码未写明，按字节理解）。上传路径不读取该字段。

### setReplace

```php
public function setReplace(bool $replace = true)
```

赋值 `$this->replace`。默认参数为 `true`，因此 `setReplace()` 无参即允许覆盖。接口默认值与此一致。

## 受保护方法

### getSaveDir

```php
protected function getSaveDir(?string $type = null): string
```

生成日期目录：

- `$type` 为空：`Ym/d`，如 `202609/15`
- `$type` 有值：`{type}/Ym/d`

注释 `@todo 考虑移除 $type 参数`。当前正规上传路径很少再传 `$type`；历史方法 `uploadParts1()` 仍会用到。

### generateKey

```php
protected function generateKey(string $name = null, string $extension = null): string
```

未指定 `$name` 时用 `uniqid()`，有后缀则拼 `.{extension}`。最终 key 为 `getSaveDir() . '/' . $name`。

[Tencent](Handler/Tencent.md) 在 `$key` 为空时调用本方法。Local 使用自己的 `getPathInfo()`，不走这里。

### checkExtension

```php
protected function checkExtension(string $extension): void
```

`$allowExtensions === '*'` 时直接返回。否则 `explode(',', …)` 后 `in_array`。不在列表中抛出：

```text
Fize\Exception\FileException: 禁止上传后缀名为{extension}的文件
```

### getUploadedFiles

```php
protected function getUploadedFiles(?string $name = null): array
```

`ServerRequestFactory::createServerRequestFromGlobals()` → `getUploadedFiles()`。`$name` 有值时取该字段，不存在则返回空数组（不会抛错）。`uploads()` 依赖此行为。

### getUploadedFile

```php
protected function getUploadedFile(string $name): UploadedFile
```

从**全部**上传字段中按名取值（注意：内部调用的是无参 `getUploadedFiles()`，不是带 `$name` 的版本）。不存在则：

```text
FileException: 找不到文件：{name}
```

### assertHasKey

```php
protected static function assertHasKey(array $array, string $key): void
```

`isset($array[$key])` 失败时抛 `\Exception("缺少必要参数：{$key}")`。分块流程用它检查临时 JSON 是否含 `parts`、`uploadId` 等。

## 分片临时信息

三个方法操作同一个文件：`{tempDirPath}/{uuid}.json`。内容为 JSON 对象，读写使用 `Fize\IO\File` 与 `Fize\Codec\Json`。

### getPartUploadInfo

```php
protected function getPartUploadInfo(string $uuid): array
```

文件不存在或内容为空时返回 `[]`。存在则 `Json::decode`。

### savePartUploadInfo

```php
protected function savePartUploadInfo(string $uuid, array $keyValues): void
```

先读旧内容，`array_merge` 后整文件覆盖写入。写入前 `flock(LOCK_EX)`。同 uuid 并发写入仍可能互相覆盖，只适合单进程顺序分片。

`array_merge` 对列表数组是重索引追加，对关联数组才是按键覆盖。`ETags` 一类列表若整段传入，会与旧数组合并成更长列表，调用方应传入完整新列表或接受追加语义。

### deletPartUploadInfo

```php
protected function deletPartUploadInfo(string $uuid): void
```

方法名缺少 e（`delet` 而非 `delete`）。`unlink` 对应 JSON 文件。文件不存在时会触发 PHP 警告。完成或中止后各处理器会调用它。

临时 JSON 典型内容（本地风格）：

```json
{
  "uuid": "68c8a1b2c3d4e",
  "key": null,
  "blobCount": 3,
  "parts": {
    "BLOB-0": "68c8a1b2c3d4e-3-0.tmp",
    "BLOB-1": "68c8a1b2c3d4e-3-1.tmp"
  }
}
```

OSS/BOS 风格还会包含 `uploadId`、`ETags`。

## 子类职责

基类不实现任何实际上传。子类至少需要：

1. 在构造函数中合并 `$cfg`，设置 `$tempDirPath`（若使用分片）。
2. 实现接口中的上传、分块、签名 URL 方法。
3. 在写文件前调用 `checkExtension()`。
4. 分块期间使用上述三个临时信息方法，而不是另起一套状态存储（七牛的上传 Token 缓存除外）。

## 相关章节

- [UploadHandler](UploadHandler.md) — 接口契约
- [Local](Handler/Local.md) — 对基类工具方法使用最完整的实现

---

[← UploadHandler](UploadHandler.md) · [手册首页](index.md) · [下一章：UploadHandlerFactory →](UploadHandlerFactory.md)
