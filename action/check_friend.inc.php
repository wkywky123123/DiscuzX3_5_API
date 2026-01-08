<?php
/**
 * 模块：检查好友关系
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $fuid = isset($_REQUEST['fuid']) ? intval($_REQUEST['fuid']) : 0;

    if (!$uid || !$fuid) api_return(-3, 'Both uid and fuid are required');

    $is_friend = DB::result_first("SELECT uid FROM " . DB::table('home_friend') . " WHERE uid=%d AND fuid=%d", array($uid, $fuid));
    
    api_return(0, 'Success', array('is_friend' => $is_friend ? 1 : 0));
    