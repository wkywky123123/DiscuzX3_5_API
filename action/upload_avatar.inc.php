<?php
/**
 * 模块：修改用户头像
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    // 1. 接收参数
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    
    if(!$uid) api_return(-3, 'User ID (uid) is required');

    // 检查是否有文件上传
    if (!isset($_FILES['file'])) {
        api_return(-3, 'No file uploaded');
    }

    // 2. 检查用户是否存在
    $member = C::t('common_member')->fetch($uid);
    if(!$member) api_return(-6, 'User not found');

    // 3. 【关键步骤】加载系统设置缓存
    // 这一步确保 API 能读取到你在后台设置的 "图片处理库类型" (GD 或 ImageMagick)
    loadcache('setting');

    // 4. 定义 UCenter 头像存储路径逻辑
    $uid = sprintf("%09d", $uid);
    $dir1 = substr($uid, 0, 3);
    $dir2 = substr($uid, 3, 2);
    $dir3 = substr($uid, 5, 2);
    
    // 物理路径 (UCenter 默认目录)
    $avatar_dir = DISCUZ_ROOT . './uc_server/data/avatar/' . $dir1 . '/' . $dir2 . '/' . $dir3 . '/';
    
    // 如果目录不存在则创建
    if(!is_dir($avatar_dir)) {
        if(!mkdir($avatar_dir, 0777, true)) {
            api_return(-10, 'Failed to create avatar directory. Check permissions.');
        }
    }

    // 5. 加载 Discuz 核心图片处理类
    // 这个类会自动判断 $_G['setting']['imagelib'] 的值
    // 如果后台选了 ImageMagick，它会自动调用 exec() 执行 convert 命令
    require_once libfile('class/image');
    $image = new image();

    $src_file = $_FILES['file']['tmp_name'];
    
    // 验证是否是有效图片
    $imginfo = @getimagesize($src_file);
    if(!$imginfo) {
        api_return(-11, 'Invalid image file');
    }

    // 6. 生成三种尺寸的头像
    // Thumb 函数内部逻辑：
    // if($_G['setting']['imagelib']) -> 调用 ImageMagick
    // else -> 调用 GD 库
    
    $success_count = 0;
    
    // 生成大图 (200x200)
    $dst_big = $avatar_dir . substr($uid, -2) . '_avatar_big.jpg';
    // 参数说明: 源文件, 目标文件, 宽, 高, 裁剪模式(1=强制裁剪)
    if($image->Thumb($src_file, $dst_big, 200, 200, 1)) $success_count++; 
    
    // 生成中图 (120x120)
    $dst_middle = $avatar_dir . substr($uid, -2) . '_avatar_middle.jpg';
    if($image->Thumb($src_file, $dst_middle, 120, 120, 1)) $success_count++;

    // 生成小图 (48x48)
    $dst_small = $avatar_dir . substr($uid, -2) . '_avatar_small.jpg';
    if($image->Thumb($src_file, $dst_small, 48, 48, 1)) $success_count++;

    if($success_count < 3) {
        // 容错处理：如果缩略图生成失败(例如 ImageMagick 路径没配对)，尝试简单的 copy
        // 这样至少能保证头像更换成功，虽然可能没裁剪好
        if(!file_exists($dst_big)) copy($src_file, $dst_big);
        if(!file_exists($dst_middle)) copy($src_file, $dst_middle);
        if(!file_exists($dst_small)) copy($src_file, $dst_small);
        
        // 可以在这里记录日志，方便排查 ImageMagick 配置问题
    }

    // 7. 清理缓存/标记更新
    C::t('common_member')->update($uid, array('avatarstatus' => 1));

    // 生成带时间戳的 URL
    $avatar_url = $_G['siteurl'] . 'uc_server/avatar.php?uid=' . intval($uid) . '&size=middle&random=' . time();

    api_return(0, 'Avatar updated successfully', array(
        'uid' => intval($uid),
        'avatar_url' => $avatar_url,
        'processing_lib' => $_G['setting']['imagelib'] ? 'ImageMagick' : 'GD' // 仅供调试，告知使用了哪个库
    ));