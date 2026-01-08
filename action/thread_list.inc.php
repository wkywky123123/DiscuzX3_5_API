<?php
/**
 * 模块：帖子列表查询
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    // 1. 接收参数
    $fid = isset($_REQUEST['fid']) ? intval($_REQUEST['fid']) : 0;
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0; // [新增] 接收当前用户 UID
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(50, intval($_REQUEST['perpage']))) : 20;
    $orderby = isset($_REQUEST['orderby']) ? $_REQUEST['orderby'] : 'lastpost';
    
    // 2. [核心功能] 如果指定了 FID，则获取版块的收藏统计信息
    $forum_fav_info = array(
        'favorite_count' => 0, // 版块总收藏数
        'is_favorite' => 0     // 当前用户是否已收藏
    );

    if ($fid > 0) {
        // A. 查询版块被收藏的总次数 (idtype='fid')
        $forum_fav_info['favorite_count'] = DB::result_first("SELECT COUNT(*) FROM ".DB::table('home_favorite')." WHERE id=%d AND idtype='fid'", array($fid));

        // B. 如果传入了 UID，检查该用户是否收藏了该版块
        if ($uid > 0) {
            $forum_fav_info['is_favorite'] = DB::result_first("SELECT COUNT(*) FROM ".DB::table('home_favorite')." WHERE uid=%d AND id=%d AND idtype='fid'", array($uid, $fid)) ? 1 : 0;
        }
    }

    // 3. 构建帖子查询条件
    $where = "t.displayorder >= 0"; 
    if($fid) {
        $where .= " AND t.fid=" . $fid;
    }

    // 排序校验
    $allow_sort = array('dateline', 'replies', 'views', 'lastpost');
    if(!in_array($orderby, $allow_sort)) $orderby = 'lastpost';

    $start = ($page - 1) * $perpage;

    // 4. 统计总数
    $total = DB::result_first("SELECT COUNT(*) FROM ".DB::table('forum_thread')." t WHERE $where");

    $list = array();
    if($total > 0) {
        // 5. 联表查询
        $sql = "SELECT t.tid, t.fid, t.subject, t.author, t.authorid, t.dateline, t.views, t.replies, 
                       t.digest, t.attachment, t.special,
                       p.message 
                FROM ".DB::table('forum_thread')." t 
                LEFT JOIN ".DB::table('forum_post')." p ON p.tid = t.tid AND p.first = 1 
                WHERE $where 
                ORDER BY t.$orderby DESC 
                LIMIT $start, $perpage";
        
        $query = DB::query($sql);
        loadcache('forums');
        
        while($row = DB::fetch($query)) {
            // A. 处理摘要
            $message_text = $row['message'];
            $message_text = preg_replace("/\[attach\]\d+\[\/attach\]/i", '', $message_text);
            $message_text = strip_tags(preg_replace("/\[.+?\]/is", '', $message_text));
            $abstract = mb_substr(trim($message_text), 0, 100, 'utf-8');
            if(mb_strlen(trim($message_text), 'utf-8') > 100) $abstract .= '...';

            // B. 提取图片
            $image_list = get_thread_images($row['message']);

            // C. 获取版块名
            $forum_name = isset($_G['cache']['forums'][$row['fid']]['name']) ? $_G['cache']['forums'][$row['fid']]['name'] : '';

            $list[] = array(
                'tid' => $row['tid'],
                'fid' => $row['fid'],
                'forum_name' => $forum_name,
                'subject' => $row['subject'],
                'abstract' => $abstract,
                'author' => $row['author'],
                'authorid' => $row['authorid'],
                'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$row['authorid'].'&size=small',
                'dateline' => dgmdate($row['dateline'], 'u'),
                'views' => $row['views'],
                'replies' => $row['replies'],
                'special_type' => intval($row['special']),
                'is_digest' => intval($row['digest']) > 0 ? 1 : 0,
                'image_list' => $image_list, 
                'has_image' => !empty($image_list) ? 1 : 0
            );
        }
    }

    // 6. 返回结果 (合并版块统计信息)
    api_return(0, 'Success', array(
        'forum_fav_info' => $forum_fav_info, // [新增] 当前版块的收藏状态与统计
        'total' => intval($total),
        'page' => $page,
        'perpage' => $perpage,
        'list' => $list
    ));