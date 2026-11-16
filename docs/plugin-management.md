# 使用和集成指南

## 安装器升级

需要 PHP 8.1+、PDO、sodium、curl，以及 SaiAdmin 6 的 Phinx/ThinkORM 环境。管理表支持 MySQL/PostgreSQL。先在开发环境备份数据库、runtime/saipackage 载荷和配置主密钥，再更新 Composer 包及 plugin/saipackage 工作副本。本仓库的 src/Install.php 保留原有复制流程；采用 --no-scripts 部署的宿主必须显式同步工作副本，不能只更新 vendor。

1. 在宿主 server/.env 配置稳定的 SAIPACKAGE_SETTINGS_KEY（base64 编码的随机 32 字节；使用 php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;' 生成）。不要把密钥提交到版本库，也不要随容器重建重新生成。
2. 在管理功能尚未启用时执行 `php webman saipackage:setup`。命令运行独立迁移、导入已有 state=1 的插件记录，不重新执行业务插件 SQL。该命令可重复运行。
3. 若存在官方 6.x 前端，命令会复制管理组件并在原插件页嵌入 ManagementPanel；自定义前端按下文手动集成。
4. 设置 `SAIPACKAGE_MANAGEMENT_ENABLED=1`，重启所有后端进程，确认存在 plugin.saipackage.management worker。单机部署只启用一个调度实例，所有变更入口共享同一个持久 runtime 及 flock 锁。多机调度不在当前实现范围。
5. 构建并部署前端，打开原“插件市场”页面。生产环境关闭 debug。

不能在仍有安装任务时关闭管理功能，否则旧入口将回到旧安装流程。回退前先停止管理写操作并处理所有活动任务。管理数据库和密钥必须一起备份，只有数据库无法恢复加密配置。

## 插件作者

参考 examples/logistics_ocr/manifest.json 和 settings.schema.json。这里只是元数据示例，不是可安装业务插件。ZIP 根目录包含 info.ini、config.json、新声明和 docs；业务代码仍放 plugin/<app>，前端仍放 <FRONTEND_DIR>/src/views/plugin/<app>。

标识沿用安装器规则，使用 logistics_ocr 而非 logistics-ocr。版本必须为 SemVer，清单与 info.ini 一致。requiresPlugins 和 conflictsPlugins 使用 Composer Semver 约束。requiresHost 可声明 php/saiadmin/saipackage 约束。第一版每个插件保存一个上传候选包，解析器同时考虑已安装版本和该候选版本；后台版本检测不下载；管理员手动请求在线下载并预览后，才准备候选包，详见 [在线更新](online-updates.md)。

配置必须显式声明 properties；不支持 $ref、条件及组合 schema，确保表单、校验和密钥脱敏使用同一套字段。支持 object 分组和 string/integer/number/boolean 叶字段，以及 enum、required、格式和范围等标准校验。敏感值必须在叶字段设置 writeOnly=true，禁止在 default 中包含凭据。实例级为当前可用作用域；租户配置暂不开放，声明 tenant 会明确拒绝，不能从请求传入 tenantId 绕过隔离。未来租户适配需同时提供可信身份解析和授权。

配置默认值在保存时补齐。ui.fields.<path>.connection=false 表示该字段变化不要求重新验证外部服务，默认所有字段影响连接。schema 变化会使旧验证失效。For writeOnly fields use secretActions with keep, replace or clear; values must never contain credentials.

## 外部服务

用户部署服务后填写后端可达 endpoint。Docker 内的 localhost 指后端容器自身；不同容器可用共享网络的服务名，远程服务填写可达域名/IP。插件卸载不停止服务，不删除容器和卷。

内置 json-health 探测器只读调用 endpoint/health，最多 5 秒、响应最多 64KB，不跟随重定向。服务协议示例：

```json
{"version":"2.3.1","capabilities":["recognize","table"]}
```

通过 apiKey 发送 Bearer 凭据；服务声明的 version 和 capabilities 都满足才返回 OK。自定义协议实现 ServiceProbe，在插件自身 config/saipackage.php 注册：

```php
return ['probes' => ['ocr' => \plugin\logistics_ocr\app\service\OcrProbe::class]];
```

manifest 的 probe 填 logistics_ocr:ocr。检测器自行保证网络超时，只调用只读接口，不返回响应原文或凭据。安装器只保留固定结果码、延迟和受限版本文本。后台每轮最多检测一个插件；有多个服务的插件应控制单次总耗时。

手动测试当前输入不会产生启用凭证；保存后点击“验证已保存配置”才记录验证。配置修订在检测期间变化时拒绝保存结果。服务中断改变健康状态，保留管理员启用选择；恢复检测成功后自动就绪。

## 运行保护及卸载

全局 PluginReady 中间件保护已纳管插件的 HTTP 控制器和按插件注册的路由。自行放到主应用命名空间的路由需显式 guard。队列和定时任务在进入业务逻辑前调用：

```php
$manager = \Saithink\Saipackage\management\Factory::manager();
(new \Saithink\Saipackage\management\RuntimeGuard($manager->store, $manager->settings))
    ->assertReady('logistics_ocr');
```

工作进程不能跨请求缓存身份或明文配置。配置通过 $manager->settings->raw('logistics_ocr')['values'] 在后端获取，不能返回到客户端。

卸载先检查反向依赖，再调用 management.php 中的 drain 回调。宿主必须判断该插件的正在执行任务已排空；仅在确认插件没有此类任务时返回 true。默认未注册回调时拒绝执行卸载，不假定未知业务已停止。卸载执行插件自己的 rollback/SQL，可能删除业务数据；统一配置默认保留，可在停用或卸载后显式清除。

## 配置结构升级

增加 settingsVersion，并在宿主 management.php 的 settings_migrations.<app> 注册可信 callable，参数为旧配置、旧版本、目标版本，返回新数组。迁移必须是纯函数、可重复预览，不能写数据库、文件或调用有副作用的外部接口：在线预览/确认及实际安装前均可能执行。先预览迁移，禁止配置版本倒退和迁移异常在文件替换/DDL 后才被发现；实际配置在 settings 阶段以修订号校验后保存。加密快照最多保留 5 份，操作审计最多 50 条。

新必填业务项缺失显示待配置；目标必需服务的连接组必须可用，按迁移后的值和目标 schema 探测。没有迁移器时保留原配置并更新版本，不能猜测字段更名。若配置已经迁移而后续安装失败，配置 API 使用 pending 目标 schema/版本，允许修正目标配置，拒绝用旧 schema 将版本写回。

配置响应只返回当前声明字段，并持续隐藏曾标为敏感的字段路径；删除 writeOnly 或移除字段不能使历史密钥重新明文出现。需要将敏感字段改成普通字段时使用新字段名，或显式清空配置后重新配置。清空配置会删除值、历史及健康检查，但保留递增修订号，防止旧表单在清空后覆盖新配置。服务声明的版本、能力、探测器等变化也会使旧健康检查失效；升级管理器后缺少该指纹的旧检查需重新验证。

## 任务和恢复

先预览完整计划，再提交 fingerprint。提交和执行都会检查载荷，拒绝包被替换；升级已有公共依赖必须在完整计划里确认。任务在后台串行执行，浏览器关闭不影响执行。已安装的前置插件在后续失败时保留。

数据库、文件、代码库依赖不是原子事务。任务只记录插件级检查点；安装过程中中断会标记 needs_attention，禁止自动重复未知 SQL/seed。查看当前文件、数据库及迁移记录后选择 retry、accept_completed 或 cancel。accept_completed 核对已登记的状态、目标版本及包指纹。卸载重试允许同一安装对象因失败状态写入而改变修订号；若包已更换，必须取消并重新预览。

升级失败仍保留 update 标记，SQL 升级只执行 update.sql，不重跑首装 install.sql/种子；这不保证 update.sql 本身幂等，重试前仍需核对已执行语句。若 info.ini 已标记安装完成但安装登记中断，显式重试会核对目标载荷和实际文件，只完成配置/登记，不重跑 SQL 或种子。文件复制不完整不能激活；移除整棵前/后端子树的升级也会清理旧文件。每次文件备份使用独立文件名。重试前 worker 会重载，避免迁移类留在 PHP 内存中。文件 ZIP 备份不是数据库回滚点。

Composer/npm 依赖继续沿用终端安装流程，出现 waiting_dependencies 时完成原页面中的依赖安装，再确认任务完成；此状态拒绝 retry，以免重跑业务迁移。所有终端命令也持有全局变更锁。依赖声明不兼容时必须先协调宿主声明。失败的插件不能被当作已安装前置依赖复用，必须纳入修复步骤或先恢复它。

## HTTP API

前缀 /tool/install/management，仅超级管理员可访问。settings/probe 不使用记录请求正文的 SystemLog；专用异常处理器 阻止含秘密的请求进入宿主 debug 异常输出。响应沿用 code/message/data 格式。

| 方法 | 路径 | 主要参数 |
| --- | --- | --- |
| GET | catalog | 无 |
| POST | plan | appName |
| POST | submit | appName, fingerprint |
| GET | tasks | 无 |
| POST | recover | taskId, revision, action |
| GET | settings | appName |
| POST | saveSettings | appName, revision, values, secretActions |
| POST | clearSettings | appName, revision |
| POST | probe | appName, draft；草稿另传 values/secretActions |
| POST | enable | appName, enabled（JSON boolean） |
| POST | uninstallPlan | appName |
| GET | docs | appName |

旧 /app/saipackage/install/install 和 uninstall 在启用管理后返回 taskId/state=queued，旧客户端应按后台任务轮询；完整依赖计划走新接口。无 manifest 的包保持原格式，不能据此跳过新入口的依赖保护。

## 前端集成

管理组件位于 src/frontend/management，复用官方 6.x Element Plus、@/utils/http 和全局 vue-i18n。中英文消息随组件分发，通过 mergeLocaleMessage 注册；主题采用 Element Plus 变量。setup 自动适配官方 src/views/plugin/saipackage/install/index.vue；自定义页面需导入 management/index.vue 并放置 <ManagementPanel />，不覆盖整个宿主页面。

## 验证

`composer install && composer test` 运行领域测试。`python3 tests/build_fixtures.py OUTPUT` 生成独立 ZIP 测试包；在一次性官方宿主初始化并 seed 后，放到 server/tests/fixtures，运行 tests/lifecycle.php 的 install、upgrade、uninstall、failure、recover_failure 五个阶段。失败修正需新 PHP 进程。不要在客户环境运行这些测试。实际结果见 validation.md。
