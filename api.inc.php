<?php
if(!defined('IN_DISCUZ')) exit('Access Denied');

// --- 1. 基础配置与跨域 ---
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Requested-With, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') exit;

// ==========================================
// Raw JSON Input
// ==========================================
$raw_input = file_get_contents('php://input');
if (!empty($raw_input)) {
    $json_data = json_decode($raw_input, true);
    if (is_array($json_data)) {
        // 将 JSON 数据合并到 $_REQUEST，确保后续代码能读到 action 和 poll_text
        $_REQUEST = array_merge($_REQUEST, $json_data);
    }
}
// ==========================================

// --- 2. 插件设置读取 ---
$api_key = C::t('common_setting')->fetch('codeium_api_key');
$api_status = C::t('common_setting')->fetch('codeium_api_status');
$api_groupid = C::t('common_setting')->fetch('codeium_api_groupid');

$api_status = $api_status !== false ? $api_status : 0;
$api_key = $api_key !== false ? $api_key : '';
$api_groupid = $api_groupid !== false ? $api_groupid : 10;

// --- 3. 统一返回函数 ---
function api_return($code, $message, $data = null) {
    $result = array('code' => $code, 'message' => $message, 'data' => $data);
    echo json_encode($result, 256);
    exit;
}

// --- 4. 辅助解析函数 ---

/**
 * 函数 1: 将内容中的 [attach]aid[/attach] 标签转换为 HTML <img> 标签
 * 场景: 用于帖子详情 (thread_content) 或 回帖列表 (post_list) 的正文展示
 */
function parse_attach_images($message) {
    global $_G;
    if(preg_match_all("/\[attach\](\d+)\[\/attach\]/i", $message, $matches)) {
        foreach($matches[1] as $aid) {
            $aid = intval($aid);
            // 1. 查询附件主表获取 tableid
            $attach = C::t('forum_attachment')->fetch($aid);
            if($attach) {
                // 2. 查询附件详情表
                $attach_info = C::t('forum_attachment_n')->fetch($attach['tableid'], $aid);
                if($attach_info) {
                    // 3. 判断远程/本地并拼接 URL
                    $url = ($attach_info['remote'] ? $_G['setting']['ftp']['attachurl'] : $_G['siteurl'].'data/attachment/').'forum/'.$attach_info['attachment'];
                    
                    // 4. 替换标签
                    $search = '[attach]' . $aid . '[/attach]';
                    // 添加 class="api-image" 方便前端 CSS 控制，max-width 防止撑破屏幕
                    $replace = '<img src="' . $url . '" class="api-image" style="max-width:100%;" />';
                    $message = str_replace($search, $replace, $message);
                }
            }
        }
    }
    return $message;
}

/**
 * 函数 2: 提取内容中的所有图片 URL (包含外链 [img] 和附件 [attach])
 * 场景: 用于帖子列表 (thread_list) 的九宫格/瀑布流预览
 */
function get_thread_images($message) {
    global $_G;
    $images = array();

    // 1. 安全过滤：去除 [hide] 隐藏内容，防止预览泄密
    $message = preg_replace("/\[hide\]\s*(.*?)\s*\[\/hide\]/is", '', $message);

    // 2. 提取 [img] 标签 (外链图片)
    if(preg_match_all("/\[img\]\s*([^\[\<\r\n]+?)\s*\[\/img\]/is", $message, $matches)) {
        foreach($matches[1] as $url) {
            $images[] = $url;
        }
    }

    // 3. 提取 [attach] 标签 (附件图片)
    if(preg_match_all("/\[attach\](\d+)\[\/attach\]/i", $message, $matches)) {
        foreach($matches[1] as $aid) {
            $aid = intval($aid);
            $attach = C::t('forum_attachment')->fetch($aid);
            if($attach) {
                $attach_info = C::t('forum_attachment_n')->fetch($attach['tableid'], $aid);
                // 仅提取图片类型的附件 (isimage = 1)
                if($attach_info && $attach_info['isimage']) {
                    $url = ($attach_info['remote'] ? $_G['setting']['ftp']['attachurl'] : $_G['siteurl'].'data/attachment/') . 'forum/' . $attach_info['attachment'];
                    $images[] = $url;
                }
            }
        }
    }

    // 限制返回数量 (最多预览前 9 张)
    return array_slice($images, 0, 9);
}

// --- 5. 权限与密钥检查 ---
if(!$api_status) api_return(-1, 'API service is disabled');
$request_key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
if(empty($api_key) || $request_key != $api_key) api_return(-2, 'Invalid API key');

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

// --- 6. 业务逻辑区 (使用 if-else 结构，绝对不要用 break) ---

if ($action == 'login') {
    $username = isset($_REQUEST['username']) ? trim($_REQUEST['username']) : '';
    $password = isset($_REQUEST['password']) ? $_REQUEST['password'] : '';
    
    if(!$username || !$password) {
        api_return(-3, 'Username and password are required');
    }
    
    require_once libfile('function/member');
    // 调用 Discuz 标准登录函数
    $result = userlogin($username, $password, 0, '', 'username');
    
    if($result['status'] == 1) {
        // 登录成功，设置 Discuz 全局登录状态
        setloginstatus($result['member'], 1296000);
        
        // 准备返回给 App 的 Cookie 信息（用于 WebView 同步）
        $cookie_info = array(
            'prefix' => $_G['config']['cookie']['cookiepre'],
            'auth' => getglobal('auth', 'cookie'),
            'saltkey' => getglobal('saltkey', 'cookie'),
            'domain' => $_G['config']['cookie']['cookiedomain'],
            'path' => $_G['config']['cookie']['cookiepath']
        );

        api_return(0, 'Login successful', array(
            'uid' => $result['member']['uid'],
            'username' => $result['member']['username'],
            'email' => $result['member']['email'],
            'cookie_info' => $cookie_info
        ));
    } else {
        // 登录失败
        api_return(-4, 'Login failed: ' . $result['status']);
    }
    
    
    
    
    
    
    
} elseif ($action == 'userinfo') {
    $q = isset($_REQUEST['query']) ? trim($_REQUEST['query']) : '';
    if(empty($q)) {
        api_return(-3, 'Query parameter (UID or Username) is required');
    }

    // 1. 确定 UID (支持输入数字 UID 或 用户名)
    $uid = is_numeric($q) ? intval($q) : C::t('common_member')->fetch_uid_by_username($q);
    
    // 2. 获取用户主表数据
    $member = C::t('common_member')->fetch($uid);
    if(!$member) {
        api_return(-6, 'User not found');
    }
    
    // 3. 获取其他关联表数据 (积分、统计、资料)
    $profile = C::t('common_member_profile')->fetch($uid);
    $count = C::t('common_member_count')->fetch($uid);
    $status = C::t('common_member_status')->fetch($uid);
    $group = C::t('common_usergroup')->fetch($member['groupid']);

    // 4. [核心功能] 动态处理后台设置的个人资料栏目
    loadcache('profilesetting');
    $profile_data = array();
    
    if(is_array($_G['cache']['profilesetting'])) {
        foreach($_G['cache']['profilesetting'] as $fieldid => $setting) {
            // 只读取后台设置为“启用”的栏目
            if($setting['available']) {
                $val = isset($profile[$fieldid]) ? $profile[$fieldid] : '';
                // 使用后台设置的“栏目名称”作为 Key，这样 App 端可以直接显示名称
                $profile_data[$setting['title']] = $val;
            }
        }
    }

    // 5. 组装返回数据
    $user_info = array(
        'uid' => $member['uid'],
        'username' => $member['username'],
        'email' => $member['email'],
        'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$uid.'&size=middle',
        'groupid' => $member['groupid'],
        'groupname' => $group['grouptitle'],
        'credits' => $member['credits'],
        'regdate' => dgmdate($member['regdate'], 'u'), // 格式化日期
        
        // 动态资料列表
        'profile' => $profile_data,
        
        // 积分详细统计
        'extcredits' => array(
            '1' => $count['extcredits1'],
            '2' => $count['extcredits2'],
            '3' => $count['extcredits3'],
            '4' => $count['extcredits4'],
            '5' => $count['extcredits5'],
            '6' => $count['extcredits6'],
            '7' => $count['extcredits7'],
            '8' => $count['extcredits8'],
        ),
        
        // 帖子统计
        'posts' => $count['posts'],
        'threads' => $count['threads'],
        
        // 最后活跃
        'lastactivity' => dgmdate($status['lastactivity'], 'u')
    );
    
    api_return(0, 'Get user info success', $user_info);

} elseif ($action == 'latest_threads') {
    // --- 全站最新动态 (含图片预览、特殊主题标识) ---

    // 1. 接收参数
    $limit = isset($_REQUEST['limit']) ? max(1, min(50, intval($_REQUEST['limit']))) : 10;
    $fid = isset($_REQUEST['fid']) ? intval($_REQUEST['fid']) : 0;
    
    // 2. 构建查询条件
    $where = "WHERE t.displayorder >= 0"; // 排除回收站
    if($fid) $where .= " AND t.fid=" . $fid;
    
    // 3. 联表查询
    // 必须 JOIN forum_post (first=1) 获取内容用于提取图片
    // 新增 t.special 字段
    $sql = "SELECT t.tid, t.fid, t.subject, t.author, t.authorid, t.dateline, t.views, t.replies, t.special,
                   f.name as forum_name, 
                   p.message 
            FROM " . DB::table('forum_thread') . " t 
            LEFT JOIN " . DB::table('forum_forum') . " f ON f.fid=t.fid 
            LEFT JOIN " . DB::table('forum_post') . " p ON p.tid=t.tid AND p.first=1 
            $where 
            ORDER BY t.dateline DESC 
            LIMIT $limit";

    $query = DB::query($sql);
    $list = array();
    
    while($row = DB::fetch($query)) {
        // A. 处理摘要 (统一使用正则清洗，保留100字)
        $message_text = $row['message'];
        $message_text = preg_replace("/\[attach\]\d+\[\/attach\]/i", '', $message_text);
        $message_text = strip_tags(preg_replace("/\[.+?\]/is", '', $message_text));
        $abstract = mb_substr(trim($message_text), 0, 100, 'utf-8');
        if(mb_strlen(trim($message_text), 'utf-8') > 100) $abstract .= '...';

        // B. 调用辅助函数提取图片
        $image_list = get_thread_images($row['message']);
        
        $list[] = array(
            'tid' => $row['tid'],
            'fid' => $row['fid'],
            'forum_name' => $row['forum_name'],
            'subject' => $row['subject'],
            'abstract' => $abstract,
            'author' => $row['author'],
            'authorid' => $row['authorid'],
            'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$row['authorid'].'&size=small',
            'dateline' => dgmdate($row['dateline'], 'u'),
            'views' => $row['views'],
            'replies' => $row['replies'],
            // [新增] 特殊主题类型
            'special_type' => intval($row['special']), // 0普通, 1投票, 2商品...
            // [新增] 图片预览
            'image_list' => $image_list,
            'has_image' => !empty($image_list) ? 1 : 0
        );
    }
    
    api_return(0, 'Success', array('list' => $list));
    
    
    
    
    

} elseif ($action == 'new_thread') {
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $fid = isset($_REQUEST['fid']) ? intval($_REQUEST['fid']) : 0;
    $typeid = isset($_REQUEST['typeid']) ? intval($_REQUEST['typeid']) : 0; // 新增主题分类ID
    $subject = isset($_REQUEST['subject']) ? trim($_REQUEST['subject']) : '';
    $message = isset($_REQUEST['message']) ? trim($_REQUEST['message']) : '';
    
    if(!$uid || !$fid || !$subject || !$message) {
        api_return(-3, 'Missing required parameters');
    }

    $member = C::t('common_member')->fetch($uid);
    if(!$member) api_return(-6, 'User not found');

    // --- 核心修改：检查主题分类权限与强制性 ---
    $forum = C::t('forum_forum')->fetch($fid);
    $forumfield = C::t('forum_forumfield')->fetch($fid);
    if(!$forum || $forum['status'] != 1) api_return(-7, 'Forum invalid');

    // 解析版块的主题分类设置
    $threadtypes = unserialize($forumfield['threadtypes']);
    if($threadtypes['required'] && !$typeid) {
        // 如果版块强制要求分类，但用户没传 typeid
        api_return(-13, 'Thread type is required for this forum');
    }
    
    // 验证传过来的 typeid 是否属于该版块
    if($typeid && !isset($threadtypes['types'][$typeid])) {
        api_return(-13, 'Invalid thread type ID for this forum');
    }

    $now = time();
    $newthread = array(
        'fid' => $fid,
        'typeid' => $typeid, // 存入分类ID
        'author' => $member['username'],
        'authorid' => $uid,
        'subject' => $subject,
        'dateline' => $now,
        'lastpost' => $now,
        'lastposter' => $member['username'],
        'status' => 32, 
    );
    $tid = C::t('forum_thread')->insert($newthread, true);

    $pid = C::t('forum_post_tableid')->insert(array('pid' => null), true);
    C::t('forum_post')->insert($fid, array(
        'pid' => $pid, 'fid' => $fid, 'tid' => $tid, 'first' => 1,
        'author' => $member['username'], 'authorid' => $uid,
        'subject' => $subject, 'dateline' => $now, 'message' => $message,
        'useip' => $_G['clientip']
    ));

    C::t('forum_forum')->update_forum_counter($fid, 1, 1, 1);
    C::t('common_member_count')->increase($uid, array('threads' => 1, 'posts' => 1));
    api_return(0, 'Thread created successfully', array('tid' => $tid, 'typeid' => $typeid));
    
    
    
    
    
    
} elseif ($action == 'forum_list') {
    // 1. 联表查询：版块主表 + 详情表
    $query = DB::query("SELECT f.*, ff.moderators, ff.threadtypes, ff.viewperm, ff.postperm, ff.replyperm, ff.getattachperm, ff.postattachperm, ff.description 
                        FROM ".DB::table('forum_forum')." f 
                        LEFT JOIN ".DB::table('forum_forumfield')." ff ON ff.fid=f.fid 
                        WHERE f.status=1 
                        ORDER BY f.type ASC, f.displayorder ASC");
    
    $groups = array(); 
    $forums = array(); 
    
    while($row = DB::fetch($query)) {
        // --- A. 解析版主列表 ---
        $moderators = $row['moderators'] ? explode("\t", trim($row['moderators'])) : array();

        // --- B. 解析主题分类 (threadtypes) ---
        $raw_types = unserialize($row['threadtypes']);
        $threadtypes = array(
            'required' => isset($raw_types['required']) ? $raw_types['required'] : '0',
            'listable' => isset($raw_types['listable']) ? $raw_types['listable'] : '0',
            'types' => isset($raw_types['types']) ? $raw_types['types'] : (object)array()
        );

        // --- C. 解析权限表 (perms) ---
        // Discuz 权限存储格式为 "1\t2\t10" (即允许的用户组ID)
        $perms = array(
            'view' => $row['viewperm'] ? explode("\t", trim($row['viewperm'])) : array(),
            'post' => $row['postperm'] ? explode("\t", trim($row['postperm'])) : array(),
            'reply' => $row['replyperm'] ? explode("\t", trim($row['replyperm'])) : array(),
            'getattach' => $row['getattachperm'] ? explode("\t", trim($row['getattachperm'])) : array(),
            'postattach' => $row['postattachperm'] ? explode("\t", trim($row['postattachperm'])) : array()
        );

        // --- D. 提取最后发表的 200 字摘要 (延续之前的功能) ---
        $last_summary = '';
        $last_tid = 0;
        if($row['lastpost']) {
            $lp_data = explode("\t", $row['lastpost']);
            $last_tid = intval($lp_data[0]);
            if($last_tid) {
                $last_msg = DB::result_first("SELECT message FROM ".DB::table('forum_post')." WHERE tid=$last_tid AND first=1");
                if($last_msg) {
                    $last_msg = preg_replace("/\[attach\]\d+\[\/attach\]/i", '', $last_msg);
                    $last_msg = preg_replace("/\[.+?\]/is", '', $last_msg);
                    $last_msg = strip_tags($last_msg);
                    $last_summary = mb_substr($last_msg, 0, 200, 'utf-8');
                    if(mb_strlen($last_msg, 'utf-8') > 200) $last_summary .= '...';
                }
            }
        }

        // --- E. 组装单条版块数据 ---
        $forum_info = array(
            'fid' => $row['fid'],
            'type' => $row['type'],
            'name' => $row['name'],
            'status' => $row['status'],
            'threads' => $row['threads'],
            'posts' => $row['posts'],
            'todayposts' => $row['todayposts'],
            'lastpost' => $row['lastpost'],
            'lastposter' => $row['lastposter'],
            'last_tid' => $last_tid,
            'last_summary' => $last_summary,
            'moderators' => $moderators,
            'threadtypes' => $threadtypes,
            'perms' => $perms,
            'fup' => $row['fup']
        );

        if($row['type'] == 'group') {
            $forum_info['forums'] = array();
            $groups[$row['fid']] = $forum_info;
        } else {
            $forums[] = $forum_info;
        }
    }

    // --- F. 树状归类 ---
    $root_list = array();
    foreach($forums as $f) {
        if(isset($groups[$f['fup']])) {
            $groups[$f['fup']]['forums'][] = $f;
        } else {
            $root_list[] = $f;
        }
    }
    
    api_return(0, 'Forum list retrieved successfully', array('list' => array_values($groups)));
    
    
    
    
    
    
} elseif ($action == 'post_reply') {
    // 1. 接收并处理参数
    $tid = isset($_REQUEST['tid']) ? intval($_REQUEST['tid']) : 0;
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $message = isset($_REQUEST['message']) ? trim($_REQUEST['message']) : '';
    // notify 参数用于控制是否发送系统通知给楼主，默认为 1 (发送)
    $notify = isset($_REQUEST['notify']) ? intval($_REQUEST['notify']) : 1;

    if(!$tid || !$uid || !$message) {
        api_return(-3, 'Missing required parameters (tid, uid, message)');
    }

    // 2. 检查用户和主题是否存在
    $member = C::t('common_member')->fetch($uid);
    if(!$member) api_return(-6, 'User not found');

    $thread = C::t('forum_thread')->fetch($tid);
    if(!$thread || $thread['displayorder'] < 0) {
        api_return(-8, 'Thread not found or deleted');
    }

    $fid = $thread['fid'];
    $now = time();

    // 3. 计算回复的楼层位置 (position)
    // 获取当前最大楼层并 +1
    $maxposition = DB::result_first("SELECT maxposition FROM ".DB::table('forum_thread')." WHERE tid=%d", array($tid));
    $position = intval($maxposition) + 1;

    // 4. 插入帖子数据
    $pid = C::t('forum_post_tableid')->insert(array('pid' => null), true);
    $new_post = array(
        'pid' => $pid,
        'fid' => $fid,
        'tid' => $tid,
        'first' => 0, // 0 表示它是回复，不是楼主
        'author' => $member['username'],
        'authorid' => $uid,
        'subject' => '', // 回帖通常不需要标题
        'dateline' => $now,
        'message' => $message,
        'useip' => $_G['clientip'],
        'invisible' => 0,
        'position' => $position
    );
    C::t('forum_post')->insert($fid, $new_post);

    // 5. 更新主题表统计信息
    C::t('forum_thread')->update($tid, array(
        'lastposter' => $member['username'],
        'lastpost' => $now,
        'replies' => $thread['replies'] + 1,
        'maxposition' => $position
    ));

    // 6. 更新版块和用户的帖子计数
    C::t('forum_forum')->update_forum_counter($fid, 0, 1, 1); // 仅增加回帖数和今日贴数
    C::t('common_member_count')->increase($uid, array('posts' => 1));

    // 7. [可选] 发送通知给楼主
    if($notify && $thread['authorid'] != $uid) {
        // 构造简单的通知文本
        $note = $member['username'] . ' 回复了您的帖子：' . $thread['subject'];
        notification_add($thread['authorid'], 'post', $note, array('from_id' => $tid, 'from_idtype' => 'post'), 1);
    }

    api_return(0, 'Reply posted successfully', array(
        'pid' => $pid,
        'tid' => $tid,
        'position' => $position
    ));
    
    
    
    
    
    
} elseif ($action == 'upload_image') {
    // 1. 基础校验
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    if(!$uid) {
        api_return(-3, 'User ID is required');
    }

    // 检查是否有文件上传，Discuz 默认建议参数名为 'file'
    if (!isset($_FILES['file'])) {
        api_return(-3, 'No file uploaded (use parameter name "file")');
    }

    // 2. 加载 Discuz 上传类
    require_once libfile('class/upload');
    $upload = new discuz_upload();

    // 初始化上传，'forum' 表示存放在论坛附件目录
    // init 会自动处理文件合法性检查（后缀、大小等）
    $res = $upload->init($_FILES['file'], 'forum');
    if(!$res) {
        api_return(-10, 'Upload init failed: ' . $upload->errormessage());
    }

    // 3. 保存文件
    // save() 会自动处理目录创建（按日期）、重命名等逻辑
    $attach = $upload->save();
    if(!$attach) {
        api_return(-10, 'Save file failed: ' . $upload->errormessage());
    }

    // 4. 将附件信息写入数据库（这一步才能产生 aid）
    // 默认作为临时附件，等到发帖提交时再正式关联
    $data = array(
        'atid' => 0, // 还没关联到具体帖子，先填 0
        'uid' => $uid,
        'dateline' => TIMESTAMP,
        'filename' => $attach['name'],
        'filesize' => $attach['size'],
        'attachment' => $attach['attachment'],
        'isimage' => $attach['isimage'],
        'thumb' => $attach['thumb'],
        'remote' => $attach['remote'],
        'width' => $attach['width'],
    );
    
    // 生成全局唯一的 aid
    $aid = C::t('forum_attachment')->insert(array('uid' => $uid, 'tableid' => 127), true);
    // 写入具体的附件分表
    C::t('forum_attachment_n')->insert('127', array_merge(array('aid' => $aid), $data));

    // 5. 拼装返回结果
    // 判断是远程还是本地附件
    if($attach['remote']) {
        $url = $_G['setting']['ftp']['attachurl'] . 'forum/' . $attach['attachment'];
    } else {
        $url = $_G['siteurl'] . 'data/attachment/forum/' . $attach['attachment'];
    }

    api_return(0, 'Upload success', array(
        'aid' => $aid,             // 重要：App发帖时需要这个 ID
        'url' => $url,             // 用于在 App 端即时预览
        'width' => $attach['width'],
        'filesize' => $attach['size']
    ));
    
    
    
    
    
} elseif ($action == 'credit') {
    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $type = isset($_REQUEST['type']) ? trim($_REQUEST['type']) : ''; // 格式如 extcredits1, extcredits2
    $value = isset($_REQUEST['value']) ? intval($_REQUEST['value']) : 0; // 增减值，正数为加，负数为减
    $reason = isset($_REQUEST['reason']) ? trim($_REQUEST['reason']) : 'API Operation'; // 变动原因

    // 2. 基础校验
    if(!$uid || !$type || !$value) {
        api_return(-3, 'Missing parameters (uid, type, value)');
    }

    // 检查积分字段格式是否正确 (extcredits1 - extcredits8)
    if(!preg_match('/^extcredits[1-8]$/', $type)) {
        api_return(-7, 'Invalid credit type (must be extcredits1 to extcredits8)');
    }

    // 检查用户是否存在
    $member = C::t('common_member')->fetch($uid);
    if(!$member) api_return(-6, 'User not found');

    // 3. 检查该积分项在 Discuz 后台是否已启用
    loadcache('setting');
    $credit_idx = intval(substr($type, -1)); // 提取数字部分
    if(!isset($_G['setting']['extcredits'][$credit_idx])) {
        api_return(-7, 'This credit type is not enabled in forum settings');
    }
    
    $credit_info = $_G['setting']['extcredits'][$credit_idx];
    $credit_name = $credit_info['title']; // 获取积分名称，如 "金钱"、"威望"

    // 4. 获取变动前的余额 (用于返回对比)
    $old_count = C::t('common_member_count')->fetch($uid);
    $old_val = $old_count[$type];

    // 5. 调用 Discuz 核心函数更新积分
    // updatemembercount 参数: UID, 变动数组, 是否检查上限, 记录类型, 相关ID, 来源UID, 原因
    require_once libfile('function/post'); // 引入积分相关函数库
    updatemembercount($uid, array($credit_idx => $value), true, 'API', 0, 0, $reason);

    // 6. 发送系统通知（提醒用户）
    $val_str = ($value > 0 ? '+' : '') . $value;
    $note = "您的 {$credit_name} 有变动：{$val_str} {$credit_info['unit']}。原因：{$reason}";
    notification_add($uid, 'system', $note, array(), 1);

    // 7. 获取变动后的余额
    $new_count = C::t('common_member_count')->fetch($uid);

    api_return(0, 'Credit updated successfully', array(
        'uid' => $uid,
        'username' => $member['username'],
        'credit_type' => $type,
        'credit_name' => $credit_name,
        'old_value' => $old_val,
        'change_value' => $value,
        'new_value' => $new_count[$type]
    ));
    
    
    
    
    
    
} elseif ($action == 'verify_account') {
    // 1. 接收参数
    $username = isset($_REQUEST['username']) ? trim($_REQUEST['username']) : '';
    $password = isset($_REQUEST['password']) ? $_REQUEST['password'] : '';

    if(empty($username) || empty($password)) {
        api_return(-3, 'Username and password required');
    }

    // 2. 加载 Discuz 核心函数
    require_once libfile('function/member');
    loaducenter();

    // 3. 先检查用户是否存在以及账号状态
    $user = C::t('common_member')->fetch_by_username($username);
    if(!$user) {
        api_return(-6, 'User not found');
    }
    
    // 检查是否被禁止访问 (status = -1)
    if($user['status'] == -1) {
        api_return(-7, 'Account is banned', array('uid' => $user['uid'], 'status' => 'banned'));
    }

    // 4. 调用核心登录函数进行密码比对
    // 注意：这里仅做校验，不产生登录 Session
    $login_result = userlogin($username, $password, '', '', 'username', '');

    if($login_result['status'] == 1) {
        // 验证成功
        api_return(0, 'Account verification successful', array(
            'uid' => $user['uid'],
            'username' => $user['username'],
            'email' => $user['email'],
            'groupid' => $user['groupid'],
            'status' => 'normal'
        ));
    } else {
        // 验证失败：密码错误或其它 UC 错误
        api_return(-8, 'Invalid password or verification failed', array('status_code' => $login_result['status']));
    }
    
} elseif ($action == 'send_notification') {
    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $message = isset($_REQUEST['message']) ? trim($_REQUEST['message']) : '';
    $url = isset($_REQUEST['url']) ? trim($_REQUEST['url']) : ''; // 点击提醒后跳转的链接（可选）

    // 2. 基础校验
    if(!$uid || !$message) {
        api_return(-3, 'Missing parameters (uid, message)');
    }

    // 检查用户是否存在
    $member = C::t('common_member')->fetch($uid);
    if(!$member) {
        api_return(-6, 'User not found');
    }

    // 3. 处理带链接的消息内容
    // 如果提供了 URL，我们将消息包装成 HTML 链接，这样用户在网页端点击提醒时能直接跳转
    if($url) {
        $message = '<a href="' . $url . '" target="_blank">' . $message . '</a>';
    }

    // 4. 调用 Discuz 原生函数发送提醒
    // 参数说明：接收者UID, 提醒类型(system), 消息内容, 模板变量, 是否强制显示
    require_once libfile('function/home'); // 确保加载了 home 模块下的通知函数
    notification_add($uid, 'system', $message, array(), 1);

    // 5. 返回成功
    api_return(0, 'Notification sent successfully', array(
        'uid' => $uid,
        'username' => $member['username'],
        'sent_content' => $message
    ));
    
} elseif ($action == 'post_list') {
    // 1. 接收参数
    $tid = isset($_REQUEST['tid']) ? intval($_REQUEST['tid']) : 0;
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(100, intval($_REQUEST['perpage']))) : 20;
    
    if(!$tid) {
        api_return(-3, 'Thread ID (tid) is required');
    }

    // 2. 检查主题是否存在
    $thread = C::t('forum_thread')->fetch($tid);
    if(!$thread || $thread['displayorder'] < 0) {
        api_return(-8, 'Thread not found or deleted');
    }

    // 3. 计算分页起始位置
    $start = ($page - 1) * $perpage;

    // 4. 查询回复列表
    // 条件：tid 匹配，且 first = 0 (排除楼主贴自己，因为楼主贴通常由 userinfo 或 latest_threads 展示)
    // 排序：按楼层顺序排列 (ASC)
    $sql = "SELECT p.*, m.username 
            FROM " . DB::table('forum_post') . " p 
            LEFT JOIN " . DB::table('common_member') . " m ON m.uid = p.authorid 
            WHERE p.tid = %d AND p.first = 0 AND p.invisible = 0 
            ORDER BY p.dateline ASC 
            LIMIT %d, %d";
    
    $query = DB::query($sql, array($tid, $start, $perpage));
    $list = array();
    
    while($row = DB::fetch($query)) {
        $list[] = array(
            'pid' => $row['pid'],
            'tid' => $row['tid'],
            'author' => $row['author'],
            'authorid' => $row['authorid'],
            'dateline' => dgmdate($row['dateline'], 'u'), // 格式化时间
            'position' => $row['position'], // 楼层号
            // [核心] 解析回复内容中的 [attach] 标签为图片
            'content' => parse_attach_images($row['message']),
            'status' => $row['status']
        );
    }

    // 5. 获取总回复数用于分页
    $total = DB::result_first("SELECT COUNT(*) FROM ".DB::table('forum_post')." WHERE tid=%d AND first=0 AND invisible=0", array($tid));

    api_return(0, 'Success', array(
        'total' => $total,
        'page' => $page,
        'perpage' => $perpage,
        'total_page' => ceil($total / $perpage),
        'list' => $list
    ));
    
    } elseif ($action == 'user_list') {
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(100, intval($_REQUEST['perpage']))) : 20;
    $search = isset($_REQUEST['search']) ? trim($_REQUEST['search']) : '';
    $groupid = isset($_REQUEST['groupid']) ? intval($_REQUEST['groupid']) : 0;
    
    $where = array();
    $params = array();
    if($search) { $where[] = "m.username LIKE %s"; $params[] = '%'.$search.'%'; }
    if($groupid) { $where[] = "m.groupid = %d"; $params[] = $groupid; }
    
    $wheresql = $where ? ' WHERE '.implode(' AND ', $where) : '';
    $start = ($page - 1) * $perpage;
    
    $total = DB::result_first("SELECT COUNT(*) FROM ".DB::table('common_member')." m $wheresql", $params);
    
    $list = array();
    if($total > 0) {
        // --- 修正点：将 mc.credits 改为 m.credits ---
        $sql = "SELECT m.uid, m.username, m.email, m.groupid, m.regdate, m.credits,
                       mc.posts, mc.threads,
                       g.grouptitle
                FROM ".DB::table('common_member')." m 
                LEFT JOIN ".DB::table('common_member_count')." mc ON mc.uid = m.uid 
                LEFT JOIN ".DB::table('common_usergroup')." g ON g.groupid = m.groupid 
                $wheresql 
                ORDER BY m.uid DESC 
                LIMIT %d, %d";
        
        $query_params = array_merge($params, array($start, $perpage));
        $query = DB::query($sql, $query_params);
        
        while($row = DB::fetch($query)) {
            $list[] = array(
                'uid' => $row['uid'],
                'username' => $row['username'],
                'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$row['uid'].'&size=middle',
                'groupid' => $row['groupid'],
                'groupname' => $row['grouptitle'],
                'credits' => $row['credits'], // 总积分
                'posts' => $row['posts'],
                'regdate' => dgmdate($row['regdate'], 'u')
            );
        }
    }
    
    api_return(0, 'Success', array(
        'total' => intval($total),
        'page' => $page,
        'perpage' => $perpage,
        'total_page' => ceil($total / $perpage),
        'list' => $list
    ));
    
    
    
    
    
    
    
    } elseif ($action == 'thread_content') {
    // --- 终极版：帖子详情接口 (整合互动状态 + 附件列表 + 全特殊主题支持) ---

    $tid = isset($_REQUEST['tid']) ? intval($_REQUEST['tid']) : 0;
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0; // 当前登录用户UID
    
    if(!$tid) api_return(-3, 'Thread ID (tid) is required');

    // 0. 加载核心依赖
    loadcache('setting'); 
    require_once libfile('function/forum'); // 必须引入，用于支持 aidencode()

    // 1. 查询主题及主楼基础信息 (含统计字段)
    $sql = "SELECT t.tid, t.fid, t.subject, t.author, t.authorid, t.dateline, t.views, t.replies, t.special, t.price,
                   t.recommends, t.recommend_add, t.favtimes, t.heats,
                   p.pid, p.message 
            FROM " . DB::table('forum_thread') . " t 
            LEFT JOIN " . DB::table('forum_post') . " p ON p.tid = t.tid AND p.first = 1 
            WHERE t.tid = %d AND t.displayorder >= 0";
    
    $thread = DB::fetch_first($sql, array($tid));
    if(!$thread) api_return(-8, 'Thread not found');

    // 2. 获取当前用户互动状态 (点赞、收藏、评分)
    $user_interaction = array(
        'is_liked' => 0,
        'is_favorited' => 0,
        'is_rated' => 0
    );

    if ($uid > 0) {
        // A. 检查点赞状态 (推荐记录)
        $user_interaction['is_liked'] = DB::result_first("SELECT count(*) FROM ".DB::table('forum_memberrecommend')." WHERE tid=%d AND recommenduid=%d", array($tid, $uid)) ? 1 : 0;

        // B. 检查收藏状态 (idtype 为 tid)
        $user_interaction['is_favorited'] = DB::result_first("SELECT count(*) FROM ".DB::table('home_favorite')." WHERE uid=%d AND id=%d AND idtype='tid'", array($uid, $tid)) ? 1 : 0;

        // C. 检查评分状态 (根据此 PID 查询评分日志)
        $user_interaction['is_rated'] = DB::result_first("SELECT count(*) FROM ".DB::table('forum_ratelog')." WHERE uid=%d AND pid=%d", array($uid, $thread['pid'])) ? 1 : 0;
    }

    // 3. 解析非图片附件列表 (下载功能支持)
    $attachment_list = array();
    $query_attach = DB::query("SELECT aid, tableid FROM ".DB::table('forum_attachment')." WHERE tid=%d", array($tid));
    while($att_idx = DB::fetch($query_attach)) {
        $aid = $att_idx['aid'];
        $tableid = $att_idx['tableid'];
        $attach = C::t('forum_attachment_n')->fetch($tableid, $aid);
        
        if($attach && !$attach['isimage']) {
            // [修改点]：不再生成 download_url，仅返回元数据
            $attachment_list[] = array(
                'aid' => $aid,
                'filename' => $attach['filename'],
                'filesize' => sizecount($attach['filesize']),
                'downloads' => intval($attach['downloads']),
                'price' => intval($attach['price']),
                'readperm' => intval($attach['readperm']),
                'extension' => strtolower(pathinfo($attach['filename'], PATHINFO_EXTENSION))
                // 此处已移除 download_url
            );
        }
    }

    // 更新浏览量
    C::t('forum_thread')->increase($tid, array('views' => 1));

    // 4. 特殊主题逻辑处理
    $special_info = array();
    $special_type = intval($thread['special']);

    if ($special_type == 1) { 
        // --- 投票贴 (Poll) ---
        $poll = C::t('forum_poll')->fetch($tid);
        if($poll) {
            // 获取投票图片映射
            $poll_imgs = array();
            $query_img = DB::query("SELECT poid, aid FROM ".DB::table('forum_polloption_image')." WHERE tid=$tid");
            while($img = DB::fetch($query_img)) { $poll_imgs[$img['poid']] = $img['aid']; }

            $special_info = array(
                'maxchoices' => $poll['maxchoices'],
                'expiration' => $poll['expiration'] ? dgmdate($poll['expiration']) : '永久',
                'voters' => $poll['voters'],
                'visible' => $poll['visible'],
                'multiple' => $poll['multiple'],
                'options' => array()
            );

            $query = DB::query("SELECT * FROM ".DB::table('forum_polloption')." WHERE tid=$tid ORDER BY displayorder");
            while($opt = DB::fetch($query)) {
                $option_image_url = '';
                if(isset($poll_imgs[$opt['polloptionid']])) {
                    $p_aid = intval($poll_imgs[$opt['polloptionid']]);
                    $att = C::t('forum_attachment')->fetch($p_aid);
                    $p_tableid = $att ? $att['tableid'] : ($p_aid % 10);
                    $att_n = C::t('forum_attachment_n')->fetch($p_tableid, $p_aid);
                    if($att_n) {
                        $option_image_url = ($att_n['remote'] ? $_G['setting']['ftp']['attachurl'] : $_G['siteurl'].'data/attachment/').'forum/'.$att_n['attachment'];
                    }
                }
                $special_info['options'][] = array(
                    'polloptionid' => $opt['polloptionid'],
                    'polloption' => $opt['polloption'],
                    'votes' => $opt['votes'],
                    'percent' => $poll['voters'] > 0 ? round($opt['votes'] / $poll['voters'] * 100, 1) : 0,
                    'image' => $option_image_url
                );
            }
        }

    } elseif ($special_type == 4) {
        // --- 活动贴 (Activity) ---
        $activity = C::t('forum_activity')->fetch($tid);
        if($activity) {
            $activity_cover = '';
            if($activity['aid'] > 0) {
                $act_aid = $activity['aid'];
                $att = C::t('forum_attachment')->fetch($act_aid);
                $act_tableid = $att ? $att['tableid'] : ($act_aid % 10);
                $att_n = C::t('forum_attachment_n')->fetch($act_tableid, $act_aid);
                if($att_n) {
                    $activity_cover = ($att_n['remote'] ? $_G['setting']['ftp']['attachurl'] : $_G['siteurl'].'data/attachment/').'forum/'.$att_n['attachment'];
                }
            }
            $special_info = array(
                'starttimefrom' => dgmdate($activity['starttimefrom']),
                'starttimeto' => $activity['starttimeto'] ? dgmdate($activity['starttimeto']) : '',
                'place' => $activity['place'],
                'cost' => $activity['cost'],
                'class' => $activity['class'],
                'gender' => $activity['gender'],
                'number' => $activity['number'],
                'applynumber' => $activity['applynumber'],
                'cover_image' => $activity_cover
            );
        }

    } elseif ($special_type == 3) {
        // --- 悬赏贴 (Reward) ---
        $special_info = array(
            'reward_price' => abs($thread['price']),
            'is_solved' => ($thread['price'] < 0) ? 1 : 0
        );

    } elseif ($special_type == 2) {
        // --- 商品贴 (Trade) ---
        $trade = DB::fetch_first("SELECT * FROM ".DB::table('forum_trade')." WHERE tid=$tid ORDER BY pid LIMIT 1");
        if($trade) {
            $trade_img = '';
            if($trade['aid'] > 0) {
                 $t_aid = $trade['aid'];
                 $att = C::t('forum_attachment')->fetch($t_aid);
                 $t_tableid = $att ? $att['tableid'] : ($t_aid % 10);
                 $att_n = C::t('forum_attachment_n')->fetch($t_tableid, $t_aid);
                 if($att_n) $trade_img = ($att_n['remote'] ? $_G['setting']['ftp']['attachurl'] : $_G['siteurl'].'data/attachment/').'forum/'.$att_n['attachment'];
            }
            $special_info = array(
                'trade_name' => $trade['subject'],
                'price' => $trade['price'],
                'original_price' => $trade['costprice'],
                'location' => $trade['locus'],
                'seller' => $trade['seller'],
                'image' => $trade_img
            );
        }
    }

    // 5. 组装最终结果
    $data = array(
        'tid' => $thread['tid'],
        'pid' => $thread['pid'],
        'subject' => $thread['subject'],
        'author' => $thread['author'],
        'authorid' => $thread['authorid'],
        'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$thread['authorid'].'&size=middle',
        'dateline' => dgmdate($thread['dateline'], 'u'),
        'views' => intval($thread['views']) + 1,
        'replies' => intval($thread['replies']),
        
        // 实时统计
        'recommend_add' => intval($thread['recommend_add']),
        'favtimes' => intval($thread['favtimes']),
        'heats' => intval($thread['heats']),
        
        // 用户互动状态
        'user_interaction' => $user_interaction, 
        
        // 附件列表 (非图片文件)
        'attachment_list' => $attachment_list,

        // 特殊主题数据
        'special_type' => $special_type,
        'special_info' => $special_info,
        
        // 正文解析 (BBCode -> HTML)
        'content' => parse_attach_images($thread['message'])
    );

    api_return(0, 'Success', $data);
    
    
    
    
    
    } elseif ($action == 'announcements') {
    // 1. 获取当前时间戳
    $now = time();
    $limit = isset($_REQUEST['limit']) ? max(1, min(20, intval($_REQUEST['limit']))) : 10;

    // 2. 修正后的查询：将 common_announcement 改为 forum_announcement
    $sql = "SELECT id, author, subject, type, starttime, endtime, message 
            FROM " . DB::table('forum_announcement') . " 
            WHERE starttime <= $now AND (endtime >= $now OR endtime = 0) 
            ORDER BY displayorder ASC, starttime DESC 
            LIMIT $limit";

    $query = DB::query($sql);
    $list = array();

    while($row = DB::fetch($query)) {
        $list[] = array(
            'id' => $row['id'],
            'author' => $row['author'],
            'subject' => $row['subject'],
            'type' => $row['type'],
            'starttime' => dgmdate($row['starttime'], 'd'),
            'endtime' => $row['endtime'] ? dgmdate($row['endtime'], 'd') : '永久',
            'content' => $row['message'] 
        );
    }

    api_return(0, 'Success', array('list' => $list));
    
    
    
    
    
    
    
    
    } elseif ($action == 'send_pm') {
    // 1. 接收参数
    $from_uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;     // 发送者 UID
    $to_uid = isset($_REQUEST['touid']) ? intval($_REQUEST['touid']) : 0;   // 接收者 UID
    $message = isset($_REQUEST['message']) ? trim($_REQUEST['message']) : ''; // 消息内容
    $subject = isset($_REQUEST['subject']) ? trim($_REQUEST['subject']) : ''; // 标题 (可选)

    // 2. 基础校验
    if (!$from_uid || !$to_uid || !$message) {
        api_return(-3, 'Missing parameters (uid, touid, message)');
    }

    if ($from_uid == $to_uid) {
        api_return(-11, 'You cannot send a message to yourself');
    }

    // 3. 加载 UCenter 核心
    require_once libfile('function/member');
    loaducenter();

    // 4. 检查收件人是否存在
    $to_member = C::t('common_member')->fetch($to_uid);
    if (!$to_member) {
        api_return(-6, 'Recipient user not found');
    }

    // 5. 调用 UCenter 函数发送私信
    // uc_pm_send 参数：发件人UID, 收件人UID/用户名, 标题, 内容, 是否立即发送, 回复ID, 是否为用户名, 消息类型
    // 第 7 个参数设为 0 表示通过 UID 发送
    $result = uc_pm_send($from_uid, $to_uid, $subject, $message, 1, 0, 0);

    // 6. 处理结果
    if ($result > 0) {
        // 发送成功，返回消息 ID (pmid)
        api_return(0, 'Message sent successfully', array(
            'pmid' => $result,
            'from_uid' => $from_uid,
            'to_uid' => $to_uid
        ));
    } else {
        // 发送失败，映射 UCenter 错误代码
        $error_msg = 'Send failed';
        if ($result == -1) $error_msg = 'Exceed maximum messages per day (每日发送上限)';
        if ($result == -2) $error_msg = 'Minimum interval time limit (发送间隔太快)';
        if ($result == -3) $error_msg = 'Recipient does not exist';
        if ($result == -4) $error_msg = 'Too many recipients';
        if ($result == -8) $error_msg = 'Recipient has a full inbox (对方收件箱已满)';
        
        api_return(-11, $error_msg, array('uc_error' => $result));
    }
    
    
    
    
    
    } elseif ($action == 'edit_post') {
    // --- 编辑/删除帖子 (双重保底驱动版) ---

    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $pid = isset($_REQUEST['pid']) ? intval($_REQUEST['pid']) : 0;
    $tid_input = isset($_REQUEST['tid']) ? intval($_REQUEST['tid']) : 0;
    
    $subject = isset($_REQUEST['subject']) ? trim($_REQUEST['subject']) : '';
    $message = isset($_REQUEST['message']) ? trim($_REQUEST['message']) : '';
    $delete = isset($_REQUEST['delete']) ? intval($_REQUEST['delete']) : 0; 

    // 2. 基础验证
    if (!$uid) api_return(-3, 'User ID (uid) is required');
    if (!$pid && !$tid_input) api_return(-3, 'Post ID (pid) or TID is required');

    // 3. 获取帖子数据
    $post = false;
    if($pid) {
        $post = C::t('forum_post')->fetch('tid:0', $pid);
        if(!$post) $post = DB::fetch_first("SELECT * FROM ".DB::table('forum_post')." WHERE pid=$pid");
    }
    if(!$post && $tid_input) {
        $post = DB::fetch_first("SELECT * FROM ".DB::table('forum_post')." WHERE tid=$tid_input AND first=1");
    }

    if (!$post) api_return(-8, 'Post not found');

    // 4. 权限校验
    if ($post['authorid'] != $uid) {
        api_return(-9, 'Access denied. You are not the author.');
    }

    $pid = $post['pid'];
    $tid = $post['tid'];
    $fid = $post['fid'];
    $is_first = $post['first']; 

    // --- 逻辑 A: 执行删除 ---
    if ($delete > 0) {
        // 1. 尝试使用 Discuz 标准方式加载函数库
        if(!function_exists('deletethreads')) {
            @include_once libfile('function/post');
            @include_once libfile('function/forum');
            @include_once libfile('function/delete');
        }
        
        if ($is_first) {
            // A. 如果系统函数可用，走系统流程 (最安全，会自动扣分和清理附件)
            if (function_exists('deletethreads')) {
                deletethreads(array($tid));
                api_return(0, 'Thread deleted by core function', array('tid' => $tid));
            } else {
                // B. 【保底方案】直接操作数据库删除 (防止函数库加载失败)
                // 删除主题、所有帖子、审核记录
                DB::query("DELETE FROM ".DB::table('forum_thread')." WHERE tid='$tid'");
                DB::query("DELETE FROM ".DB::table('forum_post')." WHERE tid='$tid'");
                DB::query("DELETE FROM ".DB::table('forum_threadmod')." WHERE tid='$tid'");
                // 更新版块统计
                C::t('forum_forum')->update_forum_counter($fid, -1, -1, -1);
                api_return(0, 'Thread deleted by direct DB operation', array('tid' => $tid));
            }
        } else {
            // 删除普通回帖
            if (function_exists('deletepost')) {
                deletepost(array($pid), 'pid');
                C::t('forum_thread')->decrease($tid, array('replies' => 1));
                C::t('forum_forum')->update_forum_counter($fid, 0, -1, -1);
                api_return(0, 'Post deleted by core function', array('pid' => $pid));
            } else {
                // 【保底方案】直接删除帖子
                DB::query("DELETE FROM ".DB::table('forum_post')." WHERE pid='$pid'");
                C::t('forum_thread')->decrease($tid, array('replies' => 1));
                C::t('forum_forum')->update_forum_counter($fid, 0, -1, -1);
                api_return(0, 'Post deleted by direct DB operation', array('pid' => $pid));
            }
        }
    }

    // --- 逻辑 B: 执行编辑 ---
    if (empty($message)) api_return(-3, 'Message is required for editing');

    $update_data = array('message' => $message);
    if ($is_first && !empty($subject)) {
        C::t('forum_thread')->update($tid, array('subject' => $subject));
        $update_data['subject'] = $subject;
    }

    C::t('forum_post')->update('tid:'.$tid, $pid, $update_data);

    api_return(0, 'Post updated successfully', array('pid' => $pid, 'tid' => $tid));
    
    
    
    
    
    } elseif ($action == 'my_threads') {
    // --- 用户帖子列表 (含图片预览、智能摘要、特殊主题标识) ---
    
    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(100, intval($_REQUEST['perpage']))) : 20;

    if(!$uid) {
        api_return(-3, 'User ID (uid) is required');
    }

    // 2. 统计符合条件的主题总数
    $count_sql = "SELECT COUNT(*) FROM ".DB::table('forum_thread')." WHERE authorid=%d AND displayorder>=0";
    $total = DB::result_first($count_sql, array($uid));

    $list = array();
    
    if($total > 0) {
        $start = ($page - 1) * $perpage;
        
        // 3. 联表查询：Thread + Forum + Post
        // JOIN forum_post (first=1) 获取内容
        $sql = "SELECT t.tid, t.fid, t.subject, t.dateline, t.views, t.replies, t.special, 
                       f.name as forum_name, 
                       p.message 
                FROM ".DB::table('forum_thread')." t 
                LEFT JOIN ".DB::table('forum_forum')." f ON f.fid = t.fid 
                LEFT JOIN ".DB::table('forum_post')." p ON p.tid = t.tid AND p.first = 1 
                WHERE t.authorid=%d AND t.displayorder>=0 
                ORDER BY t.dateline DESC 
                LIMIT %d, %d";
        
        $query = DB::query($sql, array($uid, $start, $perpage));
        
        while($row = DB::fetch($query)) {
            // A. 处理摘要
            $message_text = $row['message'];
            $message_text = preg_replace("/\[attach\]\d+\[\/attach\]/i", '', $message_text);
            $message_text = strip_tags(preg_replace("/\[.+?\]/is", '', $message_text));
            $abstract = mb_substr(trim($message_text), 0, 100, 'utf-8');
            if(mb_strlen(trim($message_text), 'utf-8') > 100) $abstract .= '...';

            // B. 提取图片
            $image_list = get_thread_images($row['message']);

            $list[] = array(
                'tid' => $row['tid'],
                'fid' => $row['fid'],
                'forum_name' => $row['forum_name'],
                'subject' => $row['subject'],
                'abstract' => $abstract,
                // 虽然请求参数里有 uid，但返回结构里带上 authorid 和 avatar 更规范，方便前端组件统一处理
                'authorid' => $uid,
                'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$uid.'&size=small',
                'views' => $row['views'],
                'replies' => $row['replies'],
                'dateline' => dgmdate($row['dateline'], 'u'),
                // [新增] 特殊主题类型
                'special_type' => intval($row['special']),
                // [新增] 图片预览
                'image_list' => $image_list,
                'has_image' => !empty($image_list) ? 1 : 0
            );
        }
    }

    api_return(0, 'Success', array(
        'total' => intval($total),
        'page' => $page,
        'perpage' => $perpage,
        'total_page' => ceil($total / $perpage),
        'list' => $list
    ));
    
    
    
    
    
    } elseif ($action == 'album_list') {
    // --- 修改后的接口：获取用户相册列表 (支持隐私状态细分) ---
    
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(100, intval($_REQUEST['perpage']))) : 20;

    if(!$uid) {
        api_return(-3, 'User ID (uid) is required');
    }

    $total = DB::result_first("SELECT COUNT(*) FROM ".DB::table('home_album')." WHERE uid=%d", array($uid));

    $list = array();
    
    if($total > 0) {
        $start = ($page - 1) * $perpage;
        
        $sql = "SELECT albumid, albumname, picnum, pic, picflag, updatetime, friend 
                FROM ".DB::table('home_album')." 
                WHERE uid=%d 
                ORDER BY updatetime DESC 
                LIMIT %d, %d";
        
        $query = DB::query($sql, array($uid, $start, $perpage));
        
        while($row = DB::fetch($query)) {
            $cover_url = '';
            if($row['pic']) {
                $cover_url = ($row['picflag'] == 2 ? $_G['setting']['ftp']['attachurl'] : $_G['siteurl'].'data/attachment/').'album/'.$row['pic'];
            } else {
                $cover_url = $_G['siteurl'].'static/image/common/nophoto.gif';
            }

            // --- 核心修改：隐私状态判定逻辑 ---
            $friend_status = intval($row['friend']);
            
            $list[] = array(
                'albumid' => $row['albumid'],
                'albumname' => $row['albumname'],
                'picnum' => $row['picnum'],
                'cover' => $cover_url,
                'updatetime' => dgmdate($row['updatetime'], 'd'),
                
                // 标志位扩展
                'is_public' => ($friend_status == 0) ? 1 : 0,           // 是否公开
                'friend_only' => ($friend_status == 1 || $friend_status == 2) ? 1 : 0, // 是否仅好友可见
                'password_protected' => ($friend_status == 4) ? 1 : 0,  // 是否需要密码
                'private_me' => ($friend_status == 3) ? 1 : 0,          // 是否仅自己可见
                'privacy_level' => $friend_status                       // 原始权限等级(0-4)
            );
        }
    }

    api_return(0, 'Success', array(
        'total' => intval($total),
        'page' => $page,
        'perpage' => $perpage,
        'list' => $list
    ));
    
    
    
    
    } elseif ($action == 'upload_avatar') {
    // --- 新增接口：修改用户头像 (自动适配后台 GD/ImageMagick 设置) ---

    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    
    if(!$uid) api_return(-3, 'User ID (uid) is required');

    // 检查是否有文件上传
    if (!isset($_FILES['file'])) {
        api_return(-3, 'No file uploaded');
    }

    // 2. 检查用户是否存在
    $member = C::t('common_member')->fetch($uid);
    if(!$member) api_return(-6, 'User not found');

    // 3. 【关键步骤】加载系统设置缓存
    // 这一步确保 API 能读取到你在后台设置的 "图片处理库类型" (GD 或 ImageMagick)
    loadcache('setting');

    // 4. 定义 UCenter 头像存储路径逻辑
    $uid = sprintf("%09d", $uid);
    $dir1 = substr($uid, 0, 3);
    $dir2 = substr($uid, 3, 2);
    $dir3 = substr($uid, 5, 2);
    
    // 物理路径 (UCenter 默认目录)
    $avatar_dir = DISCUZ_ROOT . './uc_server/data/avatar/' . $dir1 . '/' . $dir2 . '/' . $dir3 . '/';
    
    // 如果目录不存在则创建
    if(!is_dir($avatar_dir)) {
        if(!mkdir($avatar_dir, 0777, true)) {
            api_return(-10, 'Failed to create avatar directory. Check permissions.');
        }
    }

    // 5. 加载 Discuz 核心图片处理类
    // 这个类会自动判断 $_G['setting']['imagelib'] 的值
    // 如果后台选了 ImageMagick，它会自动调用 exec() 执行 convert 命令
    require_once libfile('class/image');
    $image = new image();

    $src_file = $_FILES['file']['tmp_name'];
    
    // 验证是否是有效图片
    $imginfo = @getimagesize($src_file);
    if(!$imginfo) {
        api_return(-11, 'Invalid image file');
    }

    // 6. 生成三种尺寸的头像
    // Thumb 函数内部逻辑：
    // if($_G['setting']['imagelib']) -> 调用 ImageMagick
    // else -> 调用 GD 库
    
    $success_count = 0;
    
    // 生成大图 (200x200)
    $dst_big = $avatar_dir . substr($uid, -2) . '_avatar_big.jpg';
    // 参数说明: 源文件, 目标文件, 宽, 高, 裁剪模式(1=强制裁剪)
    if($image->Thumb($src_file, $dst_big, 200, 200, 1)) $success_count++; 
    
    // 生成中图 (120x120)
    $dst_middle = $avatar_dir . substr($uid, -2) . '_avatar_middle.jpg';
    if($image->Thumb($src_file, $dst_middle, 120, 120, 1)) $success_count++;

    // 生成小图 (48x48)
    $dst_small = $avatar_dir . substr($uid, -2) . '_avatar_small.jpg';
    if($image->Thumb($src_file, $dst_small, 48, 48, 1)) $success_count++;

    if($success_count < 3) {
        // 容错处理：如果缩略图生成失败(例如 ImageMagick 路径没配对)，尝试简单的 copy
        // 这样至少能保证头像更换成功，虽然可能没裁剪好
        if(!file_exists($dst_big)) copy($src_file, $dst_big);
        if(!file_exists($dst_middle)) copy($src_file, $dst_middle);
        if(!file_exists($dst_small)) copy($src_file, $dst_small);
        
        // 可以在这里记录日志，方便排查 ImageMagick 配置问题
    }

    // 7. 清理缓存/标记更新
    C::t('common_member')->update($uid, array('avatarstatus' => 1));

    // 生成带时间戳的 URL
    $avatar_url = $_G['siteurl'] . 'uc_server/avatar.php?uid=' . intval($uid) . '&size=middle&random=' . time();

    api_return(0, 'Avatar updated successfully', array(
        'uid' => intval($uid),
        'avatar_url' => $avatar_url,
        'processing_lib' => $_G['setting']['imagelib'] ? 'ImageMagick' : 'GD' // 仅供调试，告知使用了哪个库
    ));
    
    
    
    
    
    
    
    
    
    
    
    } elseif ($action == 'album_content') {
    // --- 重写：获取相册内容 (增加隐私权限校验) ---

    // 1. 接收参数
    $albumid = isset($_REQUEST['albumid']) ? intval($_REQUEST['albumid']) : 0;
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0; // 请求者的 UID
    $password = isset($_REQUEST['password']) ? trim($_REQUEST['password']) : ''; // 可选的相册密码
    
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(100, intval($_REQUEST['perpage']))) : 20;

    if (!$albumid) {
        api_return(-3, 'Album ID (albumid) is required');
    }

    // 2. 获取相册基础信息并校验权限
    $album = C::t('home_album')->fetch($albumid);
    if (!$album) {
        api_return(-6, 'Album not found');
    }

    $owner_uid = intval($album['uid']);
    $friend_status = intval($album['friend']); // 0:公开, 1:好友, 2:指定好友, 3:自己, 4:密码
    $is_owner = ($uid > 0 && $uid == $owner_uid);

    // 非作者本人的权限判定
    if (!$is_owner) {
        if ($friend_status == 1 || $friend_status == 2) {
            // A. 校验好友关系
            $is_friend = DB::result_first("SELECT uid FROM ".DB::table('home_friend')." WHERE uid=%d AND fuid=%d", array($owner_uid, $uid));
            if (!$is_friend) {
                api_return(-9, 'Access denied: Friends only');
            }
        } elseif ($friend_status == 3) {
            // B. 仅自己可见
            api_return(-9, 'Access denied: This album is private');
        } elseif ($friend_status == 4) {
            // C. 密码校验
            if ($password !== $album['password']) {
                api_return(-18, 'Invalid album password'); // -18 定义为相册密码错误
            }
        }
    }

    // 3. 统计该相册下的图片总数
    $total = DB::result_first("SELECT COUNT(*) FROM ".DB::table('home_pic')." WHERE albumid=%d", array($albumid));

    $list = array();
    if ($total > 0) {
        $start = ($page - 1) * $perpage;
        
        // 4. 查询图片详情
        $sql = "SELECT picid, title, filepath, thumb, remote, dateline, size 
                FROM ".DB::table('home_pic')." 
                WHERE albumid=%d 
                ORDER BY dateline DESC 
                LIMIT %d, %d";
        
        $query = DB::query($sql, array($albumid, $start, $perpage));
        
        while ($row = DB::fetch($query)) {
            $url = ($row['remote'] ? $_G['setting']['ftp']['attachurl'] : $_G['siteurl'].'data/attachment/').'album/'.$row['filepath'];

            $list[] = array(
                'picid' => $row['picid'],
                'title' => $row['title'],
                'url' => $url,
                'size' => intval($row['size']),
                'dateline' => dgmdate($row['dateline'], 'u')
            );
        }
    }

    api_return(0, 'Success', array(
        'albumid' => $albumid,
        'albumname' => $album['albumname'],
        'total' => intval($total),
        'page' => $page,
        'perpage' => $perpage,
        'list' => $list
    ));
    
    
    
    
    
    
    
    } elseif ($action == 'upload_album_pic') {
    // --- 新增接口：上传图片到指定相册 ---

    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $albumid = isset($_REQUEST['albumid']) ? intval($_REQUEST['albumid']) : 0;
    $title = isset($_REQUEST['title']) ? trim($_REQUEST['title']) : ''; // 图片描述(可选)

    // 2. 基础校验
    if(!$uid || !$albumid) {
        api_return(-3, 'UID and Album ID are required');
    }
    
    // 检查是否有文件上传
    if (!isset($_FILES['file'])) {
        api_return(-3, 'No file uploaded');
    }

    // 3. 验证相册归属权
    // 必须确保该相册属于当前用户
    $album = C::t('home_album')->fetch($albumid);
    if(!$album) {
        api_return(-6, 'Album not found');
    }
    if($album['uid'] != $uid) {
        api_return(-9, 'Access denied: You do not own this album');
    }

    // 4. 加载 Discuz 上传类
    require_once libfile('class/upload');
    $upload = new discuz_upload();

    // 初始化上传，类型设为 'album' (存入 /data/attachment/album/ 目录)
    $res = $upload->init($_FILES['file'], 'album');
    if(!$res) {
        api_return(-10, 'Upload init failed: ' . $upload->errormessage());
    }

    // 5. 保存文件
    $attach = $upload->save();
    if(!$attach) {
        api_return(-10, 'Save file failed: ' . $upload->errormessage());
    }

    // 6. 插入数据库 (home_pic 表)
    $picdata = array(
        'albumid' => $albumid,
        'uid' => $uid,
        'username' => $album['username'],
        'dateline' => TIMESTAMP,
        'postip' => $_G['clientip'],
        'filename' => $attach['name'], // 原始文件名
        'title' => $title,             // 图片描述
        'type' => $attach['type'],     // 图片类型 mime
        'size' => $attach['size'],     // 大小
        'filepath' => $attach['attachment'], // 相对路径
        'thumb' => $attach['thumb'],
        'remote' => $attach['remote'],
        'width' => $attach['width']
    );

    $picid = C::t('home_pic')->insert($picdata, true);

    // 7. 更新相册统计数据 (图片数+1, 更新时间)
    // 如果相册还没有封面，自动将这张图设为封面
    $update_data = array('picnum' => $album['picnum'] + 1, 'updatetime' => TIMESTAMP);
    if(empty($album['pic'])) {
        $update_data['pic'] = $attach['attachment'];
        $update_data['picflag'] = $attach['remote'] ? 2 : 1;
    }
    C::t('home_album')->update($albumid, $update_data);

    // 8. 拼接返回 URL
    if($attach['remote']) {
        $url = $_G['setting']['ftp']['attachurl'] . 'album/' . $attach['attachment'];
    } else {
        $url = $_G['siteurl'] . 'data/attachment/album/' . $attach['attachment'];
    }

    api_return(0, 'Upload album picture success', array(
        'picid' => $picid,
        'albumid' => $albumid,
        'url' => $url,
        'width' => $attach['width'],
        'size' => $attach['size']
    ));
    
    
    
    
    
    
    
    } elseif ($action == 'update_profile') {
    // --- 新增接口：修改用户个人资料 (支持自定义字段) ---

    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    
    if(!$uid) {
        api_return(-3, 'User ID (uid) is required');
    }

    // 2. 检查用户是否存在
    $member = C::t('common_member')->fetch($uid);
    if(!$member) {
        api_return(-6, 'User not found');
    }

    // 3. 加载后台的用户栏目设置
    // 包含了所有标准字段(gender, bio)和自定义字段(field1, field2...)
    loadcache('profilesetting'); 
    $settings = $_G['cache']['profilesetting'];

    $profile_data = array();
    
    // 4. 动态遍历所有启用的字段，寻找匹配的请求参数
    if(is_array($settings)) {
        foreach($settings as $fieldid => $conf) {
            // 仅处理后台启用的字段
            if(!$conf['available']) continue;

            $val = null;

            // [逻辑A] 尝试匹配 字段ID (如 'gender', 'mobile', 'field1')
            // 这是最稳健的方式
            if(isset($_REQUEST[$fieldid])) {
                $val = trim($_REQUEST[$fieldid]);
            }
            // [逻辑B] 尝试匹配 字段标题 (如 '出生年份', '毕业院校')
            // 兼容 userinfo 接口返回的中文 Key
            elseif(isset($_REQUEST[$conf['title']])) {
                $val = trim($_REQUEST[$conf['title']]);
            }

            // 如果找到了对应的值，则加入更新队列
            if($val !== null) {
                // 特殊字段类型处理
                if($fieldid == 'gender') {
                    $val = intval($val);
                }
                elseif($fieldid == 'bio') {
                    $val = mb_substr($val, 0, 500, 'utf-8');
                }
                // 生日相关字段强制转数字
                elseif(in_array($fieldid, array('birthyear', 'birthmonth', 'birthday'))) {
                    $val = intval($val);
                }
                
                $profile_data[$fieldid] = $val;
            }
        }
    }

    // 执行 Profile 表更新
    if(!empty($profile_data)) {
        C::t('common_member_profile')->update($uid, $profile_data);
    }

    // 5. 处理个人签名 (独立存储在 common_member_field_forum 表)
    $signature_updated = false;
    if(isset($_REQUEST['signature'])) {
        $sightml = trim($_REQUEST['signature']);
        $sightml = strip_tags($sightml, '<b><i><u><a><img><br>'); 
        C::t('common_member_field_forum')->update($uid, array('sightml' => $sightml));
        $signature_updated = true;
    }

    api_return(0, 'Profile updated successfully', array(
        'uid' => $uid,
        'updated_fields' => array_keys($profile_data), // 返回实际被更新的字段ID列表
        'signature_updated' => $signature_updated
    ));
    
    
    
    
    } elseif ($action == 'search_threads') {
    // --- 高级搜索接口 (含图片预览、智能摘要、特殊主题标识) ---

    // 1. 接收参数
    $keyword = isset($_REQUEST['keyword']) ? trim($_REQUEST['keyword']) : '';
    $fulltext = isset($_REQUEST['fulltext']) ? intval($_REQUEST['fulltext']) : 0;
    $author = isset($_REQUEST['author']) ? trim($_REQUEST['author']) : '';
    
    // 筛选参数
    $digest = isset($_REQUEST['digest']) ? intval($_REQUEST['digest']) : 0;
    $stick = isset($_REQUEST['stick']) ? intval($_REQUEST['stick']) : 0;
    $special = isset($_REQUEST['special']) ? $_REQUEST['special'] : ''; 
    $days = isset($_REQUEST['days']) ? intval($_REQUEST['days']) : 0;
    $time_type = isset($_REQUEST['time_type']) ? $_REQUEST['time_type'] : 'within';
    $fid = isset($_REQUEST['fid']) ? intval($_REQUEST['fid']) : 0;
    
    // 排序与分页
    $orderby = isset($_REQUEST['orderby']) ? $_REQUEST['orderby'] : 'lastpost';
    $ascdesc = isset($_REQUEST['ascdesc']) ? strtoupper($_REQUEST['ascdesc']) : 'DESC';
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(50, intval($_REQUEST['perpage']))) : 20;

    // 2. 构建查询条件
    $where_arr = array("t.displayorder >= 0");
    $params = array();
    $count_join_sql = ""; 

    // -- 关键词 --
    if ($keyword) {
        if ($fulltext) {
            $count_join_sql = "LEFT JOIN ".DB::table('forum_post')." p ON p.tid=t.tid AND p.first=1";
            $where_arr[] = "(t.subject LIKE %s OR p.message LIKE %s)";
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        } else {
            $where_arr[] = "t.subject LIKE %s";
            $params[] = '%' . $keyword . '%';
        }
    }

    // -- 其他筛选 --
    if ($author) { $where_arr[] = "t.author = %s"; $params[] = $author; }
    if ($digest) $where_arr[] = "t.digest > 0";
    if ($stick) $where_arr[] = "t.displayorder > 0";
    if ($special) {
        $specials = array_filter(array_map('intval', explode(',', $special)));
        if(!empty($specials)) $where_arr[] = "t.special IN (" . implode(',', $specials) . ")";
    }
    if ($days > 0) {
        $ts = TIMESTAMP - ($days * 86400);
        $where_arr[] = ($time_type == 'within') ? "t.dateline >= %d" : "t.dateline < %d";
        $params[] = $ts;
    }
    if ($fid) { $where_arr[] = "t.fid = %d"; $params[] = $fid; }

    // -- 排序校验 --
    $allow_sort = array('dateline', 'replies', 'views', 'lastpost');
    if (!in_array($orderby, $allow_sort)) $orderby = 'lastpost';
    if ($ascdesc != 'ASC' && $ascdesc != 'DESC') $ascdesc = 'DESC';

    // 3. 执行查询
    $where_sql = implode(' AND ', $where_arr);
    $start = ($page - 1) * $perpage;

    // 统计总数
    if($fulltext && $keyword) {
        $count_sql = "SELECT COUNT(*) FROM ".DB::table('forum_thread')." t $count_join_sql WHERE $where_sql";
    } else {
        $count_sql = "SELECT COUNT(*) FROM ".DB::table('forum_thread')." t WHERE $where_sql";
    }
    $total = DB::result_first($count_sql, $params);

    $list = array();
    if ($total > 0) {
        // 始终 JOIN forum_post 获取 message
        $list_join_sql = "LEFT JOIN ".DB::table('forum_post')." p ON p.tid=t.tid AND p.first=1";
        
        $sql = "SELECT t.tid, t.fid, t.subject, t.author, t.authorid, t.dateline, t.views, t.replies, 
                       t.special, t.digest, t.displayorder, t.lastpost,
                       p.message 
                FROM ".DB::table('forum_thread')." t 
                $list_join_sql 
                WHERE $where_sql 
                ORDER BY t.$orderby $ascdesc 
                LIMIT $start, $perpage";
        
        $query = DB::query($sql, $params);
        loadcache('forums');
        
        while ($row = DB::fetch($query)) {
            $fname = isset($_G['cache']['forums'][$row['fid']]['name']) ? $_G['cache']['forums'][$row['fid']]['name'] : '';
            
            // A. 智能摘要 (关键词上下文)
            $message = $row['message'];
            // 清洗内容用于摘要
            $clean_msg = preg_replace("/\[attach\]\d+\[\/attach\]/i", '', $message);
            $clean_msg = strip_tags(preg_replace("/\[.+?\]/is", '', $clean_msg));
            $clean_msg = trim(preg_replace('/\s+/', ' ', $clean_msg));

            $abstract = '';
            if ($keyword) {
                $pos = mb_stripos($clean_msg, $keyword, 0, 'utf-8');
                if ($pos !== false) {
                    $start_pos = max(0, $pos - 10);
                    $abstract = mb_substr($clean_msg, $start_pos, 50, 'utf-8');
                    if ($start_pos > 0) $abstract = '...' . $abstract;
                } else {
                    $abstract = mb_substr($clean_msg, 0, 50, 'utf-8');
                }
            } else {
                $abstract = mb_substr($clean_msg, 0, 50, 'utf-8');
            }
            if (mb_strlen($abstract, 'utf-8') >= 50 && mb_strlen($clean_msg, 'utf-8') > 50) $abstract .= '...';

            // B. [核心] 提取图片预览
            $image_list = get_thread_images($row['message']);

            $list[] = array(
                'tid' => $row['tid'],
                'fid' => $row['fid'],
                'forum_name' => $fname,
                'subject' => $row['subject'],
                'abstract' => $abstract,
                'author' => $row['author'],
                // 补全头像
                'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$row['authorid'].'&size=small',
                'dateline' => dgmdate($row['dateline'], 'u'),
                'lastpost' => dgmdate($row['lastpost'], 'u'),
                'views' => $row['views'],
                'replies' => $row['replies'],
                'special_type' => intval($row['special']),
                'is_digest' => intval($row['digest']) > 0 ? 1 : 0,
                // 图片数据
                'image_list' => $image_list,
                'has_image' => !empty($image_list) ? 1 : 0
            );
        }
    }

    api_return(0, 'Search success', array(
        'total' => intval($total),
        'page' => $page,
        'perpage' => $perpage,
        'list' => $list
    ));
    
    
    
    
    
    
    } elseif ($action == 'thread_list') {
    // --- 升级版：帖子列表接口 (含版块收藏总数及当前用户收藏状态) ---

    // 1. 接收参数
    $fid = isset($_REQUEST['fid']) ? intval($_REQUEST['fid']) : 0;
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0; // [新增] 接收当前用户 UID
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(50, intval($_REQUEST['perpage']))) : 20;
    $orderby = isset($_REQUEST['orderby']) ? $_REQUEST['orderby'] : 'lastpost';
    
    // 2. [核心功能] 如果指定了 FID，则获取版块的收藏统计信息
    $forum_fav_info = array(
        'favorite_count' => 0, // 版块总收藏数
        'is_favorite' => 0     // 当前用户是否已收藏
    );

    if ($fid > 0) {
        // A. 查询版块被收藏的总次数 (idtype='fid')
        $forum_fav_info['favorite_count'] = DB::result_first("SELECT COUNT(*) FROM ".DB::table('home_favorite')." WHERE id=%d AND idtype='fid'", array($fid));

        // B. 如果传入了 UID，检查该用户是否收藏了该版块
        if ($uid > 0) {
            $forum_fav_info['is_favorite'] = DB::result_first("SELECT COUNT(*) FROM ".DB::table('home_favorite')." WHERE uid=%d AND id=%d AND idtype='fid'", array($uid, $fid)) ? 1 : 0;
        }
    }

    // 3. 构建帖子查询条件
    $where = "t.displayorder >= 0"; 
    if($fid) {
        $where .= " AND t.fid=" . $fid;
    }

    // 排序校验
    $allow_sort = array('dateline', 'replies', 'views', 'lastpost');
    if(!in_array($orderby, $allow_sort)) $orderby = 'lastpost';

    $start = ($page - 1) * $perpage;

    // 4. 统计总数
    $total = DB::result_first("SELECT COUNT(*) FROM ".DB::table('forum_thread')." t WHERE $where");

    $list = array();
    if($total > 0) {
        // 5. 联表查询
        $sql = "SELECT t.tid, t.fid, t.subject, t.author, t.authorid, t.dateline, t.views, t.replies, 
                       t.digest, t.attachment, t.special,
                       p.message 
                FROM ".DB::table('forum_thread')." t 
                LEFT JOIN ".DB::table('forum_post')." p ON p.tid = t.tid AND p.first = 1 
                WHERE $where 
                ORDER BY t.$orderby DESC 
                LIMIT $start, $perpage";
        
        $query = DB::query($sql);
        loadcache('forums');
        
        while($row = DB::fetch($query)) {
            // A. 处理摘要
            $message_text = $row['message'];
            $message_text = preg_replace("/\[attach\]\d+\[\/attach\]/i", '', $message_text);
            $message_text = strip_tags(preg_replace("/\[.+?\]/is", '', $message_text));
            $abstract = mb_substr(trim($message_text), 0, 100, 'utf-8');
            if(mb_strlen(trim($message_text), 'utf-8') > 100) $abstract .= '...';

            // B. 提取图片
            $image_list = get_thread_images($row['message']);

            // C. 获取版块名
            $forum_name = isset($_G['cache']['forums'][$row['fid']]['name']) ? $_G['cache']['forums'][$row['fid']]['name'] : '';

            $list[] = array(
                'tid' => $row['tid'],
                'fid' => $row['fid'],
                'forum_name' => $forum_name,
                'subject' => $row['subject'],
                'abstract' => $abstract,
                'author' => $row['author'],
                'authorid' => $row['authorid'],
                'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$row['authorid'].'&size=small',
                'dateline' => dgmdate($row['dateline'], 'u'),
                'views' => $row['views'],
                'replies' => $row['replies'],
                'special_type' => intval($row['special']),
                'is_digest' => intval($row['digest']) > 0 ? 1 : 0,
                'image_list' => $image_list, 
                'has_image' => !empty($image_list) ? 1 : 0
            );
        }
    }

    // 6. 返回结果 (合并版块统计信息)
    api_return(0, 'Success', array(
        'forum_fav_info' => $forum_fav_info, // [新增] 当前版块的收藏状态与统计
        'total' => intval($total),
        'page' => $page,
        'perpage' => $perpage,
        'list' => $list
    ));
    
    
    
    
    
    
    
    } elseif ($action == 'poll_vote') {
    // --- 参与投票 (修复版：增加 ID 校验与 voterids 同步) ---

    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $tid = isset($_REQUEST['tid']) ? intval($_REQUEST['tid']) : 0;
    $options = isset($_REQUEST['options']) ? trim($_REQUEST['options']) : '';

    if(!$uid || !$tid || !$options) api_return(-3, 'Missing parameters');

    $poll = C::t('forum_poll')->fetch($tid);
    if(!$poll) api_return(-8, 'Poll not found');
    if($poll['expiration'] && $poll['expiration'] < TIMESTAMP) api_return(-14, 'Poll expired');

    // 检查是否已投过
    $voter = DB::fetch_first("SELECT * FROM ".DB::table('forum_pollvoter')." WHERE tid=$tid AND uid=$uid");
    if($voter) api_return(-15, 'Already voted');

    $opt_ids = array_unique(array_filter(array_map('intval', explode(',', $options))));

    // --- [核心修复] 校验这些 polloptionid 是否真的属于这个 tid ---
    $valid_options = array();
    $query = DB::query("SELECT polloptionid FROM ".DB::table('forum_polloption')." WHERE tid=$tid");
    while($row = DB::fetch($query)) {
        $valid_options[] = $row['polloptionid'];
    }

    foreach($opt_ids as $oid) {
        if(!in_array($oid, $valid_options)) {
            api_return(-17, "Invalid option ID: $oid. Please use polloptionid from thread_content API.");
        }
    }

    if(count($opt_ids) > $poll['maxchoices']) api_return(-16, 'Too many options');

    // 执行更新
    foreach($opt_ids as $oid) {
        // 更新票数，并追加 UID 到 voterids (Discuz 标准做法)
        DB::query("UPDATE ".DB::table('forum_polloption')." 
                   SET votes=votes+1, voterids=CONCAT(voterids, '\t', $uid) 
                   WHERE polloptionid=$oid AND tid=$tid");
    }

    // 更新投票主表
    C::t('forum_poll')->update($tid, array('voters' => $poll['voters'] + 1));
    
    // 记录投票人
    $member = C::t('common_member')->fetch($uid);
    DB::insert('forum_pollvoter', array(
        'tid' => $tid,
        'uid' => $uid,
        'username' => $member['username'],
        'options' => implode("\t", $opt_ids),
        'dateline' => TIMESTAMP
    ));

    api_return(0, 'Vote success');
    
    
    
    
    
    
    
    } elseif ($action == 'activity_apply') {
    // --- 新增接口：报名参加活动 ---

    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $tid = isset($_REQUEST['tid']) ? intval($_REQUEST['tid']) : 0;
    $message = isset($_REQUEST['message']) ? trim($_REQUEST['message']) : ''; // 留言
    $contact = isset($_REQUEST['contact']) ? trim($_REQUEST['contact']) : ''; // 联系方式

    // 2. 基础校验
    if(!$uid || !$tid || !$contact) {
        api_return(-3, 'UID, TID and Contact info are required');
    }

    // 3. 检查活动状态
    $activity = C::t('forum_activity')->fetch($tid);
    if(!$activity) {
        api_return(-8, 'Activity not found');
    }

    // 检查是否过期 (starttimeto 不为0且小于当前时间)
    if($activity['starttimeto'] && $activity['starttimeto'] < TIMESTAMP) {
        api_return(-14, 'Activity has expired');
    }

    // 4. 检查用户是否已报名
    // 表 forum_activityapply 记录报名信息
    $apply = DB::fetch_first("SELECT * FROM ".DB::table('forum_activityapply')." WHERE tid=$tid AND uid=$uid");
    if($apply) {
        api_return(-15, 'You have already applied');
    }

    // 5. 写入报名表
    $member = C::t('common_member')->fetch($uid);
    $username = $member ? $member['username'] : 'Unknown';

    $data = array(
        'tid' => $tid,
        'username' => $username,
        'uid' => $uid,
        'message' => $message,
        'verified' => 0,       // 0=待审核, 1=已通过
        'dateline' => TIMESTAMP,
        'payment' => 0,        // 暂不支持通过 API 支付积分费用
        'contact' => $contact
    );
    DB::insert('forum_activityapply', $data);

    // 6. 更新活动报名人数
    // Discuz 通常直接+1，不管是否审核通过，在前台显示“X人报名”
    C::t('forum_activity')->update($tid, array('applynumber' => $activity['applynumber'] + 1));

    api_return(0, 'Apply success');
    
    
    
    
    
    } elseif ($action == 'new_poll') {
    // --- 新增接口：发布投票主题 (数组参数版) ---

    // 1. 接收基础参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $fid = isset($_REQUEST['fid']) ? intval($_REQUEST['fid']) : 0;
    $subject = isset($_REQUEST['subject']) ? trim($_REQUEST['subject']) : '';
    $message = isset($_REQUEST['message']) ? trim($_REQUEST['message']) : '';
    $typeid = isset($_REQUEST['typeid']) ? intval($_REQUEST['typeid']) : 0;

    // 2. 接收投票设置参数
    $maxchoices = isset($_REQUEST['maxchoices']) ? max(1, intval($_REQUEST['maxchoices'])) : 1;
    $days = isset($_REQUEST['expiration']) ? intval($_REQUEST['expiration']) : 0;
    $expiration = $days > 0 ? (TIMESTAMP + $days * 86400) : 0;
    $visible = isset($_REQUEST['visible']) ? intval($_REQUEST['visible']) : 0;
    $overt = isset($_REQUEST['overt']) ? intval($_REQUEST['overt']) : 0;
    
    // 3. [核心修改] 接收选项数据 (使用数组参数: poll_text[] 和 poll_aid[])
    // 格式例如：poll_text[]=选项A&poll_text[]=选项B&poll_aid[]=0&poll_aid[]=1055
    $poll_texts = isset($_REQUEST['poll_text']) ? (array)$_REQUEST['poll_text'] : array();
    $poll_aids = isset($_REQUEST['poll_aid']) ? (array)$_REQUEST['poll_aid'] : array(); // 确保是数组

    // 4. 校验
    if(!$uid || !$fid || !$subject || !$message) api_return(-3, 'Missing basic parameters');
    if(empty($poll_texts) || !is_array($poll_texts)) api_return(-3, 'Poll options required (use poll_text[] and poll_aid[])');
    if(count($poll_texts) > 20) api_return(-16, 'Too many options (Max 20)');

    $member = C::t('common_member')->fetch($uid);
    if(!$member) api_return(-6, 'User not found');

    // 5. 插入主题表 (special = 1 代表投票)
    $newthread = array(
        'fid' => $fid,
        'typeid' => $typeid,
        'author' => $member['username'],
        'authorid' => $uid,
        'subject' => $subject,
        'dateline' => TIMESTAMP,
        'lastpost' => TIMESTAMP,
        'lastposter' => $member['username'],
        'special' => 1, // [关键] 标记为投票贴
        'status' => 32,
    );
    $tid = C::t('forum_thread')->insert($newthread, true);

    // 6. 插入帖子内容表
    $pid = C::t('forum_post_tableid')->insert(array('pid' => null), true);
    C::t('forum_post')->insert($fid, array(
        'pid' => $pid, 'fid' => $fid, 'tid' => $tid, 'first' => 1,
        'author' => $member['username'], 'authorid' => $uid,
        'subject' => $subject, 'dateline' => TIMESTAMP, 'message' => $message,
        'useip' => $_G['clientip']
    ));

    // 7. 插入投票主表 (forum_poll)
    C::t('forum_poll')->insert(array(
        'tid' => $tid,
        'multiple' => $maxchoices > 1 ? 1 : 0, // 是否多选
        'visible' => $visible,
        'maxchoices' => $maxchoices,
        'expiration' => $expiration,
        'overt' => $overt,
        'voters' => 0
    ));

    // 8. [核心修改] 循环插入投票选项 (forum_polloption)
    foreach ($poll_texts as $index => $text) {
        $text = trim($text);
        // 从 poll_aids 数组中获取对应的图片 ID
        $aid = isset($poll_aids[$index]) ? intval($poll_aids[$index]) : 0;
        
        if($text === '' && $aid === 0) continue; // 跳过完全为空的选项

        $oid = DB::insert('forum_polloption', array(
            'tid' => $tid,
            'votes' => 0,
            'displayorder' => $index, // 使用循环索引作为排序
            'polloption' => $text,
            'voterids' => ''
        ), true);

        // 如果选项带有图片 (AID)
        if($aid > 0) {
            DB::insert('forum_polloption_image', array(
                'tid' => $tid,
                'poid' => $oid,
                'aid' => $aid,
                'uid' => $uid
            ));
        }
    }

    // 9. 更新统计
    C::t('forum_forum')->update_forum_counter($fid, 1, 1, 1);
    C::t('common_member_count')->increase($uid, array('threads' => 1, 'posts' => 1));

    api_return(0, 'Poll created successfully', array('tid' => $tid));
    
    
    
    
    
    } elseif ($action == 'edit_poll') {
    // --- 编辑投票主题 (修复版：兼容不存在的索引表) ---

    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $tid = isset($_REQUEST['tid']) ? intval($_REQUEST['tid']) : 0;
    $delete = isset($_REQUEST['delete']) ? intval($_REQUEST['delete']) : 0; 
    
    $subject = isset($_REQUEST['subject']) ? trim($_REQUEST['subject']) : '';
    $message = isset($_REQUEST['message']) ? trim($_REQUEST['message']) : '';
    $maxchoices = isset($_REQUEST['maxchoices']) ? max(1, intval($_REQUEST['maxchoices'])) : 1;
    $days = isset($_REQUEST['expiration']) ? intval($_REQUEST['expiration']) : 0;
    $visible = isset($_REQUEST['visible']) ? intval($_REQUEST['visible']) : 0;
    $overt = isset($_REQUEST['overt']) ? intval($_REQUEST['overt']) : 0;

    $delete_options = isset($_REQUEST['delete_options']) ? $_REQUEST['delete_options'] : ''; 
    $poll_ids = isset($_REQUEST['poll_id']) ? (array)$_REQUEST['poll_id'] : array();     
    $poll_texts = isset($_REQUEST['poll_text']) ? (array)$_REQUEST['poll_text'] : array(); 
    $poll_aids = isset($_REQUEST['poll_aid']) ? (array)$_REQUEST['poll_aid'] : array();   

    // 2. 校验
    if(!$uid || !$tid) api_return(-3, 'UID and TID required');
    
    $thread = C::t('forum_thread')->fetch($tid);
    if(!$thread) api_return(-8, 'Thread not found');
    if($thread['authorid'] != $uid) api_return(-9, 'Access denied');
    if($thread['special'] != 1) api_return(-17, 'This is not a poll thread');

    $fid = $thread['fid'];

    // --- 核心逻辑 A: 执行删除 ---
    if ($delete > 0) {
        if(!function_exists('deletethreads')) {
            @include_once libfile('function/post');
            @include_once libfile('function/forum');
            @include_once libfile('function/delete');
        }
        
        if (function_exists('deletethreads')) {
            deletethreads(array($tid));
            api_return(0, 'Thread deleted successfully');
        } else {
            // 【保底逻辑：兼容模式】
            $tables = array(
                'forum_thread', 
                'forum_post', 
                'forum_poll', 
                'forum_polloption', 
                'forum_polloption_image', 
                'forum_pollvoter', 
                'forum_threadmod',
                'forum_sofa' // 增加沙发垫表清理
            );
            
            foreach($tables as $table) {
                // 使用 'SILENT' 参数，如果表不存在或报错，则静默跳过，不中断程序
                DB::query("DELETE FROM ".DB::table($table)." WHERE tid='$tid'", 'SILENT');
            }
            
            // 尝试删除索引表（如果不报错就删，报错就跳过）
            DB::query("DELETE FROM ".DB::table('forum_threadindex')." WHERE tid='$tid'", 'SILENT');

            if(!function_exists('updateforumcount')) {
                @include_once libfile('function/forum');
            }
            if(function_exists('updateforumcount')) {
                updateforumcount($fid); 
            }

            api_return(0, 'Thread deleted by direct DB operation');
        }
    }

    // --- 核心逻辑 B: 执行编辑 ---
    if(!empty($subject)) C::t('forum_thread')->update($tid, array('subject' => $subject));
    
    if(!empty($message)) {
        $pid = DB::result_first("SELECT pid FROM ".DB::table('forum_post')." WHERE tid=$tid AND first=1");
        if($pid) C::t('forum_post')->update('tid:'.$tid, $pid, array('message' => $message));
    }

    $expiration = $days > 0 ? (TIMESTAMP + $days * 86400) : 0;
    C::t('forum_poll')->update($tid, array(
        'maxchoices' => $maxchoices,
        'expiration' => $expiration,
        'visible' => $visible,
        'overt' => $overt,
        'multiple' => $maxchoices > 1 ? 1 : 0
    ));

    if(!empty($delete_options)) {
        $del_arr = is_array($delete_options) ? $delete_options : explode(',', $delete_options);
        $del_arr = array_filter(array_map('intval', $del_arr));
        if(!empty($del_arr)) {
            $ids_str = implode(',', $del_arr);
            DB::query("DELETE FROM ".DB::table('forum_polloption')." WHERE polloptionid IN ($ids_str) AND tid=$tid");
            DB::query("DELETE FROM ".DB::table('forum_polloption_image')." WHERE poid IN ($ids_str) AND tid=$tid");
        }
    }

    if(!empty($poll_texts)) {
        foreach ($poll_texts as $index => $text) {
            $text = trim($text);
            $aid = isset($poll_aids[$index]) ? intval($poll_aids[$index]) : 0;
            $poid = isset($poll_ids[$index]) ? intval($poll_ids[$index]) : 0;

            if($text === '' && $aid === 0) continue;

            if($poid > 0) {
                $check = DB::fetch_first("SELECT polloptionid FROM ".DB::table('forum_polloption')." WHERE polloptionid=$poid AND tid=$tid");
                if($check) DB::update('forum_polloption', array('polloption' => $text), "polloptionid=$poid");
            } else {
                $max_order = DB::result_first("SELECT MAX(displayorder) FROM ".DB::table('forum_polloption')." WHERE tid=$tid");
                $poid = DB::insert('forum_polloption', array(
                    'tid' => $tid, 'votes' => 0, 'displayorder' => intval($max_order) + 1, 'polloption' => $text, 'voterids' => ''
                ), true);
            }

            if($poid > 0 && $aid > 0) {
                DB::delete('forum_polloption_image', "poid=$poid");
                DB::insert('forum_polloption_image', array('tid' => $tid, 'poid' => $poid, 'aid' => $aid, 'uid' => $uid));
            }
        }
    }

    api_return(0, 'Poll edited successfully');
    
    
    
    
    
    } elseif ($action == 'check_unread') {
    // --- [新增] 全局未读数检查：仅获取计数，不触发已读 ---
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    
    if(!$uid) {
        api_return(-3, 'User ID (uid) is required');
    }

    // 1. 获取系统提醒未读数 (来自 pre_home_notification 表)
    $notice_unread = DB::result_first("SELECT COUNT(*) FROM ".DB::table('home_notification')." WHERE uid=$uid AND new=1");

    // 2. 获取私信会话未读数 (来自 pre_ucenter_pm_members 表)
    // 这里的表名 pre_ucenter_ 视您的数据库前缀而定，通常 Discuz 会自动处理 DB::table
    $pm_unread = DB::result_first("SELECT COUNT(*) FROM ".DB::table('ucenter_pm_members')." WHERE uid=$uid AND isnew=1");

    api_return(0, 'Success', array(
        'notice' => intval($notice_unread), // 系统消息未读数
        'pm' => intval($pm_unread),         // 私信会话未读数
        'total' => intval($notice_unread + $pm_unread) // 总未读数和
    ));
    
    
    
    
    
    
    } elseif ($action == 'notifications') {
    // --- [核心功能] 系统提醒列表：获取详情并自动标记全部已读 ---
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(100, intval($_REQUEST['perpage']))) : 20;

    if(!$uid) {
        api_return(-3, 'User ID (uid) is required');
    }

    // 1. 获取该用户的提醒总数
    $total = DB::result_first("SELECT COUNT(*) FROM ".DB::table('home_notification')." WHERE uid=$uid");

    $list = array();
    if($total > 0) {
        $start = ($page - 1) * $perpage;
        
        // 2. 获取提醒列表 (按时间倒序)
        // note 字段包含 Discuz! 生成的 HTML 提醒内容
        $sql = "SELECT id, type, new, authorid, author, note, dateline 
                FROM ".DB::table('home_notification')." 
                WHERE uid=$uid 
                ORDER BY dateline DESC 
                LIMIT $start, $perpage";
        
        $query = DB::query($sql);
        while($row = DB::fetch($query)) {
            $list[] = array(
                'id' => $row['id'],
                'type' => $row['type'],         // 提醒类型：post(回帖), at(@我), system(系统) 等
                'is_new' => intval($row['new']), // 原始状态：1为新，0为旧
                'author' => $row['author'],     // 触发者用户名
                'authorid' => $row['authorid'], // 触发者 UID
                'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$row['authorid'].'&size=small',
                'note' => $row['note'],         // 详细 HTML 内容
                'dateline' => dgmdate($row['dateline'], 'u') // 友好时间格式
            );
        }

        // 3. [关键逻辑] 自动已读：只要调用了列表接口，就将该用户所有未读提醒清零
        DB::query("UPDATE ".DB::table('home_notification')." SET new=0 WHERE uid=$uid AND new=1");
    }

    api_return(0, 'Success', array(
        'total' => intval($total),
        'page' => $page,
        'perpage' => $perpage,
        'total_page' => ceil($total / $perpage),
        'list' => $list
    ));
    
    
    
    
    
    
    } elseif ($action == 'message_list') {
    // --- 私信会话列表：获取列表，返回每个会话状态及全站未读总数 ---
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(50, intval($_REQUEST['perpage']))) : 15;

    if(!$uid) api_return(-3, 'User ID (uid) is required');

    // 加载 UCenter 核心
    require_once libfile('function/member');
    loaducenter();

    // 1. 调用 UC 函数获取私信列表
    // 'privatepm' 代表个人私信模式
    $pm_result = uc_pm_list($uid, $page, $perpage, 'inbox', 'privatepm', 200);

    $list = array();
    if($pm_result['data']) {
        foreach($pm_result['data'] as $row) {
            $list[] = array(
                'plid' => $row['plid'],             // 会话唯一 ID
                'is_new' => intval($row['isnew']),  // 该对话是否有新消息：1有，0无
                'msg_num' => $row['pmnum'],          // 该对话内的消息总数
                'subject' => $row['subject'],        // 对话标题（通常为对方用户名）
                'summary' => $row['lastsummary'],    // 最后一条消息的摘要预览
                'last_author' => $row['lastauthor'], // 最后发送者用户名
                'last_authorid' => $row['lastauthorid'],
                'last_avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$row['lastauthorid'].'&size=small',
                'last_time' => ($row['lastupdate'] > 0) ? dgmdate($row['lastupdate'], 'u') : '未知'
            );
        }
    }

    // 2. [核心功能] 独立计算全站未读私信会话的总数 (用于 App 首页红点)
    // 逻辑：查询 pm_members 表中该用户标记为 isnew=1 的记录数
    $unread_total = DB::result_first("SELECT COUNT(*) FROM ".DB::table('ucenter_pm_members')." WHERE uid=$uid AND isnew=1");

    api_return(0, 'Success', array(
        'total' => intval($pm_result['count']),   // 总会话数
        'unread_total' => intval($unread_total),  // 未读会话总数（红点数）
        'page' => $page,
        'perpage' => $perpage,
        'list' => $list
    ));
    
    
    
    
    
    } elseif ($action == 'message_details') {
    // --- 私信内容详情：获取聊天记录，且仅标记当前会话为已读 ---
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $plid = isset($_REQUEST['plid']) ? intval($_REQUEST['plid']) : 0;
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(100, intval($_REQUEST['perpage']))) : 20;

    if(!$uid || !$plid) {
        api_return(-3, 'User ID (uid) and Conversation ID (plid) are required');
    }

    // 1. 权限检查：确保当前用户是该私信会话的参与者
    // 检查 pre_ucenter_pm_members 表
    $is_member = DB::result_first("SELECT uid FROM ".DB::table('ucenter_pm_members')." WHERE plid = $plid AND uid = $uid");
    if(!$is_member) {
        api_return(-9, 'Access denied or conversation not found');
    }

    // 2. 确定消息分表名 (UCenter 私信消息按 plid % 10 进行分表存储)
    $table_index = $plid % 10;
    $table_pm_messages = "ucenter_pm_messages_$table_index";
    
    // 3. 分页查询消息记录
    $start = ($page - 1) * $perpage;
    $list = array();
    $sql = "SELECT pmid, authorid, message, dateline 
            FROM ".DB::table($table_pm_messages)." 
            WHERE plid = $plid 
            ORDER BY dateline ASC 
            LIMIT $start, $perpage";
    
    $query = DB::query($sql);
    
    $user_cache = array();
    while($row = DB::fetch($query)) {
        $msg_uid = $row['authorid'];
        
        // 缓存用户名，避免循环内重复查表
        if(!isset($user_cache[$msg_uid])) {
            $member_row = C::t('common_member')->fetch($msg_uid);
            $user_cache[$msg_uid] = $member_row ? $member_row['username'] : '未知用户';
        }

        $list[] = array(
            'pmid' => $row['pmid'],
            'author' => $user_cache[$msg_uid],
            'authorid' => $msg_uid,
            'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$msg_uid.'&size=small',
            'message' => $row['message'],
            'dateline' => dgmdate($row['dateline'], 'u'),
            // [核心功能] 方向标识：1 表示是我发送的（右侧），0 表示对方发送（左侧）
            'is_mine' => ($msg_uid == $uid ? 1 : 0)
        );
    }

    // 4. [关键逻辑] 精确已读：仅将当前 plid 对应的未读状态清零
    DB::query("UPDATE ".DB::table('ucenter_pm_members')." SET isnew=0 WHERE plid=$plid AND uid=$uid AND isnew=1");

    api_return(0, 'Success', array(
        'plid' => $plid,
        'count' => count($list),
        'page' => $page,
        'list' => $list
    ));
    
    
    
    
    
    
    } elseif ($action == 'mark_pm_all_read') {
    // --- [新增] 私信全部已读：一键清空所有私信对话的红点 ---
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;

    if(!$uid) {
        api_return(-3, 'User ID (uid) is required');
    }

    // 1. 清空 UCenter 私信成员表中的未读标记 (isnew = 0)
    // 注意：DB::table 会自动处理表前缀，通常为 pre_ucenter_pm_members
    DB::query("UPDATE ".DB::table('ucenter_pm_members')." SET isnew=0 WHERE uid=$uid AND isnew=1");
    
    // 2. 同步更新 Discuz! 本地用户状态表，确保全站范围内的私信红点消失
    // newpm 字段记录了用户未读私信的数量
    C::t('common_member_status')->update($uid, array('newpm' => 0));

    api_return(0, 'All private messages marked as read');
    
    
    
    
    
    
    } elseif ($action == 'friend_list') {
    // --- 获取好友列表 ---
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(100, intval($_REQUEST['perpage']))) : 20;

    if (!$uid) api_return(-3, 'User ID (uid) is required');

    $total = DB::result_first("SELECT COUNT(*) FROM " . DB::table('home_friend') . " WHERE uid=%d", array($uid));
    $list = array();

    if ($total > 0) {
        $start = ($page - 1) * $perpage;
        $sql = "SELECT fuid, fusername, dateline FROM " . DB::table('home_friend') . " 
                WHERE uid=%d ORDER BY dateline DESC LIMIT %d, %d";
        $query = DB::query($sql, array($uid, $start, $perpage));
        while ($row = DB::fetch($query)) {
            $list[] = array(
                'uid' => $row['fuid'],
                'username' => $row['fusername'],
                'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid=' . $row['fuid'] . '&size=small',
                'dateline' => dgmdate($row['dateline'], 'u')
            );
        }
    }
    api_return(0, 'Success', array('total' => intval($total), 'list' => $list));
    
    
    
    
    
    

} elseif ($action == 'check_friend') {
    // --- 检查好友关系 ---
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $fuid = isset($_REQUEST['fuid']) ? intval($_REQUEST['fuid']) : 0;

    if (!$uid || !$fuid) api_return(-3, 'Both uid and fuid are required');

    $is_friend = DB::result_first("SELECT uid FROM " . DB::table('home_friend') . " WHERE uid=%d AND fuid=%d", array($uid, $fuid));
    
    api_return(0, 'Success', array('is_friend' => $is_friend ? 1 : 0));
    
    
    
    
    
    

} elseif ($action == 'add_friend_request') {
    // --- 发送好友申请 (增强通知版) ---
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;     // 发起人
    $fuid = isset($_REQUEST['fuid']) ? intval($_REQUEST['fuid']) : 0;   // 接收人
    $note = isset($_REQUEST['note']) ? trim($_REQUEST['note']) : '';    // 备注

    if (!$uid || !$fuid) api_return(-3, 'Missing parameters');
    if ($uid == $fuid) api_return(-11, 'Cannot add yourself');

    // 1. 检查是否已经是好友
    if (DB::result_first("SELECT uid FROM " . DB::table('home_friend') . " WHERE uid=%d AND fuid=%d", array($uid, $fuid))) {
        api_return(-15, 'Already friends');
    }

    // 2. 检查冷却时间 (3天)
    $cooldown = 3 * 86400;
    $existing = DB::fetch_first("SELECT dateline FROM " . DB::table('home_friend_request') . " WHERE uid=%d AND fuid=%d", array($fuid, $uid));
    if ($existing && (TIMESTAMP - $existing['dateline'] < $cooldown)) {
        api_return(-15, 'Request sent recently. Cooldown is 3 days.');
    }

    // 3. 更新/插入申请记录
    DB::query("DELETE FROM " . DB::table('home_friend_request') . " WHERE uid=%d AND fuid=%d", array($fuid, $uid));
    $member = C::t('common_member')->fetch($uid);
    
    $data = array(
        'uid' => $fuid,
        'fuid' => $uid,
        'fusername' => $member['username'],
        'gid' => 0,
        'note' => $note,
        'dateline' => TIMESTAMP
    );
    DB::insert('home_friend_request', $data);
    
    // 4. 【核心增强】发送带 HTML 链接的交互式通知
    // 包含：用户名跳转链接 + 批准申请快捷链接
    $user_link = '<a href="home.php?mod=space&uid=' . $uid . '" class="xw1">' . $member['username'] . '</a>';
    $approve_url = 'home.php?mod=spacecp&ac=friend&op=add&uid=' . $uid . '&from=notice';
    $approve_link = '<a href="' . $approve_url . '" onclick="showWindow(this.id, this.href, \'get\', 0);" class="xw1" id="afr_' . $uid . '">批准申请</a>';
    
    $final_note = $user_link . ' 请求加您为好友 &nbsp; ' . $approve_link;
    
    // 如果有留言，附带留言预览
    if($note) {
        $final_note .= '<br><span class="xg1">留言: ' . $note . '</span>';
    }

    notification_add($fuid, 'friend', $final_note, array(), 1);

    api_return(0, 'Friend request sent successfully');
    
    
    
    
    
    

} elseif ($action == 'handle_friend_request') {
    // --- 处理好友申请 (同意/忽略) ---
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;     // 当前登录用户 (接收者)
    $fuid = isset($_REQUEST['fuid']) ? intval($_REQUEST['fuid']) : 0;   // 申请人 (发起者)
    $do = isset($_REQUEST['do']) ? $_REQUEST['do'] : 'accept';         // accept 或 ignore

    if (!$uid || !$fuid) api_return(-3, 'Missing parameters');

    if ($do == 'accept') {
        // 同意：Discuz 逻辑是双向插入 home_friend 表
        $member_u = C::t('common_member')->fetch($uid);
        $member_f = C::t('common_member')->fetch($fuid);

        DB::insert('home_friend', array('uid' => $uid, 'fuid' => $fuid, 'fusername' => $member_f['username'], 'dateline' => TIMESTAMP), false, true);
        DB::insert('home_friend', array('uid' => $fuid, 'fuid' => $uid, 'fusername' => $member_u['username'], 'dateline' => TIMESTAMP), false, true);
        
        // 删除申请记录
        DB::query("DELETE FROM " . DB::table('home_friend_request') . " WHERE uid=%d AND fuid=%d", array($uid, $fuid));
        
        // 更新好友数统计
        C::t('common_member_count')->increase($uid, array('friends' => 1));
        C::t('common_member_count')->increase($fuid, array('friends' => 1));

        api_return(0, 'Friend request accepted');
    } else {
        // 忽略/拒绝
        DB::query("DELETE FROM " . DB::table('home_friend_request') . " WHERE uid=%d AND fuid=%d", array($uid, $fuid));
        api_return(0, 'Friend request ignored');
    }







} elseif ($action == 'delete_friend') {
    // --- 删除好友 ---
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $fuid = isset($_REQUEST['fuid']) ? intval($_REQUEST['fuid']) : 0;

    if (!$uid || !$fuid) api_return(-3, 'Missing parameters');

    // 双向删除
    DB::query("DELETE FROM " . DB::table('home_friend') . " WHERE (uid=%d AND fuid=%d) OR (uid=%d AND fuid=%d)", array($uid, $fuid, $fuid, $uid));
    
    // 扣减好友数统计
    C::t('common_member_count')->increase($uid, array('friends' => -1));
    C::t('common_member_count')->increase($fuid, array('friends' => -1));

    api_return(0, 'Friend deleted successfully');

    
    
    
} elseif ($action == 'medal_list') {
    // --- 获取勋章基础信息列表 (全站) ---
    // 修正：表名由 common_medal 改为 forum_medal

    // 1. 查询所有启用 (available=1) 的勋章
    $sql = "SELECT medalid, name, image, description 
            FROM ".DB::table('forum_medal')." 
            WHERE available='1' 
            ORDER BY displayorder ASC";
    
    $query = DB::query($sql);
    $list = array();

    while($row = DB::fetch($query)) {
        $image_url = $_G['siteurl'] . 'static/image/common/' . $row['image'];

        $list[] = array(
            'medalid' => $row['medalid'],
            'name' => $row['name'],
            'image' => $image_url,
            'description' => $row['description']
        );
    }

    api_return(0, 'Success', array(
        'total' => count($list),
        'list' => $list
    ));






} elseif ($action == 'user_medal_list') {
    // --- 获取用户已拥有的勋章 (修正版) ---
    // 修正：去除不存在的 expiration 字段，仅查询持有状态
    
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    
    if(!$uid) {
        api_return(-3, 'User ID (uid) is required');
    }

    // 1. 联表查询：用户勋章表 (common_member_medal) + 勋章基础表 (forum_medal)
    // 逻辑：只要表里有记录且勋章启用，即视为拥有
    
    $sql = "SELECT mm.medalid, m.name, m.image, m.description 
            FROM ".DB::table('common_member_medal')." mm 
            LEFT JOIN ".DB::table('forum_medal')." m ON m.medalid = mm.medalid 
            WHERE mm.uid=%d AND m.available='1' 
            ORDER BY m.displayorder ASC"; // 按勋章后台设置的顺序排列
    
    $query = DB::query($sql, array($uid));
    $list = array();

    while($row = DB::fetch($query)) {
        // 修正图片路径：Discuz 默认勋章位于 static/image/common/
        // 部分站点可能修改过路径，这里使用标准路径
        $image_url = $_G['siteurl'] . 'static/image/common/' . $row['image'];
        
        $list[] = array(
            'medalid' => $row['medalid'],
            'name' => $row['name'],
            'image' => $image_url,
            'description' => $row['description'],
            // 如需精确过期时间需关联 forum_medallog 表，鉴于复杂度和性能，暂不通过 API 提供
            'status' => 'active' 
        );
    }

    api_return(0, 'Success', array(
        'uid' => $uid,
        'total' => count($list),
        'list' => $list
    ));
    
    
    
    
    
    
    
} elseif ($action == 'favorite_forum') {
    // --- 收藏/取消收藏 版块 ---
    
    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $fid = isset($_REQUEST['fid']) ? intval($_REQUEST['fid']) : 0;
    $do = isset($_REQUEST['do']) ? trim($_REQUEST['do']) : 'add'; // add=收藏, del=取消

    // 2. 基础校验
    if(!$uid || !$fid) {
        api_return(-3, 'UID and FID are required');
    }

    // 3. 检查版块是否存在且状态正常
    $forum = C::t('forum_forum')->fetch($fid);
    if(!$forum || $forum['status'] != 1) {
        api_return(-7, 'Forum invalid or closed');
    }

    // 4. 检查是否已收藏
    // idtype='fid' 代表版块收藏
    $fav = C::t('home_favorite')->fetch_by_id_idtype($fid, 'fid', $uid);

    if($do == 'add') {
        // --- 执行收藏 ---
        if($fav) {
            api_return(-15, 'Already favorite');
        }
        
        $data = array(
            'uid' => $uid,
            'id' => $fid,
            'idtype' => 'fid',
            'spaceuid' => 0,
            'title' => $forum['name'], // 缓存版块名称
            'description' => '',
            'dateline' => TIMESTAMP
        );
        C::t('home_favorite')->insert($data);
        
        // 注：Discuz 默认通常不统计版块的被收藏数，如果你的站点有此需求需额外处理
        
        api_return(0, 'Forum favorite added');

    } else {
        // --- 执行取消 ---
        if($fav) {
            C::t('home_favorite')->delete($fav['favid']);
        }
        // 即使原本没收藏，执行删除也返回成功（幂等性）
        api_return(0, 'Forum favorite removed');
    }
    
    
    
    
    
    
    
} elseif ($action == 'favorite_thread') {
    // --- 收藏/取消收藏 帖子 ---
    
    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $tid = isset($_REQUEST['tid']) ? intval($_REQUEST['tid']) : 0;
    $do = isset($_REQUEST['do']) ? trim($_REQUEST['do']) : 'add'; // add=收藏, del=取消

    // 2. 基础校验
    if(!$uid || !$tid) {
        api_return(-3, 'UID and TID are required');
    }

    // 3. 检查帖子是否存在
    // 同时也排除回收站中的帖子 (displayorder < 0)
    $thread = C::t('forum_thread')->fetch($tid);
    if(!$thread || $thread['displayorder'] < 0) {
        api_return(-8, 'Thread not found or deleted');
    }

    // 4. 检查是否已收藏
    // idtype='tid' 代表帖子收藏
    $fav = C::t('home_favorite')->fetch_by_id_idtype($tid, 'tid', $uid);

    if($do == 'add') {
        // --- 执行收藏 ---
        if($fav) {
            api_return(-15, 'Already favorite');
        }
        
        $data = array(
            'uid' => $uid,
            'id' => $tid,
            'idtype' => 'tid',
            'spaceuid' => 0, // 帖子收藏不需要指定空间UID
            'title' => $thread['subject'], // 缓存帖子标题
            'description' => '',
            'dateline' => TIMESTAMP
        );
        C::t('home_favorite')->insert($data);
        
        // [关键] 增加帖子的被收藏次数 (favtimes + 1)
        C::t('forum_thread')->increase($tid, array('favtimes' => 1));
        
        api_return(0, 'Favorite added');

    } else {
        // --- 执行取消 ---
        if($fav) {
            C::t('home_favorite')->delete($fav['favid']);
            
            // [关键] 减少帖子的被收藏次数 (favtimes - 1)
            // 只有真正删除了收藏记录才减，防止重复调用导致负数风险
            C::t('forum_thread')->increase($tid, array('favtimes' => -1));
        }
        api_return(0, 'Favorite removed');
    }
    
    
    
    
    
    
    
} elseif ($action == 'favorite_list') {
    // --- 获取用户收藏列表 (支持类型筛选) ---
    
    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $type = isset($_REQUEST['type']) ? trim($_REQUEST['type']) : 'all'; // 可选: all, thread, forum
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(50, intval($_REQUEST['perpage']))) : 20;

    if(!$uid) {
        api_return(-3, 'UID is required');
    }

    // 2. 构建查询条件
    $where_arr = array("uid=%d");
    $params = array($uid);

    // 筛选逻辑映射：API参数 -> 数据库字段值
    if($type == 'thread') {
        $where_arr[] = "idtype='tid'";
    } elseif($type == 'forum') {
        $where_arr[] = "idtype='fid'";
    }
    // Discuz 收藏夹还可能包含日志(blogid)、相册(albumid)等，默认为 'all' 时不加限制

    $where_sql = implode(' AND ', $where_arr);
    $start = ($page - 1) * $perpage;

    // 3. 统计总数
    $total = DB::result_first("SELECT COUNT(*) FROM ".DB::table('home_favorite')." WHERE $where_sql", $params);

    $list = array();
    if($total > 0) {
        // 4. 查询数据
        // 注意：title 是收藏时的快照标题
        $sql = "SELECT favid, id, idtype, title, description, dateline 
                FROM ".DB::table('home_favorite')." 
                WHERE $where_sql 
                ORDER BY dateline DESC 
                LIMIT $start, $perpage";
        
        $query = DB::query($sql, $params);
        while($row = DB::fetch($query)) {
            // 格式化 idtype 为前端更易读的字符串
            $type_name = 'unknown';
            if($row['idtype'] == 'tid') $type_name = 'thread';
            elseif($row['idtype'] == 'fid') $type_name = 'forum';
            elseif($row['idtype'] == 'blogid') $type_name = 'blog';
            elseif($row['idtype'] == 'albumid') $type_name = 'album';

            $list[] = array(
                'favid' => $row['favid'], // 收藏记录唯一ID
                'id' => $row['id'],       // 对象ID (tid 或 fid)
                'type' => $type_name,     // thread / forum
                'title' => $row['title'], // 标题
                'description' => $row['description'],
                'dateline' => dgmdate($row['dateline'], 'u')
            );
        }
    }

    api_return(0, 'Success', array(
        'total' => intval($total),
        'page' => $page,
        'perpage' => $perpage,
        'list' => $list
    ));
    
    
    
    
    
    
    
} elseif ($action == 'post_like') {
    // --- 优化版：无限次点赞接口 (仅保留权限开关与重复检查) ---
    
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $tid = isset($_REQUEST['tid']) ? intval($_REQUEST['tid']) : 0;
    $do = isset($_REQUEST['do']) ? trim($_REQUEST['do']) : 'add'; 

    if(!$uid || !$tid) api_return(-3, 'UID and TID are required');

    $thread = C::t('forum_thread')->fetch($tid);
    if(!$thread || $thread['displayorder'] < 0) api_return(-8, 'Thread not found');

    // 1. 检查用户组是否有权点赞 (保留此开关，防止垃圾账号刷赞)
    $member = C::t('common_member')->fetch($uid);
    $usergroup = C::t('common_usergroup_field')->fetch($member['groupid']);
    if(!$usergroup['allowrecommend']) {
        api_return(-9, 'Your usergroup is not allowed to recommend threads');
    }

    // 2. 检查是否已经点过赞 (针对该贴)
    $has_liked = DB::result_first("SELECT count(*) FROM ".DB::table('forum_memberrecommend')." WHERE tid=%d AND recommenduid=%d", array($tid, $uid));

    if($do == 'add') {
        if($has_liked) api_return(-15, 'Already liked this thread');

        // 执行点赞
        DB::insert('forum_memberrecommend', array(
            'tid' => $tid, 'recommenduid' => $uid, 'dateline' => TIMESTAMP
        ));

        // 更新统计：推荐总数+1, 支持数+1, 热度+1
        C::t('forum_thread')->increase($tid, array(
            'recommends' => 1, 
            'recommend_add' => 1,
            'heats' => 1
        ));

        // 发送提醒给作者
        if($thread['authorid'] != $uid) {
            $user_link = '<a href="home.php?mod=space&uid=' . $uid . '" class="xw1">' . $member['username'] . '</a>';
            $thread_link = '<a href="forum.php?mod=viewthread&tid=' . $tid . '" class="xw1">' . $thread['subject'] . '</a>';
            $note = $user_link . ' 赞了您的帖子 ' . $thread_link;
            notification_add($thread['authorid'], 'recommend', $note, array(), 1);
        }

        api_return(0, 'Liked successfully', array(
            'tid' => $tid,
            'is_liked' => 1,
            'current_likes' => intval($thread['recommend_add']) + 1
        ));

    } else {
        // 取消点赞
        if(!$has_liked) api_return(-15, 'Not liked yet');
        
        DB::query("DELETE FROM ".DB::table('forum_memberrecommend')." WHERE tid=%d AND recommenduid=%d", array($tid, $uid));
        C::t('forum_thread')->increase($tid, array('recommends' => -1, 'recommend_add' => -1, 'heats' => -1));

        api_return(0, 'Unliked successfully', array(
            'tid' => $tid,
            'is_liked' => 0,
            'current_likes' => max(0, intval($thread['recommend_add']) - 1)
        ));
    }
    
    
    
    
    
    
    
    
} elseif ($action == 'post_rate') {
    // --- 最终完整版：含重复评分检查、强制执行、实时通知 ---
    
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $pid = isset($_REQUEST['pid']) ? intval($_REQUEST['pid']) : 0;
    $credit_id = isset($_REQUEST['credit_id']) ? intval($_REQUEST['credit_id']) : 0;
    $score = isset($_REQUEST['score']) ? intval($_REQUEST['score']) : 0;
    $reason = isset($_REQUEST['reason']) ? trim($_REQUEST['reason']) : 'API评分';

    if (!$uid || !$pid || !$credit_id || !$score) api_return(-3, 'Missing parameters');

    // 1. 获取帖子和作者
    $post = DB::fetch_first("SELECT tid, fid, authorid, author FROM ".DB::table('forum_post')." WHERE pid=%d", array($pid));
    if (!$post) api_return(-8, 'Post not found');
    
    // 权限：禁止给自己评分
    if ($post['authorid'] == $uid) api_return(-9, 'You cannot rate your own post');

    // 2. 【核心新增】检查是否已经评分过
    // Discuz 逻辑：同一用户对同一 PID 只能评分一次
    $has_rated = DB::result_first("SELECT COUNT(*) FROM ".DB::table('forum_ratelog')." WHERE pid=%d AND uid=%d", array($pid, $uid));
    if ($has_rated) {
        api_return(-15, 'You have already rated this post');
    }

    // 3. 获取权限和余额
    $member = C::t('common_member')->fetch($uid);
    $member_count = C::t('common_member_count')->fetch($uid);
    $usergroup = C::t('common_usergroup_field')->fetch($member['groupid']);
    
    $credit_field = 'extcredits'.$credit_id;
    $user_balance = isset($member_count[$credit_field]) ? intval($member_count[$credit_field]) : 0;

    // 4. 强制授权逻辑 (针对管理员或异常数据兜底)
    $raterange = @unserialize($usergroup['raterange']);
    if ($member['groupid'] == 1 || empty($raterange)) {
        $conf = array('deduct' => 1, 'min' => -1000, 'max' => 1000, 'daily' => 999999);
    } else {
        $conf = isset($raterange[$credit_id]) ? $raterange[$credit_id] : array('min' => -10, 'max' => 10, 'daily' => 100, 'deduct' => 1);
    }

    // 5. 执行数据库操作 (强制 SQL 驱动)
    
    // A. 记录评分日志
    DB::insert('forum_ratelog', array(
        'pid' => $pid, 'uid' => $uid, 'username' => $member['username'],
        'extcredits' => $credit_id, 'score' => $score, 'dateline' => TIMESTAMP, 'reason' => $reason
    ));

    // B. 更新帖子评分次数
    DB::query("UPDATE ".DB::table('forum_post')." SET ratetimes=ratetimes+1 WHERE pid=%d", array($pid));

    // C. 给作者加分
    DB::query("UPDATE ".DB::table('common_member_count')." SET {$credit_field}={$credit_field}+%d WHERE uid=%d", array($score, $post['authorid']));

    // D. 如果扣除自身，给评分人减分
    if ($conf['deduct']) {
        DB::query("UPDATE ".DB::table('common_member_count')." SET {$credit_field}={$credit_field}-%d WHERE uid=%d", array(abs($score), $uid));
    }

    // 6. 发送实时通知
    require_once libfile('function/home');
    loadcache('setting');
    $c_name = $_G['setting']['extcredits'][$credit_id]['title'];
    $c_unit = $_G['setting']['extcredits'][$credit_id]['unit'];
    
    $user_link = '<a href="home.php?mod=space&uid='.$uid.'" class="xw1">'.$member['username'].'</a>';
    $msg = "{$user_link} 评分了您的帖子，您获得了 [b]".($score > 0 ? '+'.$score : $score)." {$c_unit}{$c_name}[/b]。";
    if($reason) $msg .= "<br />理由：{$reason}";

    notification_add($post['authorid'], 'system', $msg, array('from_id' => $uid, 'from_idtype' => 'rate'), 1);

    api_return(0, 'Rated successfully', array(
        'score' => $score,
        'new_balance' => $user_balance - abs($score)
    ));
    
    
    
    
    
    
} elseif ($action == 'attachment_download') {
    // --- 附件下载鉴权与支付合并接口 ---

    $aid = isset($_REQUEST['aid']) ? intval($_REQUEST['aid']) : 0;
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    // confirm_pay=1 表示用户已在 App 端点击“确认支付”弹窗
    $confirm_pay = isset($_REQUEST['confirm_pay']) ? intval($_REQUEST['confirm_pay']) : 0;

    if (!$aid || !$uid) api_return(-3, 'Missing aid or uid');

    // 1. 获取附件信息
    $att_idx = C::t('forum_attachment')->fetch($aid);
    if (!$att_idx) api_return(-8, 'Attachment not found');
    $attach = C::t('forum_attachment_n')->fetch($att_idx['tableid'], $aid);
    if (!$attach) api_return(-8, 'Attachment data error');

    // 2. 基础下载权限检查
    $member = C::t('common_member')->fetch($uid);
    $usergroup = C::t('common_usergroup_field')->fetch($member['groupid']);
    if (!$usergroup['allowgetattach']) {
        api_return(-9, 'Your usergroup is not allowed to download attachments');
    }

    // 3. 计费逻辑判定
    $price = intval($attach['price']);
    $authorid = $attach['authorid'];
    $need_pay = false;

    // 只有附件有价格、且下载者不是作者本人时，才需要走支付流程
    if ($price > 0 && $uid != $authorid) {
        // 检查是否已经购买过 (forum_attachmentbuy)
        $has_bought = DB::result_first("SELECT count(*) FROM " . DB::table('forum_attachmentbuy') . " WHERE aid=%d AND uid=%d", array($aid, $uid));
        if (!$has_bought) {
            $need_pay = true;
        }
    }

    // 4. 处理支付
    if ($need_pay) {
        // 获取系统交易积分类型 (通常是 extcredits2)
        loadcache('setting');
        $credit_id = $_G['setting']['creditstrans']; 
        $credit_field = 'extcredits' . $credit_id;
        $member_count = C::t('common_member_count')->fetch($uid);
        
        // 检查余额
        if ($member_count[$credit_field] < $price) {
            api_return(-20, 'Insufficient credits', array('price' => $price, 'credit_id' => $credit_id));
        }

        // 如果用户还没确认支付，先返回价格信息，让 App 弹窗
        if (!$confirm_pay) {
            api_return(1, 'Need confirmation', array(
                'price' => $price,
                'credit_name' => $_G['setting']['extcredits'][$credit_id]['title'],
                'credit_unit' => $_G['setting']['extcredits'][$credit_id]['unit']
            ));
        }

        // 执行扣费与记录
        require_once libfile('function/post');
        // 扣除下载者积分
        updatemembercount($uid, array($credit_id => -$price), true, 'BAC', $aid);
        // 插入购买记录
        DB::insert('forum_attachmentbuy', array(
            'aid' => $aid, 'uid' => $uid, 'authorid' => $authorid, 'dateline' => TIMESTAMP, 'price' => $price
        ));
        // (可选) 给作者分成逻辑可以在这里根据后台设置补全
    }

    // 5. 生成授权下载链接 (aidencode)
    require_once libfile('function/forum');
    $download_token = aidencode($aid, 0, $attach['tid']);
    $download_url = $_G['siteurl'] . 'forum.php?mod=attachment&aid=' . $download_token;

    api_return(0, 'Success', array(
        'aid' => $aid,
        'filename' => $attach['filename'],
        'is_paid' => $need_pay ? 1 : 0, // 告知 App 刚才是否发生了扣费
        'download_url' => $download_url
    ));
    
    
    
    
    
    
    
    
} elseif ($action == 'register') {
    // --- 注册接口 (终极完整版：全安全策略同步) ---

    $email = isset($_REQUEST['email']) ? trim($_REQUEST['email']) : '';
    $invitecode = isset($_REQUEST['invitecode']) ? trim($_REQUEST['invitecode']) : ''; 

    // 0. 加载后台配置与语言包
    loadcache('setting');
    $setting = $_G['setting'];
    $regstatus = $setting['regstatus'];
    $regname = !empty($setting['regname']) ? $setting['regname'] : 'register';

    // 1. 检查注册总开关
    if($regstatus == 0) api_return(-1, 'Registration is currently closed');

    // 2. 基础格式校验
    if(empty($email) || !preg_match("/^[\w\-\.]+@[\w\-\.]+(\.\w+)+$/", $email)) {
        api_return(-3, 'Invalid email format');
    }

    // 3. 【安全防护】检查 IP 注册权限 (防止单 IP 暴力刷信)
    // 检查 ipregctrl (多少小时内同一 IP 不允许重复注册)
    if($setting['ipregctrl']) {
        foreach(explode("\n", $setting['ipregctrl']) as $ctrl_ip) {
            if(preg_match("/^(".preg_quote(trim($ctrl_ip), '/').")/", $_G['clientip'])) {
                api_return(-22, 'Your IP is in the registration blacklist');
            }
        }
    }
    // 检查 24 小时内同一 IP 注册量上限
    if($setting['ipmaxblogcount'] > 0) {
        $ip_count = DB::result_first("SELECT COUNT(*) FROM ".DB::table('common_member')." WHERE regip=%s AND regdate>%d", array($_G['clientip'], TIMESTAMP - 86400));
        if($ip_count >= $setting['ipmaxblogcount']) {
            api_return(-22, 'Too many registrations from your IP in 24 hours');
        }
    }

    // 4. 【安全防护】检查邮箱域名限制 (accessemail / censoremail)
    if($setting['accessemail']) {
        $matched = false;
        foreach(explode("\n", $setting['accessemail']) as $domain) {
            if(strpos($email, trim($domain)) !== false) { $matched = true; break; }
        }
        if(!$matched) api_return(-23, 'Only specific email domains are allowed to register');
    }
    if($setting['censoremail']) {
        foreach(explode("\n", $setting['censoremail']) as $domain) {
            if(strpos($email, trim($domain)) !== false) api_return(-23, 'This email domain is blocked');
        }
    }

    // 5. 检查邮箱唯一性
    $check_email = C::t('common_member')->fetch_by_email($email);
    if($check_email) api_return(-5, 'Email address already registered');

    // 6. 邀请码逻辑适配
    if($regstatus == 2 && empty($invitecode)) api_return(-3, 'Invitation code is mandatory');
    if(!empty($invitecode)) {
        $invite = DB::fetch_first("SELECT * FROM ".DB::table('common_invite')." WHERE code=%s AND status=0", array($invitecode));
        if(!$invite || ($invite['endtime'] > 0 && $invite['endtime'] < TIMESTAMP)) {
            api_return(-21, 'Invalid or expired invitation code');
        }
    }

    // 7. 生成官方加密 Hash 及链接
    require_once libfile('function/member');
    require_once libfile('function/mail');

    $now = TIMESTAMP;
    // Discuz! 官方加密算法：包含邮箱和当前时间戳
    $hash = authcode("$email\t$now", 'ENCODE', $_G['config']['security']['authkey']);
    
    // 完美拼装：mod地址 + Hash + email预填充
    $register_url = $_G['siteurl'].'member.php?mod='.$regname.'&hash='.urlencode($hash).'&email='.urlencode($email);
    if(!empty($invitecode)) $register_url .= '&invitecode='.urlencode($invitecode);

    // 8. 调用系统邮件引擎发送 (官方文案同步：3天有效期)
    $site_name = $setting['bbname'];
    $subject = "论坛注册地址";
    $message = "
<p>这封信是由 {$site_name} 发送的。</p>
<p></p>
<p>您收到这封邮件，是由于在 {$site_name} 获取了新用户注册地址使用了这个邮箱地址。 如果您并没有访问过 {$site_name}，或没有进行上述操作，请忽略这封邮件。您不需要退订或进行其他进一步的操作。</p>
<p></p>
<p></p>
<p>----------------------------------------------------------------------</p>
<p>新用户注册说明</p>
<p>----------------------------------------------------------------------</p>
<p>如果您是 {$site_name} 的新用户，或在修改您的注册 Email 时使用了本地址，我们需要对您的地址有效性进行验证以避免垃圾邮件或地址被滥用。</p>
<p>您只需点击下面的链接即可进行用户注册，以下链接有效期为3天。过期可以重新请求发送一封新的邮件验证：</p>
<br><a href=\"{$register_url}\">{$register_url}</a><br><br>
(如果上面不是链接形式，请将该地址手工粘贴到浏览器地址栏再访问)

<p>感谢您的访问，祝您使用愉快！</p>

<p>此致</p>
<p>{$site_name} 管理团队.</p>";

    if(sendmail($email, $subject, $message)) {
        api_return(0, 'Success: Registration link sent', array('email' => $email));
    } else {
        api_return(-11, 'SMTP send failed. Please check site mail settings.');
    }
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    

} else {
    // 未匹配到动作
    api_return(-6, 'Unknown action: ' . $action);
}
?>