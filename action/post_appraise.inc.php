<?php
/**
 * 模块：回帖投票/表态。
 */

if(!defined('IN_DISCUZ')) exit('Access Denied');

// 1. 接收并预处理参数
$uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
$pid = isset($_REQUEST['pid']) ? intval($_REQUEST['pid']) : 0;
// 操作指令：add (支持/赞), against (反对/踩)
$do = isset($_REQUEST['do']) ? trim($_REQUEST['do']) : 'add'; 

if (!$uid || !$pid) {
    api_return(-3, 'Missing required parameters (uid, pid)');
}

// 2. 动态识别站点功能是否开启
loadcache('setting');
// Discuz! 后台“启用回帖投票”设置对应的缓存位
if (empty($_G['setting']['postappraise'])) {
    api_return(-9, 'The "Reply Voting" feature is disabled on this site.');
}

// 3. 获取帖子详细信息
// 使用 tid:0 是为了兼容 Discuz! 的分表机制，由底层类自动定位物理表
$post = C::t('forum_post')->fetch('tid:0', $pid);

if (!$post || $post['invisible'] < 0) {
    api_return(-8, 'Target post not found or has been deleted.');
}

// 4. 权限校验：Discuz! 逻辑禁止用户给自己的回帖投票
if ($post['authorid'] == $uid) {
    api_return(-9, 'You are not allowed to vote on your own post.');
}

// 5. 唯一性检查：检查该用户是否已经对这一楼层表态过
// 记录表：forum_hotreply_member
$has_voted = DB::result_first("SELECT count(*) FROM " . DB::table('forum_hotreply_member') . " WHERE pid=%d AND uid=%d", array($pid, $uid));

if ($has_voted) {
    api_return(-15, 'You have already voted on this post. Duplicate voting is not allowed.');
}

// 6. 执行投票逻辑
if ($do == 'add') {
    // --- 执行“支持” (Support) ---
    
    // A. 插入成员投票记录
    DB::insert('forum_hotreply_member', array(
        'tid' => $post['tid'],
        'pid' => $pid,
        'uid' => $uid,
        'username' => $_G['username'],
        'dateline' => TIMESTAMP
    ));

    // B. 更新帖子表统计：support 字段自增
    DB::query("UPDATE " . DB::table('forum_post') . " SET support=support+1 WHERE pid=%d", array($pid));

    // C. 发送实时通知给回帖作者
    require_once libfile('function/home');
    $user_link = '<a href="home.php?mod=space&uid=' . $uid . '" class="xw1">' . $_G['member']['username'] . '</a>';
    // 构造跳转到具体楼层的链接
    $post_url = 'forum.php?mod=redirect&goto=findpost&pid=' . $pid . '&ptid=' . $post['tid'];
    $note = "{$user_link} 赞了您的回帖 <a href=\"{$post_url}\" class=\"xw1\">查看详情</a>";
    
    // 类型设为 post，作者会在“帖子”提醒分类中看到
    notification_add($post['authorid'], 'post', $note, array(), 1);

    api_return(0, 'Successfully supported the post.', array(
        'pid' => $pid,
        'type' => 'support',
        'current_count' => intval($post['support']) + 1
    ));

} elseif ($do == 'against') {
    // --- 执行“反对” (Against) ---
    
    // A. 插入成员投票记录
    DB::insert('forum_hotreply_member', array(
        'tid' => $post['tid'],
        'pid' => $pid,
        'uid' => $uid,
        'username' => $_G['username'],
        'dateline' => TIMESTAMP
    ));

    // B. 更新帖子表统计：against 字段自增
    DB::query("UPDATE " . DB::table('forum_post') . " SET against=against+1 WHERE pid=%d", array($pid));

    // 反对操作通常不发送通知（符合大多数社交产品的“静默反对”习惯），若需要通知可仿照上面补全
    
    api_return(0, 'Successfully opposed the post.', array(
        'pid' => $pid,
        'type' => 'against',
        'current_count' => intval($post['against']) + 1
    ));

} else {
    // 非法的 do 参数
    api_return(-3, 'Invalid operation. Use "add" or "against".');
}