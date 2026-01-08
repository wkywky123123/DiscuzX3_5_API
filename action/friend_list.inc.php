<?php
/**
 * 模块：获取好友列表
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(100, intval($_REQUEST['perpage']))) : 20;

    if (!$uid) api_return(-3, 'User ID (uid) is required');

    $total = DB::result_first("SELECT COUNT(*) FROM " . DB::table('home_friend') . " WHERE uid=%d", array($uid));
    $list = array();

    if ($total > 0) {
        $start = ($page - 1) * $perpage;
        $sql = "SELECT fuid, fusername, dateline FROM " . DB::table('home_friend') . " 
                WHERE uid=%d ORDER BY dateline DESC LIMIT %d, %d";
        $query = DB::query($sql, array($uid, $start, $perpage));
        while ($row = DB::fetch($query)) {
            $list[] = array(
                'uid' => $row['fuid'],
                'username' => $row['fusername'],
                'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid=' . $row['fuid'] . '&size=small',
                'dateline' => dgmdate($row['dateline'], 'u')
            );
        }
    }
    api_return(0, 'Success', array('total' => intval($total), 'list' => $list));