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
  *  使用方法 *** //开启主服务
  * nohup /usr/local/php/bin/php banIp.php startService  &
  * //设置crontab 当服务器访问量非常少的，时候，通过这个程序激活banIp 每分钟统计数据。并不是一定要设置的。
  * crontab -e
  ***   /usr/local/php/bin/php /home/www/banIp.php Crontab
  * ///在php文件中include banIp.php
  * 并在执行核心应用时使用
  * $banIp->logRequest() 记录用户操作。