<?php
/**
 * 模块：站点公告模块
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    // 1. 获取当前时间戳
    $now = time();
    $limit = isset($_REQUEST['limit']) ? max(1, min(20, intval($_REQUEST['limit']))) : 10;

    $sql = "SELECT id, author, subject, type, starttime, endtime, message 
            FROM " . DB::table('forum_announcement') . " 
            WHERE starttime <= $now AND (endtime >= $now OR endtime = 0) 
            ORDER BY displayorder ASC, starttime DESC 
            LIMIT $limit";

    $query = DB::query($sql);
    $list = array();

    while($row = DB::fetch($query)) {
        $list[] = array(
            'id' => $row['id'],
            'author' => $row['author'],
            'subject' => $row['subject'],
            'type' => $row['type'],
            'starttime' => dgmdate($row['starttime'], 'd'),
            'endtime' => $row['endtime'] ? dgmdate($row['endtime'], 'd') : '永久',
            'content' => $row['message'] 
        );
    }

    api_return(0, 'Success', array('list' => $list));