<?php
/**
 * 模块：处理好友申请
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

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
