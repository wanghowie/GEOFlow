# GEOFlow ChatGPT插件与MCP后台管理方案

状态：Proposed，仅设计文档，尚未实现或部署。  
复核日期：2026-09-18。  
代码基线：`9ed2fe80457d5eb280a4bca7cf799895bf2ca3b1`。  
配套文档：[复核发现](../reviews/chatgpt-mcp-management-review.md) · [验收与实施清单](chatgpt-mcp-management-acceptance.md)。

## 1. 决策摘要

目标：让获得授权的用户在ChatGPT中查询GEOFlow后台、理解运营问题、维护草稿，并在后续阶段执行受控任务与发布协作。

推荐采用“业务Skill + 独立MCP适配服务 + GEOFlow API及业务服务”的结构。首版限定一个已登记实例、一个明确授权的管理账号和只读工具。写入能力需要补充Core侧的原子状态检查、资源授权与可核对的操作结果，不能仅在MCP层包装现有HTTP请求就宣称已经安全完成。

主要决策：

- 保留现有后台与业务服务；MCP不直连业务数据库，不执行任意Shell、SQL、PHP或任意URL请求。
- 先完成MCP连接，再打包Skill与插件。完整插件安装、开发者模式接入、组织内分发和公开目录发布分别验收。
- 首版可授权整实例共享运营资料，授权页面明确显示该范围。跨客户、跨租户的数据隔离不作默认承诺。
- 将只读、草稿写入、内容生成、对外发布分成独立阶段和权限组，默认关闭写入。
- 主题代码编辑、Updater、备份恢复、删除、人工质量放行、账号与密钥管理不进入首版工具集。
- 本PR仅交付方案、复核依据和验收清单；不会安装插件、创建生产Token、执行迁移、修改业务代码或部署站点。

## 2. 当前实现与可复用边界

以下路径均相对于本仓库。事实依据固定到上述提交；实施时必须重新检查实际部署版本。

| 领域 | 已核对的实现 | 接入时的边界 |
| --- | --- | --- |
| REST API | [routes/api.php](../../routes/api.php)提供文章、任务、Job、素材及管理接口 | Web后台路由数量不能等同于可用领域操作数量 |
| Token | [ApiTokenService](../../app/Services/Api/ApiTokenService.php)使用Sanctum并支持scope、期限、撤销 | Sanctum Token不等于MCP OAuth授权流程；不向模型传递凭据 |
| 会话与能力 | [ManagementSessionController](../../app/Http/Controllers/Api/V1/ManagementSessionController.php)返回实例、账号、scope与管理操作 | 每次调用仍要授权；缓存的工具列表不授予权限 |
| 能力注册表 | [ManagementOperationRegistry](../../app/Support/Api/ManagementOperationRegistry.php)描述管理操作 | 文章列表等旧API未全部纳入，嵌套业务schema不完整，需独立兼容映射 |
| 文章与质检 | [ArticleController](../../app/Http/Controllers/Api/V1/ArticleController.php)及[ArticleGeoFlowService](../../app/Services/GeoFlow/ArticleGeoFlowService.php) | 普通文章查询入口没有显式viewer参数；上线前确认所授权的数据范围 |
| 任务 | [TaskController](../../app/Http/Controllers/Api/V1/TaskController.php)已有need_review与发布scope联动 | 保留已有防护，并测试任务被其他操作者修改后的执行行为 |
| 重复请求 | [IdempotencyService](../../app/Services/Api/IdempotencyService.php)与[远程CLI流程](../../.agents/skills/geoflow/references/remote-cli-workflow.md) | 旧幂等头和新收据头不同；不能统一给所有POST自动重试 |
| 远程主题 | [远程管理覆盖说明](../api/remote-management-preview.md) | 当前主题发布未开放；已有预览能力不能被描述为完整主题管理 |

特别说明：`GET /articles/{id}/ai-quality/status`返回轻量进度，不包含完整证据正文。分析质检原因需要另行读取经脱敏的文章质检详情。当前文章列表控制器不提供通用起止日期过滤，最近7天统计不能仅通过额外传入未经支持的参数实现。

## 3. 系统结构与责任

```text
用户的自然语言请求
    ↓
ChatGPT插件：业务Skill + MCP工具定义
    ↓ OAuth访问令牌，受众为MCP资源
独立MCP服务：参数校验、用户绑定、权限交集、输出投影、操作日志
    ↓ 服务端保管的、绑定到该账号/实例的GEOFlow受限凭据
GEOFlow API：认证、资源授权、事务内前置条件、质量门禁
    ↓
原有业务服务与队列：草稿、质检、生成、发布、分发
    ↓
结构化结果：资源ID、收据ID、实际业务状态、覆盖范围与后续动作
```

### 3.1 推荐部署形态

MCP服务建议使用TypeScript与经过兼容验证的MCP SDK，单独Docker容器部署。SDK、运行时与镜像在实施PR中锁定版本，不使用浮动latest作为生产基线。

首次部署采用同一GEOFlow实例旁的sidecar，避免立刻建设集中托管所有客户凭据的网关。独立服务只能访问预登记的Core地址、授权服务和必要的密钥设施，不能挂载Docker socket、宿主机目录、Core数据库凭据或Updater socket。

外部端点示例为`https://geo.example.com/mcp`，实际根路径、子目录、反向代理和自定义后台前缀均需测试。公网访问使用HTTPS；受控内部连接若使用HTTP，必须限定到精确的内部服务地址和隔离网络，不能让用户传入地址扩展访问范围。

后续集中网关是独立的多租户项目，需额外设计租户路由、凭据隔离、域名绑定和跨实例测试。

### 3.2 组件职责

| 组件 | 负责 | 不负责 |
| --- | --- | --- |
| Skill | 操作流程、证据解释、失败时如何反馈 | 权限判断、保存密码、批准自身写入 |
| MCP工具 | 稳定JSON schema、动作映射、上下文绑定、结果投影 | 通过任意HTTP/SQL工具绕过业务服务 |
| OAuth授权服务 | 登录、同意页面、客户端注册、Token发行与生命周期 | 直接把外部身份映射为超级管理员 |
| Core | 资源权限、事务、版本检查、质量规则、任务与发布效果 | 信任模型声明的admin_id或confirmed字段 |
| 队列Worker | 执行已获准工作、检查快照/预算/撤销状态、记录结果 | 将入队成功直接标记为业务完成 |

## 4. 身份、授权与撤销

### 4.1 两套凭据严格分离

ChatGPT到MCP使用OAuth访问令牌；MCP到Core使用受限的GEOFlow凭据。MCP不把收到的OAuth令牌原样转发给Core，不把Sanctum Token当作外部MCP访问令牌，也不复用一个全局超级管理员Token处理所有用户请求。

建议连接绑定记录包含：

```text
connection_id
issuer + subject
instance_id + canonical_core_origin + base_path
core_admin_id + credential_reference
allowed_resource_scope + allowed_actions
grant_version + expires_at + revoked_at
```

`subject`来自已验证的身份令牌或授权服务上下文，不能取自模型参数。绑定Core账号需用户在可信授权页面完成身份验证或经过管理员批准；仅按邮箱、昵称或用户提交的admin_id自动绑定不予接受。

最小权限以如下交集计算：

```text
有效权限 = OAuth授权 ∩ Core账号当前权限 ∩ Core Token权限
         ∩ 连接资源范围 ∩ MCP工具白名单 ∩ 当前阶段开关
```

任何一项无法确认时拒绝执行。首版不申请`*`、`articles:publish`、主题代码和Updater相关scope。服务端凭据放在密钥设施或受保护的加密存储中，日志和插件包不包含其明文。

### 4.2 OAuth兼容性

按照OpenAI当前插件认证文档与所选MCP协议版本实施授权码加PKCE流程，核对受保护资源metadata、授权服务发现、`resource`与访问令牌受众。客户端识别采用实际客户端支持的预注册、CIMD或DCR之一，不把DCR写成唯一必需选项。

这是协议实现检查项，不代表引入某个OAuth库即可自动通过。需验证签名或令牌内省、issuer、audience、有效期、允许算法、scope、回调URI精确匹配、state及PKCE。采用成熟身份组件；自行新增的Core登录绑定与同意页面仍需安全测试。

刷新令牌是否发行、是否需要offline_access以及轮换规则应由实际身份服务配置明确决定。Refresh不能增加权限。认证失败、权限不足、网络失败应分别处理。

### 4.3 撤销及恢复

断开连接首先在MCP侧撤销grant并阻止新请求，再撤销该连接专属Core凭据，不能撤销其他工具共用的Token。远端撤销失败必须记录为待清理，不能显示已经全部撤销。

账号停用、权限降低、实例身份变化或恢复事件使相关缓存和待执行批准失效。已开始的模型调用可能无法即时取消，需返回真实状态。建议在Worker开始执行及外部发布前重查执行许可；未开始的相关工作应取消或停止，具体语义由Core实施PR定义。

数据库回滚可能恢复旧grant或丢失收据。紧急禁用与撤销代际应保存在不会随业务数据库回滚的管理边界中，或在恢复程序中强制使全部旧连接失效。恢复验收不通过时保持MCP写入关闭。

## 5. MCP协议与兼容策略

公开HTTP端点采用Streamable HTTP，完成initialize、协议版本协商、tools/list和tools/call。Session ID仅用于传输会话；每次HTTP请求都进行身份验证和连接授权。若SDK使用有状态会话，要验证跨用户隔离、重启、过期和重连，不能用Session ID充当登录凭据。

工具必须具有输入schema、输出schema、描述及符合实际副作用的annotations。`readOnlyHint`、`destructiveHint`、`idempotentHint`和`openWorldHint`只用于客户端理解，服务端仍需独立防护。查询私有数据也需要认证。

固定工具表与版本化适配器共同决定可用范围：

1. 核对`auth/session`中的实例、账号和scope。
2. 获取`capabilities`并记录协议版本与contract_hash。
3. 对新管理操作要求服务端明确声明支持；对旧业务API使用经验证的映射与schema，不因注册表未列出就一概判为不存在。
4. 未知版本、schema漂移、身份变化时关闭写入，返回具体兼容性错误。工具发现后发生权限变化，每次调用仍必须拒绝失效操作。
5. 仅对确认身份后的旧版本404启用已验证的只读兼容模式。401、403、429、TLS或服务端错误不得解释为旧版。
6. 不把覆盖清单中的pending条目转换成可调用工具，不把任意Web路由自动转换成MCP动作。

协议错误与业务失败分层返回。外层认证失败使用HTTP授权错误与发现信息；已经进入tools/call后的业务失败按所选SDK的工具错误契约返回，保留机器可读error_code和安全的后续动作。响应schema无法验证时拒绝把它解释为成功。

## 6. 工具设计与能力范围

以下工具名属于拟设计名称，尚未注册。HTTP路径为现有API v1内路径，只有表中明确写为“待新增”的能力才需要新的Core契约。

### 6.1 P0只读工具

| 工具 | 现有接口 | 约束 |
| --- | --- | --- |
| get_connection_status | GET /auth/session、GET /capabilities | 仅返回脱敏身份、范围及可用能力 |
| get_catalog | GET /catalog | 投影ID、名称及必要配置元数据，不能整包转发 |
| list_articles | GET /articles | 有限分页；支持的过滤项逐项白名单化 |
| get_article | GET /articles/{id} | 有明确访问权限才读取正文；输出长度受控 |
| get_article_quality_status | GET /articles/{id}/ai-quality/status | 轻量状态，不能冒充完整证据 |
| get_article_quality_detail | 由文章详情中的ai_quality提取 | 专门的字段投影，避免无关正文与供应商日志 |
| list_tasks、get_task | GET /tasks、GET /tasks/{id} | 保留现有viewer语义；隐藏不必要配置 |
| list_task_jobs、get_job | GET /tasks/{id}/jobs、GET /jobs/{id} | 明确任务、执行记录、收据各自ID |
| get_material_summary | GET /materials | 只读摘要，不默认导出知识库所有条目 |

为每个工具定义具体Core scope映射并写进契约测试。ID是服务器返回的业务标识，不能从文章标题猜测。实例地址、Token、admin_id和任意路由不属于模型可填写参数。

### 6.2 后续阶段的写工具

| 工具 | 实施要求 | 默认状态 |
| --- | --- | --- |
| prepare_draft_change | 生成确定字段与内容摘要的计划，不修改文章 | P1，待实现 |
| commit_draft_change | 新增Core原子草稿写入契约，包含资源授权、状态/版本检查与收据 | P1，待实现 |
| create_generation_task | 复用任务服务，固定待审模式、关闭自动调度/分发，并执行预算预留 | P2，待实现 |
| enqueue_generation | 优先复用支持收据的tasks.enqueue，执行配置必须冻结或原子验证 | P2，待实现 |
| prepare_publication、commit_publication | Core保存计划与可信批准，发布前重查质量、权限和渠道 | P3，待实现 |

`prepare_*`、`commit_*`、草稿revision与业务批准记录均为本方案新增设计。现有API没有因此自动具备这些能力。具体路由名称在实施PR中通过统一注册表和OpenAPI导出，避免文档凭空承诺已上线接口。

### 6.3 显式排除项

首版不提供删除、主题原生代码、Updater、恢复备份、风险放行、质量门禁覆盖、用户权限修改、API密钥管理、任意素材URL导入、浏览器外站自动发布或通用HTTP工具。

这些能力即使存在Core API也不会自动出现在插件中。独立增加能力需要新的权限设计、威胁分析及相应验收。

## 7. 草稿写入必须在Core侧补齐的保护

当前`updateArticle`在正文、标题等风险相关字段变化时会归一到draft/pending；若调用对象已经发布，可能改变其线上状态。因此“工具名叫修改草稿”不足以建立安全边界。

P1实施要求：

- 在Core事务中完成资源授权、当前状态检查、内容revision比较及写入，锁定的是实际文章行。
- 新建限定为draft/pending；修改仅允许指定草稿状态。对象已发布、进入其他受保护状态或被删除时返回冲突，不自动下架原文。
- 使用统一内容revision或等价强前置条件。Web、API、Worker等相关写入都需更新同一版本，不能只给MCP增加一个不会被其他路径更新的计数器。
- 现有config_version用于部分质检配置检查，不能当成覆盖全文内容的通用并发版本。
- MCP先GET再PATCH只能辅助显示差异，无法消除两个请求之间的竞争；MCP内部互斥锁也无法约束Web和Worker。
- 仅允许title、content、excerpt、keywords、meta_description等经逐项确认的内容字段。status、review_status、task_id、质量配置、slug、URL及发布目标不混入普通草稿更新。
- 需要确认的动作绑定计划ID、输入摘要、资源revision、账号、实例、grant_version和过期时间。`confirmed:true`或模型生成的说明不能代替批准记录。
- 如需要最小化Core凭据的能力，增加专用草稿scope或等价Core策略。该scope为后续新增能力，不能在旧Token上凭空使用。

可信批准默认在GEOFlow已认证的确认页面完成。页面GET不执行变更，提交需CSRF保护和当前账号授权；秘密、验证码和批准凭据不要求用户粘贴到对话。未来接入ChatGPT受支持的可信确认信号时需单独验证，不能假定普通工具参数具有相同保证。

草稿写入本身也可能触发质检与模型消耗，预算与结果描述需要包含这些副作用。

## 8. 任务、预算、收据与不确定结果

### 8.1 防止任务配置在执行前漂移

已有TaskController会让缺少articles:publish的Token创建/修改的任务进入need_review模式，并阻止执行无需审核的任务。插件必须保留这些判断。

P2进一步要求：计划绑定任务revision、模型、提示词、知识来源、need_review、调度方式与渠道集合。排队时原子验证并保存执行快照；Worker按获准快照执行，或检测变更后停止。仅在MCP中检查一次need_review仍不足以保证后续执行配置不变。

首次只提交单个明确的生成工作。批量生成使用每项独立ID、总预算预留和可核对汇总，不能用一次工具调用无限循环入队。

预算建议包含每日调用量、生成篇数、最大并发、输入/输出Token上限、重试上限及质检/优化附加消耗。金额估计与已发生费用分开记录；只有上游计量完整才报告实际费用。未经可执行上限保护的模型调用不开放到P2。

### 8.2 两套幂等语义分别适配

旧业务写入使用`X-Idempotency-Key`；新任务收据使用`X-Client-Request-Id`，不能同时传入。请求ID由服务端的持久操作计划产生并复用，不由模型在重试时临时重生成。

为每项操作登记是否支持幂等、是否有收据、指纹范围、保留期限和恢复方式。旧Token失效、重新登录后，不能假设旧幂等重放拥有与新收据相同的身份续接语义；未经验证时停止并对账。

超时、502、进程崩溃、幂等记录stale、恢复后收据404，都可能对应结果未知。应保留请求日志与原ID，查询收据并核对业务结果。不能换ID、删除日志或重新提交来消除“不确定”提示。

Core业务记录、操作收据与待派发工作需要事务一致性或等价可恢复机制。MCP日志只能帮助排查，不能单独实现跨服务exactly-once保证。

### 8.3 状态与副作用分开表达

建议响应字段如下，属于新MCP输出契约：

```json
{
  "instance_id": "example-instance",
  "operation_id": "example-operation",
  "operation_state": "accepted",
  "work_state": "queued",
  "effects_state": "not_started",
  "resource_ids": [],
  "request_id": "example-request",
  "as_of": "2026-09-18T00:00:00Z",
  "next_action": "query_operation"
}
```

这些状态由具体适配器映射，保留必要的upstream_state以便核对。接受请求、执行完成、质检完成、主站发布与远端渠道发布分别报告。不能用HTTP 2xx、收据completed或生成出article_id证明文章已经全部发布。

同样，HTTP失败也不能一概解释为完全未写入。文章创建门禁可能保留草稿并返回阻断结果，需输出实际资源ID与副作用。部分渠道成功时返回逐渠道状态，撤回本地文章不保证外部渠道同步撤回。

首版使用有界状态查询，不承诺关闭对话后自动持续运行或主动通知。未来通知、Webhook或定时运营需独立定义事件鉴权、去重、重放和停止策略。

## 9. 数据边界、统计与提示注入

### 9.1 字段投影与证据

各工具使用独立输出字段白名单。默认不输出密钥、Cookie、完整供应商请求/响应、内部文件路径、联系方式、线索数据和整库知识。正文与质检证据按用户目的读取，限制单次长度，保留resource_id、revision、来源定位和截断标识。

模型、提示词及知识库访问控制必须沿用Core实际规则，不能因为catalog:read而返回所有配置。工具错误与审计日志也执行同样脱敏。

部署者应在同意页面解释哪些企业内容会进入ChatGPT或其他授权处理方。部署地域、企业数据要求、保留期限与对话平台的数据设置分别确认；本方案不声称已完成任何法律或合规认证。

### 9.2 时间窗与完整性

查询结果至少表达as_of、应用时区、使用的过滤条件、分页信息及是否完整。服务端时间转换为带时区时间戳，不能把不带时区的数据库字符串直接假定为UTC。

当前API不支持的时间筛选或聚合不得静默忽略。可以在明确页面/条数上限内计算“已读取样本”的结果并标为partial，或在后续Core PR中增加授权范围内的时间过滤与聚合。完整“最近7天”统计需要覆盖全部匹配数据，并处理分页过程中数据变化。文章数、渠道数与AI可见度之间不能自行建立不存在的数据口径。

### 9.3 提示注入与外连

文章、素材、质检证据和日志均按不可信业务数据处理。其内容不能改变实例、提升权限、提交批准或触发新工具操作。输出中的网址仅作证据引用，不能自动成为网络请求目标。

Core地址由部署配置绑定；模型不能提供base_url、认证头或重定向目标。对OAuth metadata、CIMD、JWKS以及其他必要发现请求施加HTTPS、目的地策略、大小/超时上限与重定向控制，防止配置与发现流程扩展成任意代理。内部服务例外必须精确登记，不能宽泛允许所有私网。

## 10. 运维、交付与插件安装

实现应交付：MCP服务源码与锁文件、Docker部署样例、只含占位值的配置样例、工具契约、Skill、插件包、操作手册及测试报告。相关目录在未来实施PR中建立，本PR不创建假可运行脚手架。

插件包按当前官方格式选择root plugin.json、mcp.json及skills目录，或经过验证的兼容格式。二者schema不同，不能直接改文件名。现有开发者模式注册的MCP连接还可能需要真实的平台注册映射标识，禁止在公共示例中编造可用ID。

安装步骤分开验收：

1. 确认测试站点、Core版本、实例身份、数据范围与账号权限。
2. 部署MCP端点与授权服务，完成metadata和协议联调。
3. 在实际目标ChatGPT账号/工作区建立连接并授权；核对扫描出的工具。
4. 单独验证MCP后再安装完整插件与Skill，重开会话验收。
5. 工具schema/权限/metadata变化后刷新连接并重新执行相关用例。

开发者模式端点可使用公开HTTPS或当前平台支持的Secure MCP Tunnel。私网开发联调与公开目录提交具有不同要求，不能把隧道可用当成公开发布资格。账号、工作区、客户端和功能开关以实际验收为准；不同帮助页面描述的历史入口不得作为唯一安装路径。

建议运维保护：独立非root容器、最少出站权限、日志脱敏、健康检查、连接/用户级限流、响应大小限制、TLS及受信任代理配置、持久收据、密钥轮换、写入总开关及按连接禁用。

审计记录包含工具、连接、账号、实例、授权版本、资源、计划、请求ID、结果状态、耗时及必要的前后摘要。无需保存完整聊天或业务正文。业务正文快照与审计事件分开授权、加密及设定保留期。

MCP停机不应影响原Web后台。关闭插件并不回滚已执行的业务动作；回滚与补偿须逐项列出能力和限制。

## 11. 实施路线及通过标准

| 阶段 | 工作内容 | 通过标准 |
| --- | --- | --- |
| P0：只读试点 | 授权绑定、只读工具、数据投影、协议、版本和分页契约 | 身份正确、无越界数据、撤销生效、无隐式写入、结果可与后台核对 |
| P1：草稿写入 | Core原子草稿接口、统一revision、计划/批准、幂等和收据 | 无覆盖他人修改、无误改已发布文章、重复提交不重复写、错误效果可核对 |
| P2：受控生成 | 任务配置快照、预算、队列状态和重试恢复 | 无超预算、无自动发布漂移、取消/撤销语义明确、超时不重复生成 |
| P3：发布协作 | 可信批准、质量快照、渠道集合、逐渠道回读 | 过期/变更批准失效、质量阻断不可绕过、部分成功和外部效果真实显示 |
| P4：产品化 | 多实例、组织分发、兼容矩阵、公开目录材料 | 完成独立租户与客户端验收，无共享凭据或跨实例混用 |

每阶段由验收表的对应门禁控制，不用工具数量或API路由数量替代完成率。P0可以先交付；P1到P3不能在Core前置条件缺失时仅靠Skill提示开放。

## 12. 资料与复核方法

仓库依据以第2节及配套复核报告中的固定提交为准。只做静态源码和文档交叉核对，尚未接入实际部署站点，也未执行端到端OAuth或业务测试。

官方资料，访问日期为2026-09-18；实施时重新核验当前客户端与协议版本：

- [OpenAI：插件认证](https://developers.openai.com/plugins/build/auth)
- [OpenAI：插件打包](https://developers.openai.com/plugins/build/plugins)
- [OpenAI：连接与测试](https://developers.openai.com/plugins/deploy/connect-chatgpt)
- [OpenAI：工具定义](https://developers.openai.com/plugins/plan/tools)
- [MCP：2025-11-25授权规范](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization)
- [MCP：2025-11-25传输规范](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports)
- [MCP：安全实践](https://modelcontextprotocol.io/specification/2025-11-25/basic/security_best_practices)

这些资料用于支持接口与安全要求的核对；阶段划分、GEOFlow操作计划、预算和验收门禁属于本方案的设计建议。
