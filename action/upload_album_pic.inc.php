<?php
/**
 * 模块：相册图片上传 
 */

if(!defined('IN_DISCUZ')) exit('Access Denied');

// 1. 基础参数校验
$uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
if(!$uid) api_return(-3, 'User ID is required');
if (empty($_FILES['file'])) api_return(-3, 'No file uploaded');

$albumid = isset($_REQUEST['albumid']) ? intval($_REQUEST['albumid']) : 0;
$is_new_album = isset($_REQUEST['new_album']) ? intval($_REQUEST['new_album']) : 0;
$title = isset($_REQUEST['title']) ? trim($_REQUEST['title']) : '';

// 2. 确定目标相册 (支持智能新建)
if ($is_new_album === 1) {
    // --- 分支：现场创建新相册 ---
    $albumname = isset($_REQUEST['albumname']) ? trim($_REQUEST['albumname']) : '新相册';
    $depict = isset($_REQUEST['depict']) ? trim($_REQUEST['depict']) : '';
    $friend = isset($_REQUEST['friend']) ? intval($_REQUEST['friend']) : 0; 
    $password = isset($_REQUEST['password']) ? trim($_REQUEST['password']) : '';

    $member = C::t('common_member')->fetch($uid);
    if(!$member) api_return(-6, 'User record missing');

    $album_data = array(
        'albumname' => $albumname,
        'depict' => $depict,
        'uid' => $uid,
        'username' => $member['username'],
        'dateline' => TIMESTAMP,
        'updatetime' => TIMESTAMP,
        'friend' => $friend,
        'password' => ($friend == 4) ? $password : '',
        'picnum' => 0
    );
    // 产生新相册 ID
    $albumid = DB::insert('home_album', $album_data, true);
    // 更新用户统计数
    C::t('common_member_count')->increase($uid, array('albums' => 1));
} else {
    // --- 分支：校验现有相册归属 ---
    if(!$albumid) api_return(-3, 'Album ID or new_album=1 is required');
    $album = DB::fetch_first("SELECT * FROM ".DB::table('home_album')." WHERE albumid=%d", array($albumid));
    if(!$album || $album['uid'] != $uid) {
        api_return(-9, 'Access denied: Album not found or not yours');
    }
}

// 3. 自适应路径加载上传类 (兼容 X3.4/X3.5)
$upload_loaded = false;
$upload_paths = array(
    DISCUZ_ROOT . './source/class/discuz/discuz_upload.php', 
    DISCUZ_ROOT . './source/class/class_upload.php'
);
foreach ($upload_paths as $path) {
    if (file_exists($path)) { require_once $path; $upload_loaded = true; break; }
}
if (!$upload_loaded) api_return(-10, 'Discuz core upload class not found.');

// 4. 执行物理上传
$upload = new discuz_upload();
if (!$upload->init($_FILES['file'], 'album') || !$upload->save()) {
    api_return(-10, 'Upload Error: ' . $upload->errormessage());
}

// 5. 【核心集成】执行你那段强大的自定义水印逻辑
if($upload->attach['isimage']) {
    $member_info = C::t('common_member')->fetch($uid);
    $wm_username = $member_info ? $member_info['username'] : 'Guest';
    
    // 直接调用本文件底部定义的函数
    apply_custom_album_watermark($upload->attach['target'], $wm_username);
}

// 6. 写入图片数据库 pre_home_pic
$picdata = array(
    'albumid' => $albumid,
    'uid' => $uid,
    'username' => $member_info['username'],
    'dateline' => TIMESTAMP,
    'postip' => $_G['clientip'],
    'filename' => $upload->attach['name'], 
    'title' => $title,             
    'type' => $upload->attach['extension'],     
    'size' => $upload->attach['size'],     
    'filepath' => $upload->attach['attachment'], 
    'thumb' => $upload->attach['thumb'] ? 1 : 0,
    'remote' => $upload->attach['remote'] ? 1 : 0
);
$picid = DB::insert('home_pic', $picdata, true);

// 7. 更新相册统计与封面
$album_stats = DB::fetch_first("SELECT pic, picnum FROM ".DB::table('home_album')." WHERE albumid=%d", array($albumid));
$up_data = array('picnum' => $album_stats['picnum'] + 1, 'updatetime' => TIMESTAMP);
if(empty($album_stats['pic'])) {
    $up_data['pic'] = $upload->attach['attachment'];
    $up_data['picflag'] = $upload->attach['remote'] ? 2 : 1;
}
DB::update('home_album', $up_data, array('albumid' => $albumid));

// 8. 返回最终结果
loadcache('setting');
$final_url = ($upload->attach['remote'] ? $_G['setting']['ftp']['attachurl'] : $_G['siteurl'].'data/attachment/').'album/'.$upload->attach['attachment'];

api_return(0, 'Success', array(
    'picid' => intval($picid),
    'albumid' => intval($albumid),
    'url' => $final_url,
    'is_new_album' => $is_new_album ? true : false
));

/**
 * 【自定义水印函数】整合了你提供的所有自适应缩放与布局逻辑
 */
function apply_custom_album_watermark($target_file, $username) {
    // --- 网站基础信息 ---
    $site_name = "宝宝新天地";     
    $site_url  = "www.newbabyworld.top"; 
    $cur_user  = 'By ' . $username; 
    $font_path = DISCUZ_ROOT . './static/image/seccode/font/HanYiTiJian-1.ttf';
    
    // --- 样式参数 ---
    $base_size_name = 13; $base_size_url = 8; $base_size_user = 16;
    $base_margin = 5; $base_line_gap = 3; $watermark_opacity = 70; $jpg_quality = 90;

    if(!function_exists('imagettftext') || !file_exists($font_path)) return;

    $info = @getimagesize($target_file);
    if(!$info) return;

    // 创建资源
    switch($info[2]) {
        case 1: $img = imagecreatefromgif($target_file); break;
        case 2: $img = imagecreatefromjpeg($target_file); break;
        case 3: $img = imagecreatefrompng($target_file); break;
        default: return;
    }

    imagealphablending($img, true);
    imagesavealpha($img, true);

    // 计算缩放比例 (以1000px为基准)
    $w = $info[0]; $h = $info[1];
    $scale = $w / 1000;
    if($scale < 0.6) $scale = 0.6;
    if($scale > 4.0) $scale = 4.0;

    $f_name = round($base_size_name * $scale);
    $f_url  = round($base_size_url * $scale);
    $f_user = round($base_size_user * $scale);
    $m_gap  = round($base_margin * $scale);
    $l_gap  = round($base_line_gap * $scale);

    // 计算文字盒模型
    $box_n = imagettfbbox($f_name, 0, $font_path, $site_name);
    $box_u = imagettfbbox($f_url,  0, $font_path, $site_url);
    $box_s = imagettfbbox($f_user, 0, $font_path, $cur_user);

    $dim_n = array('w' => abs($box_n[2] - $box_n[0]), 'h' => abs($box_n[7] - $box_n[1]));
    $dim_u = array('w' => abs($box_u[2] - $box_u[0]), 'h' => abs($box_u[7] - $box_u[1]));
    $dim_s = array('w' => abs($box_s[2] - $box_s[0]), 'h' => abs($box_s[7] - $box_s[1]));

    $left_w = max($dim_n['w'], $dim_u['w']);
    $total_w = $left_w + $m_gap + $dim_s['w'];
    $total_h = max(($dim_n['h'] + $l_gap + $dim_u['h']), $dim_s['h']);

    // 随机坐标
    $pad = round(15 * $scale);
    if($w < $total_w + $pad*2 || $h < $total_h + $pad*2) { imagedestroy($img); return; }
    $rx = rand($pad, $w - $total_w - $pad);
    $ry = rand($pad, $h - $total_h - $pad);

    // 颜色处理
    $alpha = intval(127 - ($watermark_opacity * 1.27));
    $white = imagecolorallocatealpha($img, 255, 255, 255, $alpha);
    $black = imagecolorallocatealpha($img, 0, 0, 0, $alpha);
    $off = max(1, round(1 * $scale));

    // 内部绘制匿名函数
    $draw = function($i, $sz, $x, $y, $t) use ($font_path, $white, $black, $off) {
        imagettftext($i, $sz, 0, $x+$off, $y+$off, $black, $font_path, $t);
        imagettftext($i, $sz, 0, $x, $y, $white, $font_path, $t);
    };

    $ny = $ry + $dim_n['h'];
    $draw($img, $f_name, $rx, $ny, $site_name);
    $draw($img, $f_url,  $rx, $ny + $l_gap + $dim_u['h'], $site_url);
    $draw($img, $f_user, $rx + $left_w + $m_gap, $ry + ($total_h/2) + ($dim_s['h']/2) - round(2*$scale), $cur_user);

    // 保存物理文件
    if($info[2] == 2) imagejpeg($img, $target_file, $jpg_quality);
    elseif($info[2] == 3) imagepng($img, $target_file);
    elseif($info[2] == 1) imagegif($img, $target_file);

    imagedestroy($img);
}