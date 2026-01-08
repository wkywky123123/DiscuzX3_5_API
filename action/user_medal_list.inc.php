<?php
/**
 * 模块：获取用户勋章
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    
    if(!$uid) {
        api_return(-3, 'User ID (uid) is required');
    }

    // 1. 联表查询：用户勋章表 (common_member_medal) + 勋章基础表 (forum_medal)
    // 逻辑：只要表里有记录且勋章启用，即视为拥有
    
    $sql = "SELECT mm.medalid, m.name, m.image, m.description 
            FROM ".DB::table('common_member_medal')." mm 
            LEFT JOIN ".DB::table('forum_medal')." m ON m.medalid = mm.medalid 
            WHERE mm.uid=%d AND m.available='1' 
            ORDER BY m.displayorder ASC"; // 按勋章后台设置的顺序排列
    
    $query = DB::query($sql, array($uid));
    $list = array();

    while($row = DB::fetch($query)) {
        // 修正图片路径：Discuz 默认勋章位于 static/image/common/
        // 部分站点可能修改过路径，这里使用标准路径
        $image_url = $_G['siteurl'] . 'static/image/common/' . $row['image'];
        
        $list[] = array(
            'medalid' => $row['medalid'],
            'name' => $row['name'],
            'image' => $image_url,
            'description' => $row['description'],
            // 如需精确过期时间需关联 forum_medallog 表，鉴于复杂度和性能，暂不通过 API 提供
            'status' => 'active' 
        );
    }

    api_return(0, 'Success', array(
        'uid' => $uid,
        'total' => count($list),
        'list' => $list
    ));