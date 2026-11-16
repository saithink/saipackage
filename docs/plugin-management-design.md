# 插件依赖、配置与外部服务管理方案

## 目标和边界

用户自行部署 Docker 服务。saipackage 只安装插件、解析插件依赖、保存连接配置、检测服务和管理可用状态；不访问 Docker Socket，不执行容器命令，不删除用户的服务或数据卷。本文是实施契约，验收记录另见 validation.md，不将设计当作已实现。

## 包契约

保留 info.ini、config.json、plugin/<app> 和前端 views 子树。新增 manifest.json、settings.schema.json、docs/deployment.md、docs/compose.example.yaml。标识沿用现有 PHP 命名空间规则：字母开头的字母、数字和下划线；示例使用 logistics_ocr，不使用连字符。manifest 的 id/version 必须与 info.ini 一致。无 manifest 的旧包继续安装。

manifest schemaVersion=1，字段包括 id、version、requiresPlugins、conflictsPlugins、settingsVersion、externalServices。依赖使用 Composer Semver；externalServices 包含 id/title/required/settingsGroup/probe。probe 是宿主配置注册的检测器标识，不允许请求传入类名、URL 探测脚本或命令。配置使用 JSON Schema draft-07 子集及 ui 扩展；后端使用标准验证器。发布示例和支持字段见 examples/。

## 插件依赖

解析已安装版本和已上传候选包，回溯求解版本组合，检查完整依赖图、循环、冲突、反向约束和主程序要求。优先复用已安装的兼容版本。缺包报出依赖链；第一版不自动访问商店下载。先计划后提交，计划包含版本、文件指纹和升级影响，提交时校验指纹防止包变化。升级现有前置插件必须显式提交完整计划，不能通过旧安装接口静默升级。

按拓扑顺序安装；已完成的依赖在后续失败时保留。被已安装插件依赖时禁止卸载，被已启用插件依赖时禁止停用。无级联强制操作。依赖已安装但配置未完成不阻止安装，只阻止功能就绪。

## 配置

配置与代码分离，使用数据库持久化；实例级为默认，预留租户级但仅在宿主提供可信租户解析器和授权器时开放。前端不能自行指定租户。字段的作用域由 schema 声明，不隐式覆盖实例凭据。

提供 schema、脱敏读取、修订号条件保存、草稿测试和已保存配置检测接口。秘密字段 writeOnly=true，使用单独 secretActions（keep/replace/clear）表达操作，不能回传明文或用空字符串隐式删除。数据库之外的稳定主密钥使用环境变量提供，缺失时拒绝保存秘密。审计只保存字段名、修订号、操作者和时间。

每次保存增加 revision；检测关联 revision 和连接字段指纹，连接字段变化废弃验证，不影响连接的字段可声明 ui.fields.<path>.connection=false。并发编辑 revision 不一致时拒绝。升级使用专用 SettingsMigration，旧快照加密保留；迁移失败阻止激活，新必填字段缺失显示待配置。配置默认在卸载时保留，清除需单独显式操作。配置历史及检测记录有限保留。

## 服务检测

ServiceProbe 接收只在后端可见的配置，返回固定结果码、兼容版本、能力和延迟。支持 OK、UNREACHABLE、TIMEOUT、AUTH_FAILED、VERSION_UNSUPPORTED、CAPABILITY_MISSING、UNKNOWN。检测是只读、低成本操作，必须由检测器设置网络超时；禁止将底层异常、响应体或凭据写进接口和日志。草稿测试不产生可用于启用的凭证。验证已保存配置时再次核对 revision，避免检测过程中配置被修改。

必需服务首次验证成功才允许启用。后台有限并发周期检测，瞬时失败改变健康状态不改变管理员启用选择；恢复后自动恢复就绪。版本兼容和能力由插件检测器核对，HTTP 200 不等于可用。endpoint 是后端可达地址，localhost 是后端所在容器自身。

## 状态与运行保护

分别记录安装状态（installing/installed/upgrading/uninstalling/failed/uninstalled）、enabled、配置状态、依赖状态和服务健康。汇总优先级：操作中/失败、停用、依赖阻塞、待配置/待验证、服务异常/降级、可用。安装成功不等于可用，服务异常不等于停用。

RuntimeGuard 统一检查 HTTP、队列、定时任务；全局 HTTP 中间件覆盖 plugin/<app> 控制器，队列和定时任务通过 SDK guard 调用。宿主独立前端不在本 PHP 仓库，提供可安装的管理页及 HTTP API，页面使用宿主布局、主题和语言系统，不假定修改宿主前端仓库可随 Composer 分发。管理接口仅超级管理员，配置请求不进入通用正文日志。

## 数据模型和一致性

数据库管理 plugin_packages（候选包）、plugin_installations（已安装清单快照和版本）、plugin_dependencies（声明及解析版本）、plugin_settings（作用域/加密值/schemaVersion/revision）、plugin_service_checks（脱敏探测结果及修订）、plugin_tasks（持久计划、步骤和失败信息）。具体 DDL可将可变文档放入 text JSON，使用 MySQL/PostgreSQL 通用 Phinx 迁移。保留配置快照和审计。

上传候选包不得覆盖已安装记录。旧 runtime/saipackage 下 state=1 的记录首次导入数据库，未知版本不得默认为兼容。数据库为新管理状态事实源，旧 state 字段仅用于兼容原界面；runtime 仍是安装载荷，应持久化，不能随缓存清理。

全局串行任务锁防止并发修改 Composer/npm、迁移和重启；单插件锁保护上传。锁顺序固定为全局再单插件。任务持久记录每个步骤和检查点，计划提交后自动执行。进程中断不自动重复未知的非幂等 SQL/seed，标记 needs_attention，检查实际结果后显式恢复；安全步骤允许重试。HTTP 请求不承载长任务。后端重启延迟至结果持久化且响应已发出。

## 生命周期

安装：校验/预检 → 依赖安装 → 文件和代码库依赖 → 数据库迁移 → 保存清单/schema/docs → 安装记录 → 重载。外部服务不阻塞安装。

升级：反向依赖检查 → 影响预览 → 文件与加密配置快照 → 文件/迁移/配置升级 → 激活 → 重新计算验证状态。数据库、文件和依赖管理不是统一事务，不承诺无损自动回滚；失败保留证据和已有版本快照，提供恢复入口。

卸载：反向依赖检查 → 禁止新业务 → 宿主任务排空回调 → 原卸载数据库和文件 → 保留/清除配置 → 更新安装状态。不触碰 Docker。配置、数据库删除影响必须在预览说明。

## 接口与界面

/tool/install/management 下提供 catalog、plan、submit、task、settings、probe、enable、uninstallPlan、retry、docs。所有变更使用 POST，设置保存校验 revision，计划提交校验 fingerprint。旧 install/uninstall 入口同样执行依赖和状态保护，不能绕过新机制。列表保留旧字段并附加 management，详情包含概览、依赖关系、配置、部署说明、任务日志。敏感配置接口不使用记录请求正文的 SystemLog。

## 兼容和迁移

无清单视为旧包，无配置描述视为无需统一配置。旧插件默认保持启用；扫描只建立记录不执行 SQL。旧安装器升级不能重新复制所有业务插件。候选包独立于已安装版本；manifest/schema/docs 安装快照留在数据库，即使候选包更换也不丢失。数据库迁移通过安装器启用命令执行，不能在普通业务请求中反复 DDL。

## 验收矩阵

1. A→B→C 排序、缺包、循环、不同版本共同约束、冲突插件、反向升级不兼容。
2. 前置已安装复用、前置升级需计划确认、计划过期拒绝、依赖安装失败不执行后续。
3. 被依赖插件卸载/停用保护，HTTP/任务入口阻止不可用功能。
4. 安装未部署外部服务成功并待配置，配置校验、密钥保持/替换/清除、并发保存冲突、脱敏和跨租户拒绝。
5. 草稿探测不激活、修改配置撤销验证、探测并发修订检查、超时/鉴权/版本/能力错误、故障恢复。
6. 配置升级、失败恢复、卸载保留、重装复用、显式清除、历史保留策略。
7. 全新 MySQL/PostgreSQL 初始化、旧记录导入、两套隔离环境首装、同版拒绝、升级、卸载重装。
8. 后台任务串行、进程中断、失败步骤重试、安全日志、真实重载。
9. 管理页面显示、配置操作、网络请求、主题与语言切换；无 Docker 操作。

## 实施顺序

先提交本文；随后实现领域服务和数据库迁移，再接入安装器、HTTP API、持久 worker 和管理页，最后补齐示例、双数据库及生命周期验证。提交 PR 时附实际验收与限制，不以单元测试代替真实安装验收。

## 在线更新扩展

支持自动检测、手动确认升级。更新源、缓存、兼容版本筛选、独立下载准备任务、目标外部服务检查和二次确认契约见 [在线更新](online-updates.md)。新版本检测不操作 Docker，也不自动执行插件安装。官方缺少兼容声明时显示待验证，自建源需完整清单和 SHA256。
