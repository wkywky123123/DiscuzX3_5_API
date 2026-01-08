<?php
/**
 * 模块：发表回复/评论
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

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