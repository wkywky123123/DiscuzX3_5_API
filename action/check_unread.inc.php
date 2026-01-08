<?php
/**
 * 模块：全局未读检查
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    
    if(!$uid) {
        api_return(-3, 'User ID (uid) is required');
    }

    // 1. 获取系统提醒未读数 (来自 pre_home_notification 表)
    $notice_unread = DB::result_first("SELECT COUNT(*) FROM ".DB::table('home_notification')." WHERE uid=$uid AND new=1");

    // 2. 获取私信会话未读数 (来自 pre_ucenter_pm_members 表)
    // 这里的表名 pre_ucenter_ 视您的数据库前缀而定，通常 Discuz 会自动处理 DB::table
    $pm_unread = DB::result_first("SELECT COUNT(*) FROM ".DB::table('ucenter_pm_members')." WHERE uid=$uid AND isnew=1");

    api_return(0, 'Success', array(
        'notice' => intval($notice_unread), // 系统消息未读数
        'pm' => intval($pm_unread),         // 私信会话未读数
        'total' => intval($notice_unread + $pm_unread) // 总未读数和
    ));
    
    
    
    
    
    
    } elseif ($action == 'notifications') {
    // --- [核心功能] 系统提醒列表：获取详情并自动标记全部已读 ---
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(100, intval($_REQUEST['perpage']))) : 20;

    if(!$uid) {
        api_return(-3, 'User ID (uid) is required');
    }

    // 1. 获取该用户的提醒总数
    $total = DB::result_first("SELECT COUNT(*) FROM ".DB::table('home_notification')." WHERE uid=$uid");

    $list = array();
    if($total > 0) {
        $start = ($page - 1) * $perpage;
        
        // 2. 获取提醒列表 (按时间倒序)
        // note 字段包含 Discuz! 生成的 HTML 提醒内容
        $sql = "SELECT id, type, new, authorid, author, note, dateline 
                FROM ".DB::table('home_notification')." 
                WHERE uid=$uid 
                ORDER BY dateline DESC 
                LIMIT $start, $perpage";
        
        $query = DB::query($sql);
        while($row = DB::fetch($query)) {
            $list[] = array(
                'id' => $row['id'],
                'type' => $row['type'],         // 提醒类型：post(回帖), at(@我), system(系统) 等
                'is_new' => intval($row['new']), // 原始状态：1为新，0为旧
                'author' => $row['author'],     // 触发者用户名
                'authorid' => $row['authorid'], // 触发者 UID
                'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$row['authorid'].'&size=small',
                'note' => $row['note'],         // 详细 HTML 内容
                'dateline' => dgmdate($row['dateline'], 'u') // 友好时间格式
            );
        }

        // 3. [关键逻辑] 自动已读：只要调用了列表接口，就将该用户所有未读提醒清零
        DB::query("UPDATE ".DB::table('home_notification')." SET new=0 WHERE uid=$uid AND new=1");
    }

    api_return(0, 'Success', array(
        'total' => intval($total),
        'page' => $page,
        'perpage' => $perpage,
        'total_page' => ceil($total / $perpage),
        'list' => $list
    ));