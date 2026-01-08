<?php
/**
 * 模块：高级搜索
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    // 1. 接收参数
    $keyword = isset($_REQUEST['keyword']) ? trim($_REQUEST['keyword']) : '';
    $fulltext = isset($_REQUEST['fulltext']) ? intval($_REQUEST['fulltext']) : 0;
    $author = isset($_REQUEST['author']) ? trim($_REQUEST['author']) : '';
    
    // 筛选参数
    $digest = isset($_REQUEST['digest']) ? intval($_REQUEST['digest']) : 0;
    $stick = isset($_REQUEST['stick']) ? intval($_REQUEST['stick']) : 0;
    $special = isset($_REQUEST['special']) ? $_REQUEST['special'] : ''; 
    $days = isset($_REQUEST['days']) ? intval($_REQUEST['days']) : 0;
    $time_type = isset($_REQUEST['time_type']) ? $_REQUEST['time_type'] : 'within';
    $fid = isset($_REQUEST['fid']) ? intval($_REQUEST['fid']) : 0;
    
    // 排序与分页
    $orderby = isset($_REQUEST['orderby']) ? $_REQUEST['orderby'] : 'lastpost';
    $ascdesc = isset($_REQUEST['ascdesc']) ? strtoupper($_REQUEST['ascdesc']) : 'DESC';
    $page = isset($_REQUEST['page']) ? max(1, intval($_REQUEST['page'])) : 1;
    $perpage = isset($_REQUEST['perpage']) ? max(1, min(50, intval($_REQUEST['perpage']))) : 20;

    // 2. 构建查询条件
    $where_arr = array("t.displayorder >= 0");
    $params = array();
    $count_join_sql = ""; 

    // -- 关键词 --
    if ($keyword) {
        if ($fulltext) {
            $count_join_sql = "LEFT JOIN ".DB::table('forum_post')." p ON p.tid=t.tid AND p.first=1";
            $where_arr[] = "(t.subject LIKE %s OR p.message LIKE %s)";
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        } else {
            $where_arr[] = "t.subject LIKE %s";
            $params[] = '%' . $keyword . '%';
        }
    }

    // -- 其他筛选 --
    if ($author) { $where_arr[] = "t.author = %s"; $params[] = $author; }
    if ($digest) $where_arr[] = "t.digest > 0";
    if ($stick) $where_arr[] = "t.displayorder > 0";
    if ($special) {
        $specials = array_filter(array_map('intval', explode(',', $special)));
        if(!empty($specials)) $where_arr[] = "t.special IN (" . implode(',', $specials) . ")";
    }
    if ($days > 0) {
        $ts = TIMESTAMP - ($days * 86400);
        $where_arr[] = ($time_type == 'within') ? "t.dateline >= %d" : "t.dateline < %d";
        $params[] = $ts;
    }
    if ($fid) { $where_arr[] = "t.fid = %d"; $params[] = $fid; }

    // -- 排序校验 --
    $allow_sort = array('dateline', 'replies', 'views', 'lastpost');
    if (!in_array($orderby, $allow_sort)) $orderby = 'lastpost';
    if ($ascdesc != 'ASC' && $ascdesc != 'DESC') $ascdesc = 'DESC';

    // 3. 执行查询
    $where_sql = implode(' AND ', $where_arr);
    $start = ($page - 1) * $perpage;

    // 统计总数
    if($fulltext && $keyword) {
        $count_sql = "SELECT COUNT(*) FROM ".DB::table('forum_thread')." t $count_join_sql WHERE $where_sql";
    } else {
        $count_sql = "SELECT COUNT(*) FROM ".DB::table('forum_thread')." t WHERE $where_sql";
    }
    $total = DB::result_first($count_sql, $params);

    $list = array();
    if ($total > 0) {
        // 始终 JOIN forum_post 获取 message
        $list_join_sql = "LEFT JOIN ".DB::table('forum_post')." p ON p.tid=t.tid AND p.first=1";
        
        $sql = "SELECT t.tid, t.fid, t.subject, t.author, t.authorid, t.dateline, t.views, t.replies, 
                       t.special, t.digest, t.displayorder, t.lastpost,
                       p.message 
                FROM ".DB::table('forum_thread')." t 
                $list_join_sql 
                WHERE $where_sql 
                ORDER BY t.$orderby $ascdesc 
                LIMIT $start, $perpage";
        
        $query = DB::query($sql, $params);
        loadcache('forums');
        
        while ($row = DB::fetch($query)) {
            $fname = isset($_G['cache']['forums'][$row['fid']]['name']) ? $_G['cache']['forums'][$row['fid']]['name'] : '';
            
            // A. 智能摘要 (关键词上下文)
            $message = $row['message'];
            // 清洗内容用于摘要
            $clean_msg = preg_replace("/\[attach\]\d+\[\/attach\]/i", '', $message);
            $clean_msg = strip_tags(preg_replace("/\[.+?\]/is", '', $clean_msg));
            $clean_msg = trim(preg_replace('/\s+/', ' ', $clean_msg));

            $abstract = '';
            if ($keyword) {
                $pos = mb_stripos($clean_msg, $keyword, 0, 'utf-8');
                if ($pos !== false) {
                    $start_pos = max(0, $pos - 10);
                    $abstract = mb_substr($clean_msg, $start_pos, 50, 'utf-8');
                    if ($start_pos > 0) $abstract = '...' . $abstract;
                } else {
                    $abstract = mb_substr($clean_msg, 0, 50, 'utf-8');
                }
            } else {
                $abstract = mb_substr($clean_msg, 0, 50, 'utf-8');
            }
            if (mb_strlen($abstract, 'utf-8') >= 50 && mb_strlen($clean_msg, 'utf-8') > 50) $abstract .= '...';

            // B. [核心] 提取图片预览
            $image_list = get_thread_images($row['message']);

            $list[] = array(
                'tid' => $row['tid'],
                'fid' => $row['fid'],
                'forum_name' => $fname,
                'subject' => $row['subject'],
                'abstract' => $abstract,
                'author' => $row['author'],
                // 补全头像
                'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$row['authorid'].'&size=small',
                'dateline' => dgmdate($row['dateline'], 'u'),
                'lastpost' => dgmdate($row['lastpost'], 'u'),
                'views' => $row['views'],
                'replies' => $row['replies'],
                'special_type' => intval($row['special']),
                'is_digest' => intval($row['digest']) > 0 ? 1 : 0,
                // 图片数据
                'image_list' => $image_list,
                'has_image' => !empty($image_list) ? 1 : 0
            );
        }
    }

    api_return(0, 'Search success', array(
        'total' => intval($total),
        'page' => $page,
        'perpage' => $perpage,
        'list' => $list
    ));