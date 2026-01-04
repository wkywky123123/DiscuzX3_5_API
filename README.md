# DiscuzX3.5 API 🚀

> 一个为 Discuz! X3.5 提供 API 能力的插件  
> 基于已有插件二次开发，但目标是：**真正能用**

本项目是对 Discuz 应用中心已有插件  
**「API接口插件 1.1（codeium_api）」** 的深度重构与重写。

简单说就是：

> 原插件能跑，但不太好用  
> 我看不下去了，然后重写了大部分
> 实际上中间因为我手滑不小心全删了，所以相当于重构了整个项目
> 

---

## ✨ 项目特性

- 面向 Discuz! X3.5
- 提供统一的 API 接口入口
- JSON 格式返回，适合 App / 小程序 / 前端调用
- 接口结构更清晰，逻辑更可读（自卖自夸了属于是）

---

## ⚠️ 安装方式（非常重要）

**不要直接把本项目丢进 Discuz。**

正确安装流程如下：

### 1️⃣ 安装基础插件（必须）

前往 Discuz 应用中心，安装：

👉 **API接口插件 1.1**

地址：

[https://addon.dismall.com/plugins/codeium_api.html](https://addon.dismall.com/plugins/codeium_api.html)


安装完成后，确保插件可以正常启用。

---

### 2️⃣ 覆盖为本项目代码

将本仓库中的文件覆盖到 Discuz 原插件目录


也就是插件的安装目录本身。

> 本项目是 **在原有插件基础上的替换与增强**，  
> 不是一个完全独立的插件。

---

## 📂 项目结构说明


```text
codeium_api/
├── template/
│   └── post_reply.htm
├── admincp.inc.php
├── api.inc.php
├── api_doc.html
└── doc.inc.php
````

### 各文件说明

#### `api.inc.php`

* API 主入口
* 核心逻辑集中地

#### `api_doc.html`

* API 接口文档
* 包含接口说明、参数、返回结构

#### `doc.inc.php`

* 文档相关逻辑
* 用于在 Discuz 后台环境中展示说明

#### `admincp.inc.php`

* 插件后台配置入口
* API Key 等设置项

#### `template/post_reply.htm`

* 历史遗留模板文件
* 来源于早期版本的他人实现
* 功能有限，当前项目中**基本未使用**
* 保留它的原因只有一个：删了怕哪天出事

---

## 🔐 API 调用与鉴权

所有接口请求必须包含以下参数：

| 参数     | 必填 | 说明                    |
| ------ | -- | --------------------- |
| id     | 是  | 固定值：`codeium_api:api` |
| key    | 是  | 后台配置的 API Key         |
| action | 是  | 接口动作名称                |

示例：

```
GET /plugin.php?id=codeium_api:api&key=YOUR_KEY&action=forum_list
```

---

## 📚 API 接口文档

完整接口文档请查看：

```
api_doc.html
```


---

## 🧩 二次开发说明

### 新增接口的一般思路

* 所有接口统一通过 `api.inc.php` 进入
* 根据 `action` 分发逻辑
* 返回数据必须是 JSON
* 不要完全信任客户端传参
  >一定要鉴权！！！
  >你永远都不知道你的用户会搞什么骚操作！！！
  >

统一返回结构：

```json
{
  "code": 0,
  "message": "Success",
  "data": {}
}
```

---

## 🚫 关于注册接口的说明

### ❌ 不提供 API 注册接口

项目中 **明确放弃** 通过 API 进行用户注册。

原因：

* Discuz 注册流程高度复杂
* 涉及安全校验、验证码、风控
* API 注册极易被滥用为批量注册工具
* 实现成本与安全风险不成正比

### ✅ 推荐做法

> 客户端跳转 Discuz 官方注册页面
> 注册完成后，再通过 API 进行登录和数据交互

这是**主动设计选择**，不是功能缺失。

### 🚩不过
如果有大牛愿意来解决这个问题，我也非常欢迎你来PR
反正这玩意不是我一个大一学生能办得了的ヽ(*。>Д<)o゜

---

## 📝 TODO

以下接口和功能已完成设计构想，但由于时间、精力、小AI每日tokens限制、以及人类寿命有限等客观原因，目前仍处于 **规划 / 半成品 / 等哪天有空** 状态。

欢迎有兴趣的开发者一起补完。

ReadMe文档中的Todo不再添加新内容，因为太过麻烦，新的Todo可以前往[https://todo.mrgeda.top/](https://todo.mrgeda.top/)查看

- [x] **用户勋章接口**
  - `user_medal_list`：获取用户已拥有的勋章
  - `medal_list`：勋章基础信息（名称、图标、说明）
  > 在做了在做了

- [ ] **用户收藏接口**
  - `favorite_list`：获取用户收藏的帖子列表
  - `favorite_add`：收藏指定主题
  - `favorite_remove`：取消收藏  
  > 在做了

- [ ] **点赞 / 支持接口**
  - `thread_like`
  - `post_like`  
  >尽量做吧，我真不想干了

- [ ] **附件下载鉴权接口**
  - `attachment_info`
  - `attachment_download_token`  
  > 防止附件被直接扒走，顺便减少站长血压波动

- [ ] **用户等级接口**
  - `user_level_info`：当前等级与头衔
  - `level_rule_list`：等级与积分规则
  - `level_progress`：升级进度  
  > 实际上可以在客户端判断的


---

## 📄 License

MIT License
随便你怎么用，怎么改，但是出了事不要来找我就是了~

---

## 📌 项目说明

本项目的目标不是“功能最多”，而是：

> **在 Discuz 的现实限制下，做一个能长期维护的 API 插件**

如果你刚好也在和 Discuz 打交道，那你大概懂这有多难。
