<?php
/**
 * 模块：私信全部已读
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;

    if(!$uid) {
        api_return(-3, 'User ID (uid) is required');
    }

    // 1. 清空 UCenter 私信成员表中的未读标记 (isnew = 0)
    // 注意：DB::table 会自动处理表前缀，通常为 pre_ucenter_pm_members
    DB::query("UPDATE ".DB::table('ucenter_pm_members')." SET isnew=0 WHERE uid=$uid AND isnew=1");
    
    // 2. 同步更新 Discuz! 本地用户状态表，确保全站范围内的私信红点消失
    // newpm 字段记录了用户未读私信的数量
    C::t('common_member_status')->update($uid, array('newpm' => 0));

    api_return(0, 'All private messages marked as read');