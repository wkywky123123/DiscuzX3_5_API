<?php
/**
 * 模块：点赞/支持
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

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