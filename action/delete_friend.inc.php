<?php
/**
 * 模块：删除好友
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $fuid = isset($_REQUEST['fuid']) ? intval($_REQUEST['fuid']) : 0;

    if (!$uid || !$fuid) api_return(-3, 'Missing parameters');

    // 双向删除
    DB::query("DELETE FROM " . DB::table('home_friend') . " WHERE (uid=%d AND fuid=%d) OR (uid=%d AND fuid=%d)", array($uid, $fuid, $fuid, $uid));
    
    // 扣减好友数统计
    C::t('common_member_count')->increase($uid, array('friends' => -1));
    C::t('common_member_count')->increase($fuid, array('friends' => -1));

    api_return(0, 'Friend deleted successfully');