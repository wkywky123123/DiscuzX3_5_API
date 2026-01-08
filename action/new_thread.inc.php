<?php
/**
 * 模块：发布新主题
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

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