<?php
/**
 * 模块：添加/取消收藏版块
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $fid = isset($_REQUEST['fid']) ? intval($_REQUEST['fid']) : 0;
    $do = isset($_REQUEST['do']) ? trim($_REQUEST['do']) : 'add'; // add=收藏, del=取消

    // 2. 基础校验
    if(!$uid || !$fid) {
        api_return(-3, 'UID and FID are required');
    }

    // 3. 检查版块是否存在且状态正常
    $forum = C::t('forum_forum')->fetch($fid);
    if(!$forum || $forum['status'] != 1) {
        api_return(-7, 'Forum invalid or closed');
    }

    // 4. 检查是否已收藏
    // idtype='fid' 代表版块收藏
    $fav = C::t('home_favorite')->fetch_by_id_idtype($fid, 'fid', $uid);

    if($do == 'add') {
        // --- 执行收藏 ---
        if($fav) {
            api_return(-15, 'Already favorite');
        }
        
        $data = array(
            'uid' => $uid,
            'id' => $fid,
            'idtype' => 'fid',
            'spaceuid' => 0,
            'title' => $forum['name'], // 缓存版块名称
            'description' => '',
            'dateline' => TIMESTAMP
        );
        C::t('home_favorite')->insert($data);
        
        // 注：Discuz 默认通常不统计版块的被收藏数，如果你的站点有此需求需额外处理
        
        api_return(0, 'Forum favorite added');

    } else {
        // --- 执行取消 ---
        if($fav) {
            C::t('home_favorite')->delete($fav['favid']);
        }
        // 即使原本没收藏，执行删除也返回成功（幂等性）
        api_return(0, 'Forum favorite removed');
    }