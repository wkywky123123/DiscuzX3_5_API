<?php
/**
 * 模块：用户相册列表
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(100, intval($_REQUEST['perpage']))) : 20;

    if(!$uid) {
        api_return(-3, 'User ID (uid) is required');
    }

    $total = DB::result_first("SELECT COUNT(*) FROM ".DB::table('home_album')." WHERE uid=%d", array($uid));

    $list = array();
    
    if($total > 0) {
        $start = ($page - 1) * $perpage;
        
        $sql = "SELECT albumid, albumname, picnum, pic, picflag, updatetime, friend 
                FROM ".DB::table('home_album')." 
                WHERE uid=%d 
                ORDER BY updatetime DESC 
                LIMIT %d, %d";
        
        $query = DB::query($sql, array($uid, $start, $perpage));
        
        while($row = DB::fetch($query)) {
            $cover_url = '';
            if($row['pic']) {
                $cover_url = ($row['picflag'] == 2 ? $_G['setting']['ftp']['attachurl'] : $_G['siteurl'].'data/attachment/').'album/'.$row['pic'];
            } else {
                $cover_url = $_G['siteurl'].'static/image/common/nophoto.gif';
            }

            // --- 核心修改：隐私状态判定逻辑 ---
            $friend_status = intval($row['friend']);
            
            $list[] = array(
                'albumid' => $row['albumid'],
                'albumname' => $row['albumname'],
                'picnum' => $row['picnum'],
                'cover' => $cover_url,
                'updatetime' => dgmdate($row['updatetime'], 'd'),
                
                // 标志位扩展
                'is_public' => ($friend_status == 0) ? 1 : 0,           // 是否公开
                'friend_only' => ($friend_status == 1 || $friend_status == 2) ? 1 : 0, // 是否仅好友可见
                'password_protected' => ($friend_status == 4) ? 1 : 0,  // 是否需要密码
                'private_me' => ($friend_status == 3) ? 1 : 0,          // 是否仅自己可见
                'privacy_level' => $friend_status                       // 原始权限等级(0-4)
            );
        }
    }

    api_return(0, 'Success', array(
        'total' => intval($total),
        'page' => $page,
        'perpage' => $perpage,
        'list' => $list
    ));