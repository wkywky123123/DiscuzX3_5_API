<?php
/**
 * 模块：同步帖子附件到个人相册
 */

if(!defined('IN_DISCUZ')) exit('Access Denied');

// 1. 接收参数
$uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
$albumid = isset($_REQUEST['albumid']) ? intval($_REQUEST['albumid']) : 0;
$aids_raw = isset($_REQUEST['aids']) ? trim($_REQUEST['aids']) : ''; // 逗号分隔的 AID，如 "4627,4628"

if(!$uid || !$albumid || !$aids_raw) {
    api_return(-3, 'Missing parameters (uid, albumid, aids)');
}

// 2. 校验相册归属权
$album = DB::fetch_first("SELECT * FROM ".DB::table('home_album')." WHERE albumid=%d", array($albumid));
if(!$album || $album['uid'] != $uid) {
    api_return(-9, 'Access denied: Target album not found or not yours');
}

// 3. 解析并清洗 AID 列表
$aids = array_unique(array_filter(array_map('intval', explode(',', $aids_raw))));
if(empty($aids)) api_return(-3, 'Invalid aids format');

$success_count = 0;
$results = array();

// 4. 循环处理每一个附件
foreach($aids as $aid) {
    // A. 校验附件归属权（只能存自己的图）
    $att_idx = C::t('forum_attachment')->fetch($aid);
    if(!$att_idx || $att_idx['uid'] != $uid) continue; // 跳过不属于自己的图

    $attach = C::t('forum_attachment_n')->fetch($att_idx['tableid'], $aid);
    if(!$attach || !$attach['isimage']) continue; // 跳过非图片

    // B. 准备物理路径
    // 虽然物理存储位置可能一样，但为了符合 Discuz! 相册标准逻辑，
    // 我们建议将文件从 data/attachment/forum/ 复制到 data/attachment/album/
    $subdir = date('Ym').'/'.date('d').'/';
    $target_folder = DISCUZ_ROOT . './data/attachment/album/' . $subdir;
    if(!is_dir($target_folder)) @mkdir($target_folder, 0777, true);

    $new_name = TIMESTAMP . random(6) . '.' . pathinfo($attach['attachment'], PATHINFO_EXTENSION);
    $source_file = DISCUZ_ROOT . './data/attachment/forum/' . $attach['attachment'];
    $dest_file = $target_folder . $new_name;

    // C. 执行物理复制 (确保相册有独立文件，不随帖子删除而消失)
    if(@copy($source_file, $dest_file)) {
        
        // D. 如果有水印逻辑，重新印上当前用户的水印
        if(function_exists('apply_custom_watermark_api')) {
            apply_custom_watermark_api($dest_file, $album['username']);
        }

        // E. 写入 home_pic 表
        $picdata = array(
            'albumid' => $albumid,
            'uid' => $uid,
            'username' => $album['username'],
            'dateline' => TIMESTAMP,
            'postip' => $_G['clientip'],
            'filename' => $attach['filename'],
            'title' => '同步自帖子附件: ' . $aid,
            'type' => strtolower(pathinfo($attach['filename'], PATHINFO_EXTENSION)),
            'size' => $attach['filesize'],
            'filepath' => $subdir . $new_name,
            'thumb' => 0,
            'remote' => 0 
        );
        $picid = DB::insert('home_pic', $picdata, true);

        if($picid) {
            $success_count++;
            $results[] = array('aid' => $aid, 'picid' => intval($picid));
        }
    }
}

// 5. 更新相册统计
if($success_count > 0) {
    DB::query("UPDATE ".DB::table('home_album')." SET picnum=picnum+$success_count, updatetime='".TIMESTAMP."' WHERE albumid=$albumid");
}

api_return(0, "Successfully synced $success_count images to album", array(
    'total_requested' => count($aids),
    'success_count' => $success_count,
    'synced_list' => $results
));