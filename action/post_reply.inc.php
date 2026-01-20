<?php
/**
 * 模块：发表回复/评论 
 */

if(!defined('IN_DISCUZ')) exit('Access Denied');

// 1. 接收并处理参数
$tid = isset($_REQUEST['tid']) ? intval($_REQUEST['tid']) : 0;
$uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
$message = isset($_REQUEST['message']) ? trim($_REQUEST['message']) : '';
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
$now = TIMESTAMP;

// 3. 计算回复的楼层位置 (position)
$maxposition = DB::result_first("SELECT maxposition FROM ".DB::table('forum_thread')." WHERE tid=%d", array($tid));
$position = intval($maxposition) + 1;

// 4. 插入帖子数据 (pre_forum_post)
$pid = C::t('forum_post_tableid')->insert(array('pid' => null), true);
$new_post = array(
    'pid' => $pid,
    'fid' => $fid,
    'tid' => $tid,
    'first' => 0, // 0 表示它是回复
    'author' => $member['username'],
    'authorid' => $uid,
    'subject' => '',
    'dateline' => $now,
    'message' => $message,
    'useip' => $_G['clientip'],
    'invisible' => 0,
    'position' => $position,
    'attachment' => 0 // 初始为 0
);
C::t('forum_post')->insert($fid, $new_post);

// 5. 【核心增强】附件认领与转正逻辑
$attachment_flag = 0; // 0:无附件, 1:文件, 2:图片
if(preg_match_all("/\[attach(img)?\](\d+)\[\/attach(img)?\]/i", $message, $matches)) {
    $aids = array_unique(array_map('intval', $matches[2]));
    if(!empty($aids)) {
        // 附件存储分表必须与主题一致 (tid % 10)
        $tableid = $tid % 10;
        $has_image = false;

        foreach($aids as $aid) {
            // A. 从 unused 表提取临时数据
            $att_unused = DB::fetch_first("SELECT * FROM ".DB::table('forum_attachment_unused')." WHERE aid=%d AND uid=%d", array($aid, $uid));
            
            if($att_unused) {
                // B. 搬家到正式分表
                $att_data = array(
                    'aid' => $att_unused['aid'],
                    'tid' => $tid,
                    'pid' => $pid,
                    'uid' => $uid,
                    'dateline' => $att_unused['dateline'],
                    'filename' => $att_unused['filename'],
                    'filesize' => $att_unused['filesize'],
                    'attachment' => $att_unused['attachment'],
                    'isimage' => $att_unused['isimage'],
                    'thumb' => $att_unused['thumb'],
                    'remote' => $att_unused['remote'],
                    'width' => $att_unused['width'],
                );
                C::t('forum_attachment_n')->insert($tableid, $att_data);
                
                // C. 更新索引主表 (tableid 从 127 转为真实分表)
                C::t('forum_attachment')->update($aid, array(
                    'tid' => $tid,
                    'pid' => $pid,
                    'tableid' => $tableid
                ));
                
                // D. 删除临时记录
                DB::delete('forum_attachment_unused', array('aid' => $aid));
                
                if($att_unused['isimage']) $has_image = true;
            }
        }
        
        $attachment_flag = $has_image ? 2 : 1;

        // E. 回写标志位到回帖记录
        if($attachment_flag > 0) {
            C::t('forum_post')->update('tid:'.$tid, $pid, array('attachment' => $attachment_flag));
            
            // F. 如果该主题之前没有附件标志，或者现在有了图片，更新主题表标志位
            if($thread['attachment'] < $attachment_flag) {
                C::t('forum_thread')->update($tid, array('attachment' => $attachment_flag));
            }
        }
    }
}

// 6. 更新主题表统计信息
C::t('forum_thread')->update($tid, array(
    'lastposter' => $member['username'],
    'lastpost' => $now,
    'replies' => $thread['replies'] + 1,
    'maxposition' => $position
));

// 7. 更新版块和用户的帖子计数
C::t('forum_forum')->update_forum_counter($fid, 0, 1, 1);
C::t('common_member_count')->increase($uid, array('posts' => 1));

// 8. 【核心增强】发送带超链接的交互式通知给楼主
if($notify && $thread['authorid'] != $uid) {
    // A. 构造跳转链接：使用 redirect 机制自动定位到具体 PID 楼层
    $post_url = 'forum.php?mod=redirect&goto=findpost&pid=' . $pid . '&ptid=' . $tid;
    
    // B. 构造 HTML 格式通知
    // 用户名链接 + 跳转到帖子的链接
    $user_link = '<a href="home.php?mod=space&uid=' . $uid . '" class="xw1">' . $member['username'] . '</a>';
    $thread_link = '<a href="' . $post_url . '" class="xw1">' . $thread['subject'] . '</a>';
    
    $note = $user_link . ' 回复了您的帖子 ' . $thread_link;

    // 调用通知函数，类型设为 'post'
    notification_add($thread['authorid'], 'post', $note, array('from_id' => $tid, 'from_idtype' => 'post'), 1);
}

api_return(0, 'Reply posted successfully', array(
    'pid' => $pid,
    'tid' => $tid,
    'position' => $position,
    'attachment_type' => $attachment_flag
));