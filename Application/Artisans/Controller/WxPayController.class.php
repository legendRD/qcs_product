<?php
namespace Artisans\Controller;
class WxPayController extends CommonController {
      private $_pay_log_url = '/share/pay_log_url/weixin/original';                       //订单支付日志
      private $_error_url   = '/share/pay_log_url/weixin/original/error.log';             //订单失败日志
      private $_success_url = '/share/pay_log_url/weixin/original/caifutong_success.log'; //财付通成功返回日志
      private $_update_url  = '/share/pay_log_url/weixin/original/update_status.log';     //更新订单日志
      private $_findwx_order_url = '/share/pay_log_url/weixin/original/findwx_order.lgo'; //重新生成订单
      
      /*
      *解析xml格式的文档
      *@param xml文件或者相关数据
      *@return array 返回要插入的数据数组
      */
      public function parseXml($xml_data, $param) {
      	     //初始化要插入的数组
      	     $returndata = array();
      	     //实例化dom解析对象
      	     $doc = new \DOMDocument('1.0', 'utf-8');
      	     //保留原有空格元素，默认清楚
      	     $doc->preserveWhiteSpace = false;
      	     //加载xml元素
      	     $doc->loadXML($xml_data);
      	     //实例化xpath对象
      	     $xpath = new \DOMXPath($doc);
      	     //根据用户所传数组参数来进行解析
      	     foreach($param as $value) {
      	     	     //根据指定的字段实例化相关的数据对象
      	     	     $item = $xpath->query("//xml/".$value."[1]");
      	     	     if(!empty($item)) {
      	     	     	       //获取数据长度
      	     	     	       $length = $item->length;
      	     	     	       //获取字段的数据
      	     	     	       $nodeValue = $item->item(0)->nodeValue;
      	     	     	       //如果该节点有数据，才获取该数据
      	     	     	       if($length>0) {
      	     	     	       	         $returndata[$value] = $nodeValue;
      	     	     	       }
      	     	     }
      	     }
      	     //返回解析好的结果
      	     return $returndata;
      }
      
      //微信回调更新订单
      public function notice() {
             $orderParam = I('get.');     //获取订单参数
             $userParam  = $GLOBALS['HTTP_RAW_POST_DATA'];  //获取用户信息
             $dateString = date('Ymd');
             
             //将原始信息存入文件中
             $log_url = $this->_pay_log_url.'/'.$dateString.'.log';
             wlog($log_url,'---------------start---------------');
             wlog($log_url,'---------------GET---------------');
	     wlog($log_url,$orderParam);
	     wlog($log_url,'---------------POSTBEBIN---------------');
	     wlog($log_url,$userParam);
             wlog($log_url,'---------------END---------------');
		 
	     //将数据解析后存入表中 获取支付用户表的字段数组
	     $payment_user_fields    = M("pay_payment_userinfo")->getDbFields();
	     $userinfo               = $this->parseXml($userParam, $payment_user_fields);
	     $userinfo['create_time']= array('exp', 'now()');
	     $userinfo['status']     = 1;
	     $userinfo_flag 	     = M('pay_payment_userinfo')->add($userinfo);
	     
	     //判断是否插入成功
	     if(!$userinfo_flag) {
	     	    wlog($this->_error_url, mysql_error().'mysql'.M('pay_payment_order')->getLastSql());
	     }
	     $orderParam['OpenId'] = $userinfo['OpenId'];
	     $orderParam['create_time'] = array('exp','now()');
	     $orderParam['status'] = 1;
	     $order_flag = M('pay_payment_order')->add($orderParam);
	     if(!$order_flag) {
	     	wlog($this->_error_url, mysql_error().'mysql'.M('pay_payment_order')->getLastSql());
	     }
	     
	     //更新订单状态及发送消息，其中trade_state为财付通返回，0代表成功
	     $trade_state = true;
	     if($orderParam['trade_state'] == '0') {
	     	
	     	    //该订单支付信息在表中的记录次数
	     	    $paymentoutcount = M('pay_payment_order')->where("trade_state='0' and out_trade_no='".$orderParam['out_trade_no']."'")->count();
	     	    wlog($this->_success_url, M('pay_payment_order')->getLastSql().'--order count:'.$paymentoutcount);
	     	    
	     	    //只有获取第一次通知记录更新订单状态
	     	    if($paymentoutcount == 1) {
	     	    	$usershopid = $orderParam['out_trade_no'];	//订单号
	     	    	$artisans_order = M('ord_orderinfo')->where("VmallOrderId='".$usershopid."'")->find();
	     	    	wlog($this->_success_url, $artisans_order);
	     	    	if($artisans_order) {
	     	    		$artisans_order['Status']	 = $data['Status']      = 3;
				$comm_time			 = date('Y-m-d H:i:s');
				$artisans_order['UpdateTime']    = $data['UpdateTime']	= $comm_time;
				$artisans_order['PayTime']	 = $data['PayTime']	= $comm_time;
				
				//修改用户订单状态为已支付
				$usershop_res = M('ord_orderinfo')->where("VmallOrderId='".$usershopid."'")->save($data);
				
				//记录错误日志
				if(!$usershop_res) {
					
					//如果更新失败，则计入错误日志
					file_put_contens($this->pay_log_url, "artisans_cft_log.txt", date('Y-m-d H:i:s').'--->用户订单更新支付状态失败,对应的订单号为:'.$usershopid.':from:notice'.'\r\n', FIEL_APPEND);
					$trade_state = false;
					wlog($this->_success_url, 'update status fail! mysql: '.M()->getLastSql());
				}else{
					//发送消息, 调取接口
					$this->_paySuccessDeal($artisans_order);
					wlog($this->_success_url, ' update status success!');
				}
	     	    	}else{
	     	    		$trade_state = false;
	     	    		wlog($this->_success_url, " The trade_out_no ".$usershopid." does not exist");
	     	    	}
	     	    }
	     }else{
	     	  wlog($this->_error_url, "caifutong return error! trade_out_no: ".$orderParam['out_trade_no']);
	     	  $trade_state = false;
	     }
	     if($userinfo_flag && $order_flag && $trade_state) {
	     	echo 'success';
	     }else{
	     	echo 'fail';
	     }
      }
      
      //查看订单支付状态是否更新成功
      public function updateOrder() {
      	     $post_data = I('post.');
      	     $out_trade_no = $post_data['out_trade_no'];	//订单号
      	     wlog($this->_update_url, "------------start------------");
      	     wlog($this->_update_url, $post_data);
      	     $openid = $post_data['openid'];
      	     wlog($this->_update_url, 'openid:'.$openid);
      	     if(!$openid) {
      	     	  return json_encode(array('status'=>0, 'out_trade_no'=>$out_trade_no));
      	     }
      	     if(C('ProductStatus') === false) {
      	     	   $orderinfo = M('pay_payment_order')->where(" out_trade_no = '{$out_trade_no}'")->order(" create_time desc ")->limit(1)->field("trade_state, id")->find();
      	     }else{
      	     	   $orderinfo = M('pay_payment_order')->where(" OpenId='{$openid}' and out_trade_no = '{$out_trade_no}' ")->order(" create_time desc ")->limit(1)->field("trade_state, id")->find();
      	     }
      	     $status = false;
      	     if(isset($orderinfo['trade_state']) && $order['trade_state'] == 0) {
      	     		//查看订单状态是否更新，如果没有更新，则更新订单状态
      	     		$artisans_order	= M('ord_orderinfo')->where(" VmallOrderId='".$out_trade_no."' ")->find();
			wlog($this->_update_url,$artisans_order);
			if($artisans_order['Status'] == 3) {
				$status = true;
			}
			if($artisans_order['Status'] == 0) {
				$artisans_order['Status']     = $data['Status']     = 3;
				$comm_time   		      = date('Y-m-d H:i:s');
				$artisans_order['UpdateTime'] = $data['UpdateTime'] = $comm_time; 
				$artisans_order['PayTime']    = $data['PyaTime']    = $comm_time;
				$res = M('ord_orderinfo')->where(" VmallOrderId='{$out_trade_no}' ")->save($data);
				if(!$res) {
					//如果更新失败，则计入到错误日志
					wlog($this->_update_url, " update status fail ! mysql: ".M()->getLastSql());
				}else{
					$status = true;
					$this->_paySuccessDeal($artisans_order);	//发送消息，调取接口
					wlog($this->_update_url, ' update status success ! ');
				}
			}
			
      	     }
      	     wlog($this->_update_url, "------------end------------");
      	     if($status) {
      	     	       return json_encode(array('status'=>200, 'out_trade_no'=>$out_trade_no));
      	     }else{
      	     	       return json_encode(array('status'=>0,   'out_trade_no'=>$out_trade_no));
      	     }
      }
      
      //查询微信后台服务器是否成功生成订单
      public function findwxorder() {
      		$status = false;
      		$post_data = I('post.');
      		wlog($this->_findwx_order_url, "------------start------------");
		wlog($this->_findwx_order_url, $post_data);
		$out_trade_no = $post_data['out_trade_no'];
		$openid       = $post_data['openid'];
		wlog($this->_findwx_order_url, "openid".$openid);
		$ret = $this->orderQueryApi($out_trade_no);
		
		//解析返回的订单结果
      }
}
