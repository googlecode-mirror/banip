#php proxy service程序

可以将浏览器请求发送给后端服务器，并返回，同时处理get post file upload和session cookie.

将client信息发送给proxy server 处理后发送给service server
  * 支持get post file upload
  * 兼容[.md](.md)方式

&lt;input name=test[]&gt;


  * 兼容cookie发送(support session)

将service server发送给proxy server组装给client
  * 支持service server发送的Header指令
  * 当header中是loction跳转的时候，自动exit
  * 对proxy server的header进行过滤，通过过滤的，发送给前段。