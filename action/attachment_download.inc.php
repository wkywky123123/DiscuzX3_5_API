<?php
/**
 * 模块：附件下载鉴权
 */
if(!defined('IN_DISCUZ')) exit('Access Denied');

    $aid = isset($_REQUEST['aid']) ? intval($_REQUEST['aid']) : 0;
    $uid = isset($_REQUEST['uid']) ? intval($_REQUEST['uid']) : 0;
    // confirm_pay=1 表示用户已在 App 端点击“确认支付”弹窗
    $confirm_pay = isset($_REQUEST['confirm_pay']) ? intval($_REQUEST['confirm_pay']) : 0;

    if (!$aid || !$uid) api_return(-3, 'Missing aid or uid');

    // 1. 获取附件信息
    $att_idx = C::t('forum_attachment')->fetch($aid);
    if (!$att_idx) api_return(-8, 'Attachment not found');
    $attach = C::t('forum_attachment_n')->fetch($att_idx['tableid'], $aid);
    if (!$attach) api_return(-8, 'Attachment data error');

    // 2. 基础下载权限检查
    $member = C::t('common_member')->fetch($uid);
    $usergroup = C::t('common_usergroup_field')->fetch($member['groupid']);
    if (!$usergroup['allowgetattach']) {
        api_return(-9, 'Your usergroup is not allowed to download attachments');
    }

    // 3. 计费逻辑判定
    $price = intval($attach['price']);
    $authorid = $attach['authorid'];
    $need_pay = false;

    // 只有附件有价格、且下载者不是作者本人时，才需要走支付流程
    if ($price > 0 && $uid != $authorid) {
        // 检查是否已经购买过 (forum_attachmentbuy)
        $has_bought = DB::result_first("SELECT count(*) FROM " . DB::table('forum_attachmentbuy') . " WHERE aid=%d AND uid=%d", array($aid, $uid));
        if (!$has_bought) {
            $need_pay = true;
        }
    }

    // 4. 处理支付
    if ($need_pay) {
        // 获取系统交易积分类型 (通常是 extcredits2)
        loadcache('setting');
        $credit_id = $_G['setting']['creditstrans']; 
        $credit_field = 'extcredits' . $credit_id;
        $member_count = C::t('common_member_count')->fetch($uid);
        
        // 检查余额
        if ($member_count[$credit_field] < $price) {
            api_return(-20, 'Insufficient credits', array('price' => $price, 'credit_id' => $credit_id));
        }

        // 如果用户还没确认支付，先返回价格信息，让 App 弹窗
        if (!$confirm_pay) {
            api_return(1, 'Need confirmation', array(
                'price' => $price,
                'credit_name' => $_G['setting']['extcredits'][$credit_id]['title'],
                'credit_unit' => $_G['setting']['extcredits'][$credit_id]['unit']
            ));
        }

        // 执行扣费与记录
        require_once libfile('function/post');
        // 扣除下载者积分
        updatemembercount($uid, array($credit_id => -$price), true, 'BAC', $aid);
        // 插入购买记录
        DB::insert('forum_attachmentbuy', array(
            'aid' => $aid, 'uid' => $uid, 'authorid' => $authorid, 'dateline' => TIMESTAMP, 'price' => $price
        ));
        // (可选) 给作者分成逻辑可以在这里根据后台设置补全
    }

    // 5. 生成授权下载链接 (aidencode)
    require_once libfile('function/forum');
    $download_token = aidencode($aid, 0, $attach['tid']);
    $download_url = $_G['siteurl'] . 'forum.php?mod=attachment&aid=' . $download_token;

    api_return(0, 'Success', array(
        'aid' => $aid,
        'filename' => $attach['filename'],
        'is_paid' => $need_pay ? 1 : 0, // 告知 App 刚才是否发生了扣费
        'download_url' => $download_url
    ));