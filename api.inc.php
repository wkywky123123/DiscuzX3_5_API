<?php
/**
 * Discuz! API Plugin - 主入口文件
 * 功能：配置初始化、安全校验、Action 路由分发
 */

if(!defined('IN_DISCUZ')) exit('Access Denied');

// --- 1. 基础配置与跨域处理 ---
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Requested-With, Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') exit;

// --- 2. 支持 Raw JSON Input ---
$raw_input = file_get_contents('php://input');
if (!empty($raw_input)) {
    $json_data = json_decode($raw_input, true);
    if (is_array($json_data)) {
        // 将 JSON 数据合并到 $_REQUEST
        $_REQUEST = array_merge($_REQUEST, $json_data);
    }
}

// --- 3. 插件设置读取 ---
$api_key = C::t('common_setting')->fetch('codeium_api_key');
$api_status = C::t('common_setting')->fetch('codeium_api_status');
$api_groupid = C::t('common_setting')->fetch('codeium_api_groupid');

$api_status = ($api_status !== false) ? $api_status : 0;
$api_key = ($api_key !== false) ? $api_key : '';
$api_groupid = ($api_groupid !== false) ? $api_groupid : 10;

// --- 4. 统一返回函数 ---
function api_return($code, $message, $data = null) {
    $result = array('code' => $code, 'message' => $message, 'data' => $data);
    echo json_encode($result, 256); // 256 = JSON_UNESCAPED_UNICODE
    exit;
}

// --- 5. 公共辅助解析函数 (供各 Action 模块直接调用) ---

/**
 * 将 [attach]aid[/attach] 标签转换为 HTML <img>
 */
function parse_attach_images($message) {
    global $_G;
    if(preg_match_all("/\[attach\](\d+)\[\/attach\]/i", $message, $matches)) {
        foreach($matches[1] as $aid) {
            $aid = intval($aid);
            $attach = C::t('forum_attachment')->fetch($aid);
            if($attach) {
                $attach_info = C::t('forum_attachment_n')->fetch($attach['tableid'], $aid);
                if($attach_info) {
                    $url = ($attach_info['remote'] ? $_G['setting']['ftp']['attachurl'] : $_G['siteurl'].'data/attachment/').'forum/'.$attach_info['attachment'];
                    $search = '[attach]' . $aid . '[/attach]';
                    $replace = '<img src="' . $url . '" class="api-image" style="max-width:100%;" />';
                    $message = str_replace($search, $replace, $message);
                }
            }
        }
    }
    return $message;
}

/**
 * 提取内容中的前 9 张图片预览 URL
 */
function get_thread_images($message) {
    global $_G;
    $images = array();
    // 过滤隐藏内容
    $message = preg_replace("/\[hide\]\s*(.*?)\s*\[\/hide\]/is", '', $message);
    // 匹配 [img]
    if(preg_match_all("/\[img\]\s*([^\[\<\r\n]+?)\s*\[\/img\]/is", $message, $matches)) {
        foreach($matches[1] as $url) $images[] = $url;
    }
    // 匹配 [attach]
    if(preg_match_all("/\[attach\](\d+)\[\/attach\]/i", $message, $matches)) {
        foreach($matches[1] as $aid) {
            $aid = intval($aid);
            $attach = C::t('forum_attachment')->fetch($aid);
            if($attach) {
                $attach_info = C::t('forum_attachment_n')->fetch($attach['tableid'], $aid);
                if($attach_info && $attach_info['isimage']) {
                    $url = ($attach_info['remote'] ? $_G['setting']['ftp']['attachurl'] : $_G['siteurl'].'data/attachment/') . 'forum/' . $attach_info['attachment'];
                    $images[] = $url;
                }
            }
        }
    }
    return array_slice($images, 0, 9);
}

// --- 6. 基础安全拦截 ---
if(!$api_status) api_return(-1, 'API service is disabled');

$request_key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
if(empty($api_key) || $request_key != $api_key) api_return(-2, 'Invalid API key');

// --- 7. Action 路由分发 (Switch 模式) ---

$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';

// ============================================================
// --- [新增] 安全层：Session/Cookie 与 UID 一致性校验 ---
// ============================================================

// 1. 定义不需要验证 Cookie 的白名单 Action
$public_actions = array(
    'login',            // 登录
    'register',         // 注册
    'verify_account',   // 账号验证
    'forum_list',       // 版块列表 (游客可看)
    'thread_list',      // 帖子列表 (游客可看)
    'thread_content',   // 帖子详情 (游客可看)
    'medal_list'        // 勋章列表
);

// 2. 获取请求中声称的 UID
$req_uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;

// 3. 执行校验逻辑
// 如果该动作不在白名单内，或者请求中带了特定的 UID (试图以某人身份操作)
if (!in_array($action, $public_actions) || $req_uid > 0) {
    
    // 全局变量 $_G['uid'] 是 Discuz 根据 Cookie 解密出来的真实用户 ID
    $session_uid = intval($_G['uid']);

    // 核心拦截：
    // 如果请求参数声称是 uid=X，但 Cookie 解析出来不是 X (可能是0，或者是其他人)
    if ($req_uid > 0 && $req_uid != $session_uid) {
        
        // 细分错误提示，方便调试
        if ($session_uid == 0) {
            // 场景：App 传了 uid，但没带 Cookie，或者 Cookie 过期
            api_return(-99, 'Session expired or not logged in. Please login again.');
        } else {
            // 场景：App 传了 uid=1，但 Cookie 是 uid=16 的
            api_return(-98, 'Security Alert: UID mismatch. You are logged in as UID ' . $session_uid);
        }
    }
}
// ============================================================

// 【安全防护】严格限制 action 字符，防止目录遍历攻击
if (!empty($action) && !preg_match('/^[a-z0-9_]+$/i', $action)) {
    api_return(-6, 'Invalid action format');
}

switch ($action) {
    case '':
        api_return(-3, 'Action is required');
        break;

    // 默认行为：动态加载 action 文件夹下的子文件
    default:
        // 构造模块文件绝对路径
        $action_file = DISCUZ_ROOT . './source/plugin/codeium_api/action/' . $action . '.inc.php';
        
        if (file_exists($action_file)) {
            // 引入子模块
            include $action_file;
        } else {
            api_return(-6, 'Unknown action: ' . $action);
        }
        break;
}

// 兜底逻辑
api_return(-6, 'End of request without response');