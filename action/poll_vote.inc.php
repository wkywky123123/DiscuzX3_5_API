<?php
/**
 * 模块：参与投票
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

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