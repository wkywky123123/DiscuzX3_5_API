<?php
/**
 * 模块：批量编辑/移动/删除相册图片
 */

if(!defined('IN_DISCUZ')) exit('Access Denied');

// 1. 接收基础参数
$uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
$albumid = isset($_REQUEST['albumid']) ? intval($_REQUEST['albumid']) : 0; // 当前所在相册ID

if(!$uid || !$albumid) {
    api_return(-3, 'UID and Album ID are required');
}

// 2. 校验当前相册归属权
$album = DB::fetch_first("SELECT * FROM ".DB::table('home_album')." WHERE albumid=%d", array($albumid));
if(!$album || $album['uid'] != $uid) {
    api_return(-9, 'Access denied: Source album not found or not yours');
}

// --- 逻辑 A：批量删除图片 ---
$delete_ids_raw = isset($_REQUEST['delete_ids']) ? trim($_REQUEST['delete_ids']) : '';
if(!empty($delete_ids_raw)) {
    $delete_ids = array_unique(array_filter(array_map('intval', explode(',', $delete_ids_raw))));
    if(!empty($delete_ids)) {
        require_once libfile('function/delete');
        deletepics($delete_ids); // 该系统函数会自动扣减 picnum
    }
}

// --- 逻辑 B：批量更新图片描述 (Title) ---
$titles = isset($_REQUEST['titles']) ? $_REQUEST['titles'] : array();
if(!empty($titles) && is_array($titles)) {
    foreach($titles as $picid => $new_title) {
        $picid = intval($picid);
        DB::update('home_pic', array('title' => trim($new_title)), array('picid' => $picid, 'uid' => $uid));
    }
}

// --- 逻辑 C：设置相册封面 ---
$set_cover_id = isset($_REQUEST['set_cover']) ? intval($_REQUEST['set_cover']) : 0;
if($set_cover_id > 0) {
    $pic = DB::fetch_first("SELECT filepath, remote FROM ".DB::table('home_pic')." WHERE picid=%d AND uid=%d", array($set_cover_id, $uid));
    if($pic) {
        DB::update('home_album', array(
            'pic' => $pic['filepath'],
            'picflag' => $pic['remote'] ? 2 : 1,
            'updatetime' => TIMESTAMP
        ), array('albumid' => $albumid));
    }
}

// --- [核心新增] 逻辑 D：批量转移图片到另一个相册 ---
$move_to_album = isset($_REQUEST['move_to_album']) ? intval($_REQUEST['move_to_album']) : 0;
$move_ids_raw = isset($_REQUEST['move_ids']) ? trim($_REQUEST['move_ids']) : '';

if($move_to_album > 0 && !empty($move_ids_raw)) {
    // 1. 校验目标相册是否属于该用户
    $target_album = DB::fetch_first("SELECT albumid, picnum FROM ".DB::table('home_album')." WHERE albumid=%d AND uid=%d", array($move_to_album, $uid));
    if(!$target_album) api_return(-6, 'Target album not found or not yours');

    if($move_to_album != $albumid) {
        $move_ids = array_unique(array_filter(array_map('intval', explode(',', $move_ids_raw))));
        if(!empty($move_ids)) {
            // 2. 更新图片的 albumid (增加 UID 校验防止越权)
            DB::query("UPDATE ".DB::table('home_pic')." SET albumid=$move_to_album WHERE picid IN (".dimplode($move_ids).") AND uid=$uid");
            $moved_count = DB::affected_rows();

            if($moved_count > 0) {
                // 3. 同步更新两个相册的图片数量统计
                DB::query("UPDATE ".DB::table('home_album')." SET picnum=picnum-$moved_count WHERE albumid=$albumid");
                DB::query("UPDATE ".DB::table('home_album')." SET picnum=picnum+$moved_count, updatetime='".TIMESTAMP."' WHERE albumid=$move_to_album");
                
                // 4. [可选] 如果目标相册之前没封面，把第一张移过去的图设为封面
                if(empty($target_album['pic'])) {
                    $first_pic = DB::fetch_first("SELECT filepath, remote FROM ".DB::table('home_pic')." WHERE picid=%d", array($move_ids[0]));
                    DB::update('home_album', array('pic' => $first_pic['filepath'], 'picflag' => ($first_pic['remote']?2:1)), array('albumid' => $move_to_album));
                }
            }
        }
    }
}

// 3. 返回最新状态
$current_album = DB::fetch_first("SELECT picnum FROM ".DB::table('home_album')." WHERE albumid=%d", array($albumid));
api_return(0, 'Batch operation completed', array(
    'albumid' => $albumid,
    'current_picnum' => intval($current_album['picnum'])
));