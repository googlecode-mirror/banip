<?php
/*
@author 肖江
2011.8.29
Client -> Proxy Server -> Service Server -> Proxy Server -> Client.

Client -->Service Server  || Support GET POST(Include File Upload)
Service Server --> Client || Support Cookie Session Header etc..

用法
$pProxyS=new phpProxyService();
$serviceData=$pProxyS->proxyService('http://www.bbc.com');

*/
class phpProxyService {
	public $cost;
	Protected function microtime_float()	{
    list($usec, $sec) = explode(" ", microtime());
    return ((float)$usec + (float)$sec);
	}
	/*
	生成普通的Header,参数是需要忽略的项，默认忽略host和encoding.
	*/
	Protected function makeHeader($ignore=array()) { 
		$toClient = array();
		$ignore = array_change_key_case(array_fill_keys(array_merge($ignore,array('content-type','Accept','Host','Accept-Encoding')),1),CASE_UPPER);//lowerCase & list TO Key and Merge
	   foreach($_SERVER as $serKey=>$serVal ) {
	   	$serKey = str_replace("_","-",$serKey);
			if(substr($serKey,0,5) == 'HTTP-' && !isset($ignore[substr($serKey,5)]) ) 
				$toClient[] = str_replace(" ","-",ucwords(str_replace("-"," ",strtolower(substr($serKey,5))))).": ".$serVal;
		}
		if(isset($_SERVER['CONTENT_TYPE'])) $toClient[] =  'Content-Type: '.$_SERVER['CONTENT_TYPE'];
		$toClient[]='Connection: close'; //强制关闭。
/*		$c=array();
		foreach($_COOKIE as $cKey => $cVal) {
			if(substr($cKey,0,3)!='__u') //这些cookie是页面级别的，不推送 TODO 这个部分可以和下边的那个TODO连上。
				$c[$cKey]=$cVal;
		}
		if($c) $toClient[]='Cookie: '.http_build_query($c,'','; ');
*/		
		return implode("\r\n",$toClient);
	}
	/*
	生成上传文件时候的数据
	*/
	Protected function buildPost($boundary) {
		$data=array();
		foreach($_POST as $pKey => $pVal) {
			if(is_array($pVal)) { //用的是[]模式。。
				foreach($pVal as $perKey => $perVal) {
					$data[]=$boundary;
					$data[]="Content-Disposition: form-data; name=\"{$pKey}[]\"\r\n";
					$data[]=$perVal;
				}
			}
			else {
				$data[]=$boundary;
				$data[]="Content-Disposition: form-data; name=\"$pKey\"\r\n";
				$data[]=$pVal;
			}
		}
		foreach($_FILES as $fKey => $perFile) {
			if(is_array($perFile['name'])) { //用的是[]模式。。真麻烦啊。。
				foreach($perFile['name'] as $perKey => $perVal) {
					if($perFile['error'][$perKey] != 0) continue;  //如果proxy server上文件上传失败了，将不再发送给service Server这里是唯一一个不一致的地方，需要小心。
					$data[]=$boundary;
					$data[]="Content-Disposition: form-data; name=\"{$fKey}[]\"; filename=\"{$perFile['name'][$perKey]}\"";
					$data[]="Content-Type: {$perFile['type'][$perKey]}\r\n";
					$data[]=file_get_contents($perFile['tmp_name'][$perKey]);
				}
			}
			else {
				if($perFile['error'] != 0) continue;  //如果proxy server上文件上传失败了，将不再发送给service Server这里是唯一一个不一致的地方，需要小心。
				$data[]=$boundary;
				$data[]="Content-Disposition: form-data; name=\"{$fKey}\"; filename=\"{$perFile['name']}\"";
				$data[]="Content-Type: {$perFile['type']}\r\n";
				$data[]=file_get_contents($perFile['tmp_name']);
			}
		}
		$data[]="\r\n".$boundary."--";
		return implode("\r\n",$data);
	}
	Protected function makeOpts() {
		/*
		处理REQUEST_METHOD的不同请求
		*/
		if(in_array($_SERVER['REQUEST_METHOD'],array('GET','HEAD'))) {  //处理get和header
			$opts = array( 
		          'method'=>"GET", 
		          'header'=>$this->makeHeader()
		      );
		}
		elseif($_SERVER['REQUEST_METHOD']=='POST') { //处理post
			/*
			Content-Type: multipart/form-data; boundary=---------------------------310001055524671 
			Content-Length: 1013 
			
			-----------------------------310001055524671 
			Content-Disposition: form-data; name="MAX_FILE_SIZE" 
			
			30000 
			-----------------------------310001055524671 
			Content-Disposition: form-data; name="userfile"; filename="cz.txt" 
			Content-Type: text/plain 
			
			test
			-----------------------------310001055524671--
			*/
			$header   = $this->makeHeader(array('Content-Length')); //生成头，自己算正文长度
			if(isset($_FILES) && count($_FILES)>0) {  //处理文件文件上传
				$boundary = '--'.trim(substr(strstr($_SERVER['HTTP_CONTENT_TYPE'],"boundary"),9));
				$postData = $this->buildPost($boundary); //生成数据主体
			}
			else {    //普通post
			/*
			Content-Type: application/x-www-form-urlencoded 
			Content-Length: 6 
			
			tt=zzz
			*/
				$header   = $this->makeHeader(array('Content-Length'));
				$postData = http_build_query($_POST);  //生成数据主体
			} 
			$header  .= "\r\nContent-Length: ".strlen($postData);
			$opts     = array( 
			            'method'=>"POST", 
			            'header'=>$header,
			            'content'=>$postData
			       			);
		}
		return array('http' => $opts);
	} //end makeOpts
	
	Public function proxyService ($serviceHost = '',$reqURI = false) {
		$time_start = $this->microtime_float();

	/*
	处理User->Proxy->Service的请求
	*/
		if($serviceHost == '') $serviceHost=$_SERVER['SERVER_NAME'];
		$opts=$this->makeOpts();
		$context = stream_context_create($opts);
		if($reqURI === false)
			$contents = file_get_contents($serviceHost.$_SERVER['REQUEST_URI'],false,$context);
		else
			$contents = file_get_contents($serviceHost.$URI,false,$context);
		if($contents === false)
			 print_r($opts);
	/*
	处理Service->Proxy->User的请求
	*/
  	$allowHeader = array('set-cookie' => 1,
											 'pragma' => 1,
											 'cache-control' => 1,
											 'expires' => 1,
											 'date' => 1,
											 'vary' => 1,
											 'content-type' => 1,
											 'location'=>2
											 );
		//TODO :由于Service Server发送过来的set cookie指令在这里能被监控到，实际上这些cookie 的key才需要打到后端，其他的可以不打到后端，是页面级别的
		//但是由于需要记录这些key 无非是用session memcached database之类的，不再本类可以控制的范围内。。所以暂时不做了。	
		//其实varnish这种缓存也会将cookie全都抛给后端，一样的。。。算了，还是不用做了。。					
		foreach($http_response_header as $perHeader) {
			$h = explode(":",$perHeader);
			if(isset($h[0]) && isset($allowHeader[strtolower($h[0])])) {
				header($perHeader);  
				if( $allowHeader[strtolower($h[0])]  == 2) die();
			}
		}
		$this->cost=$this->microtime_float()-$time_start; //计算消耗时间
		return $contents;
	} //end ProxyService
}//end Class


