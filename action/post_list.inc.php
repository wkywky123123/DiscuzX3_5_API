<?php
/**
 * 模块：回帖列表获取
 */

if(!defined('IN_DISCUZ')) exit('Access Denied');

// 1. 接收并清理参数
$tid = isset($_REQUEST['tid']) ? intval($_REQUEST['tid']) : 0;
$uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0; // 当前查看者的 UID
$page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
$perpage = isset($_REQUEST['perpage']) ? max(1, min(100, intval($_REQUEST['perpage']))) : 20;

if (!$tid) {
    api_return(-3, 'Thread ID (tid) is required');
}

// 2. 基础数据校验：主题是否存在
$thread = C::t('forum_thread')->fetch($tid);
if (!$thread || $thread['displayorder'] < 0) {
    api_return(-8, 'Thread not found or deleted');
}

// 3. 动态配置识别
loadcache('setting');
// postappraise: 1 代表开启了“回帖评价/表态”功能
$is_post_appraise_open = !empty($_G['setting']['postappraise']) ? 1 : 0;

// 4. [性能关键] 预获取针对主题 (TID) 的全局互动状态
// 收藏和点赞主题对该帖下所有回复的 UI 表现是一致的
$is_thread_favorited = 0;
$is_thread_liked = 0;

if ($uid > 0) {
    // A. 检查当前用户是否收藏了该主题
    $is_thread_favorited = DB::result_first("SELECT count(*) FROM ".DB::table('home_favorite')." WHERE uid=%d AND id=%d AND idtype='tid'", array($uid, $tid)) ? 1 : 0;
    
    // B. 检查当前用户是否点赞了该主题 (推荐)
    $is_thread_liked = DB::result_first("SELECT count(*) FROM ".DB::table('forum_memberrecommend')." WHERE tid=%d AND recommenduid=%d", array($tid, $uid)) ? 1 : 0;
}

// 5. 分页查询回复数据 (JOIN 用户表获取用户名和组信息)
$start = ($page - 1) * $perpage;
$sql = "SELECT p.*, m.username, m.groupid 
        FROM " . DB::table('forum_post') . " p 
        LEFT JOIN " . DB::table('common_member') . " m ON m.uid = p.authorid 
        WHERE p.tid = %d AND p.first = 0 AND p.invisible = 0 
        ORDER BY p.dateline ASC 
        LIMIT %d, %d";

$query = DB::query($sql, array($tid, $start, $perpage));
$raw_posts = array();
$pids = array();

while ($row = DB::fetch($query)) {
    $raw_posts[] = $row;
    $pids[] = $row['pid'];
}

// 6. [高级优化] 针对当前页所有 PID，执行批量状态检索
$my_rated_pids = array(); // 我评过分的 PID 列表
$my_supported_pids = array(); // 我表态过(点赞)的 PID 列表

if ($uid > 0 && !empty($pids)) {
    // A. 批量查评分记录 (forum_ratelog)
    $query_rate = DB::query("SELECT DISTINCT pid FROM ".DB::table('forum_ratelog')." WHERE uid=$uid AND pid IN (".dimplode($pids).")");
    while($r = DB::fetch($query_rate)) {
        $my_rated_pids[] = $r['pid'];
    }

    // B. 批量查回帖支持记录 (只有开启了表态功能才查，减少无效 SQL)
    if ($is_post_appraise_open) {
        $query_support = DB::query("SELECT pid FROM ".DB::table('forum_hotreply_member')." WHERE uid=$uid AND pid IN (".dimplode($pids).")");
        while($s = DB::fetch($query_support)) {
            $my_supported_pids[] = $s['pid'];
        }
    }
}

// 7. 组装最终 JSON 数据列表
$list = array();
foreach ($raw_posts as $post) {
    $pid = $post['pid'];

    // 确定当前楼层的点赞状态：若支持单楼点赞则返回真实状态，否则继承主题点赞状态
    $current_is_liked = ($is_post_appraise_open) ? (in_array($pid, $my_supported_pids) ? 1 : 0) : $is_thread_liked;

    $list[] = array(
        'pid' => $pid,
        'tid' => $post['tid'],
        'author' => $post['author'],
        'authorid' => $post['authorid'],
        'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$post['authorid'].'&size=small',
        'dateline' => dgmdate($post['dateline'], 'u'),
        'position' => intval($post['position']),
        // 调用主入口 api.inc.php 中的图片解析函数
        'content' => parse_attach_images($post['message']),
        
        // 统计字段
        'support' => intval($post['support']),     // 该楼层获得的点赞数
        'ratetimes' => intval($post['ratetimes']), // 该楼层累计被评分次数
        
        // 互动状态对象 (与 thread_content 格式保持一致)
        'user_interaction' => array(
            'is_liked' => $current_is_liked,
            'is_favorited' => $is_thread_favorited, // 随主题收藏状态
            'is_rated' => in_array($pid, $my_rated_pids) ? 1 : 0
        )
    );
}

// 8. 获取总回帖数 (用于分页)
$total = DB::result_first("SELECT COUNT(*) FROM ".DB::table('forum_post')." WHERE tid=%d AND first=0 AND invisible=0", array($tid));

// 9. 统一返回
api_return(0, 'Success', array(
    'total' => intval($total),
    'page' => $page,
    'perpage' => $perpage,
    'total_page' => ceil($total / $perpage),
    'post_appraise_open' => $is_post_appraise_open, // 告知 App 是否需要显示每楼的点赞手势
    'list' => $list
));