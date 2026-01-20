<?php
/**
 * 模块：编辑/删除相册
 */

if(!defined('IN_DISCUZ')) exit('Access Denied');

// 1. 接收基础参数
$uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
$albumid = isset($_REQUEST['albumid']) ? intval($_REQUEST['albumid']) : 0;
$delete = isset($_REQUEST['delete']) ? intval($_REQUEST['delete']) : 0; // 是否执行删除

if(!$uid || !$albumid) {
    api_return(-3, 'UID and Album ID are required');
}

// 2. 校验相册所有权
$album = DB::fetch_first("SELECT * FROM ".DB::table('home_album')." WHERE albumid=%d", array($albumid));
if(!$album) {
    api_return(-6, 'Album not found');
}
if($album['uid'] != $uid) {
    api_return(-9, 'Access denied: You are not the owner of this album');
}

// --- 逻辑 A：执行删除相册 ---
if($delete === 1) {
    // 引入 Discuz 核心删除函数，确保删除相册时同步清理图片记录和附件文件
    require_once libfile('function/delete');
    
    // deletealbums 函数会处理：1.删除相册记录 2.删除相册内的图片记录 3.如果是物理删除还会清理文件
    // 注意：Discuz 默认删除相册会把图片转移到系统默认相册，或者根据配置删除。
    // 这里我们采用最直接的系统调用
    $res = deletealbums(array($albumid));
    
    if($res) {
        // 同步更新用户统计
        C::t('common_member_count')->increase($uid, array('albums' => -1));
        api_return(0, 'Album deleted successfully');
    } else {
        api_return(-11, 'Delete failed: System logic error');
    }
}

// --- 逻辑 B：执行编辑相册 ---
$albumname = isset($_REQUEST['albumname']) ? trim($_REQUEST['albumname']) : '';
$depict = isset($_REQUEST['depict']) ? trim($_REQUEST['depict']) : ''; // 相册描述
$friend = isset($_REQUEST['friend']) ? intval($_REQUEST['friend']) : -1; // 隐私设置
$password = isset($_REQUEST['password']) ? trim($_REQUEST['password']) : ''; // 若 friend=4 则需密码

$update_data = array();

// 只有传入了对应参数才更新，支持部分更新
if($albumname !== '') $update_data['albumname'] = $albumname;
if($depict !== '')    $update_data['depict'] = $depict;

// 隐私设置处理 (0:全站, 1:仅好友, 2:指定好友, 3:仅自己, 4:凭密码)
if($friend >= 0 && $friend <= 4) {
    $update_data['friend'] = $friend;
    if($friend == 4) {
        $update_data['password'] = $password;
    } else {
        $update_data['password'] = ''; // 非密码模式清空密码
    }
}

if(empty($update_data)) {
    api_return(-3, 'No update data provided');
}

$update_data['updatetime'] = TIMESTAMP;

// 执行更新
DB::update('home_album', $update_data, array('albumid' => $albumid));

api_return(0, 'Album updated successfully', array(
    'albumid' => $albumid,
    'fields_updated' => array_keys($update_data)
));