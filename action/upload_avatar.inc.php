<?php
/**
 * 模块：修改用户头像
 */

if(!defined('IN_DISCUZ')) exit('Access Denied');

// 1. 基础参数
$uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
if(!$uid) api_return(-3, 'User ID is required');
if (empty($_FILES['file'])) api_return(-3, 'No file uploaded');

// 2. 绝对路径定位 (基于你刚才返回的 path)
$base_path = '/www/wwwroot/www.newbabyworld.top/';
$avatar_dir_base = $base_path . 'uc_server/data/avatar/';

$uid_padded = sprintf("%09d", $uid);
$dir1 = substr($uid_padded, 0, 3);
$dir2 = substr($uid_padded, 3, 2);
$dir3 = substr($uid_padded, 5, 2);
$file_prefix = substr($uid_padded, -2);

$target_dir = $avatar_dir_base . "$dir1/$dir2/$dir3/";

// 3. 强制创建目录
if(!is_dir($target_dir)) {
    @mkdir($target_dir, 0777, true);
}

// 4. 处理图片源 (原生 GD)
$tmp_file = $_FILES['file']['tmp_name'];
$img_info = @getimagesize($tmp_file);
if(!$img_info) api_return(-11, 'Invalid Image File');

$src_img = null;
switch($img_info[2]) {
    case 1: $src_img = imagecreatefromgif($tmp_file); break;
    case 2: $src_img = imagecreatefromjpeg($tmp_file); break;
    case 3: $src_img = imagecreatefrompng($tmp_file); break;
    default: api_return(-11, 'Unsupported format');
}

// 计算居中正方形裁剪
$src_w = $img_info[0];
$src_h = $img_info[1];
$side = min($src_w, $src_h);
$off_x = ($src_w - $side) / 2;
$off_y = ($src_h - $side) / 2;

// 5. 循环生成 3 种尺寸并【物理覆盖】
$sizes = array('big' => 200, 'middle' => 120, 'small' => 48);
$final_check = array();

foreach ($sizes as $name => $px) {
    $target_file = $target_dir . $file_prefix . "_avatar_{$name}.jpg";
    
    $new_img = imagecreatetruecolor($px, $px);
    // 填充白色背景（防止透明图变黑）
    imagefill($new_img, 0, 0, imagecolorallocate($new_img, 255, 255, 255));
    
    // 采样缩放
    imagecopyresampled($new_img, $src_img, 0, 0, $off_x, $off_y, $px, $px, $side, $side);
    
    // 【核武步骤】先删除旧的，再写新的
    if(file_exists($target_file)) @unlink($target_file);
    
    // 写入
    $res = imagejpeg($new_img, $target_file, 90);
    imagedestroy($new_img);
    @chmod($target_file, 0777);
    
    $final_check[$name] = $res ? 'SUCCESS' : 'FAILED';
}

imagedestroy($src_img);

// 6. 强制更新数据库标志位 (第一次上传头像必改)
DB::query("UPDATE ".DB::table('common_member')." SET avatarstatus=1 WHERE uid=%d", array($uid));

// 7. 返回带有物理证据的数据
api_return(0, 'Avatar Overwritten', array(
    'uid' => $uid,
    'check' => $final_check,
    'mtime' => date('Y-m-d H:i:s', filemtime($target_dir . $file_prefix . "_avatar_middle.jpg")),
    'url' => "https://www.newbabyworld.top/uc_server/avatar.php?uid={$uid}&size=middle&random=" . time()
));