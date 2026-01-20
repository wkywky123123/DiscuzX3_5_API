<?php
/**
 * 模块：获取未使用附件列表
 */

if(!defined('IN_DISCUZ')) exit('Access Denied');

// 1. 接收参数
$uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
$page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
$perpage = isset($_REQUEST['perpage']) ? max(1, min(100, intval($_REQUEST['perpage']))) : 20;

if (!$uid) {
    api_return(-3, 'User ID is required');
}

// 2. 统计未使用附件总数
$total = DB::result_first("SELECT COUNT(*) FROM ".DB::table('forum_attachment_unused')." WHERE uid=%d", array($uid));

$list = array();
if ($total > 0) {
    $start = ($page - 1) * $perpage;
    
    // 3. 查询详细列表 (按时间倒序)
    $sql = "SELECT aid, dateline, filename, filesize, attachment, isimage, remote, width 
            FROM ".DB::table('forum_attachment_unused')." 
            WHERE uid=%d 
            ORDER BY dateline DESC 
            LIMIT %d, %d";
    
    $query = DB::query($sql, array($uid, $start, $perpage));
    
    loadcache('setting');
    while($row = DB::fetch($query)) {
        // 4. 拼装绝对 URL 路径
        if($row['remote']) {
            $url = $_G['setting']['ftp']['attachurl'] . 'forum/' . $row['attachment'];
        } else {
            $url = $_G['siteurl'] . 'data/attachment/forum/' . $row['attachment'];
        }

        $list[] = array(
            'aid' => intval($row['aid']),
            'filename' => $row['filename'],
            'filesize' => sizecount($row['filesize']), // 转为易读格式
            'url' => $url,
            'is_image' => intval($row['isimage']),
            'width' => intval($row['width']),
            'dateline' => dgmdate($row['dateline'], 'u')
        );
    }
}

// 5. 统一返回
api_return(0, 'Success', array(
    'total' => intval($total),
    'page' => $page,
    'perpage' => $perpage,
    'list' => $list
));