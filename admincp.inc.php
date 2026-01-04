<?php

if(!defined('IN_DISCUZ') || !defined('IN_ADMINCP')) {
    exit('Access Denied');
}

$plugin_path = DISCUZ_ROOT.'./source/plugin/codeium_api/';

if(!submitcheck('submit')) {
    // 从插件设置中获取值
    $api_key = C::t('common_setting')->fetch('codeium_api_key');
    $api_status = C::t('common_setting')->fetch('codeium_api_status');
    $api_groupid = C::t('common_setting')->fetch('codeium_api_groupid');
    
    // 默认值设置
    $api_status = $api_status !== false ? $api_status : 1;
    $api_key = $api_key !== false ? $api_key : '';
    $api_groupid = $api_groupid !== false ? $api_groupid : 10; // 默认为普通会员组
    
    // 获取所有用户组
    $usergroups = array();
    $query = DB::query("SELECT groupid, grouptitle FROM ".DB::table('common_usergroup')." WHERE groupid NOT IN (1)");
    while($group = DB::fetch($query)) {
        $usergroups[$group['groupid']] = $group['grouptitle'];
    }
    
    showformheader('plugins&operation=config&do='.$pluginid.'&identifier=codeium_api&pmod=admincp');
    showtableheader('API接口设置');
    
    showsetting('API状态', 'api_status', $api_status, 'radio', '', '', '是否启用API服务');
    showsetting('API密钥', 'api_key', $api_key, 'text', '', '', '设置API访问密钥，用于验证API调用的合法性');
    
    $groupselect = array();
    foreach($usergroups as $gid => $gtitle) {
        $groupselect[] = array($gid, $gtitle);
    }
    showsetting('注册用户组', array('api_groupid', $groupselect), $api_groupid, 'select', '', '', '设置通过API注册的用户默认用户组');
    
    showsubmit('submit', '保存');
    showtablefooter();
    showformfooter();
    
    echo '<br><br>';
    showtableheader('API接口说明');
    showtablerow('', array(''), array('
        <b>API调用示例：</b><br>
        登录接口：<br>
        http://你的域名/plugin.php?id=codeium_api:api&action=login&username=用户名&password=密码&key=你的API密钥<br><br>
        注册接口：<br>
        http://你的域名/plugin.php?id=codeium_api:api&action=register&username=用户名&password=密码&email=邮箱&key=你的API密钥<br><br>
        <b>返回格式说明：</b><br>
        所有接口均返回JSON格式数据，包含以下字段：<br>
        - code: 状态码，0表示成功，负数表示错误<br>
        - message: 状态信息<br>
        - data: 返回的数据，失败时为null<br><br>
        <b>错误码说明：</b><br>
        -1: API服务已禁用<br>
        -2: 无效的API密钥<br>
        -3: 缺少必要参数<br>
        -4: 登录失败<br>
        -5: 注册失败<br>
        -6: 未知的操作类型
    '));
    showtablefooter();
    
} else {
    // 保存设置
    $api_key = trim($_GET['api_key']);
    $api_status = intval($_GET['api_status']);
    $api_groupid = intval($_GET['api_groupid']);
    
    // 直接保存到设置表
    C::t('common_setting')->update('codeium_api_key', $api_key);
    C::t('common_setting')->update('codeium_api_status', $api_status);
    C::t('common_setting')->update('codeium_api_groupid', $api_groupid);
    
    // 更新缓存
    updatecache('setting');
    
    cpmsg('设置更新成功', 'action=plugins&operation=config&do='.$pluginid.'&identifier=codeium_api&pmod=admincp', 'succeed');
}
    		  	  		  	  		     	  	 			    		   		     		       	   	 		    		   		     		       	   	 		    		   		     		       	   				    		   		     		       	   		      		   		     		       	   	 	    		   		     		       	 	        		   		     		       	 	        		   		     		       	   	       		   		     		       	   	       		   		     		       	   	       		   		     		       	 	   	    		   		     		       	  	   	    		   		     		       	  		 	     		   		     		       	   	       		   		     		       	  			 	    		   		     		       	  				     		   		     		       	  			 	    		   		     		       	    		     		   		     		       	   	       		   		     		       	  	        		   		     		       	   		 	    		   		     		       	  	  		    		   		     		       	  		 		    		   		     		       	 	   	    		   		     		       	   	 		    		   		     		       	  	        		   		     		       	   				    		   		     		       	 	        		 	      	  		  	  		     	
?>