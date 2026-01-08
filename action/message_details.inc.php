<?php
/**
 * 模块：私信内容记录
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $plid = isset($_REQUEST['plid']) ? intval($_REQUEST['plid']) : 0;
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(100, intval($_REQUEST['perpage']))) : 20;

    if(!$uid || !$plid) {
        api_return(-3, 'User ID (uid) and Conversation ID (plid) are required');
    }

    // 1. 权限检查：确保当前用户是该私信会话的参与者
    // 检查 pre_ucenter_pm_members 表
    $is_member = DB::result_first("SELECT uid FROM ".DB::table('ucenter_pm_members')." WHERE plid = $plid AND uid = $uid");
    if(!$is_member) {
        api_return(-9, 'Access denied or conversation not found');
    }

    // 2. 确定消息分表名 (UCenter 私信消息按 plid % 10 进行分表存储)
    $table_index = $plid % 10;
    $table_pm_messages = "ucenter_pm_messages_$table_index";
    
    // 3. 分页查询消息记录
    $start = ($page - 1) * $perpage;
    $list = array();
    $sql = "SELECT pmid, authorid, message, dateline 
            FROM ".DB::table($table_pm_messages)." 
            WHERE plid = $plid 
            ORDER BY dateline ASC 
            LIMIT $start, $perpage";
    
    $query = DB::query($sql);
    
    $user_cache = array();
    while($row = DB::fetch($query)) {
        $msg_uid = $row['authorid'];
        
        // 缓存用户名，避免循环内重复查表
        if(!isset($user_cache[$msg_uid])) {
            $member_row = C::t('common_member')->fetch($msg_uid);
            $user_cache[$msg_uid] = $member_row ? $member_row['username'] : '未知用户';
        }

        $list[] = array(
            'pmid' => $row['pmid'],
            'author' => $user_cache[$msg_uid],
            'authorid' => $msg_uid,
            'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$msg_uid.'&size=small',
            'message' => $row['message'],
            'dateline' => dgmdate($row['dateline'], 'u'),
            // [核心功能] 方向标识：1 表示是我发送的（右侧），0 表示对方发送（左侧）
            'is_mine' => ($msg_uid == $uid ? 1 : 0)
        );
    }

    // 4. [关键逻辑] 精确已读：仅将当前 plid 对应的未读状态清零
    DB::query("UPDATE ".DB::table('ucenter_pm_members')." SET isnew=0 WHERE plid=$plid AND uid=$uid AND isnew=1");

    api_return(0, 'Success', array(
        'plid' => $plid,
        'count' => count($list),
        'page' => $page,
        'list' => $list
    ));