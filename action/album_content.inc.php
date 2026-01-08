<?php
/**
 * 模块：相册内容详情
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    // 1. 接收参数
    $albumid = isset($_REQUEST['albumid']) ? intval($_REQUEST['albumid']) : 0;
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0; // 请求者的 UID
    $password = isset($_REQUEST['password']) ? trim($_REQUEST['password']) : ''; // 可选的相册密码
    
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(100, intval($_REQUEST['perpage']))) : 20;

    if (!$albumid) {
        api_return(-3, 'Album ID (albumid) is required');
    }

    // 2. 获取相册基础信息并校验权限
    $album = C::t('home_album')->fetch($albumid);
    if (!$album) {
        api_return(-6, 'Album not found');
    }

    $owner_uid = intval($album['uid']);
    $friend_status = intval($album['friend']); // 0:公开, 1:好友, 2:指定好友, 3:自己, 4:密码
    $is_owner = ($uid > 0 && $uid == $owner_uid);

    // 非作者本人的权限判定
    if (!$is_owner) {
        if ($friend_status == 1 || $friend_status == 2) {
            // A. 校验好友关系
            $is_friend = DB::result_first("SELECT uid FROM ".DB::table('home_friend')." WHERE uid=%d AND fuid=%d", array($owner_uid, $uid));
            if (!$is_friend) {
                api_return(-9, 'Access denied: Friends only');
            }
        } elseif ($friend_status == 3) {
            // B. 仅自己可见
            api_return(-9, 'Access denied: This album is private');
        } elseif ($friend_status == 4) {
            // C. 密码校验
            if ($password !== $album['password']) {
                api_return(-18, 'Invalid album password'); // -18 定义为相册密码错误
            }
        }
    }

    // 3. 统计该相册下的图片总数
    $total = DB::result_first("SELECT COUNT(*) FROM ".DB::table('home_pic')." WHERE albumid=%d", array($albumid));

    $list = array();
    if ($total > 0) {
        $start = ($page - 1) * $perpage;
        
        // 4. 查询图片详情
        $sql = "SELECT picid, title, filepath, thumb, remote, dateline, size 
                FROM ".DB::table('home_pic')." 
                WHERE albumid=%d 
                ORDER BY dateline DESC 
                LIMIT %d, %d";
        
        $query = DB::query($sql, array($albumid, $start, $perpage));
        
        while ($row = DB::fetch($query)) {
            $url = ($row['remote'] ? $_G['setting']['ftp']['attachurl'] : $_G['siteurl'].'data/attachment/').'album/'.$row['filepath'];

            $list[] = array(
                'picid' => $row['picid'],
                'title' => $row['title'],
                'url' => $url,
                'size' => intval($row['size']),
                'dateline' => dgmdate($row['dateline'], 'u')
            );
        }
    }

    api_return(0, 'Success', array(
        'albumid' => $albumid,
        'albumname' => $album['albumname'],
        'total' => intval($total),
        'page' => $page,
        'perpage' => $perpage,
        'list' => $list
    ));