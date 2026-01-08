<?php
/**
 * 模块：附件图片上传
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    // 1. 基础校验
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    if(!$uid) {
        api_return(-3, 'User ID is required');
    }

    // 检查是否有文件上传，Discuz 默认建议参数名为 'file'
    if (!isset($_FILES['file'])) {
        api_return(-3, 'No file uploaded (use parameter name "file")');
    }

    // 2. 加载 Discuz 上传类
    require_once libfile('class/upload');
    $upload = new discuz_upload();

    // 初始化上传，'forum' 表示存放在论坛附件目录
    // init 会自动处理文件合法性检查（后缀、大小等）
    $res = $upload->init($_FILES['file'], 'forum');
    if(!$res) {
        api_return(-10, 'Upload init failed: ' . $upload->errormessage());
    }

    // 3. 保存文件
    // save() 会自动处理目录创建（按日期）、重命名等逻辑
    $attach = $upload->save();
    if(!$attach) {
        api_return(-10, 'Save file failed: ' . $upload->errormessage());
    }

    // 4. 将附件信息写入数据库（这一步才能产生 aid）
    // 默认作为临时附件，等到发帖提交时再正式关联
    $data = array(
        'atid' => 0, // 还没关联到具体帖子，先填 0
        'uid' => $uid,
        'dateline' => TIMESTAMP,
        'filename' => $attach['name'],
        'filesize' => $attach['size'],
        'attachment' => $attach['attachment'],
        'isimage' => $attach['isimage'],
        'thumb' => $attach['thumb'],
        'remote' => $attach['remote'],
        'width' => $attach['width'],
    );
    
    // 生成全局唯一的 aid
    $aid = C::t('forum_attachment')->insert(array('uid' => $uid, 'tableid' => 127), true);
    // 写入具体的附件分表
    C::t('forum_attachment_n')->insert('127', array_merge(array('aid' => $aid), $data));

    // 5. 拼装返回结果
    // 判断是远程还是本地附件
    if($attach['remote']) {
        $url = $_G['setting']['ftp']['attachurl'] . 'forum/' . $attach['attachment'];
    } else {
        $url = $_G['siteurl'] . 'data/attachment/forum/' . $attach['attachment'];
    }

    api_return(0, 'Upload success', array(
        'aid' => $aid,             // 重要：App发帖时需要这个 ID
        'url' => $url,             // 用于在 App 端即时预览
        'width' => $attach['width'],
        'filesize' => $attach['size']
    ));