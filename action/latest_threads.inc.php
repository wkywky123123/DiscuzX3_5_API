<?php
/**
 * 模块：全站最新动态
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

// 1. 接收参数
    $limit = isset($_REQUEST['limit']) ? max(1, min(50, intval($_REQUEST['limit']))) : 10;
    $fid = isset($_REQUEST['fid']) ? intval($_REQUEST['fid']) : 0;
    
    // 2. 构建查询条件
    $where = "WHERE t.displayorder >= 0"; // 排除回收站
    if($fid) $where .= " AND t.fid=" . $fid;
    
    // 3. 联表查询
    // 必须 JOIN forum_post (first=1) 获取内容用于提取图片
    // 新增 t.special 字段
    $sql = "SELECT t.tid, t.fid, t.subject, t.author, t.authorid, t.dateline, t.views, t.replies, t.special,
                   f.name as forum_name, 
                   p.message 
            FROM " . DB::table('forum_thread') . " t 
            LEFT JOIN " . DB::table('forum_forum') . " f ON f.fid=t.fid 
            LEFT JOIN " . DB::table('forum_post') . " p ON p.tid=t.tid AND p.first=1 
            $where 
            ORDER BY t.dateline DESC 
            LIMIT $limit";

    $query = DB::query($sql);
    $list = array();
    
    while($row = DB::fetch($query)) {
        // A. 处理摘要 (统一使用正则清洗，保留100字)
        $message_text = $row['message'];
        $message_text = preg_replace("/\[attach\]\d+\[\/attach\]/i", '', $message_text);
        $message_text = strip_tags(preg_replace("/\[.+?\]/is", '', $message_text));
        $abstract = mb_substr(trim($message_text), 0, 100, 'utf-8');
        if(mb_strlen(trim($message_text), 'utf-8') > 100) $abstract .= '...';

        // B. 调用辅助函数提取图片
        $image_list = get_thread_images($row['message']);
        
        $list[] = array(
            'tid' => $row['tid'],
            'fid' => $row['fid'],
            'forum_name' => $row['forum_name'],
            'subject' => $row['subject'],
            'abstract' => $abstract,
            'author' => $row['author'],
            'authorid' => $row['authorid'],
            'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$row['authorid'].'&size=small',
            'dateline' => dgmdate($row['dateline'], 'u'),
            'views' => $row['views'],
            'replies' => $row['replies'],
            // [新增] 特殊主题类型
            'special_type' => intval($row['special']), // 0普通, 1投票, 2商品...
            // [新增] 图片预览
            'image_list' => $image_list,
            'has_image' => !empty($image_list) ? 1 : 0
        );
    }
    
    api_return(0, 'Success', array('list' => $list));
    