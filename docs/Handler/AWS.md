# AWS

命名空间：`Fize\Provider\Upload\Handler`

源码：`src/Handler/AWS.php`

亚马逊 AWS S3 处理器占位类。README 将其列为已支持平台，但类体为空，未实现 [`UploadHandler`](../UploadHandler.md) 的任何方法。

[← ALiYun](ALiYun.md) · [手册首页](../index.md) · [下一章：Azure →](Azure.md)

## 类声明

```php
class AWS extends UploadHandlerAbstract implements UploadHandler
{
}
```

可以 `new AWS()` 或 `UploadHandlerFactory::create('AWS')`，调用 `upload()` 等接口方法会因方法不存在而致命错误。

基类的 `setAllowExtensions()` / `setMaxSize()` / `setReplace()` 仍可调用，没有实际上传效果。

## 当前不可用

- 无构造配置
- 无 AWS SDK 引用（`composer.json` 亦未声明 `aws/aws-sdk-php`）
- 无单测文件

需要 S3 时请直接使用官方 SDK，或改用已实现的处理器（如 [Tencent](Tencent.md)、[ALiYun](ALiYun.md)）。

---

[← ALiYun](ALiYun.md) · [手册首页](../index.md) · [下一章：Azure →](Azure.md)
