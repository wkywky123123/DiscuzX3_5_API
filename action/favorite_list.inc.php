<?php
/**
 * 模块：用户收藏列表
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $type = isset($_REQUEST['type']) ? trim($_REQUEST['type']) : 'all'; // 可选: all, thread, forum
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(50, intval($_REQUEST['perpage']))) : 20;

    if(!$uid) {
        api_return(-3, 'UID is required');
    }

    // 2. 构建查询条件
    $where_arr = array("uid=%d");
    $params = array($uid);

    // 筛选逻辑映射：API参数 -> 数据库字段值
    if($type == 'thread') {
        $where_arr[] = "idtype='tid'";
    } elseif($type == 'forum') {
        $where_arr[] = "idtype='fid'";
    }
    // Discuz 收藏夹还可能包含日志(blogid)、相册(albumid)等，默认为 'all' 时不加限制

    $where_sql = implode(' AND ', $where_arr);
    $start = ($page - 1) * $perpage;

    // 3. 统计总数
    $total = DB::result_first("SELECT COUNT(*) FROM ".DB::table('home_favorite')." WHERE $where_sql", $params);

    $list = array();
    if($total > 0) {
        // 4. 查询数据
        // 注意：title 是收藏时的快照标题
        $sql = "SELECT favid, id, idtype, title, description, dateline 
                FROM ".DB::table('home_favorite')." 
                WHERE $where_sql 
                ORDER BY dateline DESC 
                LIMIT $start, $perpage";
        
        $query = DB::query($sql, $params);
        while($row = DB::fetch($query)) {
            // 格式化 idtype 为前端更易读的字符串
            $type_name = 'unknown';
            if($row['idtype'] == 'tid') $type_name = 'thread';
            elseif($row['idtype'] == 'fid') $type_name = 'forum';
            elseif($row['idtype'] == 'blogid') $type_name = 'blog';
            elseif($row['idtype'] == 'albumid') $type_name = 'album';

            $list[] = array(
                'favid' => $row['favid'], // 收藏记录唯一ID
                'id' => $row['id'],       // 对象ID (tid 或 fid)
                'type' => $type_name,     // thread / forum
                'title' => $row['title'], // 标题
                'description' => $row['description'],
                'dateline' => dgmdate($row['dateline'], 'u')
            );
        }
    }

    api_return(0, 'Success', array(
        'total' => intval($total),
        'page' => $page,
        'perpage' => $perpage,
        'list' => $list
    ));