<?php
/**
 * 模块：用户帖子列表
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(100, intval($_REQUEST['perpage']))) : 20;

    if(!$uid) {
        api_return(-3, 'User ID (uid) is required');
    }

    // 2. 统计符合条件的主题总数
    $count_sql = "SELECT COUNT(*) FROM ".DB::table('forum_thread')." WHERE authorid=%d AND displayorder>=0";
    $total = DB::result_first($count_sql, array($uid));

    $list = array();
    
    if($total > 0) {
        $start = ($page - 1) * $perpage;
        
        // 3. 联表查询：Thread + Forum + Post
        // JOIN forum_post (first=1) 获取内容
        $sql = "SELECT t.tid, t.fid, t.subject, t.dateline, t.views, t.replies, t.special, 
                       f.name as forum_name, 
                       p.message 
                FROM ".DB::table('forum_thread')." t 
                LEFT JOIN ".DB::table('forum_forum')." f ON f.fid = t.fid 
                LEFT JOIN ".DB::table('forum_post')." p ON p.tid = t.tid AND p.first = 1 
                WHERE t.authorid=%d AND t.displayorder>=0 
                ORDER BY t.dateline DESC 
                LIMIT %d, %d";
        
        $query = DB::query($sql, array($uid, $start, $perpage));
        
        while($row = DB::fetch($query)) {
            // A. 处理摘要
            $message_text = $row['message'];
            $message_text = preg_replace("/\[attach\]\d+\[\/attach\]/i", '', $message_text);
            $message_text = strip_tags(preg_replace("/\[.+?\]/is", '', $message_text));
            $abstract = mb_substr(trim($message_text), 0, 100, 'utf-8');
            if(mb_strlen(trim($message_text), 'utf-8') > 100) $abstract .= '...';

            // B. 提取图片
            $image_list = get_thread_images($row['message']);

            $list[] = array(
                'tid' => $row['tid'],
                'fid' => $row['fid'],
                'forum_name' => $row['forum_name'],
                'subject' => $row['subject'],
                'abstract' => $abstract,
                // 虽然请求参数里有 uid，但返回结构里带上 authorid 和 avatar 更规范，方便前端组件统一处理
                'authorid' => $uid,
                'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$uid.'&size=small',
                'views' => $row['views'],
                'replies' => $row['replies'],
                'dateline' => dgmdate($row['dateline'], 'u'),
                // [新增] 特殊主题类型
                'special_type' => intval($row['special']),
                // [新增] 图片预览
                'image_list' => $image_list,
                'has_image' => !empty($image_list) ? 1 : 0
            );
        }
    }

    api_return(0, 'Success', array(
        'total' => intval($total),
        'page' => $page,
        'perpage' => $perpage,
        'total_page' => ceil($total / $perpage),
        'list' => $list
    ));