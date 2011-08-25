<?php
/* 
 * @author Albert
 * 2011.8.24
 * version 0.1.2
 * 处理前端应用发过来的LOG
 * serverIn.log :  格式为IP(long) | time() 一行一个
 * 分别为1分钟 5 分钟 30分钟设置阀值(在$limit中)
 * 使用popen tail -f log文件
 * 
 * 1.分解log 按照时间（分钟） IP
 * 2.IP如果在BanIP列表中 ，或者在 whiteIPList中则不继续检查
 * 3.按照1 5 30 分别计算每个IP的次数
 * 4.超过阀值的IP放入BanList准备写入文件
 * 5.每5分钟将BanList写入$banIpLimitDataFile 准备banIpJob进行ban
 * 6.每5分钟将$whiteIpLimitDataFile读入白名单，和之前的白名单混合
 * 
 * **************** 使用方法 ***********
 * //开启主服务
 * nohup /usr/local/php/bin/php banIp.php startService  &    
 * //设置crontab 当服务器访问量非常少的，时候，通过这个程序激活banIp 每分钟统计数据。并不是一定要设置的。
 * crontab -e
 * * * * * * /usr/local/php/bin/php /home/www/banIp.php Crontab
 * ///在php文件中include banIp.php
 * 并在执行核心应用时使用
 * $banIp->logRequest() 记录用户操作。
 * **************** 使用方法介绍结束 ****
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

class banIpClass  {
	/////Config BanIp应用
	Private $limit = array("1" =>40, "5" =>80, "30" =>200);  //1分钟 40个请求，5分钟80个请求，30分钟200个请求(可调整)
	Private $allowSuffix = array(".googlebot.com", ".yahoo.net", ".search.live.com", ".inktomisearch.com", "msn.com");
	Private $phpBin = '/usr/local/php/bin/php';
	//定义交互文件
	Private $baseDir =false ;
	Private $banIpLimitDataFile = "requestLimit.data";    //json串，将Ban IP存在内存文件中，抛送给BanIpJob程序
	Private $whiteIpLimitDataFile = "requestwhiteLimit.data";  //BanIpJob回传的白名单
	Private $serverLogIn = "serverIn.log";  //应用写入文件
	Private $banIpLogFile = 'banIpLog.log';  //写入 Mem Usage: 669.61 kb --- BanListIp --1  --whiteIpList -- 7-
	Private $banIpRealBanIP= 'banIpList.log'; //最终的BanIp的列表
	//预定义变量
	Private $banIpList = array();
	Private $whiteIpList = array('0.0.0.0'=>1); //默认一个ip这个ip是sysCrontab插入的，每分钟插入一个，用以激活计算循环有一定流量的网站不用这条
	Private $banIpLogOnce = false;
	
	////获取程序允许的目录////////////
	Protected function getDir() {
		if(!$this->baseDir)
			$this->baseDir=rtrim(dirname(__FILE__), '/\\') . DIRECTORY_SEPARATOR ;
		return $this->baseDir;
	}
	////按照1 5 30分钟来算
	Protected function returnData($time) {
		return array("1" =>$time, "5" =>intval($time/5), "30" =>intval($time/30));
	}
	////内存转化显示
	Protected function memConvert($size) {
	  $unit = array('b', 'kb', 'mb', 'gb', 'tb', 'pb');
	  return @round($size/pow(1024, ($i = floor(log($size, 1024)))), 2).' '.$unit[$i];
	}
	///系统job 用于流量比较小的网站，无法保证每分钟有一个请求
	Public function sysCrontabe() {  
		error_log('0.0.0.0|'.intval(time()/60)."\r\n", 3, $this->getDir().$this->serverLogIn ); //每分钟系统crontab调用一次
	}
  ///正式的banIp Service 函数，处理调用的log数据，算出那些ip准备ban并忽略白名单ip
	Public function banIpService() {
		$tmpBanIpList = array();
		if(!is_file($this->getDir().$this->serverLogIn)) die("没有找到数据文件,请先调用logRequest() 函数获取数据\r\n");
		$tailLog  =  popen("tail -0f ".$this->getDir().$this->serverLogIn,  "r");  //使用popen 链接tail方法，性能还是不错的 使用0f是为了避免之前的数据影响分析
		if ($tailLog) {
		    while (1) {
		        $data  =  explode("|", trim(fgets($tailLog)));
		        if(isset($data[1]) && is_numeric($data[1])) {
		        	if(!isset($nowCheck)) {
		        		$nowCheck = $this-> returnData($data[1]);
		        	}
		        	$ipLong = ip2long($data[0]);
		        	//========================================================================== 
		        	if(isset($this->banIpList[$ipLong])||isset($this->whiteIpList[$ipLong])) continue; //已经ban了或者已经是白名单了。
		        	$tmp = $this->returnData($data[1]);
		        	foreach(array("1", "5", "30") as $interval) {
		        		if($tmp[$interval]!= $nowCheck[$interval]) {
		        			unset($ipList[$interval][$nowCheck[$interval]]);  //释放内存
		        			$nowCheck[$interval] = $tmp[$interval];
		        			if($interval == 5) {  //过了5分钟了，开始操作ban记录
		        				if(!is_file($this->getDir().$this->banIpLimitDataFile)&& count($this->banIpList)>0 ) { //文件不存在的时候再创建
		
		        					file_put_contents($this->getDir().$this->banIpLimitDataFile, json_encode($this->banIpList));
		        					$tmpBanIpList = $this->banIpList;
		        					$this->banIpList = array(); //清除了，应该已经发送ban IP的指令了，如果不能ban Ip也发回white IP了。
		        				}
		        				if(is_file($this->getDir().$this->whiteIpLimitDataFile)) {  //文件存在的时候开始
		        					$tmpw = json_decode(file_get_contents($this->getDir().$this->whiteIpLimitDataFile), true);
		        					foreach($tmpw as $perIpListV  => $perTmpe)
		        						$this->whiteIpList[$perIpListV] = 1;
		        					@unlink ($this->getDir().$this->whiteIpLimitDataFile) ;  //删除这个白名单文件，因为已经读到内存中了
		        				}
		        				//========================输出log===========================
		        				$logStr = "Mem Usage: ".$this->memConvert(memory_get_usage())." --- BanListIp --".count($tmpBanIpList)."  --whiteIpList -- ".count($this->whiteIpList)."-\r\n";
		        				error_log($logStr, 3, $this->getDir().$this->banIpLogFile); //写入log
		        				exec($this->phpBin .' '.__FILE__." BanJob");
		        			}
		        		}
		        		if(isset($ipList[$interval][$nowCheck[$interval]][$ipLong])) {
		        			$ipList[$interval][$nowCheck[$interval]][$ipLong]++;
		        			if($ipList[$interval][$nowCheck[$interval]][$ipLong] > $this->limit[$interval])
		        				$this->banIpList[$ipLong] = $interval;
		        		}
		        		else $ipList[$interval][$nowCheck[$interval]][$ipLong] = 1;
		        	}
		        }
		    }
		    fclose($handle);
		}
	} //close banIpService
	
  // 获取执行人IP
	Protected function get_real_ip() {
	        $ip = false;
	        //注意HTTP_X_FORWARDED_FOR可以被欺诈，我这么用是因为前面有cdn不怕，但是正常情况下请优先remote_addr
	        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
	                $ips  =  explode (", " ,  $_SERVER['HTTP_X_FORWARDED_FOR']);
	                foreach($ips as $perIp) {
	                	if ( $this->check_ip( trim($perIp) ) ) {
	                     $ip  =  trim($perIp);
	                      break;
	                     }
	                }
	        }
	        if( $ip  === false && !empty($_SERVER["HTTP_CLIENT_IP"]) && $this->check_ip($_SERVER["HTTP_CLIENT_IP"])) {
	               $ip  =  trim($_SERVER["HTTP_CLIENT_IP"]);
	        }
	        return ($ip ? $ip : $_SERVER['REMOTE_ADDR']);
	}
	//检查ip是否是内部ip
	Protected function check_ip($ip) {
	 return !preg_match("/^(10|172.16|192.168|127.0.0)./" ,  trim( $ip ) );
	}
	
	//记录外部请求共service分析
	Public function logRequest() { 
		if($this->banIpLogOnce) return;
		else $this->banIpLogOnce = true;  //保证logRequest 在一次请求中只记录一次
	  error_log($this->get_real_ip().'|'.intval(time()/60)."\r\n", 3, $this->getDir().$this->serverLogIn ); //每次php核心应用都要保证调用这里
	}
	
	
	//这个函数将被 banIpService 所调用 
	Public function banIpJob() {
		if(!is_file($this->getDir().$this->banIpLimitDataFile)) die;  //前端指令未下达，所以我也不用执行
		$banIpList = json_decode(file_get_contents($this->getDir().$this->banIpLimitDataFile), true);
		$whiteIpList=array();
		$ban = 0;
		foreach($banIpList as $perIp  => $perRes) {
			$host = gethostbyaddr(long2ip($perIp));
			if($this->checkHost($host)) {   //是被允许的后缀，说明他是正常的搜索机器人
				$whiteIpList[$perIp] = 1;  //添加到白名单去
			}
			else {  //否则，就真干掉了！
				exec("iptables -I INPUT -s ".long2ip($perIp)." -j DROP"); //干掉 要注意，这个一定是root身份执行，否则不会成功
				error_log(long2ip($perIp)."|".date(DATE_RFC822)."\r\n", 3, $this->getDir().$this->banIpRealBanIP);
				$ban++;
			}
		}
		if(count($whiteIpList)>0) {
			file_put_contents($this->getDir().$this->whiteIpLimitDataFile, json_encode($whiteIpList));
		}
		error_log("BanIpJob--BanIP:".$ban." WhiteIp:".count($whiteIpList)."\r\n", 3,$this->getDir().$this->banIpLogFile );
		@unlink($this->getDir().$this->banIpLimitDataFile);  //删掉这个文件以后，下一个文件才能送下来。否则会暂存在banIp文件(内存) 中
	}
	
	
	//检查host是不是再被可允许范围内
	Protected function checkHost($host) {
			foreach($this->allowSuffix as $perSuffix) {
				if(substr($host, (0-strlen($perSuffix))) == $perSuffix)
					return true;
			}
			return false;
		}
} //close Class

$banIp = new banIpClass();

//$banIp->logRequest(); include 这个文件以后，可以直接调用这个函数记录。

if(isset($argv[1]) && trim($argv[1]) == 'serviceStart') {  //使用php banIp.php serviceStart 指令来启动
	$banIp->banIpService();
}
elseif(isset($argv[1]) && trim($argv[1]) == 'BanJob') {   //5分钟启动一次job 进行iptables的ban操作
	$banIp->banIpJob();
}
elseif(isset($argv[1]) && trim($argv[1]) == 'Crontab') {   //crontab -e 定义* * * * * /usr/local/php/bin/php banIp.php Crontab
	$banIp->sysCrontabe();
}