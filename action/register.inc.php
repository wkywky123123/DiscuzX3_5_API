<?php
/**
 * 模块：注册接口
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    $email = isset($_REQUEST['email']) ? trim($_REQUEST['email']) : '';
    $invitecode = isset($_REQUEST['invitecode']) ? trim($_REQUEST['invitecode']) : ''; 

    // 0. 加载后台配置与语言包
    loadcache('setting');
    $setting = $_G['setting'];
    $regstatus = $setting['regstatus'];
    $regname = !empty($setting['regname']) ? $setting['regname'] : 'register';

    // 1. 检查注册总开关
    if($regstatus == 0) api_return(-1, 'Registration is currently closed');

    // 2. 基础格式校验
    if(empty($email) || !preg_match("/^[\w\-\.]+@[\w\-\.]+(\.\w+)+$/", $email)) {
        api_return(-3, 'Invalid email format');
    }

    // 3. 【安全防护】检查 IP 注册权限 (防止单 IP 暴力刷信)
    // 检查 ipregctrl (多少小时内同一 IP 不允许重复注册)
    if($setting['ipregctrl']) {
        foreach(explode("\n", $setting['ipregctrl']) as $ctrl_ip) {
            if(preg_match("/^(".preg_quote(trim($ctrl_ip), '/').")/", $_G['clientip'])) {
                api_return(-22, 'Your IP is in the registration blacklist');
            }
        }
    }
    // 检查 24 小时内同一 IP 注册量上限
    if($setting['ipmaxblogcount'] > 0) {
        $ip_count = DB::result_first("SELECT COUNT(*) FROM ".DB::table('common_member')." WHERE regip=%s AND regdate>%d", array($_G['clientip'], TIMESTAMP - 86400));
        if($ip_count >= $setting['ipmaxblogcount']) {
            api_return(-22, 'Too many registrations from your IP in 24 hours');
        }
    }

    // 4. 【安全防护】检查邮箱域名限制 (accessemail / censoremail)
    if($setting['accessemail']) {
        $matched = false;
        foreach(explode("\n", $setting['accessemail']) as $domain) {
            if(strpos($email, trim($domain)) !== false) { $matched = true; break; }
        }
        if(!$matched) api_return(-23, 'Only specific email domains are allowed to register');
    }
    if($setting['censoremail']) {
        foreach(explode("\n", $setting['censoremail']) as $domain) {
            if(strpos($email, trim($domain)) !== false) api_return(-23, 'This email domain is blocked');
        }
    }

    // 5. 检查邮箱唯一性
    $check_email = C::t('common_member')->fetch_by_email($email);
    if($check_email) api_return(-5, 'Email address already registered');

    // 6. 邀请码逻辑适配
    if($regstatus == 2 && empty($invitecode)) api_return(-3, 'Invitation code is mandatory');
    if(!empty($invitecode)) {
        $invite = DB::fetch_first("SELECT * FROM ".DB::table('common_invite')." WHERE code=%s AND status=0", array($invitecode));
        if(!$invite || ($invite['endtime'] > 0 && $invite['endtime'] < TIMESTAMP)) {
            api_return(-21, 'Invalid or expired invitation code');
        }
    }

    // 7. 生成官方加密 Hash 及链接
    require_once libfile('function/member');
    require_once libfile('function/mail');

    $now = TIMESTAMP;
    // Discuz! 官方加密算法：包含邮箱和当前时间戳
    $hash = authcode("$email\t$now", 'ENCODE', $_G['config']['security']['authkey']);
    
    // 完美拼装：mod地址 + Hash + email预填充
    $register_url = $_G['siteurl'].'member.php?mod='.$regname.'&hash='.urlencode($hash).'&email='.urlencode($email);
    if(!empty($invitecode)) $register_url .= '&invitecode='.urlencode($invitecode);

    // 8. 调用系统邮件引擎发送 (官方文案同步：3天有效期)
    $site_name = $setting['bbname'];
    $subject = "论坛注册地址";
    $message = "
<p>这封信是由 {$site_name} 发送的。</p>
<p></p>
<p>您收到这封邮件，是由于在 {$site_name} 获取了新用户注册地址使用了这个邮箱地址。 如果您并没有访问过 {$site_name}，或没有进行上述操作，请忽略这封邮件。您不需要退订或进行其他进一步的操作。</p>
<p></p>
<p></p>
<p>----------------------------------------------------------------------</p>
<p>新用户注册说明</p>
<p>----------------------------------------------------------------------</p>
<p>如果您是 {$site_name} 的新用户，或在修改您的注册 Email 时使用了本地址，我们需要对您的地址有效性进行验证以避免垃圾邮件或地址被滥用。</p>
<p>您只需点击下面的链接即可进行用户注册，以下链接有效期为3天。过期可以重新请求发送一封新的邮件验证：</p>
<br><a href=\"{$register_url}\">{$register_url}</a><br><br>
(如果上面不是链接形式，请将该地址手工粘贴到浏览器地址栏再访问)

<p>感谢您的访问，祝您使用愉快！</p>

<p>此致</p>
<p>{$site_name} 管理团队.</p>";

    if(sendmail($email, $subject, $message)) {
        api_return(0, 'Success: Registration link sent', array('email' => $email));
    } else {
        api_return(-11, 'SMTP send failed. Please check site mail settings.');
    }