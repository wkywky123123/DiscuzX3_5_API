<?php
/**
 * 模块：私信会话列表
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(50, intval($_REQUEST['perpage']))) : 15;

    if(!$uid) api_return(-3, 'User ID (uid) is required');

    // 加载 UCenter 核心
    require_once libfile('function/member');
    loaducenter();

    // 1. 调用 UC 函数获取私信列表
    // 'privatepm' 代表个人私信模式
    $pm_result = uc_pm_list($uid, $page, $perpage, 'inbox', 'privatepm', 200);

    $list = array();
    if($pm_result['data']) {
        foreach($pm_result['data'] as $row) {
            $list[] = array(
                'plid' => $row['plid'],             // 会话唯一 ID
                'is_new' => intval($row['isnew']),  // 该对话是否有新消息：1有，0无
                'msg_num' => $row['pmnum'],          // 该对话内的消息总数
                'subject' => $row['subject'],        // 对话标题（通常为对方用户名）
                'summary' => $row['lastsummary'],    // 最后一条消息的摘要预览
                'last_author' => $row['lastauthor'], // 最后发送者用户名
                'last_authorid' => $row['lastauthorid'],
                'last_avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$row['lastauthorid'].'&size=small',
                'last_time' => ($row['lastupdate'] > 0) ? dgmdate($row['lastupdate'], 'u') : '未知'
            );
        }
    }

    // 2. [核心功能] 独立计算全站未读私信会话的总数 (用于 App 首页红点)
    // 逻辑：查询 pm_members 表中该用户标记为 isnew=1 的记录数
    $unread_total = DB::result_first("SELECT COUNT(*) FROM ".DB::table('ucenter_pm_members')." WHERE uid=$uid AND isnew=1");

    api_return(0, 'Success', array(
        'total' => intval($pm_result['count']),   // 总会话数
        'unread_total' => intval($unread_total),  // 未读会话总数（红点数）
        'page' => $page,
        'perpage' => $perpage,
        'list' => $list
    ));