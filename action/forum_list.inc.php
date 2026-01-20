<?php
/**
 * 模块：获取版块列表
 * 功能：含树状结构、权限详细配置、主题分类及最后发表摘要
 */

if(!defined('IN_DISCUZ')) exit('Access Denied');

// 1. 接收可选参数 uid (用于判断版块是否被当前用户收藏)
$uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;

// 2. [可选增强] 预取当前用户的收藏版块列表
$fav_fids = array();
if ($uid > 0) {
    $query_fav = DB::query("SELECT id FROM ".DB::table('home_favorite')." WHERE uid=%d AND idtype='fid'", array($uid));
    while($f = DB::fetch($query_fav)) {
        $fav_fids[] = $f['id'];
    }
}

// 3. 联表查询版块主表与详情表
// 获取版块的基本统计、权限字符串、版主、主题分类等
$query = DB::query("SELECT f.fid, f.fup, f.type, f.name, f.threads, f.posts, f.todayposts, f.lastpost, f.status, f.displayorder,
                           ff.moderators, ff.threadtypes, ff.viewperm, ff.postperm, ff.replyperm, ff.getattachperm, ff.postattachperm 
                    FROM ".DB::table('forum_forum')." f 
                    LEFT JOIN ".DB::table('forum_forumfield')." ff ON ff.fid = f.fid 
                    WHERE f.status = 1 
                    ORDER BY f.type ASC, f.displayorder ASC");

$groups = array(); // 存放分区 (Group)
$forums = array(); // 存放具体的版块 (Forum)

while($row = DB::fetch($query)) {
    // --- A. 解析版主列表 ---
    // Discuz 存储格式为 "admin\tuser1"
    $moderators = $row['moderators'] ? explode("\t", trim($row['moderators'])) : array();

    // --- B. 解析主题分类 (threadtypes) ---
    $raw_types = unserialize($row['threadtypes']);
    $threadtypes = array(
        'required' => isset($raw_types['required']) ? (string)$raw_types['required'] : '0',
        'listable' => isset($raw_types['listable']) ? (string)$raw_types['listable'] : '0',
        'types' => (isset($raw_types['types']) && is_array($raw_types['types'])) ? $raw_types['types'] : (object)array()
    );

    // --- C. 解析权限表 (perms) ---
    // 权限存储格式为 "1\t10\t11" (允许访问的用户组ID)
    $perms = array(
        'view' => $row['viewperm'] ? explode("\t", trim($row['viewperm'])) : array(),
        'post' => $row['postperm'] ? explode("\t", trim($row['postperm'])) : array(),
        'reply' => $row['replyperm'] ? explode("\t", trim($row['replyperm'])) : array(),
        'getattach' => $row['getattachperm'] ? explode("\t", trim($row['getattachperm'])) : array(),
        'postattach' => $row['postattachperm'] ? explode("\t", trim($row['postattachperm'])) : array()
    );

    // --- D. 提取最后发表的 200 字纯文本摘要 ---
    $last_summary = '';
    $last_tid = 0;
    if($row['lastpost']) {
        // lastpost 字段格式通常为: "tid\tsubject\ttimestamp\tauthor"
        $lp_data = explode("\t", $row['lastpost']);
        $last_tid = intval($lp_data[0]);
        if($last_tid > 0) {
            // 获取主楼内容
            $last_post_msg = DB::result_first("SELECT message FROM ".DB::table('forum_post')." WHERE tid=%d AND first=1", array($last_tid));
            if($last_post_msg) {
                // 清洗内容：去除图片标签、BBCode、HTML标签
                $last_post_msg = preg_replace("/\[attach\]\d+\[\/attach\]/i", '', $last_post_msg);
                $last_post_msg = preg_replace("/\[.+?\]/is", '', $last_post_msg);
                $last_post_msg = strip_tags($last_post_msg);
                // 截取 200 字
                $last_summary = mb_substr(trim($last_post_msg), 0, 200, 'utf-8');
                if(mb_strlen($last_post_msg, 'utf-8') > 200) $last_summary .= '...';
            }
        }
    }

    // --- E. 组装版块对象 ---
    $item = array(
        'fid' => intval($row['fid']),
        'fup' => intval($row['fup']),
        'type' => $row['type'],
        'name' => $row['name'],
        'is_favorite' => in_array($row['fid'], $fav_fids) ? 1 : 0, // 是否已收藏状态
        'threads' => intval($row['threads']),
        'posts' => intval($row['posts']),
        'todayposts' => intval($row['todayposts']),
        'last_tid' => $last_tid,
        'last_summary' => $last_summary,
        'moderators' => $moderators,
        'threadtypes' => $threadtypes,
        'perms' => $perms
    );

    // --- F. 树状归类准备 ---
    if($row['type'] == 'group') {
        $item['forums'] = array();
        $groups[$row['fid']] = $item;
    } else {
        $forums[] = $item;
    }
}

// --- 4. 执行嵌套归类 (将版块放入所属分区) ---
foreach($forums as $f) {
    if(isset($groups[$f['fup']])) {
        $groups[$f['fup']]['forums'][] = $f;
    }
}

// 5. 格式化输出 (只返回分区列表，分区内含子版块)
api_return(0, 'Forum list retrieved successfully', array('list' => array_values($groups)));