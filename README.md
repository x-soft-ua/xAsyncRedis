# xAsyncRedis v1.0
Реализация асинхронного PHP-Redis коннектора.

Пример использования:

  $redisCommands = [];
  $redisCommands[] = array('SET foo bar', array('unix:///tmp/redis.sock'));
  $redisCommands[] = array('GET foo1', array('tcp://127.0.0.1:6380'));
  $ps = new xAsyncRedis();
  $ps->settimeout(1000);
  $ps->setCommands($redisCommands);
  $ps->run();
  $responses = $ps->getresponse());	
