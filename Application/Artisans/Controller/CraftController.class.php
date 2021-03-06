<?php
namespace Artisans\Controller;
class CraftController extends CommonController {
      private $_user_api_reg     = '/UserCenterApi/regUser';    //用户中心api
      private $_create_order_api = '/OrderApi/createOrder';     //订单中心api【创建订单】
      private $_get_order_api    = '/OrderApi/getOneOrder';     //单个订单信息
      private $_get_more_order   = '/OrderApi/getMoreOrder';    //多个订单信息
      private $_cancle_order     = '/OrderApi/updateOrder';     //取消订单
      private $_order_step       = '/OrderApi/updateOrderStep'; //订单关键步骤
      private $_daogou           = '/OrderApi/getDaogou';       //客服导购产品信息
      private $_product_api      = '/Api/getProductInfo';       //产品详情信息api
      private $_product_list_api = '/Api/getProductList';       //产品列表
      private $_Product_step     = '/Api/getProductStep';       //产品关键步骤
      private $_get_craft_info   = '/Api/getCraftsManInfo';     //XXX信息api
      
      private $_get_param        = array();                     //get请求传递的参数
      private $_openid           = '';                          //用户openid
      private $_usercenter_uid   = 0;                           //用户中心uid
      private $_city_id          = 0;                           //当前的城市id
      private $_ip               = '';
      
      private $_wxpay_action       = array('selectcard', 'simplepay', 'expertservice');   //用到微信支付的action
      private $_clean_session      = array('submitUserinfo');                             //清空session('auth_get_param')的action
      private $_pay_offline_cityid = array(1, 73, 198);                                   //支持线下支付的城市Id, 【北上广】
      private $_order_window_status= array(100, 200, 300, 400);                           //100跳转支付成功页， 200关闭窗口。
      private $_city_list          = array('北京市'=>1,'上海市'=>73,'广州市'=>198,'天津市'=>2,'深圳市'=>200,'济南市'=>135,'成都市'=>237,'西安市'=>290,'杭州市'=>87,'南京市'=>74,'武汉市'=>170);   //开通的城市
      
      //获取用户openid
      public function _initialize() {
          /*if(in_array(ACTION_NAME, $this->_clean_session)) {
              //清空提交页session('auth_get_param')的值
              session('auth_get_param', null);
          }*/
      }
      
      protected function checkAuthGetParam() {
          $get = I("get.");
          foreach($get as $key=>$value) {
              $getinfo .= $key.'='.$value.'**';
          }
          if(I("code")) {
              $code = I("code");
              $shop = D("WeiXinApi");
              $userinfo = $shop->getOAuthAccessToken($code);
              $openid = $userinfo["openid"];
              if(!$openid) {
                  wlog('/share/weixinLog/artisans/user_center_api/no_find_openid.log', $userinfo);    //auth验证没有获取到openid
              }
          }else{
              if(in_array(strtolower(ACTION_NAME), $this->_wxpay_action)) {
                  $openid = $this->reGetOAuthDebug(C("WeixinPayUrl").ACTION_NAME.'?getinfo='.$getinfo);
              }else{
                  $openid = $this->reGetOAuthDebug(U('Craft/'.ACTION_NAME).'?getinfo='.$getinfo);
              }
          }
          if($openid) {
              $third_name = M('cut_uid_thirdname')->where(" ThirdName='%s' ", $openid)->getField('UserId');
              if($third_name) {
                  $openid_user_id = $third_name;
              }else{
                  $base_info_s    = array(
                      'source_from'=>'weixin',
                      'user_type'=>'other',
                      'type'=>200,
                      'username'=>$openid
                  );
                  $user_center_info = send_curl(C("ArtisansApi")).$this->_user_api_reg, $base_info_s);
                  $parse_data       = json_decode($user_center_info, true);
                  if(!$parse_data['data']['uid']) {
                      $parse_data['data']['uid']  = 0;
                      wlog('/share/weixinLog/artisans/user_center_api/no_find_uid.log', $openid);     //调用用户中心接口， 获取不到用户openid
                      wlog('/share/weixinLog/artisans/user_center_api/no_find_uid.log', $parse_data);
                  }else{
                      $third_data['ThirdName']	= $openid;
            					$third_data['UserId']		= $parse_data['data']['uid'];
            					$third_data['CreaterTime']	= date('Y-m-d H:i:s');
            					
            					M('cut_uid_thirdname')->add($third_data);
                  }
                  $openid_user_id = $parse_data['data']['uid'];
              }
          }
          $this->_openid         = $openid;
          $this->_usercenter_uid = $openid_user_id;
          $this->_ip             = get_client_ip();
          if(C('ProductStatus') === false) {
              $this->_get_param = I('get.');
          }else{
              $getinfo    = $get['getinfo'];
            //$getinfo    = urldecode($getinfo);
              $this->_get_param  = array();
              foreach($get_param_s as $key=>$value) {
                   if($value) {
                      $tmp = array();
                      $tmp = explode('=', $value);
                      $this->_get_param[$tmp[0]] = $tmp[1];
                   }
              }
          }
          
          $this->assign('getData', $this->_get_param);
          $this->count_source($this->_get_param);
          
          //城市配对
          $this->assign('cityList', json_encode($this->_city_list));
          
          $cityinfo = M('cut_customer_city')->where(array('Uid'=>$this->_usercenter_uid))->find()['cityid'];
          if($cityinfo) {
              $this->_city_id = $cityinfo;
          }else{
              $this->_city_id = $this->_get_param['city_id'];
          }
          //默认为北京
          if(empty($this->_city_id)) {
              $this->_city_id = 1;
          }
          
          $this->assign('uid', $this->_usercenter_uid);
          $this->assign('openid', $this->_openid);
          $this->assign('city_id', $this->_city_id);
          $this->assign('city', array_serach($this->_city_id, $this->_city_list));
      }
      
      //用户提交信息
      public function submitUserinfo() {
          $this->checkAuthGetParam();
          $openid = $this->_openid;
          
          //测试数据
          /*session('auth_cityid_craft',1);
      		$this->_get_param['craft_id']	= 6;
      		$this->_get_param['pro_id']	= 1;
      		$this->_get_param['order_date']	= '2015-05-03';
      		$this->_get_param['order_time_id']	= 1;
      		$this->_get_param['for_who']	= 200;
      		$this->_get_param['lat']	= '39.74629731';
      		$this->_get_param['lng']	= '116.32575479';
      		$this->_get_param['pay_process']	= 4;
      		if (empty($this->_get_param['city_id'])) {
      		    $cityinfo  = M('cut_customer_city')->where(array('Uid'=>$this->_usercenter_uid))->find()['Cityid'];
      			  $this->_get_param['city_id']  =  $cityinfo;
      			  unset($cityinfo);
      		}*/
      		
      		$this->assign('openid', $openid);
      		$this->assign('get_param', $this->_get_param);
      		$this->assign('pageHome', 'craftSubmitOrder');
      		$this->assign('pagename', 'XXXXX-提交订单页');
      		
      		$this->display('qcs_sbmit_order');
      }
      
      public function cSubinfo() {
                $post_data	          = I('post.');
                $data['ProductId']	  = $post_data['pro_id'];
      		$data['CraftsmanId']	  = $post_data['craft_id'];
      		$data['UserOpenid']	  = $post_data['openid'];
      		$data['Name']	          = $post_data['name'];
      		$data['CityName']	  = $post_data['city_id'];
      		$data['Address']	  = $post_data['address'];
      		$data['Phone']	          = $post_data['phone'];
      		$data['OrderDate']	  = $post_data['order_date'];
      		$data['OrderTimeId']	  = $post_data['order_time_id'];
      		$data['ForWho']	          = $post_data['for_who'];
      		$data['ShortMessage']	  = $post_data['wish'];
      		$data['Lat']	          = $post_data['lat'];
      		$data['Lng']	          = $post_data['lng'];
      		$data['Codeid']	          = $post_data['codeid'];
      		$data['Cardid']	          = $post_data['cardid'];
      		$data['PayProcess']	  = $post_data['pay_process'];
      		$data['CreaterTime']	  = date('Y-m-d H:i:s');
      		
      		$id	= M('ord_submit_info')->add($data);
      		if($id) {
      		    return json_encode(array('status'=>200));
      		}else{
      		    return json_encode(array('status'=>300));
      		}
      }
      
      public function selectCard() {
                $this->checkAuthGetParam();
                
                $this->_get_param['openid']          = $openid          = $this->_openid;
                $this->_get_param['uid']             = $uid             = $this->_usercenter_uid;
                
                $info = M('ord_submit_info')->where(" UserOpenid='%s'", $openid)->order("InfoId desc")->find();
                
                $this->_get_param['city_id']         = $city_id         = $info['CityName'];
                $this->_get_param['pro_id']          = $pro_id          = $info['ProductId'];          //产品id
                $this->_get_param['craft_id']        = $craft_id        = $this->_get_param['crt_id']; //XXXid
                $this->_get_param['address']	     = $info['Address'].'('.$info['AddressInfo'].')';
                $this->_get_param['address_s']	     = $info['Address'];
		$this->_get_param['address_info']    = $info['AddressInfo'];
		$this->_get_param['lat']	     = $lat		= $info['Lat'];
		$this->_get_param['lng']	     = $lng		= $info['Lng'];
		$this->_get_param['order_data']      = $info['OrderDate'];                           //预约日期
		$this->_get_param['order_time_id']   = $info['OrderTimeId'];                         //时间Id
		$this->_get_param['for_who']         = $info['ForWho'];                              //100为自己，200为朋友
		$this->_get_param['wish']            = $info['ShortMessage'];
		$this->_get_param['name']            = $info['Name'];
		$this->_get_param['phone']           = $info['Phone'];
		
		$this->_get_param['crt_name']       = M('crt_craftsmaninfo')->where(array('CraftsmanId'=>$this->_get_param['crt_id']))->getField('TrueName');
		if(!$this->_get_param['pay_process']) {
		      $this->_get_param['pay_process'] = $info['PayProcess'];
		}
		
		//用户选择的卡券code和卡券Id
		$codeid 	= $this->_get_param['codeid'];
		$cardid 	= $this->_get_param['cardid'];
		
		$this->_get_param['time'] = M('prd_servicetime')->where(array('TimeId'=>$this->_get_param['order_time_id']))->getField('StartTime');
		
		//api接口
		$transfer_data = array(
		      'PlatformId'=>0,
		      'CityId'=>$city_id,
		      'ProductId'=>$pro_id
		);
		$product_info = A('Api')->getProductInfo($transfer_data);
		$this->_get_param['prd_name'] = $product_info['data']['title'];
		if($product_info['data']['promotion']) {
		      $price = $product_info['data']['promotion']['endPrice'];
		}else{
		      $price = $product_info['data']['Price'];
		}
		$price = $price ? $price : 120;
		
		//支付需要的参数
		$conf['appid']      =  C("APPID");
		$conf['partnerkey'] =  C("PARTNERKEY");
		$conf['partnerid']  =  C("PARTNERID");
		$conf['appkey']     =  C("PAYSIGNKEY");
		
		$assign_data['select_redbag_url'] = $select_redbag_url = U('Redbag/select_redbag');   //选择卡券页
		$assign_data['price']	          = $price;
		
		$craft_param = array(
		      'CraftsmanId'=>$craft_id,
                          'lat'=>$lat,
                          'lng'=>$lng
		);
		
		$craft_info	= A('Api')->getCraftsManInfo($craft_param);
		
		$trave_price = ceil($craft_info['data']['Distance'])*2;     //上门费  价格=公里数*2
		$trave_price = $trave_price>0? $trave_price:0;
		if($trave_price>80 || empty($craft_id)) {	                  //上门费超过80元 或者 XXXid为空
			$trave_price	= 80;
		}
		$assign_data['trave_price'] = $trave_price;
		
		//用户卡券信息
		$card_info_data['cardid']  = $cardid;
		$card_info_data['codeid']  = $codeid;
		$card_info_data['openid']  = $openid;
		
		$pay_api_model = D('PayApi');
		$redbag_data	= $pay_api_model->getUserCardinfo($card_info_data);
		
		$assign_data['redbag_data']	= json_encode($redbag_data);
		$assign_data['openid']		= $openid;
		
		//线上线下支付
		if(in_array($this->_city_id, $this->_pay_offline_cityid)) {
			$assign_data['is_below_line']	= 1;
        	}else{
			$assign_data['is_below_line']	= 0;
		}
		
		//优惠券信息
		$coupons_info	= array(
		        'source'=>1,
		        'phone' =>$this->_get_param['phone'],
		        'pro_id'=>$pro_id
		);
		$activity_data	= $pay_api_model->getUserCouponsinfo($coupons_info);
		$assign_data['activity_data']	= json_encode($activity_data);
		
		$this->assign('pageHome', 'craftSelectCard');
		$this->assign('pagename','XXXXX-支付页');
		$this->assign('assign_data', $assign_data);
		$this->assign('get_param', $this->_get_param);         //get参数
		$this->assign('order_window_status', 100);
		$this->assign('select_redbag_url', $select_redbag_url);
		$this->assign('conf', $conf);
		
		$this->display('qcs_pay_order');
      }
      
      //创建订单
      public function createOrderinfo() {
      		$postData 	= I('post.');
      		$openid	  	= $postData['order_openid'];
      		$uid		= $postData['order_uid'];
		$city_id	= $postData['order_city_id'];
		$ip		= get_client_ip();
		if(!($openid && $uid)) {
			return json_encode(array('status'=>100, 'message'=>'提交失败'));
		}
		$order_data['user_id']		= $uid;
		$order_data['pro_id']		= $pro_id 	= $postData['order_pro_id'];   //产品id
		$order_data['craft_id']		= $craft_id	= $postData['order_craft_id']; //XXXid
		$order_data['address']		= $address	= $postData['order_address'];
		$order_data['lat']		= $lat 		= $postData['order_lat'];
		$order_data['lng']		= $lng 		= $postData['order_lng'];
		$order_data['order_date']	= $order_date   = $postData['order_date']; 	//预约日期
		$order_data['order_time_id']	= $order_time_id= $postData['order_time_id']; 	//时间Id
		$order_data['for_who']		= $for_who	= $postData['order_for_who'];   //100为自己，200为朋友
		$order_data['wish']		= $wish		= $postData['order_wish'];
		$order_data['name']		= $name		= $postData['order_name'];
		$order_data['phone']		= $phone	= $postData['order_phone'];
		$order_data['city_id']		= $city_id;
		
		//用户选择的卡券code和卡券Id
		$order_data['order_codeid']	= $codeid	= $postData['order_codeid'];
		$order_data['order_cardid']	= $cardid	= $postData['order_cardid'];
		$order_data['openid']		= $openid;
		
		//array(1=>'线下支付',2=>'微信系统微信支付',3=>'vmall微信支付',4=>'vmall支付宝支付');
		$order_data['pay_way']		= $pay_way	= $postData['order_pay_way']==2?2:1;
		$order_data['source_from']	= 100;		//订单来源【可选值100微信，200web,300安卓，400ios】
		$order_data['pay_process']	= $postData['order_pay_process'];	//支付流程：1正常支付，2客服引导，3客服专家在线，4.距离大于40公里支付
		$order_data['package_id']	= '';
		$order_data['address_id']	= 0;
		$order_data['index_source']	= session('source');	//首页来源
		$order_dtaa['coupons_id']	= $postData['order_activity'];	//优惠券Id
		
		$create_info = A('OrderApi')->createOrder2($order_data);
		if($create_info['code'] == 200 && $create_info['data']) {
			return json_encode(array('status'=>200, 'data'=>$create_info['data']));
		}else{
			return json_encode(array('status'=>0, 'message'=>$create_info['message']));
		}
      }
      
      public function returnJsonData($num, $data) {
      		$hash = array();
      		$txt_array = array(
      			200=>'success',
      			300=>'参数不正确',
      			500=>'修改数据库失败',
	              10000=>'写入数据失败',
	              10001=>'没有访问权限'
      		);
      		$hash['status'] = $num;
      		$hash['msg']	= $txt_array[$num];
      		if(isset($data)) {
      			$hash['data'] = $data;
      		}
      		echo json_encode($hash);
      		exit();
      }
      
      public function successPay() {
      	     $this->checkAuthGetParam();
      	     $order_id       = $this->_get_param['order_id'];
      	     $uid            = $this->_usercenter_uid;
      	     $openid         = $this->_openid;
      	     $get_order_data = array('order_id'=>$order_id);
      	     $order_info_s   = A('OrderApi')->getOneOrder($get_order_data);
      	     $order_info     = $order_info_s['data']; 
      	     
      	     // 0 未支付，100支付失败，200取消订单，300已支付，400已服务，500已点评，600申请退款
      	     $order_info['status_txt'] = $this->_getOrderStatus($order_info);
      	     $jump_url = 'http://'.$_SERVER['HTTP_HOST'].U('Craft/qcsstatus2').'?ordernum='.$order_id;
      	     $assign_data['jump_url']	= $jump_url;
	     $assign_data['openid']	= $openid;
	     
	     $this->assign('assign_data',$assign_data);
	     $this->assign('orderinfo',$order_info);
	     $this->assign('pagename','XXXXX-支付成功页');
	     
	     $this->display('qcs_order');
      }
      
      //查看产品关键步骤
      public function qcsstatus2() {
      	     $this->checkAuthGetParam();
      	     $order_id = $this->_get_param['ordernum'];
      	     $openid   = $this->_openid;
      	     $get_order_data = array('order_id'=>$order_id);
      	     $order_info_s = A('OrderApi')->getOneOrder($get_order_data);
      	     $order_info   = $order_info_s['data'];
      	     $order_info['status_txt'] = $this->_getOrderStatus($order_info);
      	     $uid = $order_info['user_id'];
      	     if($order_info['openid'] == $openid) {
      	     	      $user_role = 'user';	//用户
      	     }else{
      	     	      $user_role = 'craft';	//XXX
      	     }
      	     
      	     //获取产品关键步骤
      	     $get_step_data = array('pro_id'=>$order_info['pro_id'], 'order_id'=>$order_id);
      	     $step_info     = A('Api')->getProductStep($get_step_data);
      	     $order_step    = $step_info['data']['order_step'] ? $step_info['data']['order_step'] : array();
      	     $pro_step	    = $step_info['data']['pro_step'] ? $step_info['data']['pro_step'] : array();
      	     foreach($order_step as $value) {
      	     	     $order_step_s[$value['StepId']] = $value;
      	     }
      	     foreach($pro_step as &$val) {
      	     	     if($order_step_s[$val['step_id']['State']==='0') {
      	     	     	              $val['status'] = 100;
      	     	     	              $val['create_time'] = $order_step_s[$val['step_id']]['CreaterTime'];
      	     	     }elseif($order_step_s[$val['step_id']]['State']==='1') {
      	     	     		      $val['status'] = 200;
      	     	     		      $val['create_time'] = $order_step_s[$val['step_id']]['CreaterTime'];
      	     	     }else{
      	     	     		      $val['status'] = 300;
      	     	     		      $val['create_time'] = '';
      	     	     }
      	     }
      	     
      	     //取消预约按钮显示状态：
      	     //I.订单状态
      	     //II.关键步骤一条记录没有
      	     if(($order_info['status'] == 300 || ($order_info['pay_way'] == 1 && $order_info['status'] == 0)) && count($order_step) == 0 && $user_role == 'user'){
      	     	 $cancle_status	= 'yes';
      	     }else{
      	     	 $cancle_status	= 'no';
      	     }
      	     if($order_info['status'] == 200) {
      	     	 $user_role	= 'craft';
      	     }
      	     
      	     $this->assign('user_role',$user_role);//用户角色
	     $this->assign('cancle_status',$cancle_status);	//取消按钮
	     $this->assign('pro_step',$pro_step); //产品步骤
	     $this->assign('openid',$openid);
	     $this->assign('order_info',$order_info); //订单消息
	     $this->assign('uid',$uid); //订单消息
	     $this->assign('pagename','XXXXX-更新状态页');
	     
	     $this->display('qcs_status2');
      }
      
      //取消订单
      public function cancel_ajax() {
      	     $post_data	= I('post.');
	     $order_id	= $post_data['id'];
	     $uid	= $post_data['uid'];
	     if(!$uid) {
	     	  return json_encode(array('status'=>300));
	     }
	     $cancel_data = array(
	     	'update_type'=>100,
	     	'order_id'=>$order_id,
	     	'uid'=>$uid
	     );
	     $get_cancel_data = A('OrderApi')->updateOrder($cancel_data);
	     if($get_cancel_data['code'] == 200 && $get_cancel_data['code']['data']) {
	     	     return json_encode(array('status'=>200));
	     }else{
	     	     return json_encode(array('status'=>800));
	     }
      }
      
      //订单状态
      private function _getOrderStatus($param) {
      	        $status	 = $param['status'];
		$pay_way = $param['pay_way'];
		switch($status) {
			case 0:
				if($pay_way == 1) {
					$status	= '未支付(线下支付)';
				}else{
					$status	= '待支付';
				}
				break;
			case 100:
				$status	= '支付失败';
				break;
			case 200:
				$status	= '订单取消';
				break;
			case 300:
				$status	= '已支付-等待联系';
				break;
			case 400:
				$status	= '已服务';
				break;
			case 500:
				$status	= '已点评';
				break;
			case 600:
				$status	= '申请退款';
				break;
			default:
				$status	= '待支付';
		}
		return $status;
      }
      
      public function count_source($source = null) {
      	     $string = '';
      	     foreach($source as $key=>$vale) {
      	     	     $string .= '/'.$key.'='.$value;
      	     }
      	     if($source['source']) {
      	     	     session('source', $source['source']);
      	     }
      	     if(session('source')) {
	      	     	$source=array(
	                'source_value'=>session('source'),
	                'source_link'=>D('Api')->clear_urlcan($_SERVER['HTTP_REFERER']),
	                'ip'=>$_SERVER['REMOTE_ADDR'],
	                'udate'=>time(),
	                'page'=>ACTION_NAME,
	                'type'=>'weixin',
	                'usrid'=>$this->_openid,
	                'now_url'=>$string,
	            );
	            M('mnitor_source')->add($source);
      	     }
      }
      
      //引导页
      public function guide() {
      	     $this->checkAuthGetParam();
      	     $uid = $this->_usercenter_uid;
      	     if($uid) {
      	     	$status = M('mnitor_visit_log')->where(array('Uid'=>$uid))->find();
      	     	if(!$status) {
      	     		$add['time'] = date('Y-m-d H:i:s');
      	     		$add['Uid']  = $uid;
      	     		M('mnitor_visti_log')->add($add);
      	     	}
      	     }
      	     
      	     $this->display('qcs_guide');
      }
      
      //首页
      public function index() {
      	     $this->checkAuthGetParam();
      	     $uid = $this->_usercenter_uid;
      	     if($uid) {
      	     	$status = M('mnitor_visit_log')->where(array('Uid'=>$uid))->find();
      	     	if(!$status) {
      	     		$add['time'] = date('Y-m-d H:i:s');
      	     		$add['Uid']  = $uid;
      	     		M('mnitor_visit_log')->add($add);
      	     		header('location:'.U('guide'));
      	     	}
      	     }
      	     
      	     $field['Name']='title';
    	     $field['Color']='color';
    	     $field['Icon']='ico';
    	     $field['Pos']='pos';
    	     $field['ClassId']='id';
    	     $classType = M('prd_class')->where(array('IsDelete'=>0, 'IsUse'=>1))->field($field)->order('OrderId asc')->select();
    	     
    	     $location = send_curl('http://localhost/'.C('TP_PROJECT_WEXIN').'/index.php/Api/getlocation', array('openid'=>$this->_openid));
    	     $location = json_decode($location,true); 
    	     
    	     $city = M('cut_customer_city')->where(array('Uid'=>$uid))->find()['Cityid'];
    	     if($city) {
    	     	$param['CityId'] = $city;
    	     }else{
    	     	$param['CityId'] = $this->_city_id == 0 ? 1 : $this->_city_id;	//城市id
    	     }
    	     
    	     $param['PlatformId']=0;   //微信的平台ID
             $param['Sorting']=1;      //排序方式 2为正序 1为倒叙
             $param['ClassType']=0;    //分类 0热门 1 电脑类 2手机类
             $param['limit']=6;        //分页
             $param['page']=1;         //分页
             
             $module=A('Api')->getProductList($param);
             
             $this->assign('classType', $classType);
             $this->assign('module',$module['data']);
             $this->assign('location',$location);
             $this->assign('pagename','XXXXX-首页');
             
             $this->display('qcs_index_new');
      }
      
      public function index_sub() {
      	     $this->checkAuthGetParam()
      	     $param['CityId'] = $this->_city_id == 0 ? 1 : $this->_city_id;	//城市ID
      	     $param['PlatformId'] = 0;	//微信的平台ID
      	     $param['Sorting']    = 1;  //排序方式 2为正序  1为倒序
      	     $param['ClassType']  = $this->_get_param['class'];	//分类
      	     $classinfo = M('prd_class')->find($this->_get_param['class']);
      	     $module = A('Api')->getProductList($param);
      	     
      	     $this->assign('status', $module['status']);
      	     $this->assign('module', $module['data']);
      	     $this->assign('info', $classinfo);
      	     $this->assign('openId', $this->_openid);
      	     $this->assign('pagename', 'XXXXX-子菜单');
      	     
      	     $this->display('qcs_index_sub');
      }
      
      public function proDetails() {
      	     $this->checkAuthGetParam();
      	     if ( I('get.Plat')=='app') {
	        $param['PlatformId']=2;   //app平台
	       	$param['CityId']=I('get.acity');       //城市ID
	       	$param['ProductId']=I('get.ProductId');       //城市ID
	     }else{
        	$param['PlatformId']=0;   //微信的平台ID
        	$param['CityId']=$this->_city_id;       //城市ID
        	$param['ProductId']=$this->_get_param['ProductId'];       //城市ID
	     }
	     $module =  A('Api')->getProductInfo($param);
	     
	     $this->assign('description',$module['data']);
             $this->assign('pagename','【'.$module['data']['title'].'】-产品详情页');
             
             $this->display('qcs_intro');
      }
      
      //列表页
      public function systemList() {
      	     $this->checkAuthGetParam();
      	     $getData      =   $this->_get_param;
      	     if($this->_city_id != $getData['city'] && in_array($getData['city'], $this->_city_list)) {
      	     	       $this->_city_id = $getData['city'];
      	     }
      	     $param['ProductId']= $getData['ProductId'];
             $param['Capacity'] = $getData['day'];
             $param['TimeId']   = $getData['time'];  //时间Id
             $param['City']     = $getData['city'];  //时间Id
             $param['lng']      = $getData['lng'];       //经纬度
             $param['lat']      = $getData['lat'];       //经纬度
             $param['page']     = 1;        //当前页
             $param['limit']    = 5;       //一页的数据量
             $param['Distance'] = 'asc';    //距离
             $param['goodRate'] = 'asc';
             $stemList = A('Api')->getCraftsManList($param);
             if($stemList['data'][0]['Distance']>40 || $stemList['data'] == null) {
             	         header("location:submitUserinfo.html?pro_id={$getData['ProductId]}&address={$getData['address]}&lat={$getData['lat']}&lng={$getData['lng']}&order_date={$getData['day']}&order_time_id={$getData['time']}&for_who={$getData['type']}&city_id={$param['city']}&pay_process=4");
             }
             
             $info['ProductId']  = $getData['ProductId'];
             $info['CityId']     = $getData['city'];
             $info['PlatformId'] = 0;
             $product = A('Api')->getProductInfo($info);
             $price   = $product['data']['Price'];
             
             $this->assign('price', $price);
             $this->assign('stemList', $stemList);
             $this->assign('get', $getData);
             $this->assign('pageHome', 'craftSystemList');
             $this->assign('pagename', 'XXXXX-列表页');
             
             $this->display('qcs_list_spa');
      }
      
      public function engineer() {
      	     $this->checkAuthGetParam();
      	     $info_id = $this->_get_param['info_id'];
      	     $info = M('ord_submit_info')->where(array('InfoId'=>$info_id))->find();
      	     $param['ProductId'] = $info['ProductId'];
      	     $param['Capacity']  = $info['OrderDate'];
      	     $param['TimeId']    = $info['OrderTimeId'];	//时间Id
      	     $param['City']      = $info['CityName'];
      	     $param['lng']	 = $info['Lng'];		//经度
      	     $param['lat']	 = $info['Lat'];		//纬度
      	     $param['Distance']  = 'asc';			//距离
      	     $param['goodRate']  = 'asc';
      	     $stemList = A('Api')->getCraftsManList($param);
      	     if($stemList['data'][0]['Distance']>40||$stemList['data'] == null) {
      	     	         header("location:selectCard.html?info_id={$getData['info_id']}&crt_id=9999&pay_process=4");
      	     }
      	     $info['ProductId']  = $info['ProductId'];
      	     $info['CityId']     = $info['CityName'];
      	     $info['PlatformId'] = 0;
      	     $product = A('Api')->getProductInfo($info);
      	     $price   = $product['data']['Price'];
      	     foreach($stemList['data'] as $k => $v) {
      	     	     $stemList['data'][$k]['proPrice'] = $price;
      	     }
      	     $stemList = json_encode($stemList);
      	     $this->assign('stemList', $stemList);
      	     $this->assign('get', $getData);
      	     $this->display('qcs_engineer');
      }
      
      //ajax列表页
      public function ajsystemList($pagedata=null) {
      	     $postData = I('post.');
      	     
      	     $param['Capacity']  = $postData['day'];
      	     $param['ProductId'] = $postData['product_id'];
      	     $param['TimeId']    = $postData['time'];		//时间Id
      	     $param['lat']	 = $postData['lat'];
      	     $param['lng'] 	 = $postData['lng'];
      	     $param['page']      = $postData['page'];
      	     $param['limit']     = $postData['limit'];
      	     $param['City']	 = $postData['city'];
      	     
      	     if($postData['type'] == 0) {
      	     	     //按距离 好评率
      	     	     $param['Distance']   = 'asc';	//距离
      	     	     $param['serviceNum'] = 'desc';
      	     	     $param['goodRate']   = 'desc';
      	     }elseif($postData['type'] == 1) {
      	     	     //按人气 好评率
      	     	     $param['Distance']   = 'asc';	//距离
      	     	     $param['serviceNum'] = 'desc';
      	     	     $param['goodRate']   = 'desc';
      	     }elseif($postData['type'] == 2) {
      	     	     $param['serviceNum'] = 'desc';
      	     	     $param['goodRate']   = 'desc';
      	     }elseif($postData['type'] == 3) {
      	     	     $param['Distance']   = 'asc';
      	     }
      	     $stemList = A('Api')->getCraftsManList($param);
      	     return json_encode($stemList);
      }
      
      public function systemDetail() {
      	     $this->checkAuthGetParam();
      	     $getData = $this->_get_param;
      	     
      	     $param['CraftsmanId'] = $getData['CraftsmanId'];
      	     $param['lng']	   = $getData['lng'];
      	     $param['lat']	   = $getData['lat'];
      	     $CraftsInfo = A('Api')->getCraftsManInfo($param);
      	     
      	     $prd_param['ProductId']  = $getData['ProductId'];
      	     $prd_param['CityId']     = $this->_city_id;
      	     $prd_param['PlatformId'] = 0;
      	     $productInfo = A('Api')->getProductInfo($prd_param);
      	     $date_arr = $this->showweek();
      	     
      	     $time_s = array('10:00'=>1, '11:00'=>14, '12:00'=>15, '13:00'=>16, '14:00'=>2, '15:00'=>17, '16:00'=>18, '17:00'=>19, '18:00'=>3, '19:00'=>20, '20:00'=>21, '21:00'=>22);
      	     $j      = 0;
      	     foreach($date_arr as $value) {
      	     	  if($value['date'] == $getData['day']) {
      	     	  	break;
      	     	  }
      	     	  $j++;
      	     }
      	     $i = 0;
      	     foreach($time_s as $v) {
      	     	if($v = $getData['time']) {
      	     		break;
      	     	}
      	     	$i++;
      	     }
      	     $CraftsInfo['data']['disPrice'] = ceil($CraftsInfo['data']['Distance']*2);
      	     
      	     $this->assign('index',$i);
 	     $this->assign('index_day',$j);
             $this->assign('date_arr',json_encode($date_arr));
             $this->assign('time_arr',json_encode($time_s));
             $this->assign('ProductInfo', $ProductInfo['data']);
             $this->assign('CraftsInfo', $CraftsInfo['data']);
             $this->assign('pageHome','craftDetail');
             $this->assign('pagename','XXXXX-XXX信息页');
            
             $this->display('qcs_detail');
      }
      
      public function new_dt() {
      	     $this->checkAuthGetParam();
      	     $param['PlatformId'] = 0;	//微信的平台ID
      	     $param['CityId']     = $this->_city_id;	//城市ID
      	     $param['ProductId']  = $this->_get_param['ProductId'];	//城市ID
      	     $ClassType = M('prd_product_platform_city')->where($param)->getField('ClassType');
      	     $module = A('Api')->getProductInfo($param);
      	     if($ClassType == 0) {
      	     	   $module['data']['BannerImgUrl']    = $module['data']['headImg'];
      	     	   $module['data']['BannerImgCdnUrl'] = $module['data']['headImg_cdn']; 
      	     }
      	     $date_arr = $this->showweek();
      	     $time_s = array('10:00'=>1, '11:00'=>14, '12:00'=>15, '13:00'=>16, '14:00'=>2, '15:00'=>17, '16:00'=>18, '17:00'=>19, '18:00'=>3, '19:00'=>20, '20:00'=>21, '21:00'=>22);
      	     
      	     $this->assign('date_arr',json_encode($date_arr));
             $this->assign('time_arr',json_encode($time_s));
             $this->assign('description',$module['data']);
             $this->assign('pagename','XXXXX-选时间页');
             
             $this->display('qcs_creat_order');
      }
      
      public function dt() {
      	     $this->checkAuthGetParam();
             $param['PlatformId']=0;   //微信的平台ID
             $param['CityId']=$this->_city_id;       //城市ID
             $param['ProductId']=$this->_get_param['ProductId'];       //城市ID
             
             $module=A('Api')->getProductInfo($param);
             $date_arr = $this->showweek();
             $time_s = array('10:00'=>1, '11:00'=>14, '12:00'=>15, '13:00'=>16, '14:00'=>2, '15:00'=>17, '16:00'=>18, '17:00'=>19, '18:00'=>3, '19:00'=>20, '20:00'=>21, '21:00'=>22);
             
             $this->assign('date_arr',json_encode($date_arr));
             $this->assign('time_arr',json_encode($time_s));
             $this->assign('description',$module['data']);
             $this->assign('pagename','XXXXX-选时间页');
             
             $this->display('qcs_dt');
      }
      
      //显示日期， 显示最近12天
      public function showweek() {
      	     $week_arr = array("日","一","二","三","四","五","六");
      	     for($i=0;$i<12;$i++) {
      	     	 $tmp_time = time+86400*$i;
      	     	 $tmp['date'] = date('Y-m-d', $tmp_time);
      	     	 $tmp['week'] = '周'.$week_arr[date('w', $tmp_time)];
      	     	 $date_arr[] = $tmp;
      	     }
      	     return $date_arr;
      }
      
      public function ajProductList() {
      	     $city = I('param.city');
      	     
      	     $param['PlatformId'] = 0;		//微信的平台ID
      	     $param['CityId']     = $city;	//城市ID
      	     $param['Sorting']    = 1;		//排序方式 2为正序 1为倒序
      	     $param['ClassType']  = 0;		//分类 0热门 1电脑类 2手机类
      	     $param['limit']      = 6;		//分页
      	     $param['page']	  = 1;		//分页
      	     
      	     $module = A('Api')->getProductList($param);
      	     if($module['status'] == 200) {
      	     	       foreach($module['data'] as $k=>$v) {
      	     	       	       $module['data'][$k]['detailHref'] = 'proDetail/ProductId/'.$module['data'][$k]['ProductId'];
      	     	       	       if($module['data'][$k]['prdtype'] == 1) {
      	     	       	       	         $module['data'][$k]['dtHref'] = 'proDetail/ProductId/'.$module['data'][$k]['ProductId'];
      	     	       	       }else{
      	     	       	       	         $module['data'][$k]['dtHref'] = 'new_dt/ProductId/'.$module['data'][$k]['ProductId'];
      	     	       	       }
      	     	       }
      	     }
      	     return json_encode($module);
      }
      
      public function setCity() {
      	     $city_id = I('post.city');
      	     $uid     = I('post.uid');
      	     if(in_array($city_id, $this->_city_list)) {
      	     	
      	     	//缓存或添加数据
      	          $data['Uid']    = $uid;
      	     	  $data['CityId'] = $city_id;
      	     	  $data['CrtaterTime'] = date('Y-m-d H:i:s');
      	     	  
      	     	  $res = M('cut_customer_city')->where(array('Uid'=>$data['Uid']))->find();
      	     	  if($res) {
      	     	  	$res = M('cut_customer_city')->where(array('Uid'=>$data['Uid']))->save($data);
      	     	  }else{
      	     	  	M('cut_customer_city')->add($data);
      	     	  }
      	     	  
      	     	  $return['res']   = $res;
      	     	  $return['data']  = $data;
      	     	  $return['status'] = 200;
      	     }else{
      	     	  $return['status'] = 300;
      	     }
      	     return json_encode($return);
      }
      
      public function capacityByCityProIdDate() {
      	     $postData = I('param.');
      	     $param['CityId']    = $postData['city_id'];
      	     $param['ProductId'] = $postData['product_id'];
      	     $param['Date']      = date('Y-m-d', strtotime($postData['date']));
      	     
      	     $data = A('Api')->capacityByCityProIdDate($param);
      	     $this->ajaxReturn($data);
      }
      
      public function capacityByuserIdDate() {
      	     $postData=I('post.');
    	     $param['CraftsmanId']=$postData['userId']; //XXXid
    	     $param['Date']=$postData['time'];   //日期
    	     $param['ProductId']=$postData['productId'];   //日期

             $data=A('Api')->capacityByCityProIdDate($param);
    	     json_return($data);
      }
      
      //前端异步监控提交接口
      public function FrontMonitor() {
      	     //usrid:_id, //用户标识
      	     //time:_t,   //点击时间
      	     //content:_v,  //点击内容
      	     //position:_p, //点击位置
      	     //zoon:_z,    //点击所属区域
      	     //page:_page, //点击所属页面
      	     //from:_from  //上一页面
      	     //type:weixin //微信平台
      	     $data = I('get.');
      	     $data['content'] = strip_tags(urldecode($data['content']));
      	     $data['from']    = strip_tags(urldecode(D('Api')->clear_urlcan($data['from'])));
      	     $data['ip']      = $_SERVER['REMOTE_ADDR'];
      	     $data['type']    = 'weixin';
      	     M('mnitor_pcmnitor')->add($data);
      }
      
      //操作关键步骤 ajax
      public function qcsstatusajax2() {
      	     $post_data = I('post.');
      	     $order_id  = $post_data['id'];
      	     $status    = $post_data['status'];	//关键步骤行为：‘yes' or ’no'
      	     $step_id   = $post_data['option']; //关键步骤id
      	     $openid    = $post_data['openid']; //openid
      	     $crafts_username = $post_data['username'];	//XXX用户名
      	     $nick_name       = $post_data['name'];	//用户姓名
      	     
      	     $order_step_data['order_id']    = $order_id;
      	     $order_step_data['step_id']     = $step_id;
      	     $order_step_data['step_status'] = $status == 'yes' ? 100:200;
      	     $order_step = A('OrderApi')->updateOrderStep($order_step_data);
      	     wlog('/share/weixinLog/artisans/user_center_api/send_dianping_'.date('ymd').'.log', $order_step);	//点评接口日志
      	     if($order_step['code'] == 200 && $order_step['data']['step_info']) {
      	     	    if($order_step['data']['status'] == 'yes') {
      	     	    		//推送点评 
      	     	    		$data	= array(
					"source"      => 800,
					"openid"      => $openid,
					"username"    => $craft_username,
					"create_time" => date('Y-m-d H:i:s'),
					"nick_name"   => $nick_name,
					"caseid"      => $order_id
				);
				wlog('/share/weixinLog/artisans/user_center_api/send_dianping_'.date('ymd').'.log', $data); 	//点评接口日志
				$this->adddianping($data);
				return json_encode(array('status'=>200, 'time'=>$order_step['data']['create_time']));
      	     	    }else{
      	     	    		return json_encode(array('status'=>0));
      	     	    }
      	     }
        }
        
        //附加点评数据
        public function adddianping($data) {
        	$add_dianping = C('postdianpingdata');
        	wlog('/share/weixinLog/artisans/user_center_api/send_dianping_'.date('ymd').'.log', $add_dianping);	//点评接口日志
        	$json = jsone_decode(send_curl($add_dianping, $data), true);
        	wlog('/share/weixinLog/aritsans/user_center_api/send_dianping_'.date('ymd').'.log', $json);
        	
        	//200：成功并发送点评消息
        	if($json['code']==200) {
        		$content = array(
        			'title'=>'点击为TA评价',
        			'description'=>'XXXXXXX为您提供的服务，您还满意吗？请为Ta进行点评',
        			'url'=>'http://localhost/valuation/index.php/tel/index.html?uid='.$data['openid'].'&time='.strtotime($data['create_time']),
        			'picurl'=>'http://localhost/weixin/link/dianping1.jpg'
        		);
        		$data['recordid'] = $json['msg'];
        		M('ord_record')->add($data);
        		$param['openid'] = $data['openid'];
        		$param['type']   = 'news';
        		$param['content']= $content;
        		$return_data = D('WeiXinApi')->sendWxMsg($param);
        		wlog('/share/weixinLog/artisans/user_center_api/send_dianping_'.date('ymd').'.log', $return_data);
        		return 200;
        	}else{
        		return $json['code'];
        	}
        }
        
        public function getEvaluationList() {
        	$postData = I('post.');
        	$param['CraftsmanId'] = $postData['userId'];	//XXXid
        	$param['page']        = $postData['page'];
        	$param['limit']       = $postData['limit'];
        	$data = A('Api')->getEvaluationList($param);
        	return json_encode($data);
        }
        
        public function simplePay() {
        	$this->checkAuthGetParam();
        	$p_type = $this->_get_param['p_type'];	//商品编号
        	$this->_get_param['openid'] = $this->_openid;
        	$this->_get_param['uid']    = $this->_usercenter_uid;
        	
        	//支付需要的参数
        	$conf['appid']      = C('APPID');
        	$conf['partnerkey'] = C('PARTNERKEY');
        	$conf['partnerid']  = C('PARTNERID');
        	$conf['appkey']     = C('PAYSIGNKEY');
        	
        	//商品价格
        	$daogou_data['p_type'] = $p_type;
        	$daogou_info           = A('OrderApi')->getDaogou($daogou_data);
        	$price  	       = $daogou_info['data']['shop_price'];
        	
        	$this->assign('conf', $conf);
        	$this->assign('price', $price);
        	$this->assign('get_param', $this->_get_param);
        	$this->assign('order_window_status',200);
        	$this->assign('pagename','XXXXX-客服支付页');
        	
        	$this->display('simple_pay');
        }
        
        //客服导购创建订单
        public function cCreateOrder() {
        	$post_data	= I('post.');
	    	$openid		= $post_data['openid'];
	    	$uid		= $post_data['uid'];
	    	$p_type		= $post_data['p_type'];
	    	$p_from		= $post_data['p_from'];
	    	
	    	if(!($openid && $uid)) {
	    		return json_encode(array('status'=>100, 'message'=>'提交失败'));
	    	}
	    	
	    	$data['pay_process']	= 2; //客服导购
	    	$data['pay_way']	= 2; //微信系统微信支付
	    	$data['user_id']	= $uid;
	    	$data['openid']		= $openid;
	    	$data['package_id']	= 0;
	    	$data['ip']		= get_client_ip();
	    	$data['for_who']	= 100;
	    	$data['enginner_name']	= $p_from;
	    	$data['pro_id']		= $p_type;
	    	$data['city_id']	= 1;
	    	$data['source_from']	= 100;
	    	$data['index_source']	= session('source');
	    	
	    	$create_info = A('OrderApi')->createOrder($data);
	    	
	    	if($create_info['code'] == 200 && $create_info['data']) {
	    		return json_encode(array('status'=>200, 'data'=>$create_info['data']));
	    	}else{
	    		return json_encode(array('status'=>0, 'message'=>$create_info['message']));
	    	}
        }
        
        //远程服务
        public function expertService() {
        	$this->checkAuthGetParam();
        	$pro_id  = $this->_get_param['pro_id'];
        	$city_id = $this->_get_param['city_id'];
        	$uid     = $this->_usercenter_uid;
        	$openid  = $this->_openid;
        	
        	//获取产品信息
        	$transfer_data = array(
        		'PlatformId'=>0,
    			'CityId'=>$city_id,
    			'ProductId'=>$pro_id
        	);
        	$product_info	= A('Api')->getProductInfo($transfer_data);
        	
        	if($product_info['data']['promotion']) {
	    		$price	= $product_info['data']['promotion']['endPrice'];
	    	}else{
	    		$price	= $product_info['data']['Price'];
	    	}
	    	
	    	$price	= $price>0? $price:200;
	    	
	    	//支付需要的参数
    		$conf['appid']  = C("APPID");
    		$conf['partnerkey'] = C("PARTNERKEY");
    		$conf['partnerid']  = C("PARTNERID");
    		$conf['appkey'] = C("PAYSIGNKEY");
    		
    		$pro_info['price']			= $price;
    		$pro_info['name']			= $product_info['data']['name'];
    	      //$pro_info['effect_time']	        = '1年';
    	      //$pro_info['use_times']		        = '1次';
    	        $pro_info['effect_time']	        = $product_info['data']['remoteEffectDate'];
    	        $pro_info['use_times']		        = $product_info['data']['remoteUseTimes'];
    	        $pro_info['pro_id']			= $pro_id;
    	        
    	        $this->assign('conf',$conf);
    		$this->assign('pro_info',$pro_info);
    		$this->assign('order_window_status',200);
    		$this->assign('openid',$openid);
    		$this->assign('uid',$uid);
    		$this->assign('city_id',$city_id);
    		
    		$this->display('qcs_sbmit_order_yc');
        }
        
        //远程服务创建订单
        public function yCreateOrder() {
        	$post_data	= I('post.');
	    	$openid		= $post_data['openid'];
	    	$uid		= $post_data['uid'];
	    	$pro_id		= $post_data['pro_id'];
	    	$phone		= $post_data['phone'];
	    	$city_id	= $post_data['city_id'];
	    	
	    	if(!($pro_id && $phone)) {
	    		return json_encode(array('status'=>300,'message'=>'提交失败'));
	    	}
	    	if(!($openid && $uid)) {
	    		return json_encode(array('status'=>100,'message'=>'提交失败'));
	    	}
	    	
	    	$data['pay_process']		= 3; //远程服务
	    	$data['pay_way']		= 2; //微信系统微信支付
	    	$data['user_id']		= $uid;
	    	$data['openid']			= $openid;
	    	$data['ip']			= get_client_ip();
	    	$data['for_who']		= 100;
	    	$data['pro_id']			= $pro_id;
	    	$data['city_id']		= $city_id;
	    	$data['source_from']		= 100;
	    	$data['phone']			= $phone;
	    	$data['index_source']		= session('source');
	    	
	    	$create_info	= A('OrderApi')->createOrder($data);
	    	if($create_info['code'] == 200 && $create_info['data']) {
	    		return json_encode(array('status'=>200, 'data'=>$create_info['data']));
	    	}else{
	    		return json_encode(array('status'=>0, 'message'=>$create_info['message']));
	    	}
        }
        
        //报名页
        public function apply() {
        	$this->checkAuthGetParam();
        	$this->share_url = 'http://localhost/'.C('TP_PROJECT_NAME').'/index.php/PlanRepair/index';
        	$this->imgUrl = 'http://localhost/'.C('TP_PROJECT_NAME').'Public/Images/PlanRepair/share.jpg';
        	
        	$this->assign('user_id',$this->_openid);
                $this->assign('pagename','XXXXX-报名成为XXX页');
                
                $this->display('qcs_applay');
        }
        
        public function ajapply() {
        	$postData = I('post.');
	        $data['Email']=$postData['email'];
	        $data['OpenId'] =$postData['openid'];
	        $data['Name'] = $postData['name'];
	        $data['Phone'] = $postData['phone'];
	        $data['Description'] = $postData['content'];
	        $data['Status'] = 0;
	        $data['CreaterTime'] = date('Y-m-d H:i:s');
	        $data['Idea']=$postData['idea'];
	        
	        $id = M('crt_craftsmaninapply')->add($data);
	        if($id) {
	        	$return_data = array('status'=>200);
	        }else{
	        	$return_data = array('status'=>0);
	        }
	        return json_encode($return_data);
        }
        
        //调研的方法
        public function surveyNew() {
               $this->checkAuthGetParam();
               
               //筛选从数据库中取出可以显示的问题
               $model = M('prd_question')->where(array('displayStatus'=>1))->select();
               
               // 分配到模板变量中去
               $this->assign('openId',$this->_openid);
               $this->assign('question',$model);
               
               // 显示模板页面
               $this->assign('pagename','XXXXX-下一个服务你来定');
               $this->display('qcs_survey');
        }
        
        //调研提交表单的方法
        public function survey_submit() {
        	//获取表单提交的数据
        	//获取问题id的数组
        	$data = I('post.select');
        	//获取电话号
        	$insert['phone']  = I('post.telephone');
        	//获取用户的openId
        	$insert['openId'] = I('post.openId');
        	//获取用户的其他信息
        	$insert['other']  = I('post.other');
        	//获取时间添加数据的时间戳
        	$insert['createTime'] = time();
        	//拼接表单提交的id数据
        	$insert['questionIdStr'] = implode(',', $data);
        	//生成一笔调查问卷数据
        	$model = M('prd_questionnaire')->add($insert);
        	//更新问题回答条数
        	$model = M('prd_question')->where(array('id'=>array('in', $insert['questionIdStr'])))->setInc('countTime');
        	if($model) {
        		$returnData = array('status'=>200, 'msg'=>'非常感谢您，参与需求调研。');
        	}else{
        		$returnData = array('status'=>300, 'msg'=>'提交失败请重新提交。');
        	}
        	return json_encode($returnData);
        }
        
        public function sendCode() {
        	$param['phone'] = I('post.phone');
        	$param['type']  = 2;
        	$data = A('Api')->SendCode($param);
        	return json_encode($data);
        }
        
        public function submit_to_order() {
        	$post_data = I('param.');
        	$data['ProductId']  = $post_data['pro_id'];	//产品ID
        	$data['UserOpenid'] = $post_data['openid'];     //用户openid
        	$data['Name']       = $post_data['name'];	//用户姓名
        	$data['Address']    = $post_data['address'];	//地址
        	$data['Phone']      = $post_data['phone'];	//手机号
        	$data['OrderDate']  = $post_data['date'];	//日期
        	$data['CreaterTime']= date('Y-m-d H:i:s');	//创建日期
        	$date['ForWho']     = $post_data['type'];	//为谁约
        	$data['ShortMessage'] = $post_data['remark'];	//祝福的话
        	$data['Lat']        = $post_data['lat'];	//经度
        	$data['Lng']	    = $post_data['lng'];	//纬度
        	$data['CityName']   = $this->_city_list[$post_data['city']];
        	$data['code']       = $post_data['idcode'];
        	$data['PayProcess'] = 1;			//支付流程 1
        	
        	$btn[] = isset($data['ProductId'])?true:false;
        	$btn[] = isset($data['UserOpenid'])?true:false;
        	$btn[] = isset($data['Name'])?true:false;
        	$btn[] = isset($data['Phone'])?true:false;
        	$btn[] = isset($data['code'])?true:false;
        	$btn[] = isset($data['OrderDate'])?true:false;
        	$btn[] = isset($data['OrderTimeId'])?true:false;
        	$btn[] = isset($data['ForWho'])?true:false;
        	$btn[] = isset($data['Lat'])?true:false;
        	$btn[] = isset($data['Lng'])?true:false;
        	
        	//验证验证码是否过期
        	if(!in_array($false, $btn)) {
        		if(array_key_exists($post_data['city'], $this->_city_list)) {
        			$param['phone'] = $data['Phone'];
				$param['code'] = $post_data['idcode'];
				$code = A('Api')->CheckCode($param);
				if($code['status'] == 200) {
					if(isset($post_data['address_s'])) {
						$data['AddressInfo'] = $post_data['address_s'];
					}
					$id = M('ord_submit_info')->add($data);
					if($id) {
					        	$return['status'] = 200;
					        	$return['msg']    = 'success';
					        	$return['info_id']= $id;
					}else{
					        	$return['status'] = 500;
							$return['msg']    = '网络开了小差，请重试';
					}
				}else{
					$return['status']=503;
					$return['msg']='您填写的验证码有误';	
				}
			}else{
				$return['status']=505;
				$return['msg']='您选择的服务地点未开通XXX服务';
			}
        	}else{
        		$return['status']=300;
			$return['msg']='网络开了小差，请重试';
        	}
        	return json_return($return, $post_data['test']);
        }
        
        //是否关注
        public function is_attention() {
               //echo send_curl('http://localhost/'.C('TP_PROJECT_WEIXIN').'/index.php/Api/is_attention', I('param.')); 
               echo 200;
        }
        
        //在线客服
        public function isonlinekefu() {
        	//工程师是否在线
        	$user_id = I('param.prodlineid');
        	$type    = I('param.type');
        	$hours   = date('H');
        	
        	if($hours<18 && $hours>=9) {
        		$return['result'] = 200;
        	}else{
        		$return['result'] = 100;
        	}
        	
        	return json_encode($return);
        	//$this->ajaxReturn(array('result'=>200));
        }
        
        public function createQueue() {
        	//是否接入会话
        	$data = I('param.');
        	//验证用户是否在会话中 是否排队
        	$caseon['uid']   = $data['open_id'];
        	$caseon['marks'] = '100';
        	$caseon = json_encode($caseon, JSON_UNESCAPED_UNICODE);
        	
        	$awaitinfo = send_curl('http://localhost/Await/findlocation', $caseon);
        	$awaitinfo = json_decode($awaitinfo, true);
        	
        	$getone = send_curl('http://localhost/case/getone', $caseon);
        	$getone = json_decode($getone, true);
        	
        	if($getone['error_code'] == 0 && $awaitinfo['error_code'] == 10010) {
        		//进入队列
        		// $userinfo = M('user_info')->find();
        		$sql = "SELECT * FROM XXXweixin.'wx_user_info' WHERE ( 'user_id' = '{$data['open_id']}' ) LIMIT 1";
        		$userinfo = M()->query($sql);
        		
        		$post_data['uid'] = $data['open_id'];
        		$post_data['marks'] = '100';
        		$post_data['awaittype'] = '200';
        		$post_data['skill'] = 'Q701';
        		$post_data['nicename'] = $userinfo[0]['nick_name'];
        		$post_data['sex'] = $userinfo[0]['sex'] == '1'?'男':'女';
        		$post_data['interface_type'] = '100';
        		$post_data['source_info'] = $data['remark'];
        		
        		$post_data = json_encode($post_data, JSON_UNESCAPED_UNICODE);
        		$return = send_curl('http://localhost/Await/queued', $post_data);
        		$return = json_decode($return, true);
        		if($return['error_code'] == 10000) {
        			// $param['openid']  = $data['open_id'];
        			// $param['msgtype'] = 'text';
        			// $param['message'] = '您好，您已进入排队中。\n菜单上还有好玩的东东,记得戳一下试试哦O(∩_∩)O';
        			// $msg = send_curl('http://localhost/message/send_wxmsg', $param);
        			$return['success'] = true;
        		}else{
        			// $param['openid']  = $data['open_id'];
        			// $param['msgtype'] = 'text';
        			// $param['message'] = '您好，您已进入排队中。\n菜单上还有好玩的东东,记得戳一下试试哦O(∩_∩)O';
        			// $msg = send_curl('http://localhost/message/send_wxmsg', $param);
        			$return['success'] = false;
        		}
        	}elseif($getone['error_code']!=0) {
        		$param['openid']	=  $Data['open_id'];
			$param['msgtype']	= 'text';
			$param['message']	= "您好，你已经在会话中哦 O(∩_∩)O";
			$msg = send_curl('http://localhost/message/send_wxmsg', $param);
			$return['success']      = false;
        	}elseif($awaitinfo['error_code'] == 10000) {
        		//设置用户当前动作
        		$set['uid'] = $Data['open_id'];
        		$set['handle'] = 'manualservice';
        		send_curl('http://localhost/message/send_wxmsg', $set);
        		$param['openid']	=  $data['open_id'];
			$param['msgtype']	= 'text';
			$param['message']	= "欢迎体验XXX服务，正在为您接通XXX客服，请稍等";
        	}
        	return json_encode($return);	
        }
        
        public function orderpay() {
        	$this->display('qcs_pay_order');
        }
        
        //58跳转地址
        public function qcsIndexActivity() {
        	$this->checkAuthGetParam();
        	$uid = $this->_usercenter_uid;
        	$openid = $this->_openid;
        	
        	$location = send_curl('http://localhost/'.C('TP_PROJECT_WEIXIN').'/index.php/Api/getlocation', array('openid'=>$this->_openid));
        	$location = json_decode($location, true);
        	
        	$param['CityId'] = $this->_city_id;
        	$param['PlatformId'] = 0;	//微信的平台ID
        	$param['Sorting']    = 1;	//排序方式 2为正序 1为倒序
        	$param['pro_id_s']   = '6,8,9';
        	
        	$module = A('Api')->getProductList($param);
        }
        
        public function selectCard() {
        	$this->checkAuthGetParam();
        	$openid = $this->_openid;
        	$uid    = $this->_usercenter_uid;
        	$this->_get_param['openid'] = $openid;
        	$this->_get_param['uid']    = $uid;
        	$info   = M('ord_submit_info')->where("UserOpenid='%s'", $openid)->order('InfoId desc')->find();
        	$this->_get_param['city_id']  = $city_id  = $info['CityName'];
        	$this->_get_param['pro_id']   = $pro_id   = $info['ProductId'];	//产品id
        	$this->_get_param['craft_id'] = $craft_id = $this->_get_param['crt_id'];	//XXXid
        	$this->_get_param['address']  = $info['Address'].'('.$info['AddressInfo'].')';
        	$this->_get_param['address_info'] = $info['Address'];
        	$this->_get_param['lat'] = $lat = $info['Lat'];
        	$this->_get_param['lng'] = $lng = $info['Lng'];
        	$this->_get_param['order_date'] = $info['OrderDate'];	//预约日期
        	$this->_get_param['order_time_id'] = $info['OrderTimeId'];	//时间Id
        	$this->_get_param['for_who'] = $info['ForWho'];		//100为自己， 200为朋友
        	$this->_get_param['wish']    = $info['ShortMessage'];
        	$this->_get_param['name']    = $info['Name'];
        	$this->_get_param['phone']   = $info['Phone'];
        	$this->_get_param['crt_name'] = M('crt_craftsmaninfo')->where(array('CraftsmanId'=>$this->_get_param['crt_id']))->getField('TrueName');
        	if(!$this->_get_param['pay_process']) {
        		$this->_get_param['pay_process'] = $info['PayProcess'];
        	}
        	
        	//用户选择的卡券code和卡券Id
        	$codeid = $this->_get_param['codeid'];
        	$cardid = $this->_get_param['cardid'];
        	
        	$this->_get_param['time'] = M('prd_servicetime')->where(array('TimeId'=>$this->_get_param['order_time_id']))->getField('StartTime');
        	
        	//api接口
        	$transfer_data = array(
        		'PlatformId'=>0,
        		'CityId'=>$city_id,
        		'ProductId'=>$pro_id
        	);
        	$product_info = A('Api')->getProductInfo($transfer_data);
        	$this->_get_param['prd_name'] = $product_info['data']['title'];
        	if($product_iofo['data']['promotion']) {
        		$price = $product_info['data']['promotion']['endPrice'];
        	}else{
        		$price = $product_info['data']['Price'];
        	}
        	$price = $price ? $price : 120;
        	$assign_data['price'] = $price;
        	
        	//支付需要的参数
		$conf['appid']  	= C("APPID");
		$conf['partnerkey']     = C("PARTNERKEY");
		$conf['partnerid']      = C("PARTNERID");
		$conf['appkey'] 	= C("PAYSIGNKEY");
		
		$select_regbag_url      = U('Redbag/select_redbag');	//选择卡券页
		$assign_data['select_redbag_url'] = $select_redbag_url;
		$craft_param = array(
			'CraftsmanId'=>$craft_id,
			'lat'=>$lat,
			'lng'=>$lng
		);
		$craft_info = A('Api')->getCraftsManInfo($craft_param);
		
		//上门费 价格=公里数
		$trave_price = ceil($craft_info['data']['Distance']*2);
		$trave_price = $trave_price>0 ? $trave_price : 0;
		if($trave_price > 80 || empty($craft_id)) {
			//上门费超过80元 或者 XXX id为空
			$trave_price = 80;
		}
		$assign_data['trave_price'] = $trave_price;
		
		//用户卡券信息
		$card_info_data['cardid'] = $cardid;
		$card_info_data['codeid'] = $codeid;
		$card_info_data['openid'] = $openid;
		
		$pay_api_model = D('PayApi');
		$regbag_data = $pay_api_model->getUserCardinfo($card_info_data);
		
		$assign_data['redbag_data'] = json_encode($redbag_data);
		$assign_data['openid']      = $openid;
		
		//线上线下支付
		if(in_array($this->_city_id, $this->_pay_offline_cityid)) {
			$assign_data['is_below_line'] = 1;
		}else{
			$assign_data['is_below_line'] = 0;
		}
		
		//优惠券信息
		$coupons_info = array(
			'source'=>1,
			'phone' =>$this->_get_param['phone'];
			'pro_id'=>$pro_id
		);
		
		$activity_data = $pay_api_model->getUserCouponsinfo($coupons_info);
		$assign_data['activity_data'] = json_encode($activity_data);
		
		$this->assign('pageHome', 'craftSelectCard');
		$this->assign('pagename', 'XXXXX-支付页');
		$this->assign('assign_data', $assign_data);
		$this->assign('get_param', $this->_get_param); //get参数
		$this->assign('order_window_status', 100);
		$this->assign('select_redbag_url', $select_redbag_url);
		$this->assign('conf', $conf);
		
		$this->display('qcs_pay_order');
        }
        
        //创建订单ajax
        public function createOrderinfo() {
        	$postData = I('post.');
        	$openid   = $postData['order_openid'];
        	$uid      = $postData['order_uid'];
        	$city_id  = $postData['order_city_id'];
        	if(!($openid && $uid)) {
        		return json_encode(array('status'=>100, 'message'=>'提交失败'));
        	}
        	$order_data['user_id'] = $uid;
        	$order_data['pro_id']  = $pro_id   = $postData['order_pro_id'];		//产品id
        	$order_data['craft_id']= $craft_id = $postData['order_address'];	//XXXid
        	$order_data['lat']     = $lat      = $postData['order_lat'];
        	$order_data['order_date']	   = $order_date    = $postData['order_date']; //预约日期
		$order_data['order_time_id']       = $order_time_id = $postData['order_time_id']; //时间Id
		$order_data['for_who']		   = $for_who	    = $postData['order_for_who']; //100为自己，200为朋友
		$order_data['wish']		   = $wish	    = $postData['order_wish'];
		$order_data['name']		   = $name	    = $postData['order_name'];
		$order_data['phone']		   = $phone	    = $postData['order_phone'];
		$order_data['city_id']		   = $city_id;
		
		//用户选择的卡券code和卡券Id
		$order_data['order_codeid']	   = $codeid        = $postData['order_codeid'];
		$order_data['order_cardid']	   = $cardid 	    = $postData['order_cardid'];
		$order_data['openid']		   = $openid;
		
		//array(1=>'线下支付',2=>'微信系统微信支付',3=>'vmall微信支付',4=>'vmall支付宝支付');
		$order_data['pay_way']		   = $pay_way	    = $postData['order_pay_way']==2? 2:1;
		$order_data['source_from']         = 100;	    //订单来源【可选值100微信，200web,300安卓，400ios】
		$order_data['pay_process']	   = $postData['order_pay_process'];	//支付流程：1正常支付，2客服引导，3客服专家在线，4.距离大于40公里支付
		$order_data['package_id']	   = '';
		$order_data['address_id']	   = 0;
		$order_data['index_source']	   = session('source'); //首页来源
		$order_data['coupons_id']	   = $postData['order_activity'];	//优惠券Id
		
		$create_info = A('OrderApi')->createOrder2($order_data);
		if($create_info['code'] == 200 && $create_info['data']) {
			return json_encode(array('status'=>200, 'data'=>$create_info['data']));
		}else{
			return json_encode(array('status'=>0, 'message'=>$create_info['message']));
		}
        }
}
