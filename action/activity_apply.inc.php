<?php
/**
 * 模块：报名活动
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

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