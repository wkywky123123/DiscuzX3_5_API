<?php
/**
 * 模块：全站勋章列表
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    $sql = "SELECT medalid, name, image, description 
            FROM ".DB::table('forum_medal')." 
            WHERE available='1' 
            ORDER BY displayorder ASC";
    
    $query = DB::query($sql);
    $list = array();

    while($row = DB::fetch($query)) {
        $image_url = $_G['siteurl'] . 'static/image/common/' . $row['image'];

        $list[] = array(
            'medalid' => $row['medalid'],
            'name' => $row['name'],
            'image' => $image_url,
            'description' => $row['description']
        );
    }

    api_return(0, 'Success', array(
        'total' => count($list),
        'list' => $list
    ));