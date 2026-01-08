<?php
/**
 * 模块：发送好友申请
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;     // 发起人
    $fuid = isset($_REQUEST['fuid']) ? intval($_REQUEST['fuid']) : 0;   // 接收人
    $note = isset($_REQUEST['note']) ? trim($_REQUEST['note']) : '';    // 备注

    if (!$uid || !$fuid) api_return(-3, 'Missing parameters');
    if ($uid == $fuid) api_return(-11, 'Cannot add yourself');

    // 1. 检查是否已经是好友
    if (DB::result_first("SELECT uid FROM " . DB::table('home_friend') . " WHERE uid=%d AND fuid=%d", array($uid, $fuid))) {
        api_return(-15, 'Already friends');
    }

    // 2. 检查冷却时间 (3天)
    $cooldown = 3 * 86400;
    $existing = DB::fetch_first("SELECT dateline FROM " . DB::table('home_friend_request') . " WHERE uid=%d AND fuid=%d", array($fuid, $uid));
    if ($existing && (TIMESTAMP - $existing['dateline'] < $cooldown)) {
        api_return(-15, 'Request sent recently. Cooldown is 3 days.');
    }

    // 3. 更新/插入申请记录
    DB::query("DELETE FROM " . DB::table('home_friend_request') . " WHERE uid=%d AND fuid=%d", array($fuid, $uid));
    $member = C::t('common_member')->fetch($uid);
    
    $data = array(
        'uid' => $fuid,
        'fuid' => $uid,
        'fusername' => $member['username'],
        'gid' => 0,
        'note' => $note,
        'dateline' => TIMESTAMP
    );
    DB::insert('home_friend_request', $data);
    
    // 4. 【核心增强】发送带 HTML 链接的交互式通知
    // 包含：用户名跳转链接 + 批准申请快捷链接
    $user_link = '<a href="home.php?mod=space&uid=' . $uid . '" class="xw1">' . $member['username'] . '</a>';
    $approve_url = 'home.php?mod=spacecp&ac=friend&op=add&uid=' . $uid . '&from=notice';
    $approve_link = '<a href="' . $approve_url . '" onclick="showWindow(this.id, this.href, \'get\', 0);" class="xw1" id="afr_' . $uid . '">批准申请</a>';
    
    $final_note = $user_link . ' 请求加您为好友 &nbsp; ' . $approve_link;
    
    // 如果有留言，附带留言预览
    if($note) {
        $final_note .= '<br><span class="xg1">留言: ' . $note . '</span>';
    }

    notification_add($fuid, 'friend', $final_note, array(), 1);

    api_return(0, 'Friend request sent successfully');