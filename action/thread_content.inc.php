<?php
/**
 * 模块：帖子详情获取
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    $tid = isset($_REQUEST['tid']) ? intval($_REQUEST['tid']) : 0;
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0; // 当前登录用户UID
    
    if(!$tid) api_return(-3, 'Thread ID (tid) is required');

    // 0. 加载核心依赖
    loadcache('setting'); 
    require_once libfile('function/forum'); // 必须引入，用于支持 aidencode()

    // 1. 查询主题及主楼基础信息 (含统计字段)
    $sql = "SELECT t.tid, t.fid, t.subject, t.author, t.authorid, t.dateline, t.views, t.replies, t.special, t.price,
                   t.recommends, t.recommend_add, t.favtimes, t.heats,
                   p.pid, p.message 
            FROM " . DB::table('forum_thread') . " t 
            LEFT JOIN " . DB::table('forum_post') . " p ON p.tid = t.tid AND p.first = 1 
            WHERE t.tid = %d AND t.displayorder >= 0";
    
    $thread = DB::fetch_first($sql, array($tid));
    if(!$thread) api_return(-8, 'Thread not found');

    // 2. 获取当前用户互动状态 (点赞、收藏、评分)
    $user_interaction = array(
        'is_liked' => 0,
        'is_favorited' => 0,
        'is_rated' => 0
    );

    if ($uid > 0) {
        // A. 检查点赞状态 (推荐记录)
        $user_interaction['is_liked'] = DB::result_first("SELECT count(*) FROM ".DB::table('forum_memberrecommend')." WHERE tid=%d AND recommenduid=%d", array($tid, $uid)) ? 1 : 0;

        // B. 检查收藏状态 (idtype 为 tid)
        $user_interaction['is_favorited'] = DB::result_first("SELECT count(*) FROM ".DB::table('home_favorite')." WHERE uid=%d AND id=%d AND idtype='tid'", array($uid, $tid)) ? 1 : 0;

        // C. 检查评分状态 (根据此 PID 查询评分日志)
        $user_interaction['is_rated'] = DB::result_first("SELECT count(*) FROM ".DB::table('forum_ratelog')." WHERE uid=%d AND pid=%d", array($uid, $thread['pid'])) ? 1 : 0;
    }

    // 3. 解析非图片附件列表 (下载功能支持)
    $attachment_list = array();
    $query_attach = DB::query("SELECT aid, tableid FROM ".DB::table('forum_attachment')." WHERE tid=%d", array($tid));
    while($att_idx = DB::fetch($query_attach)) {
        $aid = $att_idx['aid'];
        $tableid = $att_idx['tableid'];
        $attach = C::t('forum_attachment_n')->fetch($tableid, $aid);
        
        if($attach && !$attach['isimage']) {
            // [修改点]：不再生成 download_url，仅返回元数据
            $attachment_list[] = array(
                'aid' => $aid,
                'filename' => $attach['filename'],
                'filesize' => sizecount($attach['filesize']),
                'downloads' => intval($attach['downloads']),
                'price' => intval($attach['price']),
                'readperm' => intval($attach['readperm']),
                'extension' => strtolower(pathinfo($attach['filename'], PATHINFO_EXTENSION))
                // 此处已移除 download_url
            );
        }
    }

    // 更新浏览量
    C::t('forum_thread')->increase($tid, array('views' => 1));

    // 4. 特殊主题逻辑处理
    $special_info = array();
    $special_type = intval($thread['special']);

    if ($special_type == 1) { 
        // --- 投票贴 (Poll) ---
        $poll = C::t('forum_poll')->fetch($tid);
        if($poll) {
            // 获取投票图片映射
            $poll_imgs = array();
            $query_img = DB::query("SELECT poid, aid FROM ".DB::table('forum_polloption_image')." WHERE tid=$tid");
            while($img = DB::fetch($query_img)) { $poll_imgs[$img['poid']] = $img['aid']; }

            $special_info = array(
                'maxchoices' => $poll['maxchoices'],
                'expiration' => $poll['expiration'] ? dgmdate($poll['expiration']) : '永久',
                'voters' => $poll['voters'],
                'visible' => $poll['visible'],
                'multiple' => $poll['multiple'],
                'options' => array()
            );

            $query = DB::query("SELECT * FROM ".DB::table('forum_polloption')." WHERE tid=$tid ORDER BY displayorder");
            while($opt = DB::fetch($query)) {
                $option_image_url = '';
                if(isset($poll_imgs[$opt['polloptionid']])) {
                    $p_aid = intval($poll_imgs[$opt['polloptionid']]);
                    $att = C::t('forum_attachment')->fetch($p_aid);
                    $p_tableid = $att ? $att['tableid'] : ($p_aid % 10);
                    $att_n = C::t('forum_attachment_n')->fetch($p_tableid, $p_aid);
                    if($att_n) {
                        $option_image_url = ($att_n['remote'] ? $_G['setting']['ftp']['attachurl'] : $_G['siteurl'].'data/attachment/').'forum/'.$att_n['attachment'];
                    }
                }
                $special_info['options'][] = array(
                    'polloptionid' => $opt['polloptionid'],
                    'polloption' => $opt['polloption'],
                    'votes' => $opt['votes'],
                    'percent' => $poll['voters'] > 0 ? round($opt['votes'] / $poll['voters'] * 100, 1) : 0,
                    'image' => $option_image_url
                );
            }
        }

    } elseif ($special_type == 4) {
        // --- 活动贴 (Activity) ---
        $activity = C::t('forum_activity')->fetch($tid);
        if($activity) {
            $activity_cover = '';
            if($activity['aid'] > 0) {
                $act_aid = $activity['aid'];
                $att = C::t('forum_attachment')->fetch($act_aid);
                $act_tableid = $att ? $att['tableid'] : ($act_aid % 10);
                $att_n = C::t('forum_attachment_n')->fetch($act_tableid, $act_aid);
                if($att_n) {
                    $activity_cover = ($att_n['remote'] ? $_G['setting']['ftp']['attachurl'] : $_G['siteurl'].'data/attachment/').'forum/'.$att_n['attachment'];
                }
            }
            $special_info = array(
                'starttimefrom' => dgmdate($activity['starttimefrom']),
                'starttimeto' => $activity['starttimeto'] ? dgmdate($activity['starttimeto']) : '',
                'place' => $activity['place'],
                'cost' => $activity['cost'],
                'class' => $activity['class'],
                'gender' => $activity['gender'],
                'number' => $activity['number'],
                'applynumber' => $activity['applynumber'],
                'cover_image' => $activity_cover
            );
        }

    } elseif ($special_type == 3) {
        // --- 悬赏贴 (Reward) ---
        $special_info = array(
            'reward_price' => abs($thread['price']),
            'is_solved' => ($thread['price'] < 0) ? 1 : 0
        );

    } elseif ($special_type == 2) {
        // --- 商品贴 (Trade) ---
        $trade = DB::fetch_first("SELECT * FROM ".DB::table('forum_trade')." WHERE tid=$tid ORDER BY pid LIMIT 1");
        if($trade) {
            $trade_img = '';
            if($trade['aid'] > 0) {
                 $t_aid = $trade['aid'];
                 $att = C::t('forum_attachment')->fetch($t_aid);
                 $t_tableid = $att ? $att['tableid'] : ($t_aid % 10);
                 $att_n = C::t('forum_attachment_n')->fetch($t_tableid, $t_aid);
                 if($att_n) $trade_img = ($att_n['remote'] ? $_G['setting']['ftp']['attachurl'] : $_G['siteurl'].'data/attachment/').'forum/'.$att_n['attachment'];
            }
            $special_info = array(
                'trade_name' => $trade['subject'],
                'price' => $trade['price'],
                'original_price' => $trade['costprice'],
                'location' => $trade['locus'],
                'seller' => $trade['seller'],
                'image' => $trade_img
            );
        }
    }

    // 5. 组装最终结果
    $data = array(
        'tid' => $thread['tid'],
        'pid' => $thread['pid'],
        'subject' => $thread['subject'],
        'author' => $thread['author'],
        'authorid' => $thread['authorid'],
        'avatar' => $_G['siteurl'] . 'uc_server/avatar.php?uid='.$thread['authorid'].'&size=middle',
        'dateline' => dgmdate($thread['dateline'], 'u'),
        'views' => intval($thread['views']) + 1,
        'replies' => intval($thread['replies']),
        
        // 实时统计
        'recommend_add' => intval($thread['recommend_add']),
        'favtimes' => intval($thread['favtimes']),
        'heats' => intval($thread['heats']),
        
        // 用户互动状态
        'user_interaction' => $user_interaction, 
        
        // 附件列表 (非图片文件)
        'attachment_list' => $attachment_list,

        // 特殊主题数据
        'special_type' => $special_type,
        'special_info' => $special_info,
        
        // 正文解析 (BBCode -> HTML)
        'content' => parse_attach_images($thread['message'])
    );

    api_return(0, 'Success', $data);