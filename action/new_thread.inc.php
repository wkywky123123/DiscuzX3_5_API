<?php
/**
 * 模块：发布新主题
 */

if(!defined('IN_DISCUZ')) exit('Access Denied');

// 1. 参数获取
$uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
$fid = isset($_REQUEST['fid']) ? intval($_REQUEST['fid']) : 0;
$typeid = isset($_REQUEST['typeid']) ? intval($_REQUEST['typeid']) : 0; 
$subject = isset($_REQUEST['subject']) ? trim($_REQUEST['subject']) : '';
$message = isset($_REQUEST['message']) ? trim($_REQUEST['message']) : '';

if(!$uid || !$fid || !$subject || !$message) api_return(-3, 'Missing parameters');

// 2. 权限与数据校验
$member = C::t('common_member')->fetch($uid);
if(!$member) api_return(-6, 'User not found');

$forum = C::t('forum_forum')->fetch($fid);
if(!$forum || $forum['status'] != 1) api_return(-7, 'Forum invalid');

// 3. 插入主题表 (pre_forum_thread)
$now = TIMESTAMP;
$new_thread = array(
    'fid' => $fid,
    'typeid' => $typeid,
    'author' => $member['username'],
    'authorid' => $uid,
    'subject' => $subject,
    'dateline' => $now,
    'lastpost' => $now,
    'lastposter' => $member['username'],
    'status' => 32,
    'attachment' => 0 // 初始值
);
$tid = C::t('forum_thread')->insert($new_thread, true);

// 4. 插入帖子表 (pre_forum_post)
$pid = C::t('forum_post_tableid')->insert(array('pid' => null), true);
$new_post = array(
    'pid' => $pid, 'fid' => $fid, 'tid' => $tid, 'first' => 1,
    'author' => $member['username'], 'authorid' => $uid,
    'subject' => $subject, 'dateline' => $now, 'message' => $message,
    'useip' => $_G['clientip'], 'attachment' => 0
);
C::t('forum_post')->insert($fid, $new_post);

// 5. 【核心】附件认领与正式入库逻辑
$attachment_type = 0; // 0:无, 1:文件, 2:图片
$first_aid = 0; // 用于生成封面图

if(preg_match_all("/\[attach(img)?\](\d+)\[\/attach(img)?\]/i", $message, $matches)) {
    $aids = array_unique(array_map('intval', $matches[2]));
    if(!empty($aids)) {
        // 计算 Discuz 标准分表 ID
        $tableid = $tid % 10;
        $has_image = false;

        foreach($aids as $aid) {
            // A. 从 unused 表提取临时数据
            $unused = DB::fetch_first("SELECT * FROM ".DB::table('forum_attachment_unused')." WHERE aid=%d AND uid=%d", array($aid, $uid));
            
            if($unused) {
                // B. 插入正式分表 (使用 C::t 确保表名和前缀 100% 正确)
                $att_data = array(
                    'aid' => $unused['aid'],
                    'tid' => $tid,
                    'pid' => $pid,
                    'uid' => $uid,
                    'dateline' => $unused['dateline'],
                    'filename' => $unused['filename'],
                    'filesize' => $unused['filesize'],
                    'attachment' => $unused['attachment'],
                    'isimage' => $unused['isimage'],
                    'thumb' => $unused['thumb'],
                    'remote' => $unused['remote'],
                    'width' => $unused['width'],
                );
                C::t('forum_attachment_n')->insert($tableid, $att_data);
                
                // C. 更新索引主表 (把 tableid 从 127 转正)
                C::t('forum_attachment')->update($aid, array(
                    'tid' => $tid,
                    'pid' => $pid,
                    'tableid' => $tableid
                ));
                
                // D. 清理临时记录
                DB::delete('forum_attachment_unused', array('aid' => $aid));
                
                if($unused['isimage']) {
                    $has_image = true;
                    if(!$first_aid) $first_aid = $aid; // 记录第一张图
                }
            }
        }
        
        $attachment_type = $has_image ? 2 : 1;

        // E. 回写附件标志位
        if($attachment_type > 0) {
            C::t('forum_thread')->update($tid, array('attachment' => $attachment_type));
            C::t('forum_post')->update('tid:'.$tid, $pid, array('attachment' => $attachment_type));
            
            // F. 如果有图，写入主题封面索引 (让列表页显示预览图)
            if($first_aid) {
                $main_attach = C::t('forum_attachment_n')->fetch($tableid, $first_aid);
                if($main_attach) {
                    DB::insert('forum_threadimage', array(
                        'tid' => $tid,
                        'attachment' => $main_attach['attachment'],
                        'remote' => $main_attach['remote']
                    ), false, true);
                }
            }
        }
    }
}

// 6. 更新全局统计
C::t('forum_forum')->update_forum_counter($fid, 1, 1, 1);
C::t('common_member_count')->increase($uid, array('threads' => 1, 'posts' => 1));

api_return(0, 'Thread created successfully', array(
    'tid' => $tid,
    'pid' => $pid,
    'attachment_type' => $attachment_type
));