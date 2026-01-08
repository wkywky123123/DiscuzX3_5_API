<?php
/**
 * 模块：上传图片到相册
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    $albumid = isset($_REQUEST['albumid']) ? intval($_REQUEST['albumid']) : 0;
    $title = isset($_REQUEST['title']) ? trim($_REQUEST['title']) : ''; // 图片描述(可选)

    // 2. 基础校验
    if(!$uid || !$albumid) {
        api_return(-3, 'UID and Album ID are required');
    }
    
    // 检查是否有文件上传
    if (!isset($_FILES['file'])) {
        api_return(-3, 'No file uploaded');
    }

    // 3. 验证相册归属权
    // 必须确保该相册属于当前用户
    $album = C::t('home_album')->fetch($albumid);
    if(!$album) {
        api_return(-6, 'Album not found');
    }
    if($album['uid'] != $uid) {
        api_return(-9, 'Access denied: You do not own this album');
    }

    // 4. 加载 Discuz 上传类
    require_once libfile('class/upload');
    $upload = new discuz_upload();

    // 初始化上传，类型设为 'album' (存入 /data/attachment/album/ 目录)
    $res = $upload->init($_FILES['file'], 'album');
    if(!$res) {
        api_return(-10, 'Upload init failed: ' . $upload->errormessage());
    }

    // 5. 保存文件
    $attach = $upload->save();
    if(!$attach) {
        api_return(-10, 'Save file failed: ' . $upload->errormessage());
    }

    // 6. 插入数据库 (home_pic 表)
    $picdata = array(
        'albumid' => $albumid,
        'uid' => $uid,
        'username' => $album['username'],
        'dateline' => TIMESTAMP,
        'postip' => $_G['clientip'],
        'filename' => $attach['name'], // 原始文件名
        'title' => $title,             // 图片描述
        'type' => $attach['type'],     // 图片类型 mime
        'size' => $attach['size'],     // 大小
        'filepath' => $attach['attachment'], // 相对路径
        'thumb' => $attach['thumb'],
        'remote' => $attach['remote'],
        'width' => $attach['width']
    );

    $picid = C::t('home_pic')->insert($picdata, true);

    // 7. 更新相册统计数据 (图片数+1, 更新时间)
    // 如果相册还没有封面，自动将这张图设为封面
    $update_data = array('picnum' => $album['picnum'] + 1, 'updatetime' => TIMESTAMP);
    if(empty($album['pic'])) {
        $update_data['pic'] = $attach['attachment'];
        $update_data['picflag'] = $attach['remote'] ? 2 : 1;
    }
    C::t('home_album')->update($albumid, $update_data);

    // 8. 拼接返回 URL
    if($attach['remote']) {
        $url = $_G['setting']['ftp']['attachurl'] . 'album/' . $attach['attachment'];
    } else {
        $url = $_G['siteurl'] . 'data/attachment/album/' . $attach['attachment'];
    }

    api_return(0, 'Upload album picture success', array(
        'picid' => $picid,
        'albumid' => $albumid,
        'url' => $url,
        'width' => $attach['width'],
        'size' => $attach['size']
    ));