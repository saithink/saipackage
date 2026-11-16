# 验证记录

日期：2026-11-16。仅在一次性本地环境验证，未合并、发布或部署到用户业务系统。

## 基线与环境

- 本次实现提交：a89c584（后续文档提交不改变运行代码）。
- saipackage 基线：6.x，a8cb24f76b29b1bc7b1b94c426c7471168e5bfa2。
- 官方 SaiAdmin 宿主：saithink/saiadmin6.x，b697986e7838dab87463c7597b2787cb3c0d1a7d；实际解析 SaiAdmin 6.1.5、Phinx 0.16.12、Webman 2.2.5（以实验室 composer.lock 为准）。
- 本机 PHP 8.4.26；隔离 PHP 8.4.27。MySQL 8.4、PostgreSQL 17 两套独立 Compose 项目，各自独立数据库卷、网络、可写源码和 Redis。
- 最终测试项目：sai6lab-kvns2rct（MySQL）和 sai6lab-tpe_yzmn（PostgreSQL）；另用全新 sai6lab-zrmcw57f 直接首装 1.1.0。早期探索环境未作为最终首装证据。
- Docker 只用于本次测试宿主及数据库隔离；被测安装器没有调用 Docker，模拟外部服务由测试脚本手动启动。

## 已执行结果

| 检查 | 结果 | 范围与证据 |
| --- | --- | --- |
| PHP 8.1 / 8.4 CI | 通过 | [实现提交的 CI 运行](https://github.com/guozhijian611/saipackage/actions/runs/43738758352)；最终分支检查见 PR |
| PHP 领域测试 | 通过 | composer test：33 tests / 61 assertions |
| PHP 语法及 Composer 配置 | 通过 | php -l、composer validate、git diff --check |
| 两库管理表初始化 | 通过 | 真实 Phinx 迁移；不是手工建表替代 |
| 两库首次 ZIP 安装 | 通过 | 缺依赖阻止、补齐包后拓扑排序、任务成功、服务未部署仍安装成功并待配置 |
| 新版直接首装 | 通过 | 第三套空宿主直接安装 1.1.0；note 列存在、无保留配置、显示待配置 |
| 同版重复上传 | 通过 | 拒绝且已有安装可用 |
| 升级 | 通过 | 1.0.0 → 1.1.0，上传不覆盖已安装版本；业务记录保留、废弃文件删除、配置保留 |
| 卸载再安装 | 通过 | 两库均验证 rollback、文件删除及新包重装；加密配置保留并复用 |
| 故意失败及修复 | 通过 | 失败迁移标记 needs_attention，功能被拦截；取消旧任务、修正版经新 PHP 进程恢复成功 |
| 旧包兼容 | 通过 | labphinx-1.0.0 无 manifest/schema 的 ZIP 在两库均安装并保持可用 |
| 真实后台执行 | 通过 | enqueue 请求仅创建任务；实际 management worker 完成任务，持久化 database 等阶段并触发重载 |
| HTTP 权限与配置 | 通过 | 匿名 401、非超级管理员 403；GET 脱敏、保存、409 修订冲突、草稿/保存后检测 |
| 错误响应与日志脱敏 | 通过 | debug 开启时错误响应无 request_param/trace/测试密钥；日志检索无测试凭据 |
| 业务入口保护 | 通过 | 停用后业务 HTTP 503，服务验证并启用后恢复；实际重载后返回 1.1.0 |
| 真实 HTTP 服务探测 | 通过 | OK、AUTH_FAILED、VERSION_UNSUPPORTED、CAPABILITY_MISSING、UNREACHABLE、TIMEOUT；两库独立服务，超时测试真实等待 5 秒 |
| 前端构建与类型 | 通过 | 官方宿主 pnpm build（vue-tsc + Vite）；新组件随 setup 复制 |
| 浏览器操作 | 通过 | 正常管理员登录、配置表单、保持密钥、保存超时参数、草稿/保存后验证、可用状态展示 |
| 主题与语言 | 通过 | 明暗主题、绿色主题色跟随宿主；中英文不刷新切换；最后恢复原主题和中文 |

逻辑层生命周期使用 install(false)/uninstall(false)，避免在 CLI 中发送重启信号；其本身不证明真实重载。另行使用实际后台 worker、Webman reload 和 HTTP 返回版本验证重载，不将容器存活当作业务成功。

前端首次单独执行 vue-tsc 时宿主尚未生成自动导入声明，出现宿主范围的未定义 ref 等错误；运行正常 Vite 流程生成声明后，最终完整 pnpm build 通过。开发中的代理端口和热更新报错已解决，最终页面加载、配置和检测请求成功。

## 重现方式

1. 从上述官方基线建立独立宿主，把当前包作为 Composer path repository（版本映射 6.2.0），安装依赖；同步 src/plugin/saipackage 到工作副本。
2. 使用 saiadmin6-plugin 技能的 lab.py 创建每轮独立 MySQL/PG 环境。初始化核心并运行 PureSeeder；生成稳定配置密钥，执行 saipackage:setup，再启用 management 并重启。
3. `python3 tests/build_fixtures.py OUTPUT` 生成 ZIP，将 ZIP 和 tests/*.php 放入宿主 server/tests；将 ZIP 放在 server/tests/fixtures。测试宿主配置 drain 回调为 true（这些夹具没有运行中业务任务）。
4. 每个阶段使用独立 PHP 进程，依次运行 `php tests/lifecycle.php install`、upgrade、uninstall、failure、recover_failure。另外在第三套空宿主运行 direct 阶段，直接安装 1.1.0，验证完整迁移和空配置。
5. 手动启动 `php -S 127.0.0.1:18999 tests/mock_health.php`。逻辑测试后执行真实 Webman reload，再运行 http.php 和 probes.php。HTTP 脚本仅在测试宿主使用正常 JWT 签发器生成测试令牌，鉴权中间件保持开启；浏览器登录另行完成正常验证码流程。
6. background.php enqueue 创建异步任务，另进程 background.php poll 检查实际 worker。此测试只运行一次；需要复测时用新环境或按正常卸载流程移除 managed_async。
7. 旧包检查由技能 build_example.py 生成 labphinx-1.0.0.zip，再执行 legacy.php。

夹具源码和独立构建器在 tests/，不作为用户业务插件发布。ZIP 使用固定时间戳生成，可重复核对 SHA256；安装器另对解压载荷计算指纹。

## 明确边界

- 当前开放实例级配置；租户作用域需要未来的宿主身份/授权适配，现阶段明确拒绝。
- 当前使用单机持久 runtime 目录和文件锁，不支持多个独立主机同时调度。
- Composer/npm 延续既有终端安装流程，waiting_dependencies 需完成库安装后确认任务；不承诺自动解决 npm 版本冲突。
- HTTP 控制器及按插件注册的路由自动保护；宿主自定义主应用路由、队列、定时任务必须调用 RuntimeGuard，无法自动识别任意第三方业务入口。
- 卸载排空由宿主 drain 回调证明。未提供时拒绝实际卸载。
- 不提供数据库/文件/依赖的原子回滚，不声称可以自动恢复所有部分执行的 SQL。未实测真实客户外部服务和生产部署。
- 前端浏览器测试针对官方 6.x 宿主；自定义宿主需按接入指南复验。PHP 8.1 和 8.4 均已实际运行 CI，发布前仍应核对 PR 最终提交的检查状态。

## 截图

![配置及连接验证](screenshots/settings-verified.jpg)

![宿主自定义主题色](screenshots/theme-custom.jpg)

## 本地验收保留

保留 MySQL 测试宿主及前端：http://127.0.0.1:18180/#/plugin，后端仅监听 http://127.0.0.1:18182。其余已记录的临时 Compose 项目在完成验证后清理；保留各自临时目录作本地证据，不上传凭据。此地址依赖本机开发进程，不能作为生产服务使用。

## 在线检测与手动确认升级补充验收（2026-11-16）

- 单元测试扩展至 48 项、102 个断言；覆盖自动检测不下载/不安装、持久间隔与退避、预发布/撤回过滤、最新与兼容版本分离、依赖及反向约束、元数据缺失、更新源/环境失效、外部服务阻塞及重复确认保护。Composer validate、PHP lint、完整前端 vue-tsc/Vite 构建通过。
- 已有 MySQL 8.4 宿主只追加新迁移；另建空 PostgreSQL 17 宿主执行首版及追加迁移，两者通过。首版迁移的建表列表保持不变。
- 两库通过本地真实 HTTP 更新源及后台 worker，运行 `updates_http.php check/service/invalid/upgrade`：检测不下载、不创建安装任务；线上 2.0.0 因 SaiAdmin 7 约束阻塞，元数据兼容版为 1.3.0；1.3.0 因外部服务仍为 2.x 阻塞确认及实际执行；可转选 1.2.0；摘要、包版本、清单不一致均拒绝且保留原候选；失效指纹/配置修订被拒绝；手动确认后升级并保留业务数据和配置。
- 两库实际完成预发布 0.9.0-beta.1 到正式 0.9.0 升级，验证同补丁号稳定版升级。
- PostgreSQL 另运行 `updates_http.php confirm_once`：模拟用户先将外部服务更新至 3.0.0，再手动确认 1.2.0→1.3.0；确认与安装任务关联持久化，重复请求返回 409，业务数据与配置修订保留。
- 浏览器实际操作“查看更新→下载并预览→外部服务版本不兼容提示→用户服务升级后重新预览→确认升级”，最终显示已安装 1.3.0。准备后未确认时仍为 1.2.0；旧准备记录显示已失效。下载进度、等待确认及安装任务分别展示，中英文文本补齐。
- 官方商店适配仅验证协议映射及缺失 manifest/SHA256 时的待验证行为，未使用真实付费账号执行官方商店下载；自建源已做真实网络与 ZIP 升级验收。未进行生产部署。

复现时先按上述流程安装 managed_ocr 1.1.0，配置 [在线更新源](online-updates.md)，运行 `php -S 127.0.0.1:19000 tests/mock_updates.php`（另进程运行 18999 健康服务）；映射 managed_ocr 到 fixture 源，测试环境显式 allow_http，token 使用夹具中的假值。依次运行四个 HTTP 阶段；再将 mock-state.json 设置为 `{"version":"3.0.0","capabilities":["recognize"]}`，运行 confirm_once。`prerelease.php` 用于独立测试 beta→stable。所有测试在隔离宿主执行，禁止指向生产数据库。

![手动确认升级计划](screenshots/update-confirmation.jpg)

![升级完成及旧准备失效](screenshots/update-completed.jpg)

## 全量复审补充验收（2026-11-16）

复审发现及修复见 [PR 审查记录](pr-review.md)，使用最终代码另建独立 MySQL 8.4 / PostgreSQL 17 宿主（项目 sai6lab-pfzlt9ev / sai6lab-thd7h4b3）。两套前序故障实验室 sai6lab-3ak2dse5 / sai6lab-fuznsqr3 只用于定位和早期复现，不代替最终回归。

- 63 个单元测试、124 个断言；Composer validate、PHP lint 通过。此次没有改动前端组件。
- 两库 ZIP 首装、DDL 后中断及显式重试、种子只执行一次、整棵旧前端移除、安装登记前中断补齐、卸载回滚失败及重试、配置版本倒退提前拒绝、迁移预览异常提前拒绝、配置已提交后失败恢复、暂存目录丢失后升级，全部通过。
- 两库真实 HTTP：配置已迁移而后续失败时，GET 返回目标 schema/settingsVersion 且密钥脱敏，POST 可使用该目标契约保存，继续恢复成功。配置保存阶段最初测试被 CLI 持有的全局锁拒绝；测试 harness 改为释放 CLI 锁后再模拟独立 HTTP 管理员，409 并非配置接口错误。
- MySQL SQL-only 夹具首装及升级通过，验证升级不再执行非幂等 install.sql，旧行保留、update.sql 新行恰好一次；PG 按契约跳过 SQL-only 包。
- 两库事务内重复 INSERT 正确返回修订冲突，不被 PG aborted transaction 异常掩盖。
- 两库复跑原在线 HTTP check/service/invalid/upgrade/confirm_once：检测零下载/零安装、服务不兼容阻塞、摘要/身份/清单错误、过期确认、配置修改、重复确认、实际后台升级、配置/业务数据保留均通过；beta→stable 实际升级通过。

新夹具由 tests/build_review_fixtures.py 生成，阶段脚本为 tests/review_recovery.php。迁移/SQL/种子故障均为一次性环境的受控注入；没有操作生产数据库或部署生产服务。复审实验室验证完成后仅按各自项目清理，原本保留的本地浏览器验收环境继续保留。
