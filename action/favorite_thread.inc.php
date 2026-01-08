<?php
/**
 * 模块：添加/取消收藏帖子
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $tid = isset($_REQUEST['tid']) ? intval($_REQUEST['tid']) : 0;
    $do = isset($_REQUEST['do']) ? trim($_REQUEST['do']) : 'add'; // add=收藏, del=取消

    // 2. 基础校验
    if(!$uid || !$tid) {
        api_return(-3, 'UID and TID are required');
    }

    // 3. 检查帖子是否存在
    // 同时也排除回收站中的帖子 (displayorder < 0)
    $thread = C::t('forum_thread')->fetch($tid);
    if(!$thread || $thread['displayorder'] < 0) {
        api_return(-8, 'Thread not found or deleted');
    }

    // 4. 检查是否已收藏
    // idtype='tid' 代表帖子收藏
    $fav = C::t('home_favorite')->fetch_by_id_idtype($tid, 'tid', $uid);

    if($do == 'add') {
        // --- 执行收藏 ---
        if($fav) {
            api_return(-15, 'Already favorite');
        }
        
        $data = array(
            'uid' => $uid,
            'id' => $tid,
            'idtype' => 'tid',
            'spaceuid' => 0, // 帖子收藏不需要指定空间UID
            'title' => $thread['subject'], // 缓存帖子标题
            'description' => '',
            'dateline' => TIMESTAMP
        );
        C::t('home_favorite')->insert($data);
        
        // [关键] 增加帖子的被收藏次数 (favtimes + 1)
        C::t('forum_thread')->increase($tid, array('favtimes' => 1));
        
        api_return(0, 'Favorite added');

    } else {
        // --- 执行取消 ---
        if($fav) {
            C::t('home_favorite')->delete($fav['favid']);
            
            // [关键] 减少帖子的被收藏次数 (favtimes - 1)
            // 只有真正删除了收藏记录才减，防止重复调用导致负数风险
            C::t('forum_thread')->increase($tid, array('favtimes' => -1));
        }
        api_return(0, 'Favorite removed');
    }