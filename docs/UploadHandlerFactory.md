# UploadHandlerFactory

命名空间：`Fize\Provider\Upload`

源码：`src/UploadHandlerFactory.php`

文件上传工厂。按 Handler 短类名解析 `Fize\Provider\Upload\Handler\{$handler}`，支持单例注册与一次性创建。

[← UploadHandlerAbstract](UploadHandlerAbstract.md) · [手册首页](index.md) · [下一章：Local →](Handler/Local.md)

## 类声明

```php
class UploadHandlerFactory
```

全部为静态方法。不实例化。

## 属性

```php
private static $handlers;
```

以处理器短类名为键、实例为值的数组。注释写明“使用数组使每个驱动不互相影响”，即 `Local` 与 `ALiYun` 可同时各持一个单例。

同一 `$handler` 再次 `setInstance` 会覆盖旧实例。进程内无销毁/重置方法。

## 方法

### setInstance

```php
public static function setInstance(string $handler, array $config = []): void
```

构造并缓存单例：

```php
$class = '\\' . __NAMESPACE__ . '\\Handler\\' . $handler;
self::$handlers[$handler] = new $class($config);
```

只把 `$config` 传给构造函数第一个参数。`$providerCfg`、`$tempDir` 均为默认值。

### getInstance

```php
public static function getInstance(string $handler): UploadHandler
```

返回已注册单例。未注册时：

```text
InvalidArgumentException: Handler '{handler}' not found.
```

不会自动 `setInstance`。必须先设置再获取。

### create

```php
public static function create(string $handler, array $config = []): UploadHandler
```

每次 `new $class($config)`，**不写入** `$handlers`。与单例相互独立。

## `$handler` 合法值

必须与 `src/Handler` 下的类名完全一致（大小写敏感）：

| `$handler` | 类 |
|------------|-----|
| `Local` | `Handler\Local` |
| `ALiYun` | `Handler\ALiYun` |
| `AWS` | `Handler\AWS` |
| `Azure` | `Handler\Azure` |
| `BaiDu` | `Handler\BaiDu` |
| `Google` | `Handler\Google` |
| `HuaWei` | `Handler\HuaWei` |
| `PingAn` | `Handler\PingAn` |
| `QiNiu` | `Handler\QiNiu` |
| `Tencent` | `Handler\Tencent` |

传入 `'local'`、`'Aliyun'`、`'OSS'` 等会导致类不存在的致命错误，而不是 `InvalidArgumentException`。

## 使用示例

```php
use Fize\Provider\Upload\UploadHandlerFactory;

$cfg = [
    'rootPath' => dirname(__DIR__),
    'saveDir'  => '/uploads',
    'domain'   => 'https://static.example.com',
];

// 一次性
$uploader = UploadHandlerFactory::create('Local', $cfg);

// 单例
UploadHandlerFactory::setInstance('Local', $cfg);
$same = UploadHandlerFactory::getInstance('Local');
```

测试中会先注入 HTTP 请求，因为 Local 构造函数在 `domain` 为空时会调用 `Fize\Web\Request::domain()`：

```php
use Fize\Http\ServerRequest;
use Fize\Http\ServerRequestFactory;

$request = new ServerRequest('POST', '//www.example.com/upload');
ServerRequestFactory::setGlobals($request);
UploadHandlerFactory::setInstance('Local', ['rootPath' => __DIR__ . '/tmp']);
```

## 限制

1. **无法传递 `$providerCfg`。** [Tencent](Handler/Tencent.md) 要求 `providerCfg['region']` 非空，经工厂创建会立即 `RuntimeException`。应 `new Tencent($cfg, $providerCfg)`。
2. **无法传递 `$tempDir`。** 依赖第三参数的处理器只能直接 `new`。
3. **不做类存在性检查。** 错误类名在 `new` 时失败。
4. **空实现也能注册。** `setInstance('AWS')` 会成功，直到调用上传方法才暴露未实现。
5. **单例与配置绑定在第一次 `setInstance`。** 改配置需再次 `setInstance`。

## 相关章节

- [Local](Handler/Local.md) — 最适合用工厂创建的处理器
- [Tencent](Handler/Tencent.md) — 需要两段配置，不宜只用本工厂

---

[← UploadHandlerAbstract](UploadHandlerAbstract.md) · [手册首页](index.md) · [下一章：Local →](Handler/Local.md)
