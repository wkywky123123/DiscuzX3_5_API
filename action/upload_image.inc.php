<?php
/**
 * 模块：附件图片上传
 */

if(!defined('IN_DISCUZ')) exit('Access Denied');

// 1. 基础校验
$uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
if(!$uid) api_return(-3, 'User ID is required');
if (empty($_FILES['file'])) api_return(-3, 'No file uploaded');

// 2. 【自适应路径加载】确保在所有 Discuz! 版本下都能找到类库
$upload_loaded = false;
$upload_paths = array(
    DISCUZ_ROOT . './source/class/discuz/discuz_upload.php', 
    DISCUZ_ROOT . './source/class/class_upload.php'
);

foreach ($upload_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $upload_loaded = true;
        break;
    }
}

if (!$upload_loaded) {
    api_return(-10, 'Discuz core upload class not found.');
}

// 3. 执行初始化与物理保存 (类型为 'forum')
$upload = new discuz_upload();

if (!$upload->init($_FILES['file'], 'forum')) {
    api_return(-10, 'Init Error: ' . $upload->errormessage());
}

if (!$upload->save()) {
    api_return(-10, 'Save Error: ' . $upload->errormessage());
}

// 4. 【核心修复】强制补全图片元数据 (解决之前出现的 width 为 0 的问题)
if($upload->attach['isimage'] && (empty($upload->attach['width']) || $upload->attach['width'] == 0)) {
    $img_info = @getimagesize($upload->attach['target']);
    if($img_info) {
        $upload->attach['width'] = $img_info[0];
        $upload->attach['height'] = $img_info[1];
    }
}

// 5. 【核心逻辑】写入数据库（Unused 流程）

// A. 插入索引主表
// tableid = 127 是 Discuz! 内部约定的“临时/未使用”标识位
$aid = DB::insert('forum_attachment', array(
    'uid' => $uid, 
    'tableid' => 127, 
    'tid' => 0, 
    'pid' => 0
), true);

if(!$aid) {
    api_return(-11, 'Database Error: Failed to generate AID');
}

// B. 写入 pre_forum_attachment_unused 表
// 只有写入这张表，官方后台和发帖页面才会出现“未使用附件”的提醒
$unused_data = array(
    'aid' => $aid,
    'uid' => $uid,
    'dateline' => TIMESTAMP,
    'filename' => $upload->attach['name'],      // 原始文件名
    'filesize' => $upload->attach['size'],      // 文件大小
    'attachment' => $upload->attach['attachment'], // 物理存储路径（如 202601/xx.png）
    'isimage' => $upload->attach['isimage'] ? 1 : 0,
    'remote' => $upload->attach['remote'] ? 1 : 0,
    'width' => intval($upload->attach['width']),
);

DB::insert('forum_attachment_unused', $unused_data);

// 6. 拼装最终可访问 URL (支持远程附件检测)
loadcache('setting');
$base_url = '';
if ($upload->attach['remote']) {
    $base_url = $_G['setting']['ftp']['attachurl'] . 'forum/';
} else {
    $base_url = $_G['siteurl'] . 'data/attachment/forum/';
}

// 7. 统一返回结果
api_return(0, 'Upload success (Unused mode)', array(
    'aid' => intval($aid),
    'url' => $base_url . $upload->attach['attachment'],
    'width' => intval($upload->attach['width']),
    'filesize' => intval($upload->attach['size']),
    'is_remote' => $upload->attach['remote'] ? 1 : 0
));