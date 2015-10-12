# xAsyncRedis v1.0
Реализация асинхронного PHP-Redis коннектора.<br />
<br />
Пример использования:<br />
<br /><br />
  $redisCommands = [];<br />
  $redisCommands[] = array('SET foo bar', array('unix:///tmp/redis.sock'));<br />
  $redisCommands[] = array('GET foo1', array('tcp://127.0.0.1:6380'));<br />
  $ps = new xAsyncRedis();<br />
  $ps->settimeout(1000);<br />
  $ps->setCommands($redisCommands);<br />
  $ps->run();<br />
  $responses = $ps->getresponse());<br />	
